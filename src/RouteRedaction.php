<?php

namespace Vskstudio\Takt;

final class RouteRedaction
{
    private const GROUP = '#^\(.+\)$#D';
    private const REST = '#^(\[\.\.\.[^\]]+\]|\*\*?|\*\w+|:\w+(\([^)]*\))?[*+])$#D';
    private const OPTIONAL = '#^(\[\[[^\]]+\]\]|:\w+(\([^)]*\))?\?|\{\w+(<[^>]*>)?\?[^}]*\})$#D';
    private const PARAM = '#\[[^\]]+\]|:\w+(\([^)]*\))?|\{\w+(<[^>]*>)?(\?[^}]*)?\}#';
    private const BRACE = '#\{(\w+)(<[^>]*>)?(\?[^}]*)?\}#';
    private const RESERVED_ESCAPES = ['23', '24', '26', '2B', '2C', '2F', '3A', '3B', '3D', '3F', '40'];
    private const FAILED_OUTPUT = '/[redacted]';
    private const MAX_DECODE_PASSES = 5;

    /** @var list<array{regex:string,output:string}> */
    private readonly array $routes;

    private readonly ?string $template;

    /** @param list<string> $redactRoutes */
    public function __construct(array $redactRoutes = [], ?string $routeTemplate = null)
    {
        $routes = [];
        foreach ($redactRoutes as $pattern) {
            $pattern = trim($pattern);
            if ($pattern !== '') {
                $routes[] = ['regex' => self::compile($pattern), 'output' => self::canonical($pattern)];
            }
        }
        $this->routes = $routes;
        $template = trim($routeTemplate ?? '');
        $this->template = $template !== '' ? self::canonical($template) : null;
    }

    public static function canonical(string $template): string
    {
        return '/' . implode('/', array_map(self::canonicalSegment(...), self::segments($template)));
    }

    public static function browserPattern(string $pattern): string
    {
        return preg_replace_callback(
            self::BRACE,
            static fn (array $m): string => isset($m[3]) && $m[3] !== '' ? '[[' . $m[1] . ']]' : '[' . $m[1] . ']',
            $pattern,
        ) ?? $pattern;
    }

    public function templated(): bool
    {
        return $this->template !== null;
    }

    public function isEmpty(): bool
    {
        return $this->template === null && $this->routes === [];
    }

    public function path(string $pathname): string
    {
        return $this->redactedPath($pathname) ?? $pathname;
    }

    public function page(string $url): string
    {
        if ($this->isEmpty()) {
            return $url;
        }

        return $this->rewrite($url, $this->template);
    }

    public function referrer(string $url, string $pageUrl): string
    {
        $origin = self::origin($url);
        if ($origin === null || $origin !== self::origin($pageUrl)) {
            return $url;
        }
        if ($this->template !== null) {
            return $origin . '/';
        }

        return $this->routes !== [] ? $this->rewrite($url, null) : $url;
    }

    /** @return list<string> */
    private static function segments(string $template): array
    {
        $segments = [];
        foreach (explode('/', $template) as $segment) {
            if ($segment !== '' && preg_match(self::GROUP, $segment) !== 1) {
                $segments[] = $segment;
            }
        }

        return $segments;
    }

    private static function canonicalSegment(string $segment): string
    {
        if ($segment === '**') {
            return '*';
        }
        $segment = preg_replace('#:(\w+)(\([^)]*\))?[?*+]?#', ':$1', $segment) ?? $segment;

        return preg_replace(self::BRACE, '{$1}', $segment) ?? $segment;
    }

    private static function compile(string $pattern): string
    {
        $source = '';
        foreach (self::segments($pattern) as $segment) {
            if (preg_match(self::REST, $segment) === 1) {
                $source .= '(?:/.*)?';
            } elseif (preg_match(self::OPTIONAL, $segment) === 1) {
                $source .= '(?:/[^/]+)?';
            } else {
                $source .= '/' . self::segmentSource($segment);
            }
        }

        return '#^' . $source . '/?$#Di';
    }

