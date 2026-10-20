<?php

namespace Vskstudio\Takt;

final class EndpointPolicy
{
    public static function assertSecure(string $endpoint): void
    {
        $parts = parse_url(trim($endpoint));
        if ($parts === false) {
            throw new \InvalidArgumentException('Takt endpoint is not a valid URL.');
        }
        if (isset($parts['scheme']) && strtolower($parts['scheme']) !== 'https') {
            throw new \InvalidArgumentException('Takt endpoint must use https.');
        }
    }
}
