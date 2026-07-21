<?php

namespace Elven\Observability\PhpLegacy\Support;

/**
 * Runs request finalizers before exporters flush at PHP shutdown.
 *
 * Legacy front controllers commonly terminate responses with exit/die. PHP does
 * not unwind the surrounding application callback in that case, so request
 * spans must be finalized from the library's already-registered shutdown hook.
 */
final class ShutdownRegistry
{
    private static $callbacks = array();
    private static $nextId = 1;
    private static $running = false;

    private function __construct()
    {
    }

    public static function register(callable $callback)
    {
        $id = self::$nextId++;
        self::$callbacks[$id] = $callback;
        return $id;
    }

    public static function unregister($id)
    {
        unset(self::$callbacks[(int) $id]);
    }

    public static function run()
    {
        if (self::$running || !self::$callbacks) {
            return;
        }

        self::$running = true;
        $callbacks = self::$callbacks;
        self::$callbacks = array();
        try {
            foreach ($callbacks as $callback) {
                try {
                    call_user_func($callback);
                } catch (\Throwable $ignored) {
                }
            }
        } finally {
            self::$running = false;
        }
    }

    public static function resetForTests()
    {
        self::$callbacks = array();
        self::$nextId = 1;
        self::$running = false;
    }
}
