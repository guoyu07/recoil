<?php

declare (strict_types = 1); // @codeCoverageIgnore

namespace Recoil\Kernel;

use Closure;
use Error;
use Exception;
use Generator;
use InvalidArgumentException;
use Recoil\Dev\Trace\CoroutineTrace;
use Recoil\Dev\Trace\Trace;
use Recoil\Dev\Trace\YieldTrace;
use Recoil\Exception\TerminatedException;
use Recoil\Kernel\Exception\PrimaryListenerRemovedException;
use Recoil\Kernel\Exception\StrandListenerException;
use ReflectionClass;
use SplObjectStorage;
use Throwable;

/**
 * The standard strand implementation.
 */
trait StrandTrait
{
    /**
     * @param Kernel $kernel     The kernel on which the strand is executing.
     * @param Api    $api        The kernel API used to handle yielded values.
     * @param int    $id         The strand ID.
     * @param mixed  $entryPoint The strand's entry-point coroutine.
     */
    public function __construct(
        Kernel $kernel,
        Api $api,
        int $id,
        $entryPoint
    ) {
        $this->kernel = $kernel;
        $this->primaryListener = $kernel;
        $this->api = $api;
        $this->id = $id;

        if ($entryPoint instanceof Generator) {
            $this->current = $entryPoint;
        } elseif ($entryPoint instanceof CoroutineProvider) {
            $this->current = $entryPoint->coroutine();
        } elseif (
            $entryPoint instanceof Closure || // perf
            \is_callable($entryPoint)
        ) {
            $this->current = $entryPoint();

            if (!$this->current instanceof Generator) {
                throw new InvalidArgumentException(
                    'Callable must return a generator.'
                );
            }
        } else {
            $this->current = (static function () use ($entryPoint) {
                return yield $entryPoint;
            })();
        }
    }

    /**
     * Get the strand's ID.
     *
     * No two active on the same kernel may share an ID.
     *
     * @return int The strand ID.
     */
    public function id() : int
    {
        return $this->id;
    }

    /**
     * @return Kernel The kernel on which the strand is executing.
     */
    public function kernel() : Kernel
    {
        return $this->kernel;
    }

