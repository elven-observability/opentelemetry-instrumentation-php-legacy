<?php

namespace Elven\Observability\PhpLegacy\Propagation;

use Elven\Observability\PhpLegacy\Privacy\UrlSanitizer;

final class BaggagePropagator
{
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
            if ($this->validKey($key) && !$this->sensitiveKey($key) && strlen($value) <= 512) {
                $baggage[$key] = UrlSanitizer::redactSensitiveText(urldecode($value));
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
            if ($this->validKey($key) && !$this->sensitiveKey($key)) {
                $items[] = $key . '=' . rawurlencode(UrlSanitizer::redactSensitiveText((string) $value));
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
            || preg_match('/user|customer|account|tenant_id/i', (string) $key) === 1;
    }
}
