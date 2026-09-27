<?php

namespace Vskstudio\Takt\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Vskstudio\Takt\RouteRedaction;

final class RouteRedactionTest extends TestCase
{
    /** @return iterable<string,array{string,string}> */
    public static function canonicalRoutes(): iterable
    {
        yield 'brackets kept' => ['/blog/[slug]', '/blog/[slug]'];
        yield 'group dropped' => ['/(app)/settings/[id]', '/settings/[id]'];
        yield 'colon optional' => ['/users/:id?', '/users/:id'];
        yield 'colon with regex' => ['/users/:id(\\d+)', '/users/:id'];
        yield 'colon rest' => ['/files/:path*', '/files/:path'];
        yield 'double star' => ['/docs/**', '/docs/*'];
        yield 'brace param' => ['/verify/{token}', '/verify/{token}'];
        yield 'brace optional' => ['/posts/{page?}', '/posts/{page}'];
        yield 'brace inline requirement' => ['/blog/{page<\\d+>?1}', '/blog/{page}'];
        yield 'no leading slash' => ['verify/{token}', '/verify/{token}'];
        yield 'root' => ['/', '/'];
    }

    #[DataProvider('canonicalRoutes')]
    public function test_canonical_form(string $template, string $expected): void
    {
        $this->assertSame($expected, RouteRedaction::canonical($template));
    }

    /** @return iterable<string,array{string,string,string}> */
    public static function matchingPaths(): iterable
    {
        yield 'bracket param' => ['/verify/[token]', '/verify/abc123', '/verify/[token]'];
        yield 'bracket trailing slash' => ['/verify/[token]', '/verify/abc123/', '/verify/[token]'];
        yield 'bracket inside segment' => ['/invoices/[id].pdf', '/invoices/42.pdf', '/invoices/[id].pdf'];
        yield 'optional present' => ['/shop/[[lang]]/cart', '/shop/fr/cart', '/shop/[[lang]]/cart'];
        yield 'optional absent' => ['/shop/[[lang]]/cart', '/shop/cart', '/shop/[[lang]]/cart'];
        yield 'rest several' => ['/docs/[...path]', '/docs/a/b/c', '/docs/[...path]'];
        yield 'rest none' => ['/docs/[...path]', '/docs', '/docs/[...path]'];
        yield 'group' => ['/(auth)/reset/[code]', '/reset/xyz', '/reset/[code]'];
        yield 'colon' => ['/reset/:code', '/reset/xyz', '/reset/:code'];
        yield 'colon optional' => ['/users/:id?', '/users', '/users/:id'];
        yield 'star' => ['/files/*', '/files/a/b', '/files/*'];
        yield 'double star' => ['/files/**', '/files/a/b', '/files/*'];
        yield 'brace' => ['/verify/{token}', '/verify/abc', '/verify/{token}'];
        yield 'brace optional absent' => ['/posts/{page?}', '/posts', '/posts/{page}'];
        yield 'brace optional present' => ['/posts/{page?}', '/posts/3', '/posts/{page}'];
        yield 'encoded path' => ['/café/[id]', '/caf%C3%A9/9', '/café/[id]'];
    }

    #[DataProvider('matchingPaths')]
    public function test_matching_path_is_replaced_by_the_pattern(string $pattern, string $path, string $expected): void
    {
        $this->assertSame($expected, (new RouteRedaction([$pattern]))->path($path));
    }

    /** @return iterable<string,array{string,string}> */
    public static function nonMatchingPaths(): iterable
    {
        yield 'extra segment' => ['/verify/[token]', '/verify/a/b'];
        yield 'missing segment' => ['/verify/[token]', '/verify'];
        yield 'literal differs' => ['/verify/[token]', '/verified/abc'];
        yield 'dot is literal' => ['/invoices/[id].pdf', '/invoices/42xpdf'];
        yield 'brace literal differs' => ['/verify/{token}', '/other/abc'];
    }

    #[DataProvider('nonMatchingPaths')]
    public function test_non_matching_path_is_kept(string $pattern, string $path): void
    {
        $this->assertSame($path, (new RouteRedaction([$pattern]))->path($path));
    }

    public function test_first_matching_pattern_wins(): void
    {
        $redaction = new RouteRedaction(['/a/[x]', '/a/:y']);
        $this->assertSame('/a/[x]', $redaction->path('/a/1'));
    }

    public function test_blank_patterns_are_ignored(): void
    {
        $redaction = new RouteRedaction(['  ', '']);
        $this->assertSame('https://example.com/a', $redaction->page('https://example.com/a'));
    }

    public function test_page_keeps_origin_query_and_hash(): void
    {
        $redaction = new RouteRedaction(['/verify/[token]']);
        $this->assertSame(
            'https://example.com/verify/[token]?a=1#h',
            $redaction->page('https://Example.com:443/verify/abc?a=1#h'),
        );
    }

    public function test_page_keeps_a_non_default_port(): void
    {
        $redaction = new RouteRedaction(['/verify/[token]']);
        $this->assertSame('http://localhost:8000/verify/[token]', $redaction->page('http://localhost:8000/verify/abc'));
    }

    public function test_page_leaves_an_unparsable_url_untouched(): void
    {
        $redaction = new RouteRedaction(['/verify/[token]']);
        $this->assertSame('/verify/abc', $redaction->page('/verify/abc'));
    }

    public function test_template_replaces_every_page_path(): void
    {
        $redaction = new RouteRedaction([], '/blog/{slug?}');
        $this->assertTrue($redaction->templated());
        $this->assertSame('https://example.com/blog/{slug}', $redaction->page('https://example.com/blog/hello'));
    }

    public function test_blank_template_falls_back_to_patterns(): void
    {
        $redaction = new RouteRedaction(['/verify/[token]'], '  ');
        $this->assertFalse($redaction->templated());
        $this->assertSame('https://example.com/verify/[token]', $redaction->page('https://example.com/verify/abc'));
    }

    public function test_same_origin_referrer_is_redacted(): void
    {
        $redaction = new RouteRedaction(['/verify/[token]']);
        $this->assertSame(
            'https://example.com/verify/[token]',
            $redaction->referrer('https://example.com/verify/abc', 'https://example.com/next'),
        );
    }

    public function test_cross_origin_referrer_is_kept(): void
    {
        $redaction = new RouteRedaction(['/verify/[token]']);
        $this->assertSame(
            'https://other.example/verify/abc',
            $redaction->referrer('https://other.example/verify/abc', 'https://example.com/next'),
        );
    }

    public function test_templated_mode_reduces_same_origin_referrer_to_origin(): void
    {
        $redaction = new RouteRedaction([], '/blog/[slug]');
        $this->assertSame('https://example.com/', $redaction->referrer('https://example.com/blog/a?x=1', 'https://example.com/blog/b'));
    }

    public function test_browser_pattern_translates_brace_syntax(): void
    {
        $this->assertSame('/verify/[token]', RouteRedaction::browserPattern('/verify/{token}'));
        $this->assertSame('/posts/[[page]]', RouteRedaction::browserPattern('/posts/{page?}'));
        $this->assertSame('/blog/[page]', RouteRedaction::browserPattern('/blog/{page<\\d+>}'));
        $this->assertSame('/reset/:code', RouteRedaction::browserPattern('/reset/:code'));
    }
}