    /**
     * Start the strand.
     */
    public function start()
    {
        ////////////////////////////////////////////////////////////////////////////
        // This method intentionally sacrifices readability in order to keep      //
        // the number of function calls to a minimum for the sake of performance. //
        ////////////////////////////////////////////////////////////////////////////

        // The strand has exited already. This can occur if it is terminated
        // immediately after being scheduled for execution ...
        if ($this->state === StrandState::EXITED) {
            return;
        }

        assert(
            $this->state === StrandState::READY || $this->state === StrandState::SUSPENDED_INACTIVE,
            'strand state must be READY or SUSPENDED_INACTIVE to start'
        );

        assert(
            $this->state !== StrandState::SUSPENDED_INACTIVE || $this->action !== null,
            'value must be set to start when SUSPENDED_INACTIVE'
        );

        $this->state = StrandState::RUNNING;

        // Execute the next "tick" of the current coroutine ...
        try {
            // If action is set, we are resuming the generator. The action and
            // the associated value variable must be set before jumping to the
            // "resume_generator" label, or by calling send() or throw() ...
            if ($this->action) {
                resume_generator:
                assert($this->current instanceof Generator, 'call stack must not be empty');
                assert($this->state === StrandState::RUNNING, 'strand state must be RUNNING');
                assert($this->action === 'send' || $this->action === 'throw', 'action must be "send" or "throw"');
                assert($this->action !== 'throw' || $this->value instanceof Throwable, 'value must be throwable');

                $this->current->{$this->action}($this->value);
                $this->action = $this->value = null;
            }

            // The "start_generator" is jumped to when a new generator has been
            // pushed onto the call stack ...
            start_generator:
            assert($this->current instanceof Generator, 'call stack must not be empty');
            assert($this->state === StrandState::RUNNING, 'strand state must be RUNNING');

            // If the generator is "valid" it has futher iterations to perform,
            // therefore it has yielded, rather than returned ...
            if ($this->current->valid()) {
                $produced = $this->current->current();
                $this->state = StrandState::SUSPENDED_ACTIVE;

                // Trace the yielded value. This function is responsible for
                // maintaining information about the strand's call stack when
                // using instrumented code. It is performed inside an assertion
                // so that the call is optimized away completely in production.
                //
                // The trace operation may resume the strand, in which case we
                // jump back to "resume_generator" immediately. There's no way
                // to include the 'goto' inside the assertion, so this incurs
                // the minimal overhead of an integer comparison on each yield ...
                assert(!$produced instanceof Trace || $this->trace($produced) || true);
                if ($this->state === StrandState::READY) {
                    $this->state = StrandState::RUNNING;
                    goto resume_generator;
                }

                try {
                    // Another generated was yielded, push it onto the call
                    // stack and execute it ...
                    if ($produced instanceof Generator) {
                        // "fast" functionless stack-push ...
                        $this->stack[$this->depth++] = $this->current;
                        $this->current = $produced;
                        $this->state = StrandState::RUNNING;
                        goto start_generator;

                    // A coroutine provided was yielded. Extract the coroutine
                    // then push it onto the call stack and execute it ...
                    } elseif ($produced instanceof CoroutineProvider) {
                        // The coroutine is extracted from the provider before the
                        // stack push is begun in case coroutine() throws ...
                        $produced = $produced->coroutine();

                        // "fast" functionless stack-push ...
                        $this->stack[$this->depth++] = $this->current;
                        $this->current = $produced;
                        $this->state = StrandState::RUNNING;
                        goto start_generator;

                    // An API call was made through the Recoil static facade ...
                    } elseif ($produced instanceof ApiCall) {
                        $produced = $this->api->{$produced->name}(
                            $this,
                            ...$produced->arguments
                        );

                        // The API call is implemented as a generator coroutine,
                        // push it onto the call stack and execute it ...
                        if ($produced instanceof Generator) {
                            // "fast" functionless stack-push ...
                            $this->stack[$this->depth++] = $this->current;
                            $this->current = $produced;
                            $this->state = StrandState::RUNNING;
                            goto start_generator;
                        }

                    // A generic awaitable object was yielded ...
                    } elseif ($produced instanceof Awaitable) {
                        $produced->await($this, $this->api);

                    // An awaitable provider was yeilded ...
                    } elseif ($produced instanceof AwaitableProvider) {
                        $produced->awaitable()->await($this, $this->api);

                    // Some unidentified value was yielded, allow the API to
                    // dispatch the operation as it sees fit ...
                    } else {
                        $this->api->dispatch(
                            $this,
                            $this->current->key(),
                            $produced
                        );
                    }

                // An exception occurred as a result of the yielded value. This
                // exception is not propagated up the call stack, but rather
                // sent back to the current coroutine (i.e., the one that yielded
                // the value) ...
                } catch (Throwable $e) {
                    // Update the exception's stack trace based on trace data
                    // on the strand's call stack. This is done inside an
                    // assertion so that the call is optimized away completely
                    // in production ...
                    assert($this->updateTrace($e) || true);

                    $this->action = 'throw';
                    $this->value = $e;
                    $this->state = StrandState::RUNNING;
                    goto resume_generator;
                }

                // The strand has alraedy been set back to the READY state. This
                // means that send() or throw() was called while handling the
                // yielded value. Resume the current coroutine immediately ...
                if ($this->state === StrandState::READY) {
                    $this->state = StrandState::RUNNING;
                    goto resume_generator;

                // Otherwise, if the strand was not terminated while handling
                // the yielded value, it is now fully suspended. No further
                // action will be performed until send() or throw() is called ...
                } elseif ($this->state !== StrandState::EXITED) {
                    $this->state = StrandState::SUSPENDED_INACTIVE;
                }

                return;
            }

            // The generator is not "valid", and has therefore returned a value
            // (which may be null) ...
            $this->action = 'send';
            $this->value = $this->current->getReturn();

        // An exception was thrown during the execution of the generator ...
        } catch (Throwable $e) {
            $this->action = 'throw';
            $this->value = $e;
        }

        // The current coroutine has ended, either by returning or throwing. If
        // there is a coroutine above it on the call stack, we pop the current
        // coroutine from the stack and resume the parent ...
        if ($this->depth) {
            // "fast" functionless stack-pop ...
            $current = &$this->stack[--$this->depth];
            $this->current = $current;
            $current = null;

            // Update the exception's stack trace based on trace data on the
            // strand's  call stack. This is done inside an assertion so that
            // the call is optimized away completely in production ...
            //
            assert($this->action !== 'throw' || $this->updateTrace($this->value) || true);

            $this->state = StrandState::RUNNING;
            goto resume_generator;
        }

        // Otherwise the call stack is empty, the strand has exited ...
        return $this->exit();
    }

