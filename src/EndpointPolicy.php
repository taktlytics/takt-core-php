<?php

namespace Vskstudio\Takt;

final class EndpointPolicy
{
    public static function assertSecure(string $endpoint): void
    {
        $parts = parse_url(trim($endpoint));
        if ($parts === false || strtolower($parts['scheme'] ?? '') !== 'http') {
            return;
        }
        if (!self::isLoopback($parts['host'] ?? '')) {
            throw new \InvalidArgumentException(
                'Takt endpoint must use https unless it targets a loopback host (localhost, 127.0.0.0/8 or [::1]).'
            );
        }
    }

    private static function isLoopback(string $host): bool
    {
        $host = strtolower(trim($host, '[]'));
        if ($host === 'localhost') {
            return true;
        }
        $packed = inet_pton($host);
        if ($packed === false) {
            return false;
        }

        return $packed === inet_pton('::1') || (strlen($packed) === 4 && $packed[0] === "\x7F");
    }
}
