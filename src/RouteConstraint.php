<?php

namespace Vskstudio\Takt;

final class RouteConstraint
{
    private const MAX_CONSTRAINT_LENGTH = 100;
    private const MAX_REPEAT = 100;
    private const MAX_VARIABLE_QUANTIFIERS = 2;
    private const FORBIDDEN_LITERALS = '()[]{}|*+?.\\^$/<>';
    private const CLASS_ESCAPES = 'dDwWsS';
    private const ASCII_PUNCTUATION = '!"#$%&\'()*+,-./:;<=>?@[\\]^_`{|}~';
    private const SPECIAL_IN_CLASS = '\\]^-[';
    private const SLASH = 0x2F;
    private const DELIMITER = "\x01";

    private int $index = 0;
    private int $variables = 0;

    private function __construct(private readonly string $text)
    {
    }

    /** @return array{source:string,variables:int}|null */
    public static function compile(string $body): ?array
    {
        if ($body === '' || strlen($body) > self::MAX_CONSTRAINT_LENGTH || preg_match('#^[\x20-\x7e]+$#D', $body) !== 1) {
            return null;
        }
        $compiler = new self($body);
        $branches = [];
        do {
            $branch = $compiler->branch();
            if ($branch === null) {
                return null;
            }
            $branches[] = $branch;
        } while ($compiler->alternation());
        if ($compiler->variables > self::MAX_VARIABLE_QUANTIFIERS) {
            return null;
        }

        $source = implode('|', $branches);
        if (@preg_match(self::DELIMITER . $source . self::DELIMITER . 'i', '') === false) {
            return null;
        }

        return ['source' => $source, 'variables' => $compiler->variables];
    }

    private function branch(): ?string
    {
        $source = '';
        $pieces = 0;
        while ($this->at($this->index) !== '' && $this->at($this->index) !== '|') {
            $piece = $this->piece();
            if ($piece === null) {
                return null;
            }
            $source .= $piece;
            $pieces++;
        }

        return $pieces === 0 ? null : $source;
    }

    private function alternation(): bool
    {
        if ($this->at($this->index) !== '|') {
            return false;
        }
        $this->index++;

        return true;
    }

    private function piece(): ?string
    {
        $atom = $this->atom();
        if ($atom === null) {
            return null;
        }
        $quantifier = $this->quantifier();
        if ($quantifier === null) {
            return null;
        }

        return '(?:(?!\/)' . $atom . ')' . $quantifier;
    }

    private function atom(): ?string
    {
        $char = $this->at($this->index);
        if ($char === '') {
            return null;
        }
        if ($char === '\\') {
            return $this->escape();
        }
        $this->index++;
        if ($char === '[') {
            return $this->klass();
        }
        if ($char === '.') {
            return '[^/]';
        }
        if (str_contains(self::FORBIDDEN_LITERALS, $char)) {
            return null;
        }

        return preg_quote($char, self::DELIMITER);
    }

    private function escape(): ?string
    {
        $char = $this->at($this->index + 1);
        $this->index += 2;
        if ($char === '') {
            return null;
        }
        if (str_contains(self::CLASS_ESCAPES, $char)) {
            return '\\' . $char;
        }
        if ($char !== '/' && str_contains(self::ASCII_PUNCTUATION, $char)) {
            return '\\' . $char;
        }

        return null;
    }

    private function klass(): ?string
    {
        $negated = $this->at($this->index) === '^';
        if ($negated) {
            $this->index++;
        }
        $items = [];
        while (true) {
            $char = $this->at($this->index);
            if ($char === '') {
                return null;
            }
            if ($char === ']') {
                $this->index++;

                return $items === [] ? null : '[' . ($negated ? '^' : '') . implode('', $items) . ']';
            }
            $item = $this->classItem($items === []);
            if ($item === null) {
                return null;
            }
            $items[] = $item;
        }
    }

    private function classItem(bool $first): ?string
    {
        $start = $this->index;
        $char = $this->at($start);
        if ($char === '\\') {
            return $this->escape();
        }
        if (str_contains(self::FORBIDDEN_LITERALS, $char)) {
            return null;
        }
        $next = $this->at($start + 1);
        if ($char === '-') {
            if ($next === '-' || (!$first && $next !== ']')) {
                return null;
            }
            $this->index = $start + 1;

            return '\\-';
        }
        $end = $this->at($start + 2);
        if ($next !== '-' || $end === '' || $end === ']') {
            $this->index = $start + 1;

            return $this->classChar($char);
        }
        if ($end === '-' || str_contains(self::FORBIDDEN_LITERALS, $end)) {
            return null;
        }
        if (ord($char) > ord($end) || (ord($char) <= self::SLASH && ord($end) >= self::SLASH)) {
            return null;
        }
        $this->index = $start + 3;

        return $this->classChar($char) . '-' . $this->classChar($end);
    }

    private function quantifier(): ?string
    {
        $char = $this->at($this->index);
        if ($char === '*' || $char === '+' || $char === '?') {
            $this->index++;
            $this->variables++;

            return $char;
        }
        if ($char !== '{') {
            return '';
        }
        if (preg_match('#^\{(\d+)(,(\d*))?\}#', substr($this->text, $this->index), $match) !== 1) {
            return null;
        }
        $this->index += strlen($match[0]);
        $minimum = (int) $match[1];
        if ($minimum > self::MAX_REPEAT) {
            return null;
        }
        if (!isset($match[2])) {
            return '{' . $minimum . '}';
        }
        $this->variables++;
        if (!isset($match[3]) || $match[3] === '') {
            return '{' . $minimum . ',}';
        }
        $maximum = (int) $match[3];
        if ($maximum > self::MAX_REPEAT || $maximum < $minimum) {
            return null;
        }

        return '{' . $minimum . ',' . $maximum . '}';
    }

    private function classChar(string $char): string
    {
        return str_contains(self::SPECIAL_IN_CLASS, $char) ? '\\' . $char : $char;
    }

    private function at(int $offset): string
    {
        return substr($this->text, $offset, 1);
    }
}