    /**
     * Terminate execution of the strand.
     *
     * If the strand is suspended waiting on an asynchronous operation, that
     * operation is cancelled.
     *
     * The call stack is not unwound, it is simply discarded.
     */
    public function terminate()
    {
        if ($this->state === StrandState::EXITED) {
            return;
        }

        $this->stack = [];
        $this->action = 'throw';
        $this->value = new TerminatedException($this);

        if ($this->terminator) {
            ($this->terminator)($this);
        }

        $this->exit();
    }

    /**
     * Resume execution of a suspended strand.
     *
     * @param mixed       $value  The value to send to the coroutine on the the top of the call stack.
     * @param Strand|null $strand The strand that resumed this one, if any.
     */
    public function send($value = null, Strand $strand = null)
    {
        // Ignore resumes after exit, not all asynchronous operations will have
        // meaningful cancel operations and some may attempt to resume the
        // strand after it has been terminated.
        if ($this->state === StrandState::EXITED) {
            return;
        }

        assert(
            $this->state === StrandState::SUSPENDED_ACTIVE ||
            $this->state === StrandState::SUSPENDED_INACTIVE,
            'strand must be suspended to resume'
        );

        $this->terminator = null;
        $this->action = 'send';
        $this->value = $value;

        if ($this->state === StrandState::SUSPENDED_INACTIVE) {
            $this->start();
        } else {
            $this->state = StrandState::READY;
        }
    }

    /**
     * Resume execution of a suspended strand with an error.
     *
     * @param Throwable   $exception The exception to send to the coroutine on the top of the call stack.
     * @param Strand|null $strand    The strand that resumed this one, if any.
     */
    public function throw(Throwable $exception, Strand $strand = null)
    {
        // Ignore resumes after exit, not all asynchronous operations will have
        // meaningful cancel operations and some may attempt to resume the
        // strand after it has been terminated.
        if ($this->state === StrandState::EXITED) {
            return;
        }

        assert(
            $this->state === StrandState::SUSPENDED_ACTIVE ||
            $this->state === StrandState::SUSPENDED_INACTIVE,
            'strand must be suspended to resume'
        );

        $this->terminator = null;
        $this->action = 'throw';
        $this->value = $exception;

        if ($this->state === StrandState::SUSPENDED_INACTIVE) {
            $this->start();
        } else {
            $this->state = StrandState::READY;
        }
    }

    /**
     * Check if the strand has exited.
     */
    public function hasExited() : bool
    {
        return $this->state === StrandState::EXITED;
    }

    /**
     * Set the primary listener.
     *
     * If the current primary listener is not the kernel, it is notified with
     * a {@see PrimaryListenerRemovedException}.
     *
     * @return null
     */
    public function setPrimaryListener(Listener $listener)
    {
        if ($this->state === StrandState::EXITED) {
            $listener->{$this->action}($this->value, $this);
        } else {
            $previous = $this->primaryListener;
            $this->primaryListener = $listener;

            if ($previous !== $this->kernel) {
                $previous->throw(
                    new PrimaryListenerRemovedException($previous, $this),
                    $this
                );
            }
        }
    }

