<?php

declare(strict_types=1);

/**
 * A participant in the race of
 * {@see \ModxKit\Testbench\Tests\Integration\Support\RunScopedDatabaseNameTest}: launched TWICE as
 * a real OS process (labels A and B), each time performing exactly the sequence the `setUp()` of
 * six classes performs (`DROP DATABASE IF EXISTS <name>; CREATE DATABASE <name>`), and checking
 * that its own `probe` table survived the launch of the second participant.
 *
 * Arguments: <base> <label> <ownReadyFlag> <otherReadyFlag> <resultFile>.
 */
require dirname(__DIR__, 3) . '/vendor/autoload.php';

use ModxKit\Testbench\Environment\DatabaseConfig;
use ModxKit\Testbench\Tests\Support\RunScopedDatabaseName;

[, $base, $label, $ownReadyFlag, $otherReadyFlag, $resultFile] = $argv;

$environment = DatabaseConfig::fromEnvironment();
// NO label suffix here: both participants must compute the name by ONE and the same formula
// with not a single addition from the test — otherwise the test would be proving that the
// participants' OWN labels differ, rather than that the scheme itself (the pid) produces the
// difference. The whole point of the check hangs on this name: it diverges only because the
// participants are DIFFERENT OS processes (each with its own getmypid()), and not because the
// script itself separated them with a postfix.
$name = RunScopedDatabaseName::forBase($base);
$exitCode = 0;

try {
    $server = new PDO(
        $environment->dsnWithoutDatabase(),
        $environment->user,
        $environment->password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    // Exactly the sequence the setUp() of the six classes performs.
    $server->exec('DROP DATABASE IF EXISTS `' . $name . '`');
    $server->exec('CREATE DATABASE `' . $name . '`');

    $database = new DatabaseConfig(
        host: $environment->host,
        port: $environment->port,
        name: $name,
        user: $environment->user,
        password: $environment->password,
        prefix: 'modx_',
        charset: $environment->charset,
        collation: $environment->collation,
    );

    $connection = new PDO(
        $database->dsn(),
        $database->user,
        $database->password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $connection->exec('CREATE TABLE `probe` (`marker` VARCHAR(16))');
    $connection->exec("INSERT INTO `probe` VALUES ('" . $label . "')");

    touch($ownReadyFlag);

    $deadline = microtime(true) + 15.0;
    while (!is_file($otherReadyFlag)) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('did not receive the other flag within 15 seconds');
        }
        usleep(20_000);
    }

    // By this moment the DROP/CREATE of both participants have already happened — if the names
    // coincided, exactly one of the two markers is now not the one its own participant wrote.
    $statement = $connection->query('SELECT `marker` FROM `probe`');
    $marker = $statement !== false ? $statement->fetchColumn() : false;

    file_put_contents(
        $resultFile,
        $marker === $label ? 'own-marker-intact' : ('marker-was: ' . var_export($marker, true))
    );
} catch (Throwable $exception) {
    file_put_contents($resultFile, 'error: ' . $exception->getMessage());
    fwrite(STDERR, "participant {$label}: " . $exception->getMessage() . "\n");
    $exitCode = 1;
} finally {
    // The participant's own database is removed ALWAYS, not only on the successful path —
    // otherwise a failure exactly where it is likeliest (a race, a flag timeout) would leave the
    // database on the server silently.
    try {
        (new PDO(
            $environment->dsnWithoutDatabase(),
            $environment->user,
            $environment->password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        ))->exec('DROP DATABASE IF EXISTS `' . $name . '`');
    } catch (Throwable) {
        // Cleanup "where possible": an unreachable DBMS must not mask the real reason for the
        // failure with the exit code of the cleanup.
    }
}

exit($exitCode);
