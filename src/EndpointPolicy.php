<?php

namespace Vskstudio\Takt;

final class EndpointPolicy
{
    private const SCHEME_LESS_PATH = '#^/(?![/\\\\])[^\x00-\x20\x7F\\\\]*\z#';

    public static function assertSecure(string $endpoint): void
    {
        $trimmed = trim($endpoint);
        $parts = parse_url($trimmed);
        if ($parts === false) {
            throw new \InvalidArgumentException('Takt endpoint is not a valid URL.');
        }
        if (isset($parts['scheme'])) {
            if (strtolower($parts['scheme']) !== 'https' || !isset($parts['host']) || $parts['host'] === '') {
                throw new \InvalidArgumentException('Takt endpoint must be an absolute https URL.');
            }

            return;
        }
        if (preg_match(self::SCHEME_LESS_PATH, $trimmed) !== 1) {
            throw new \InvalidArgumentException('Takt endpoint must be an absolute https URL or a path starting with a single "/".');
        }
    }
}