    /**
     * Set the primary listener to the kernel.
     *
     * The current primary listener is not notified.
     */
    public function clearPrimaryListener()
    {
        $this->primaryListener = $this->kernel;
    }

    /**
     * Set the strand 'terminator'.
     *
     * The terminator is a function invoked when the strand is terminated. It is
     * used by the kernel API to clean up any pending asynchronous operations.
     *
     * The terminator function is removed without being invoked when the strand
     * is resumed.
     */
    public function setTerminator(callable $fn = null)
    {
        assert(
            !$fn || !$this->terminator,
            'only a single terminator can be set'
        );

        assert(
            $this->state === StrandState::READY ||
            $this->state === StrandState::SUSPENDED_ACTIVE ||
            $this->state === StrandState::SUSPENDED_INACTIVE,
            'strand must be suspended to set a terminator'
        );

        $this->terminator = $fn;
    }

    /**
     * The Strand interface extends AwaitableProvider, but this particular
     * implementation can provide await functionality directly.
     *
     * Implementations must favour await() over awaitable() when both are
     * available to avoid a pointless performance hit.
     */
    public function awaitable() : Awaitable
    {
        return $this;
    }

    /**
     * Attach a listener to this object.
     *
     * @param Listener $listener The object to resume when the work is complete.
     * @param Api      $api      The API implementation for the current kernel.
     *
     * @return null
     */
    public function await(Listener $listener, Api $api)
    {
        if ($this->state === StrandState::EXITED) {
            $listener->{$this->action}($this->value, $this);
        } else {
            $this->listeners[] = $listener;
        }
    }

    /**
     * Create a uni-directional link to another strand.
     *
     * If this strand exits, any linked strands are terminated.
     *
     * @return null
     */
    public function link(Strand $strand)
    {
        if ($this->linkedStrands === null) {
            $this->linkedStrands = new SplObjectStorage();
        }

        $this->linkedStrands->attach($strand);
    }

    /**
     * Break a previously created uni-directional link to another strand.
     *
     * @return null
     */
    public function unlink(Strand $strand)
    {
        if ($this->linkedStrands !== null) {
            $this->linkedStrands->detach($strand);
        }
    }

    /**
     * Finalize the strand by notifying any listeners of the exit and
     * terminating any linked strands.
     */
    private function exit()
    {
        $this->state = StrandState::EXITED;
        $this->current = null;

        try {
            $this->primaryListener->{$this->action}($this->value, $this);

            foreach ($this->listeners as $listener) {
                $listener->{$this->action}($this->value, $this);
            }

        // Notify the kernel if any of the listeners fail ...
        } catch (Throwable $e) {
            $this->kernel->throw(
                new StrandListenerException($this, $e),
                $this
            );
        } finally {
            $this->primaryListener = null;
            $this->listeners = [];
        }

        if ($this->linkedStrands !== null) {
            try {
                foreach ($this->linkedStrands as $strand) {
                    $strand->unlink($this);
                    $strand->terminate();
                }
            } finally {
                $this->linkedStrands = null;
            }
        }
    }

    /**
     * Record information produced by instrumented code.
     */
    private function trace(&$produced)
    {
        if ($produced instanceof CoroutineTrace) {
            $this->current->coroutineTrace = $produced;
            $this->action = 'send';
            $this->value = null;
            $this->state = StrandState::READY;
        } elseif ($produced instanceof YieldTrace) {
            $this->current->yieldTrace = $produced;
            $produced = $produced->value();
        }
    }

