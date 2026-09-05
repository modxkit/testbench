<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Tests\Integration\Isolation;

use ModxKit\Testbench\TestCase;
use PDO;
use PHPUnit\Framework\Attributes\Depends;

/**
 * The fifth vessel of state: MySQL session variables. The core has one connection for the whole
 * run, so a `SET SESSION sql_mode = ''` or `SET autocommit = 0` made by a test (or by the code under
 * test) lives to the end of the run and changes the behaviour of ALL subsequent tests: an empty
 * `sql_mode` turns truncation errors into warnings, and `autocommit = 0` breaks the notion of where
 * a transaction ends.
 */
abstract class SessionStateLeakScenario extends TestCase
{
    public function testTestDirtiesTheConnectionSession(): string
    {
        $before = $this->sessionValue('@@SESSION.sql_mode');
        self::assertNotSame('', $before, 'sql_mode is empty even before the test — there is nothing to check.');

        $this->modx->exec("SET SESSION sql_mode = ''");
        $this->modx->exec('SET SESSION autocommit = 0');

        self::assertSame('', $this->sessionValue('@@SESSION.sql_mode'));
        self::assertSame('0', $this->sessionValue('@@SESSION.autocommit'));

        return $before;
    }

    #[Depends('testTestDirtiesTheConnectionSession')]
    public function testNextTestGetsACleanSession(string $before): void
    {
        self::assertSame(
            $before,
            $this->sessionValue('@@SESSION.sql_mode'),
            'The sql_mode of the previous test survived the test boundary.'
        );
        self::assertSame('1', $this->sessionValue('@@SESSION.autocommit'));
    }

    private function sessionValue(string $variable): string
    {
        $statement = $this->modx->query('SELECT ' . $variable);

        self::assertInstanceOf(PDO::class, $this->modx->pdo);
        self::assertNotFalse($statement);

        return (string) $statement->fetchColumn();
    }
}
