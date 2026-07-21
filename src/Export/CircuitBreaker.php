<?php

namespace Elven\Observability\PhpLegacy\Export;

final class CircuitBreaker
{
    private static $stateDirectory;
    private $failureThreshold;
    private $resetTimeoutMillis;
    private $failures;
    private $openedAtMillis;
    private $stateFile;

    public function __construct($failureThreshold = 3, $resetTimeoutMillis = 30000, $sharedKey = null)
    {
        $this->failureThreshold = max(1, (int) $failureThreshold);
        $this->resetTimeoutMillis = max(1, (int) $resetTimeoutMillis);
        $this->failures = 0;
        $this->openedAtMillis = 0;
        $this->stateFile = $sharedKey === null ? null : $this->stateFile((string) $sharedKey);
    }

    public function allowRequest()
    {
        if ($this->stateFile !== null) {
            return $this->withSharedState(function (array &$state) {
                if ($state['opened_at_ms'] === 0) {
                    return true;
                }
                if ($this->nowMillis() - $state['opened_at_ms'] < $this->resetTimeoutMillis) {
                    return false;
                }

                // Reserve the half-open probe so concurrent FPM workers do not stampede.
                $state['opened_at_ms'] = $this->nowMillis();
                return true;
            }, true);
        }

        if ($this->openedAtMillis === 0) {
            return true;
        }
        if ($this->nowMillis() - $this->openedAtMillis >= $this->resetTimeoutMillis) {
            return true;
        }
        return false;
    }

    public function recordSuccess()
    {
        if ($this->stateFile !== null) {
            $this->withSharedState(function (array &$state) {
                $state['failures'] = 0;
                $state['opened_at_ms'] = 0;
                return true;
            }, false);
        }
        $this->failures = 0;
        $this->openedAtMillis = 0;
    }

    public function recordFailure()
    {
        if ($this->stateFile !== null) {
            $this->withSharedState(function (array &$state) {
                $state['failures']++;
                if ($state['failures'] >= $this->failureThreshold) {
                    $state['opened_at_ms'] = $this->nowMillis();
                }
                return true;
            }, false);
        }
        $this->failures++;
        if ($this->failures >= $this->failureThreshold) {
            $this->openedAtMillis = $this->nowMillis();
        }
    }

    private function withSharedState(callable $callback, $fallback)
    {
        $handle = @fopen($this->stateFile, 'c+');
        if ($handle === false) {
            return $fallback;
        }

        try {
            @chmod($this->stateFile, 0600);
            if (!@flock($handle, LOCK_EX)) {
                return $fallback;
            }

            rewind($handle);
            $raw = stream_get_contents($handle);
            $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
            $state = array(
                'failures' => is_array($decoded) && isset($decoded['failures'])
                    ? max(0, (int) $decoded['failures'])
                    : 0,
                'opened_at_ms' => is_array($decoded) && isset($decoded['opened_at_ms'])
                    ? max(0, (int) $decoded['opened_at_ms'])
                    : 0,
            );

            $result = $callback($state);
            $json = json_encode($state);
            if ($json !== false) {
                rewind($handle);
                if (ftruncate($handle, 0)) {
                    fwrite($handle, $json);
                    fflush($handle);
                }
            }
            @flock($handle, LOCK_UN);
            return $result;
        } catch (\Throwable $e) {
            return $fallback;
        } finally {
            fclose($handle);
        }
    }

    private function stateFile($sharedKey)
    {
        $base = self::stateDirectory();
        if ($base === null) {
            return null;
        }
        if (!is_dir($base)) {
            @mkdir($base, 0700, true);
        }
        @chmod($base, 0700);
        if (!is_dir($base) || !is_writable($base)) {
            return null;
        }
        return $base . DIRECTORY_SEPARATOR . 'circuit-' . hash('sha256', $sharedKey) . '.json';
    }

    private static function stateDirectory()
    {
        if (self::$stateDirectory !== null) {
            return self::$stateDirectory === '' ? null : self::$stateDirectory;
        }

        $uid = null;
        if (function_exists('posix_geteuid')) {
            $uid = @posix_geteuid();
        }
        if (!is_int($uid)) {
            $probe = @tempnam(sys_get_temp_dir(), 'elven-otel-user-');
            if (is_string($probe)) {
                $owner = @fileowner($probe);
                @unlink($probe);
                if (is_int($owner)) {
                    $uid = $owner;
                }
            }
        }
        if (!is_int($uid)) {
            $uid = (int) getmyuid();
        }

        self::$stateDirectory = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . 'elven-observability-' . (string) $uid;
        return self::$stateDirectory;
    }

    private function nowMillis()
    {
        return (int) floor(microtime(true) * 1000);
    }
}