    /**
     * Modify an exceptions stack trace so that it reflects the strand's call
     * stack, rather than the native PHP call stack.
     */
    private function updateTrace(Throwable $exception)
    {
        // Ignore any exceptions that have already been seen ...
        if (isset($exception->__recoilOriginalTrace__)) {
            return;
        }

        $previous = $exception->getPrevious();

        if ($previous) {
            $this->updateTrace($previous);
        }

        $reflector = new ReflectionClass($exception);

        // We can't update the stack trace if the property doesn't exist. Nor
        // can we mark the exception as seen if there is a magic __set method ...
        if (
            !$reflector->hasProperty('trace') ||
            $reflector->hasMethod('__set')
        ) {
            return;
        }

        $strandTrace = [];
        $strandTraceSize = 0;
        $originalTrace = $exception->getTrace();

        // Keep the original trace up until we find the internal strand code ...
        foreach ($originalTrace as $frame) {
            $file = $frame['file'] ?? '';

            if ($file === __FILE__) {
                break;
            }

            ++$strandTraceSize;
            $strandTrace[] = $frame;
        }

        // Traverse backwards through the strand's call stack to synthesize
        // stack frames ...
        $stackIndex = $this->depth;
        $generator = $this->current;

        do {
            $coroutineTrace = $generator->coroutineTrace ?? null;
            $yieldTrace = $generator->yieldTrace ?? null;

            // If this coroutine has a "yield trace", that tells us the position
            // that the function in the previous stack frame was called ...
            if ($yieldTrace) {
                $strandTrace[$strandTraceSize - 1]['file'] = $yieldTrace->file;
                $strandTrace[$strandTraceSize - 1]['line'] = $yieldTrace->line;
            } else {
                $strandTrace[$strandTraceSize - 1]['file'] = 'Unknown';
                $strandTrace[$strandTraceSize - 1]['line'] = 0;
            }

            // If this coroutine has a "coroutine trace", that gives us
            // information about the coroutine itself ...
            if ($coroutineTrace) {
                // @todo object, args, class, type, etc
                $strandTrace[] = [
                    'function' => $coroutineTrace->function,
                    'file' => 'Unknown',
                    'line' => 0,
                ];

            // The coroutine was not instrumented, but we still need to inject
            // a stack frame to represent it ...
            } else {
                $strandTrace[] = [
                    'function' => '{uninstrumented coroutine}',
                    'file' => 'Unknown',
                    'line' => 0,
                ];
            }

            ++$strandTraceSize;
            $generator = $this->stack[--$stackIndex] ?? null;
        } while ($generator);

        // Preserve the original PHP stack trace on the exception, as it may
        // still be useful. The presence of this property also indicate that
        // this exception has already been processed ...
        $exception->__recoilOriginalTrace__ = $originalTrace;

        // Replace the exception's trace proprety with the strand stack trace ...
        $property = $reflector->getProperty('trace');
        $property->setAccessible(true);
        $property->setValue($exception, $strandTrace);
    }

    /**
     * @var Kernel The kernel.
     */
    private $kernel;

    /**
     * @var Api The kernel API.
     */
    private $api;

    /**
     * @var int The strand Id.
     */
    private $id;

    /**
     * @var array<Generator> The call stack (except for the top element).
     */
    private $stack = [];

    /**
     * @var int The call stack depth (not including the top element).
     */
    private $depth = 0;

    /**
     * @var Generator|null The current top of the call stack.
     */
    private $current;

    /**
     * @var Listener|null The strand's primary listener.
     */
    private $primaryListener;

    /**
     * @var array<Listener> Objects to notify when this strand exits.
     */
    private $listeners = [];

    /**
     * @var callable|null A callable invoked when the strand is terminated.
     */
    private $terminator;

    /**
     * @var SplObjectStorage<Strand>|null Strands to terminate when this strand
     *                                    is terminated.
     */
    private $linkedStrands;

    /**
     * @var int The current state of the strand.
     */
    private $state = StrandState::READY;

    /**
     * @var string|null The next action to perform on the current coroutine ('send' or 'throw').
     */
    private $action;

    /**
     * @var mixed The value or exception to send or throw on the next tick or
     *            the result of the strand's entry point coroutine if the strand
     *            has exited.
     */
    private $value;
}