    private static function segmentSource(string $segment): string
    {
        preg_match_all(self::PARAM, $segment, $matches, PREG_OFFSET_CAPTURE);
        $source = '';
        $last = 0;
        foreach ($matches[0] as [$param, $offset]) {
            $source .= preg_quote(substr($segment, $last, $offset - $last), '#') . '[^/]+';
            $last = $offset + strlen($param);
        }

        return $source . preg_quote(substr($segment, $last), '#');
    }

    private function redactedPath(string $pathname): ?string
    {
        if ($this->routes === []) {
            return null;
        }
        $forms = self::candidateForms($pathname);
        if ($forms === null) {
            return self::FAILED_OUTPUT;
        }
        $candidates = array_values(array_unique(array_map(self::normalize(...), $forms)));
        foreach ($this->routes as $route) {
            foreach ($candidates as $candidate) {
                $matched = preg_match($route['regex'], $candidate);
                if ($matched === false) {
                    return self::FAILED_OUTPUT;
                }
                if ($matched === 1) {
                    return $route['output'];
                }
            }
        }

        return null;
    }

    /** @return list<string>|null */
    private static function candidateForms(string $pathname): ?array
    {
        $forms = [$pathname, self::decodeUri($pathname)];
        $current = $pathname;
        for ($pass = 0; rawurldecode($current) !== $current; $pass++) {
            if ($pass === self::MAX_DECODE_PASSES) {
                return null;
            }
            $current = rawurldecode($current);
            $forms[] = $current;
        }

        return array_merge($forms, array_map(self::stripMatrixParameters(...), $forms));
    }

    private static function stripMatrixParameters(string $path): string
    {
        $truncate = static fn (string $segment): string => explode(';', $segment, 2)[0];

        return implode('/', array_map($truncate, explode('/', $path)));
    }

    private static function normalize(string $path): string
    {
        return self::collapseSlashes(self::removeDotSegments(self::collapseSlashes($path)));
    }

    private static function collapseSlashes(string $path): string
    {
        while (str_contains($path, '//')) {
            $path = str_replace('//', '/', $path);
        }

        return $path;
    }

    private static function removeDotSegments(string $path): string
    {
        $absolute = str_starts_with($path, '/');
        $segments = explode('/', $absolute ? substr($path, 1) : $path);
        $last = count($segments) - 1;
        $output = [];
        foreach ($segments as $index => $segment) {
            if ($segment === '.') {
                if ($index === $last) {
                    $output[] = '';
                }
                continue;
            }
            if ($segment === '..') {
                array_pop($output);
                if ($index === $last) {
                    $output[] = '';
                }
                continue;
            }
            $output[] = $segment;
        }

        return ($absolute ? '/' : '') . implode('/', $output);
    }

    private function rewrite(string $url, ?string $template): string
    {
        $parts = self::parse($url);
        if ($parts === null) {
            return $url;
        }
        $path = $template ?? $this->redactedPath($parts['path']);
        if ($path === null) {
            return $url;
        }

        return $parts['origin'] . $path;
    }

    private static function origin(string $url): ?string
    {
        return self::parse($url)['origin'] ?? null;
    }

    /** @return array{origin:string,path:string}|null */
    private static function parse(string $url): ?array
    {
        $parts = parse_url(trim($url));
        if ($parts === false || !isset($parts['scheme'], $parts['host']) || $parts['host'] === '') {
            return null;
        }
        $scheme = strtolower($parts['scheme']);
        $origin = $scheme . '://' . strtolower($parts['host']);
        $port = $parts['port'] ?? null;
        if ($port !== null && !($scheme === 'http' && $port === 80) && !($scheme === 'https' && $port === 443)) {
            $origin .= ':' . $port;
        }
        $path = $parts['path'] ?? '';
        return ['origin' => $origin, 'path' => $path !== '' ? $path : '/'];
    }

    private static function decodeUri(string $path): string
    {
        $decoded = preg_replace_callback(
            '#%([0-9A-Fa-f]{2})#',
            static fn (array $m): string => in_array(strtoupper($m[1]), self::RESERVED_ESCAPES, true) ? $m[0] : chr((int) hexdec($m[1])),
            $path,
        );

        return $decoded !== null && preg_match('//u', $decoded) === 1 ? $decoded : $path;
    }
}
