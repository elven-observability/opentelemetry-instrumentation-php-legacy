<?php

namespace Elven\Observability\PhpLegacy\Propagation;

use Elven\Observability\PhpLegacy\Privacy\UrlSanitizer;

final class BaggagePropagator
{
    const MAX_HEADER_BYTES = 8192;

    private $maxMembers;

    public function __construct($maxMembers = 32)
    {
        $this->maxMembers = (int) $maxMembers;
    }

    public function extract(array $carrier)
    {
        $header = $this->header($carrier, 'baggage');
        if ($header === null || $header === '') {
            return array();
        }
        if (strlen($header) > self::MAX_HEADER_BYTES) {
            return array();
        }

        $baggage = array();
        foreach (explode(',', $header) as $member) {
            if (count($baggage) >= $this->maxMembers) {
                break;
            }
            $parts = explode('=', trim($member), 2);
            if (count($parts) !== 2) {
                continue;
            }
            $key = trim($parts[0]);
            $value = trim(explode(';', $parts[1], 2)[0]);
            $decoded = rawurldecode($value);
            if ($this->validKey($key) && $this->allowedValue($key, $decoded) && strlen($decoded) <= 512) {
                $baggage[$key] = UrlSanitizer::redactSensitiveText($decoded);
            }
        }
        return $baggage;
    }

    public function inject(array &$carrier, array $baggage)
    {
        $items = array();
        foreach ($baggage as $key => $value) {
            if (count($items) >= $this->maxMembers) {
                break;
            }
            if ($this->validKey($key) && $this->allowedValue($key, $value)) {
                $member = $key . '=' . rawurlencode(UrlSanitizer::redactSensitiveText((string) $value));
                $candidate = $items ? implode(',', $items) . ',' . $member : $member;
                if (strlen($candidate) > self::MAX_HEADER_BYTES) {
                    break;
                }
                $items[] = $member;
            }
        }
        if ($items) {
            $carrier['baggage'] = implode(',', $items);
        }
    }

    private function header(array $carrier, $name)
    {
        $normalized = strtolower($name);
        foreach ($carrier as $key => $value) {
            $candidate = strtolower(str_replace('_', '-', (string) $key));
            if ($candidate === $normalized || $candidate === 'http-' . $normalized) {
                return is_array($value) ? reset($value) : (string) $value;
            }
        }
        $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        return isset($carrier[$serverKey]) ? (string) $carrier[$serverKey] : null;
    }

    private function validKey($key)
    {
        return preg_match('/^[a-zA-Z0-9_\\-\\.\\*\\/]{1,128}$/', (string) $key) === 1;
    }

    private function sensitiveKey($key)
    {
        return UrlSanitizer::isSensitiveKey($key)
            || preg_match('/user|customer|account|tenant[_.-]?id|organization[_.-]?id/i', (string) $key) === 1;
    }

    private function allowedValue($key, $value)
    {
        if (!$this->sensitiveKey($key)) {
            return true;
        }
        return preg_match('/^[a-f0-9]{32}$/i', (string) $value) === 1;
    }
}
