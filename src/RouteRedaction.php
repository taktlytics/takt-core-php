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
    private const CONSTRAINT_DELIMITER = "\x01";
    private const MAX_ROUTE_VARIABLE_QUANTIFIERS = 2;
    private const MAX_SEGMENT_VARIABLE_QUANTIFIERS = 2;
    private const MAX_AMBIGUITY_DEGREE = 1;
    private const MAX_SEGMENT_LENGTH = 256;

    /** @var list<array{output:string,regex:string,loose:string,unusable:bool}> */
    private readonly array $routes;

    private readonly ?string $template;

    /** @param list<string> $redactRoutes */
    public function __construct(
        array $redactRoutes = [],
        ?string $routeTemplate = null,
        private readonly bool $redactUndecodablePaths = true,
    ) {
        $routes = [];
        foreach ($redactRoutes as $pattern) {
            $pattern = trim($pattern);
            if ($pattern !== '') {
                $routes[] = self::route($pattern);
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
            static fn (array $m): string => self::browserParam($m),
            $pattern,
        ) ?? $pattern;
    }

    /** @param array<int, string> $m */
    private static function browserParam(array $m): string
    {
        if (isset($m[2]) && $m[2] !== '') {
            return $m[0];
        }

        return isset($m[3]) && $m[3] !== '' ? '[[' . $m[1] . ']]' : '[' . $m[1] . ']';
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
        return $this->template ?? $this->redactedPath($pathname) ?? $pathname;
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
        $parts = [];
        $current = '';
        $inConstraint = false;
        foreach (str_split($template) as $char) {
            if ($char === '/' && !$inConstraint) {
                $parts[] = $current;
                $current = '';
                continue;
            }
            if ($char === '<') {
                $inConstraint = true;
            } elseif ($char === '>') {
                $inConstraint = false;
            }
            $current .= $char;
        }
        $parts[] = $current;
        $segments = [];
        foreach ($parts as $segment) {
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

    /** @return array{output:string,regex:string,loose:string,unusable:bool} */
    private static function route(string $pattern): array
    {
        $segments = self::segments($pattern);
        $state = ['unusable' => false, 'variables' => 0, 'ambiguity' => 0];
        $strict = self::build($segments, 'strict', $state);
        $looseState = ['unusable' => false, 'variables' => 0, 'ambiguity' => 0];
        $loose = self::build($segments, 'loose', $looseState);
        $shapeState = ['unusable' => false, 'variables' => 0, 'ambiguity' => 0];
        $shape = self::build($segments, 'shape', $shapeState);
        $unusable = $state['unusable']
            || $state['variables'] > self::MAX_ROUTE_VARIABLE_QUANTIFIERS
            || $state['ambiguity'] > self::MAX_AMBIGUITY_DEGREE;

        return [
            'output' => self::canonical($pattern),
            'regex' => self::delimited($unusable ? $shape : $strict),
            'loose' => self::delimited($loose),
            'unusable' => $unusable,
        ];
    }

    private static function delimited(string $source): string
    {
        return self::CONSTRAINT_DELIMITER . '^' . $source . '/?$' . self::CONSTRAINT_DELIMITER . 'Di';
    }

    /**
     * @param list<string> $segments
     * @param array{unusable:bool,variables:int,ambiguity:int} $state
     */
    private static function build(array $segments, string $mode, array &$state): string
    {
        $source = '';
        foreach ($segments as $segment) {
            if (preg_match(self::REST, $segment) === 1) {
                $source .= '(?:/.*)?';
            } elseif (preg_match(self::OPTIONAL, $segment) === 1) {
                $piece = self::paramSource($segment, $mode, $state);
                $state['ambiguity'] += max(0, $piece['variables'] - 1);
                $source .= '(?:/' . $piece['source'] . ')?';
            } else {
                $source .= '/' . self::segmentSource($segment, $mode, $state);
            }
        }

        return $source;
    }

    /**
     * @param array{unusable:bool,variables:int,ambiguity:int} $state
     */
    private static function segmentSource(string $segment, string $mode, array &$state): string
    {
        $stray = self::hasStrayBrace($segment);
        $matches = self::params($segment);
        if ($mode === 'shape' && (count($matches) > 1 || $stray)) {
            return '[^/]+';
        }
        $pieces = [];
        foreach ($matches as [$param]) {
            $pieces[] = self::paramSource($param, $mode, $state);
        }
        $constrained = count(array_filter($pieces, static fn (array $piece): bool => $piece['constrained']));
        $variables = array_sum(array_column($pieces, 'variables'));
        $state['ambiguity'] += max(0, $variables - 1);
        if ($stray || $constrained > 1 || $variables > self::MAX_SEGMENT_VARIABLE_QUANTIFIERS) {
            $state['unusable'] = true;

            return '[^/]+';
        }
        $source = '';
        $last = 0;
        foreach ($matches as $index => [$param, $offset]) {
            $source .= preg_quote(substr($segment, $last, $offset - $last), self::CONSTRAINT_DELIMITER) . $pieces[$index]['source'];
            $last = $offset + strlen($param);
        }

        return $source . preg_quote(substr($segment, $last), self::CONSTRAINT_DELIMITER);
    }

    private static function hasStrayBrace(string $segment): bool
    {
        return preg_match('#[{}]#', preg_replace(self::PARAM, '', $segment) ?? $segment) === 1;
    }

    /** @return list<array{0:string,1:int}> */
    private static function params(string $segment): array
    {
        preg_match_all(self::PARAM, $segment, $matches, PREG_OFFSET_CAPTURE);

        return $matches[0];
    }

    /**
     * @param array{unusable:bool,variables:int,ambiguity:int} $state
     * @return array{source:string,variables:int,constrained:bool}
     */
    private static function paramSource(string $param, string $mode, array &$state): array
    {
        if ($mode === 'shape') {
            return ['source' => '[^/]+', 'variables' => 0, 'constrained' => false];
        }
        $body = self::constraintOf($param);
        if ($body === null) {
            return ['source' => '[^/]+', 'variables' => 1, 'constrained' => false];
        }
        $constraint = RouteConstraint::compile($body);
        if ($constraint === null) {
            $state['unusable'] = true;

            return ['source' => '[^/]+', 'variables' => 1, 'constrained' => true];
        }
        $state['variables'] += $constraint['variables'];

        return [
            'source' => $mode === 'strict' ? '(?:' . $constraint['source'] . ')' : '([^/]+)',
            'variables' => $constraint['variables'],
            'constrained' => true,
        ];
    }

    private static function constraintOf(string $param): ?string
    {
        if (!str_starts_with($param, '{') || preg_match('#<([^>]*)>#', $param, $match) !== 1) {
            return null;
        }

        return $match[1];
    }

    /** @param array<int|string, string> $captures */
    private static function overlong(array $captures): bool
    {
        foreach (array_slice($captures, 1) as $group) {
            if (strlen($group) > self::MAX_SEGMENT_LENGTH) {
                return true;
            }
        }

        return false;
    }

    private function redactedPath(string $pathname): ?string
    {
        if ($this->routes === []) {
            return null;
        }
        $forms = self::candidateForms($pathname, $this->redactUndecodablePaths);
        if ($forms === null) {
            return self::FAILED_OUTPUT;
        }
        $candidates = array_values(array_unique(array_map(self::normalize(...), $forms)));
        foreach ($this->routes as $route) {
            foreach ($candidates as $candidate) {
                $looseMatch = preg_match($route['loose'], $candidate, $captures);
                if ($looseMatch === false) {
                    return self::FAILED_OUTPUT;
                }
                if ($looseMatch === 1 && self::overlong($captures)) {
                    return self::FAILED_OUTPUT;
                }
            }
            foreach ($candidates as $candidate) {
                $matched = preg_match($route['regex'], $candidate);
                if ($matched === false) {
                    return self::FAILED_OUTPUT;
                }
                if ($matched === 1) {
                    return $route['unusable'] ? self::FAILED_OUTPUT : $route['output'];
                }
            }
        }

        return null;
    }

    /** @return list<string>|null */
    private static function candidateForms(string $pathname, bool $strict): ?array
    {
        $forms = [$pathname, self::decodeUri($pathname)];
        $current = $pathname;
        for ($pass = 0; ; $pass++) {
            $next = self::decodeOnce($current, $strict);
            if ($next === null) {
                return null;
            }
            if ($next === $current) {
                break;
            }
            if ($pass === self::MAX_DECODE_PASSES) {
                return null;
            }
            $current = $next;
            $forms[] = $current;
        }

        return array_merge($forms, array_map(self::stripMatrixParameters(...), $forms));
    }

    private static function decodeOnce(string $path, bool $strict): ?string
    {
        if (!$strict) {
            return rawurldecode($path);
        }
        if (preg_match('/%(?![0-9A-Fa-f]{2})/', $path) !== 0) {
            return null;
        }
        $decoded = rawurldecode($path);
        if ($decoded !== $path && preg_match('//u', $decoded) !== 1) {
            return null;
        }

        return $decoded;
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
        $decodeEscape = static fn (array $m): string => in_array(strtoupper($m[1]), self::RESERVED_ESCAPES, true)
            ? $m[0]
            : chr((int) hexdec($m[1]));
        $decoded = preg_replace_callback('#%([0-9A-Fa-f]{2})#', $decodeEscape, $path);

        return $decoded !== null && preg_match('//u', $decoded) === 1 ? $decoded : $path;
    }
}
