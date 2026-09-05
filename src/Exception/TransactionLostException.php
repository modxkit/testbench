<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Exception;

final class TransactionLostException extends TestbenchException
{
    public static function create(): self
    {
        return new self(
            'The test transaction was implicitly ended — isolation is lost. The usual cause: DDL '
            . '(CREATE/ALTER/DROP TABLE) or MyISAM tables, for which MySQL performs an implicit commit. '
            . 'Add the ModxKit\\Testbench\\Concerns\\RefreshesDatabase trait to the test. '
            . 'There is nothing left to roll back: the changes the test made before the implicit commit '
            . 'are committed to the database, and the remaining tests of the run go over a polluted '
            . 'environment — recreate it (MODX_TESTBENCH_FORCE_INSTALL=1 or `bin/modx-testbench destroy`) '
            . 'before trusting their results.'
        );
    }

    /**
     * `PDO::inTransaction()` asks the server for the flag, and a `START TRANSACTION`/`BEGIN` through
     * `exec()`, or a `commit()` followed by a new `beginTransaction()`, leave it raised — the former
     * detector let those cases through silently. They are caught by the guard marker: inserted
     * inside the test's transaction, it must disappear together with it.
     */
    public static function afterHiddenCommit(): self
    {
        return new self(
            'The test transaction was committed and opened again — isolation is lost, even though '
            . "the server did keep reporting an open transaction.\n"
            . 'The usual cause: START TRANSACTION or BEGIN executed as raw SQL, or commit() followed by '
            . 'beginTransaction() inside the test or the code under test. '
            . 'Everything the test did up to this point is committed to the database, and the remaining '
            . 'tests of the run go over a polluted environment — recreate it '
            . '(MODX_TESTBENCH_FORCE_INSTALL=1 or `bin/modx-testbench destroy`) before trusting their '
            . 'results. If the code under test genuinely needs the commit, add the '
            . 'ModxKit\\Testbench\\Concerns\\RefreshesDatabase trait to the test: a snapshot undoes '
            . 'committed changes too.'
        );
    }

    /**
     * MyISAM tables ignore the transaction — `rollBack()` runs to no effect while
     * `inTransaction()` honestly returns `true`.
     *
     * @param list<string> $tables
     */
    public static function nonTransactionalTables(array $tables): self
    {
        return new self(sprintf(
            'Transaction isolation is impossible: tables %s were created by an engine that does not '
            . "support rollback (MyISAM).\nThat happens when the installation ran into a NON-existent "
            . 'database: the MODX CLI setup then silently turns on OPT_OVERRIDE_TABLE_TYPE = MyISAM and '
            . 'carries on with a warning. Recreate the environment on a database created in advance '
            . '(MODX_TESTBENCH_FORCE_INSTALL=1); if the MyISAM tables belong to the extra, add the '
            . 'ModxKit\\Testbench\\Concerns\\RefreshesDatabase trait to the test.',
            implode(', ', $tables)
        ));
    }
}
