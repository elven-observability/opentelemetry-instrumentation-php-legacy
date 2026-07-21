<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Elven\Observability\PhpLegacy\Instrumentation\DbInstrumentation;
use Elven\Observability\PhpLegacy\Observability;

Observability::init(array('service_name' => 'legacy-public-api'));

/**
 * @return mysqli_result|bool
 */
function tracedMysqliQuery($connection, $sql)
{
    $operation = strtoupper((string) strtok(ltrim((string) $sql), " \t\r\n"));
    return DbInstrumentation::traceQuery(
        'mysql',
        $operation,
        'application',
        function ($span) use ($connection, $sql) {
            $result = $connection->query($sql);
            if ($result === false) {
                $span->setStatus('ERROR', 'database_error');
                $span->setAttribute('error.type', 'database_error');
            }
            return $result;
        },
        $sql
    );
}

// Use the application's existing mysqli connection:
// $result = tracedMysqliQuery($connection, 'SELECT status FROM jobs WHERE id = 42');
