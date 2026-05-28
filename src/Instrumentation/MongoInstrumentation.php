<?php

namespace Elven\Observability\PhpLegacy\Instrumentation;

final class MongoInstrumentation
{
    public static function trace($operation, $database, $collection, callable $callback)
    {
        return DbInstrumentation::trace('Mongo ' . strtoupper((string) $operation), 'mongo', 'mongodb', array(
            'db.system' => 'mongodb',
            'db.name' => (string) $database,
            'db.collection.name' => (string) $collection,
            'db.operation.name' => strtoupper((string) $operation),
            'dependency_type' => 'mongo',
            'dependency_name' => 'mongodb',
        ), $callback);
    }
}
