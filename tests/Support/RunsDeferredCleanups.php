<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Tests\Support;

use PHPUnit\Framework\AssertionFailedError;
use RuntimeException;
use Throwable;

/**
 * A `tearDown()` that no single failing step can cut short.
 *
 * A test that damages shared state — moves `core/packages/` aside, points the `TestbenchKernel`
 * singleton at a throwaway directory, creates a database — registers the restoration through
 * {@see self::deferCleanup()}; see that queue's own docblock for when and in what order it runs.
 *
 * The point of the trait is what happens when one of those cleanups throws. Written as a bare
 * `while (…) { array_pop(…)(); }` — the shape three test classes carried; two of them held it
 * byte for byte, the third under another field name and with no `try`/`finally` around it at all —
 * the first throw left the whole rest of the queue unexecuted, and the rest of the queue is where
 * the restorations of the SHARED state sit. One and the same injection, a cleanup registered last
 * and therefore popped first that throws, measured against each of the three before and after:
 *
 *   - `CommandsTest`: the bare loop skipped the restoration of `$_SERVER`, the next test in
 *     declaration order saw `MODX_TESTBENCH_DB_NAME` of the failed one, and the throwaway directory
 *     survived with its staging sibling beside it. What is in that sibling is measured, not
 *     assumed: an equivalent `<workspace>.new`, reproduced on purpose and then opened, holds
 *     7592 files in 1004 directories, 78 456 KiB, that is, a completely unpacked MODX tree. Its
 *     root in full: `config.core.php`, `connectors/`, `core/`, `ht.access`, `index.php`,
 *     `manager/`, `setup/` — and the `.testbench-workspace` ownership marker that
 *     `Workspace::prepareStaging()` writes, which is the one file turning the 7591 of a bare
 *     unpacking of the release into 7592. Its `core/` in full: `cache config docs error export
 *     ht.access import include lexicon model packages src vendor`;
 *   - `TransportInstallerTest`: a file the earlier-registered cleanup was to remove stayed in the
 *     shared `core/packages/`;
 *   - `TestbenchKernelTest`, whose `tearDown()` had no `try`/`finally` at all: `TestbenchKernel`
 *     was not reset — the next test got the singleton of the failed one back, measured by object
 *     identity — and `$_SERVER` leaked as well.
 *
 * With the trait the same injection leaves all three neighbours green.
 *
 * Here every step — a queued cleanup ({@see self::runDeferredCleanups()}) or a step the consumer
 * runs itself ({@see self::runCleanupStep()}) — is executed inside its own `try`, and what they
 * threw is raised once at the end by {@see self::reportCleanupFailures()}. A consumer whose
 * `tearDown()` has steps of its own around the queue therefore keeps them all guaranteed, which
 * a `try`/`finally` around the loop alone does not give.
 *
 * That includes `parent::tearDown()` wherever a consumer calls it — two of the three do, and both
 * pass it through {@see self::runCleanupStep()}.
 * Wrapped the other way — `try { runDeferredCleanups(); } finally { parent::tearDown(); }` — the
 * `finally` guards nothing, because nothing escapes the queue, and a throwing parent then leaves
 * the method before {@see self::reportCleanupFailures()} runs. Measured on `TransportInstallerTest`,
 * whose parent really can throw (it closes the isolation transaction and restores the core's
 * runtime state, `src/TestCase.php`), with a failing cleanup and a throwing parent together: that
 * form reported the parent's exception alone and lost the cleanup's; as a step, both are reported
 * as one aggregate.
 *
 * What the aggregation costs, measured by injected cleanups rather than described:
 *
 *   - ONE failing step is rethrown untouched. Measured with a single failing `assertSame()`: a
 *     FAILURE, with its own message and its `Expected`/`Actual` diff intact. In the one negative
 *     control that produces failures in bulk — the one that guts `removeWorkspaceOf()` — all five
 *     failing tests were of this shape, each reporting a single assertion rather than an aggregate.
 *     NOT MEASURED: whether two steps of one test can fail at once other than by injection.
 *   - TWO OR MORE are raised as a single exception quoting each of them in the order they ran, with
 *     the first attached as `previous` — PHPUnit prints it under "Caused by" with its own stack, so
 *     the first failure keeps its own line. Measured on two failing assertions: a FAILURE, because
 *     when every step threw an `AssertionFailedError` the aggregate is one too. Measured on three
 *     steps throwing `LogicException`, `self::fail()` and `RuntimeException`: an ERROR, because one
 *     of them is not an assertion failure — a real error must not be able to hide inside a group of
 *     them. In both runs the numbering and the order were `#1` popped first, and a cleanup
 *     registered before all of them still ran.
 *
 * What is lost in the two-or-more case is the class of every failure but the first, which survives
 * only as text in the message, and the `Expected`/`Actual` diff of all of them.
 *
 * The trait deliberately supplies no `tearDown()` of its own: a consumer declaring one would beat
 * it silently, which is exactly the mistake {@see \ModxKit\Testbench\Tests\Unit\TraitMethodOverrideTest}
 * exists to catch. The order of the steps belongs to the consumer; only their isolation belongs
 * here.
 */
trait RunsDeferredCleanups
{
    /**
     * The restorations registered by a test, drained in LIFO order: a restoration registered FIRST
     * is executed LAST, which is how a test-specific cleanup gets to run while the environment it
     * belongs to is still in place. A test registers one RIGHT NEXT to the damage it does rather
     * than after it — between a destructive action and the test entering its own `try`/`finally`
     * there must be no window in which a throw would leave the shared state broken for every
     * subsequent test.
     *
     * @var list<callable(): void>
     */
    private array $pendingCleanups = [];

    /**
     * @var list<Throwable>
     */
    private array $cleanupFailures = [];

    /**
     * Registers a restoration to be executed, guaranteed, whatever the outcome of the test:
     * see the docblock of `$pendingCleanups`.
     */
    private function deferCleanup(callable $cleanup): void
    {
        $this->pendingCleanups[] = $cleanup;
    }

    /**
     * Runs one step of `tearDown()` that is not on the queue, catching what it throws.
     */
    private function runCleanupStep(callable $step): void
    {
        try {
            $step();
        } catch (Throwable $failure) {
            $this->cleanupFailures[] = $failure;
        }
    }

    /**
     * Drains the queue, LIFO, each cleanup in its own `try`.
     */
    private function runDeferredCleanups(): void
    {
        while ($this->pendingCleanups !== []) {
            $cleanup = array_pop($this->pendingCleanups);

            $this->runCleanupStep($cleanup);
        }
    }

    /**
     * Raises whatever the steps threw. Called LAST in the consumer's `tearDown()`.
     */
    private function reportCleanupFailures(): void
    {
        $failures = $this->cleanupFailures;
        $this->cleanupFailures = [];
        $first = $failures[0] ?? null;

        if (!$first instanceof Throwable) {
            return;
        }

        if (count($failures) === 1) {
            throw $first;
        }

        $quoted = [];
        $assertionsOnly = true;

        foreach ($failures as $index => $failure) {
            $quoted[] = sprintf('#%d %s: %s', $index + 1, $failure::class, $failure->getMessage());
            $assertionsOnly = $assertionsOnly && $failure instanceof AssertionFailedError;
        }

        $message = count($failures) . " cleanup steps failed, in the order they ran:\n" . implode("\n", $quoted);

        throw $assertionsOnly
            ? new AssertionFailedError($message, 0, $first)
            : new RuntimeException($message, 0, $first);
    }
}
