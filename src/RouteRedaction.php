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
    private const MAX_CONSTRAINT_LENGTH = 100;
    private const MAX_REPEAT = 100;
    private const MAX_VARIABLE_QUANTIFIERS = 2;
    private const MAX_SEGMENT_LENGTH = 256;
    private const SPECIAL_CHARACTERS = '()[]{}|*+?.\\^$/';
    private const CLASS_ESCAPES = 'dDwWsS';
    private const ASCII_PUNCTUATION = '!"#$%&\'()*+,-./:;<=>?@[\\]^_`{|}~';

    /** @var list<array{output:string,regex:string,constraints:list<array{group:int,pattern:string}>,usable:bool}> */
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
        preg_match_all('#(?:<[^>]*>|[^/])+#', $template, $matches);
        $segments = [];
        foreach ($matches[0] as $segment) {
            if (preg_match(self::GROUP, $segment) !== 1) {
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

    /** @return array{output:string,regex:string,constraints:list<array{group:int,pattern:string}>,usable:bool} */
    private static function route(string $pattern): array
    {
        $source = '';
        $constraints = [];
        foreach (self::segments($pattern) as $segment) {
            if (preg_match(self::REST, $segment) === 1) {
                $source .= '(?:/.*)?';
            } elseif (preg_match(self::OPTIONAL, $segment) === 1) {
                $source .= self::constraintOf($segment) !== null
                    ? '(?:/' . self::segmentSource($segment, $constraints) . ')?'
                    : '(?:/[^/]+)?';
            } else {
                $source .= '/' . self::segmentSource($segment, $constraints);
            }
        }
        $usable = true;
        foreach ($constraints as $constraint) {
            $usable = $usable && self::isUsableConstraint($constraint['pattern']);
        }

        return [
            'output' => self::canonical($pattern),
            'regex' => '#^' . $source . '/?$#Di',
            'constraints' => $constraints,
            'usable' => $usable,
        ];
    }

    /** @param list<array{group:int,pattern:string}> $constraints */
    private static function segmentSource(string $segment, array &$constraints): string
    {
        preg_match_all(self::PARAM, $segment, $matches, PREG_OFFSET_CAPTURE);
        $source = '';
        $last = 0;
        foreach ($matches[0] as [$param, $offset]) {
            $source .= preg_quote(substr($segment, $last, $offset - $last), '#');
            $pattern = self::constraintOf($param);
            if ($pattern === null) {
                $source .= '[^/]+';
            } else {
                $constraints[] = ['group' => count($constraints) + 1, 'pattern' => $pattern];
                $source .= '([^/]+)';
            }
            $last = $offset + strlen($param);
        }

        return $source . preg_quote(substr($segment, $last), '#');
    }

    private static function constraintOf(string $param): ?string
    {
        return preg_match('#^\{\w+<([^>]*)>#', $param, $match) === 1 ? $match[1] : null;
    }

    private static function isUsableConstraint(string $body): bool
    {
        if ($body === '' || strlen($body) > self::MAX_CONSTRAINT_LENGTH) {
            return false;
        }
        $cursor = 0;
        $variable = 0;

        return self::parseAlternation($body, $cursor, $variable);
    }

    private static function parseAlternation(string $body, int &$cursor, int &$variable): bool
    {
        if (!self::parseBranch($body, $cursor, $variable)) {
            return false;
        }
        while (self::consume($body, $cursor, '|')) {
            if (!self::parseBranch($body, $cursor, $variable)) {
                return false;
            }
        }

        return $cursor === strlen($body);
    }

    private static function parseBranch(string $body, int &$cursor, int &$variable): bool
    {
        $pieces = 0;
        while ($cursor < strlen($body) && $body[$cursor] !== '|') {
            if (!self::parseAtom($body, $cursor) || !self::parseQuantifier($body, $cursor, $variable)) {
                return false;
            }
            $pieces++;
        }

        return $pieces > 0;
    }

    private static function parseAtom(string $body, int &$cursor): bool
    {
        $char = $body[$cursor];
        if ($char === '.') {
            $cursor++;

            return true;
        }
        if ($char === '[') {
            return self::parseClass($body, $cursor);
        }
        if ($char === '\\') {
            return self::parseEscape($body, $cursor);
        }

        return self::takeLiteral($body, $cursor);
    }

    private static function parseQuantifier(string $body, int &$cursor, int &$variable): bool
    {
        if (
            self::consume($body, $cursor, '*')
            || self::consume($body, $cursor, '+')
            || self::consume($body, $cursor, '?')
        ) {
            return self::countVariable($variable);
        }
        if (self::consume($body, $cursor, '{')) {
            return self::parseBraces($body, $cursor, $variable);
        }

        return true;
    }

    private static function parseBraces(string $body, int &$cursor, int &$variable): bool
    {
        $minimum = self::readNumber($body, $cursor);
        if ($minimum === null) {
            return false;
        }
        if (self::consume($body, $cursor, '}')) {
            return true;
        }
        if (!self::consume($body, $cursor, ',') || !self::countVariable($variable)) {
            return false;
        }
        if (self::consume($body, $cursor, '}')) {
            return true;
        }
        $maximum = self::readNumber($body, $cursor);

        return $maximum !== null && $minimum <= $maximum && self::consume($body, $cursor, '}');
    }

    private static function readNumber(string $body, int &$cursor): ?int
    {
        $number = null;
        while ($cursor < strlen($body) && ctype_digit($body[$cursor])) {
            $number = ($number ?? 0) * 10 + (int) $body[$cursor];
            if ($number > self::MAX_REPEAT) {
                return null;
            }
            $cursor++;
        }

        return $number;
    }

    private static function countVariable(int &$variable): bool
    {
        $variable++;

        return $variable <= self::MAX_VARIABLE_QUANTIFIERS;
    }

    private static function parseEscape(string $body, int &$cursor): bool
    {
        if ($cursor + 1 >= strlen($body)) {
            return false;
        }
        $escaped = $body[$cursor + 1];
        $isClassEscape = str_contains(self::CLASS_ESCAPES, $escaped);
        $isPunctuation = $escaped !== '/' && str_contains(self::ASCII_PUNCTUATION, $escaped);
        if (!$isClassEscape && !$isPunctuation) {
            return false;
        }
        $cursor += 2;

        return true;
    }

    private static function parseClass(string $body, int &$cursor): bool
    {
        $cursor++;
        self::consume($body, $cursor, '^');
        $start = $cursor;
        while ($cursor < strlen($body) && $body[$cursor] !== ']') {
            if (!self::parseClassItem($body, $cursor, $start)) {
                return false;
            }
        }

        return $cursor !== $start && self::consume($body, $cursor, ']');
    }

    private static function parseClassItem(string $body, int &$cursor, int $start): bool
    {
        if ($body[$cursor] === '\\') {
            return self::parseEscape($body, $cursor);
        }
        if ($body[$cursor] === '-') {
            return self::parseHyphen($body, $cursor, $start);
        }

        return self::parseClassLiteral($body, $cursor);
    }

    private static function parseHyphen(string $body, int &$cursor, int $start): bool
    {
        $isEdge = $cursor === $start || ($body[$cursor + 1] ?? '') === ']';
        $cursor++;

        return $isEdge;
    }

    private static function parseClassLiteral(string $body, int &$cursor): bool
    {
        $first = $body[$cursor];
        if (!self::takeLiteral($body, $cursor)) {
            return false;
        }
        if (($body[$cursor] ?? '') !== '-' || !self::isLiteral($body[$cursor + 1] ?? ']')) {
            return true;
        }
        if (ord($first) > ord($body[$cursor + 1])) {
            return false;
        }
        $cursor += 2;

        return true;
    }

    private static function takeLiteral(string $body, int &$cursor): bool
    {
        if (!self::isLiteral($body[$cursor])) {
            return false;
        }
        $cursor++;

        return true;
    }

    private static function isLiteral(string $char): bool
    {
        return ord($char) >= 0x20 && ord($char) <= 0x7E && !str_contains(self::SPECIAL_CHARACTERS, $char);
    }

    private static function consume(string $body, int &$cursor, string $expected): bool
    {
        if (($body[$cursor] ?? '') !== $expected) {
            return false;
        }
        $cursor++;

        return true;
    }

    /**
     * @param list<array{group:int,pattern:string}> $constraints
     * @param array<int|string, string> $captures
     */
    private static function constraintsHold(array $constraints, array $captures): ?bool
    {
        foreach ($constraints as $constraint) {
            $value = $captures[$constraint['group']] ?? '';
            if ($value === '') {
                continue;
            }
            if (strlen($value) > self::MAX_SEGMENT_LENGTH) {
                return null;
            }
            $held = preg_match(
                self::CONSTRAINT_DELIMITER . '^(?:' . $constraint['pattern'] . ')$' . self::CONSTRAINT_DELIMITER . 'Di',
                $value,
            );
            if ($held === false) {
                return null;
            }
            if ($held === 0) {
                return false;
            }
        }

        return true;
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
                $matched = preg_match($route['regex'], $candidate, $captures);
                if ($matched === false) {
                    return self::FAILED_OUTPUT;
                }
                if ($matched !== 1) {
                    continue;
                }
                if (!$route['usable']) {
                    return self::FAILED_OUTPUT;
                }
                $held = self::constraintsHold($route['constraints'], $captures);
                if ($held === null) {
                    return self::FAILED_OUTPUT;
                }
                if ($held) {
                    return $route['output'];
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
