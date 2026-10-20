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
        yield 'encoded slash' => ['/orders/[id]', '/orders%2F123', '/orders/[id]'];
        yield 'lowercase encoded slash' => ['/orders/[id]', '/orders%2f123', '/orders/[id]'];
        yield 'encoded slash in the middle' => ['/orders/[id]/items', '/orders%2F123/items', '/orders/[id]/items'];
        yield 'uppercase path' => ['/orders/[id]', '/Orders/123', '/orders/[id]'];
        yield 'uppercase literal with param' => ['/Orders/[id]', '/orders/123', '/Orders/[id]'];
        yield 'leading double slash' => ['/orders/[id]', '//orders/123', '/orders/[id]'];
        yield 'inner double slash' => ['/orders/[id]', '/orders//123', '/orders/[id]'];
        yield 'trailing double slash' => ['/orders/[id]', '/orders/123//', '/orders/[id]'];
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

    public function test_pattern_failing_to_evaluate_never_leaks_the_raw_path(): void
    {
        $path = '/x/' . str_repeat('a', 2000) . '.json.x';
        $redacted = (new RouteRedaction(['/x/:a:b:c.json']))->path($path);

        $this->assertNotSame($path, $redacted);
        $this->assertStringNotContainsString('aaaa', $redacted);
    }

    public function test_failing_pattern_does_not_leak_on_page_urls(): void
    {
        $url = 'https://example.com/x/' . str_repeat('a', 2000) . '.json.x?t=1';
        $page = (new RouteRedaction(['/x/:a:b:c.json']))->page($url);

        $this->assertSame('https://example.com/[redacted]', $page);
    }

    public function test_long_run_of_slashes_is_collapsed_before_matching(): void
    {
        $redaction = new RouteRedaction(['/verify/[token]']);
        $this->assertSame('/verify/[token]', $redaction->path('/verify'.str_repeat('/', 5000).'abc'));
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

    public function test_page_drops_query_and_hash_when_path_is_masked(): void
    {
        $redaction = new RouteRedaction(['/verify/[token]']);
        $this->assertSame(
            'https://example.com/verify/[token]',
            $redaction->page('https://Example.com:443/verify/abc?token=secret#h'),
        );
    }

    public function test_page_keeps_query_and_hash_when_path_is_not_masked(): void
    {
        $redaction = new RouteRedaction(['/verify/[token]']);
        $this->assertSame(
            'https://example.com/other?a=1#h',
            $redaction->page('https://example.com/other?a=1#h'),
        );
    }

    public function test_double_encoded_separator_is_matched(): void
    {
        $this->assertSame('/files/[name]', (new RouteRedaction(['/files/[name]']))->path('/files%252Fsecret'));
    }

    public function test_triple_encoded_separator_is_matched(): void
    {
        $this->assertSame('/orders/[id]', (new RouteRedaction(['/orders/[id]']))->path('/orders%25252F123'));
    }

    public function test_quadruple_encoded_path_is_matched(): void
    {
        $path = '/orders'.self::encoded('/123', 4);
        $this->assertSame('/orders/[id]', (new RouteRedaction(['/orders/[id]']))->path($path));
    }

    public function test_path_still_encoded_after_the_decoding_bound_is_masked(): void
    {
        $path = '/orders'.self::encoded('/123', 6);
        $this->assertSame('/[redacted]', (new RouteRedaction(['/orders/[id]']))->path($path));
    }

    public function test_triple_encoded_dot_segments_are_removed(): void
    {
        $this->assertSame('/orders/[id]', (new RouteRedaction(['/orders/[id]']))->path('/%25252e%25252e/orders/123'));
    }

    public function test_matrix_parameters_are_ignored_before_matching(): void
    {
        $this->assertSame('/orders/[id]', (new RouteRedaction(['/orders/[id]']))->path('/orders;a=1/123'));
        $this->assertSame('/admin/[x]', (new RouteRedaction(['/admin/[x]']))->path('/admin;x/secret'));
    }

    public function test_matrix_truncation_happens_before_dot_segment_removal(): void
    {
        $this->assertSame('/orders/[id]', (new RouteRedaction(['/orders/[id]']))->path('/a/..;/orders/123'));
        $this->assertSame('/admin/[x]', (new RouteRedaction(['/admin/[x]']))->path('/x/..;/admin/secret'));
    }

    public function test_path_encoded_exactly_the_maximum_number_of_times_is_matched(): void
    {
        $path = '/orders'.self::encoded('/123', 5);
        $this->assertSame('/orders/[id]', (new RouteRedaction(['/orders/[id]']))->path($path));
    }

    public function test_undecodable_path_is_masked_by_default(): void
    {
        $redaction = new RouteRedaction(['/orders/[id]']);
        $this->assertSame('/[redacted]', $redaction->path('/%ZZ/orders/123'));
        $this->assertSame('/[redacted]', $redaction->path('/orders/%E9/123'));
        $this->assertSame('/[redacted]', $redaction->path('/orders/%E0%A4%A'));
    }

    public function test_undecodable_path_is_kept_when_masking_is_disabled(): void
    {
        $redaction = new RouteRedaction(['/orders/[id]'], null, false);
        $this->assertSame('/%ZZ/orders/123', $redaction->path('/%ZZ/orders/123'));
        $this->assertSame('/orders/%E9/123', $redaction->path('/orders/%E9/123'));
    }

    public function test_undecodable_path_matching_a_route_is_templated_when_masking_is_disabled(): void
    {
        $this->assertSame('/orders/[id]', (new RouteRedaction(['/orders/[id]'], null, false))->path('/orders/123%ZZ'));
    }

    public function test_path_encoded_six_times_is_masked_when_masking_is_disabled(): void
    {
        $path = '/orders'.self::encoded('/123', 6);
        $this->assertSame('/[redacted]', (new RouteRedaction(['/orders/[id]'], null, false))->path($path));
    }

    public function test_valid_encoded_path_is_matched_in_both_modes(): void
    {
        $this->assertSame('/orders/[id]', (new RouteRedaction(['/orders/[id]']))->path('/orders/%C3%A9'));
        $this->assertSame('/orders/[id]', (new RouteRedaction(['/orders/[id]'], null, false))->path('/orders/%C3%A9'));
    }

    public function test_path_returns_the_route_template_when_set(): void
    {
        $this->assertSame('/blog/[slug]', (new RouteRedaction([], '/blog/[slug]'))->path('/anything/else'));
    }

    public function test_raw_path_is_matched_for_patterns_with_literal_escapes(): void
    {
        $this->assertSame('/a%20b/[x]', (new RouteRedaction(['/a%20b/[x]']))->path('/a%20b/1'));
    }

    public function test_templated_page_drops_query_and_hash(): void
    {
        $redaction = new RouteRedaction([], '/blog/{slug?}');
        $this->assertSame('https://example.com/blog/{slug}', $redaction->page('https://example.com/blog/hello?x=1#f'));
    }

    public function test_constraint_matches_a_segment_that_respects_it(): void
    {
        $this->assertSame('/blog/{page}', (new RouteRedaction(['/blog/{page<\\d+>}']))->path('/blog/12'));
    }

    public function test_constraint_leaves_a_segment_that_breaks_it_raw(): void
    {
        $this->assertSame('/blog/abc', (new RouteRedaction(['/blog/{page<\\d+>}']))->path('/blog/abc'));
    }

    public function test_constraint_is_anchored_on_the_whole_segment(): void
    {
        $this->assertSame('/blog/12abc', (new RouteRedaction(['/blog/{page<\\d+>}']))->path('/blog/12abc'));
    }

    public function test_constraint_cannot_match_across_a_slash(): void
    {
        $this->assertSame('/blog/a/b', (new RouteRedaction(['/blog/{page<.+>}']))->path('/blog/a/b'));
    }

    public function test_invalid_constraint_masks_the_path(): void
    {
        $this->assertSame('/[redacted]', (new RouteRedaction(['/x/{p<[>}']))->path('/x/abc'));
    }

    public function test_constraint_accepts_a_hash_character(): void
    {
        $this->assertSame('/x/{p}', (new RouteRedaction(['/x/{p<a#b>}']))->path('/x/a%23b'));
    }

    public function test_catastrophic_constraint_masks_the_path(): void
    {
        $path = '/x/'.str_repeat('a', 40).'c';
        $this->assertSame('/[redacted]', (new RouteRedaction(['/x/{p<(a+)+>}']))->path($path));
    }

    /** @return iterable<string,array{string,string,string}> */
    public static function acceptedConstraints(): iterable
    {
        yield 'digits' => ['/x/{p<\\d+>}', '/x/12', '/x/{p}'];
        yield 'class with ranges and dash' => ['/x/{p<[a-z0-9-]+>}', '/x/ab-1', '/x/{p}'];
        yield 'uuid' => [
            '/x/{p<[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}>}',
            '/x/123e4567-e89b-12d3-a456-426614174000',
            '/x/{p}',
        ];
        yield 'top-level alternation matches' => ['/x/{p<fr|en>}', '/x/fr', '/x/{p}'];
        yield 'top-level alternation breaks raw' => ['/x/{p<fr|en>}', '/x/de', '/x/de'];
        yield 'case insensitive class' => ['/x/{p<[a-f]+>}', '/x/ABC', '/x/{p}'];
        yield 'alternation of words' => ['/x/{id<a|b>}', '/x/ab', '/x/ab'];
        yield 'alternation of one word' => ['/x/{id<a|b>}', '/x/b', '/x/{id}'];
        yield 'param next to a literal' => ['/p/{a<\\d+>}-{b}', '/p/12-x', '/p/{a}-{b}'];
        yield 'exact repetition is not counted' => ['/x/{p<\\d{4}-\\d{2}>}', '/x/2026-10', '/x/{p}'];
        yield 'two variable quantifiers' => ['/x/{p<a?b?c>}', '/x/abc', '/x/{p}'];
        yield 'dot atom' => ['/x/{p<.>}', '/x/z', '/x/{p}'];
        yield 'escaped punctuation' => ['/x/{p<a\\.b>}', '/x/a.b', '/x/{p}'];
        yield 'backslash escape of backslash' => ['/x/{p<\\\\>}', '/x/\\', '/x/{p}'];
        yield 'bounded repetition up to 100' => ['/x/{p<a{1,100}>}', '/x/'.str_repeat('a', 100), '/x/{p}'];
        yield 'constraint of 100 characters' => ['/x/{p<'.str_repeat('a', 100).'>}', '/x/'.str_repeat('a', 100), '/x/{p}'];
        yield 'zero repetition' => ['/x/{p<a{0}b>}', '/x/b', '/x/{p}'];
        yield 'two bounded equal quantifiers' => ['/x/{p<a{1,1}b{1,1}>}', '/x/ab', '/x/{p}'];
    }

    #[DataProvider('acceptedConstraints')]
    public function test_grammar_accepted_constraint_is_enforced(string $route, string $path, string $expected): void
    {
        $this->assertSame($expected, (new RouteRedaction([$route]))->path($path));
    }

    /** @return iterable<string,array{string,string}> */
    public static function rejectedConstraints(): iterable
    {
        yield 'empty constraint' => ['/x/{p<>}', '/x/abc'];
        yield 'empty branch after alternation' => ['/x/{p<fr|>}', '/x/fr'];
        yield 'empty branch before alternation' => ['/x/{p<|fr>}', '/x/fr'];
        yield 'constraint of 101 characters' => ['/x/{p<'.str_repeat('a', 101).'>}', '/x/'.str_repeat('a', 101)];
        yield 'non ascii literal' => ['/x/{p<é>}', '/x/é'];
        yield 'capturing group' => ['/x/{p<(a)>}', '/x/a'];
        yield 'nested quantifier group' => ['/x/{p<(a|a)*>}', '/x/aa'];
        yield 'nested plus group' => ['/x/{p<((a)+)+>}', '/x/aaa'];
        yield 'negative lookahead' => ['/x/{p<(?!x)a>}', '/x/a'];
        yield 'positive lookbehind' => ['/x/{p<(?<=a)b>}', '/x/b'];
        yield 'backreference' => ['/x/{p<(a)\\1>}', '/x/aa'];
        yield 'digit escape' => ['/x/{p<\\1>}', '/x/1'];
        yield 'letter escape outside the list' => ['/x/{p<\\b>}', '/x/abc'];
        yield 'slash literal' => ['/x/{p<a/b>}', '/x/abc'];
        yield 'start anchor' => ['/x/{p<^\\d+>}', '/x/12'];
        yield 'end anchor' => ['/x/{p<\\d+$>}', '/x/12'];
        yield 'unclosed class' => ['/x/{p<[>}', '/x/abc'];
        yield 'empty class' => ['/x/{p<[]>}', '/x/abc'];
        yield 'reversed range' => ['/x/{p<[z-a]>}', '/x/m'];
        yield 'hyphen after class escape' => ['/x/{p<[\\d-z]>}', '/x/1'];
        yield 'nested class' => ['/x/{p<[a[b]>}', '/x/a'];
        yield 'empty class followed by a class' => ['/x/{p<[][a]>}', '/x/a'];
        yield 'octal escape' => ['/x/{p<\\0>}', '/x/%00'];
        yield 'repetition above 100' => ['/x/{p<a{101}>}', '/x/'.str_repeat('a', 101)];
        yield 'reversed bounds' => ['/x/{p<a{3,2}>}', '/x/aaa'];
        yield 'missing bound' => ['/x/{p<a{,3}>}', '/x/aa'];
        yield 'lazy quantifier' => ['/x/{p<a*?>}', '/x/aaa'];
        yield 'possessive quantifier' => ['/x/{p<a*+>}', '/x/aaa'];
        yield 'two quantifiers in a row' => ['/x/{p<a**>}', '/x/aaa'];
        yield 'three variable quantifiers' => ['/x/{p<a*b*c*>}', '/x/abc'];
        yield 'quantifier without atom' => ['/x/{p<*a>}', '/x/a'];
        yield 'constraint of 101 characters with a class' => ['/x/{p<[a-z]'.str_repeat('a', 96).'>}', '/x/abc'];
        yield 'three bounded equal quantifiers' => ['/x/{p<a{1,1}b{1,1}c{1,1}>}', '/x/abc'];
        yield 'angle bracket literal' => ['/x/{p<a<b>}', '/x/a<b'];
        yield 'angle bracket inside a class' => ['/x/{p<[<]>}', '/x/<'];
        yield 'dash as the end of a class range' => ['/x/{p<[!--]>}', '/x/!'];
        yield 'dash as the whole class range' => ['/x/{p<[--]>}', '/x/-'];
        yield 'dash starting a range inside a class' => ['/x/{p<[--a]>}', '/x/a'];
        yield 'class range ending with a class escape' => ['/x/{p<[a-\\d]>}', '/x/a'];
        yield 'class range ending with a forbidden literal' => ['/x/{p<[!-<]>}', '/x/!'];
        yield 'class range spanning a slash' => ['/x/{p<[!-~]>}', '/x/a'];
        yield 'dash between two ranges' => ['/x/{p<[a-b-c]>}', '/x/-'];
        yield 'stray brace after a bracket param' => ['/x/{id<[>]>}', '/x/anything'];
        yield 'unclosed brace without constraint' => ['/x/{id', '/x/anything'];
    }

    #[DataProvider('rejectedConstraints')]
    public function test_grammar_rejected_constraint_masks_a_matching_path(string $route, string $path): void
    {
        $this->assertSame('/[redacted]', (new RouteRedaction([$route]))->path($path));
    }

    public function test_rejected_constraint_leaves_other_shapes_raw(): void
    {
        $this->assertSame('/y/a', (new RouteRedaction(['/x/{p<(a)>}']))->path('/y/a'));
    }

    public function test_rejected_constraint_does_not_split_on_a_slash_inside_brackets(): void
    {
        $this->assertSame('/x/a/b', (new RouteRedaction(['/x/{p<a/b>}']))->path('/x/a/b'));
    }

    public function test_segment_longer_than_256_characters_masks_the_path(): void
    {
        $redaction = new RouteRedaction(['/x/{p<\\w+>}']);
        $this->assertSame('/x/{p}', $redaction->path('/x/'.str_repeat('a', 256)));
        $this->assertSame('/[redacted]', $redaction->path('/x/'.str_repeat('a', 257)));
    }

    public function test_grammar_worst_case_match_is_bounded(): void
    {
        $redaction = new RouteRedaction(['/x/{p<\\w*\\w*!>}', '/x/{q<a*a+!>}']);
        $started = hrtime(true);
        $raw = $redaction->path('/x/'.str_repeat('a', 128).'!'.str_repeat('a', 127));
        $matched = $redaction->path('/x/'.str_repeat('a', 255).'!');
        $elapsed = (hrtime(true) - $started) / 1e6;
        $this->assertSame('/x/'.str_repeat('a', 128).'!'.str_repeat('a', 127), $raw);
        $this->assertSame('/x/{p}', $matched);
        $this->assertLessThan(50.0, $elapsed);
    }

    public function test_optional_constrained_segment_is_checked(): void
    {
        $redaction = new RouteRedaction(['/blog/{page<\\d+>?}']);
        $this->assertSame('/blog/{page}', $redaction->path('/blog/7'));
        $this->assertSame('/blog/x', $redaction->path('/blog/x'));
    }

    public function test_dot_matches_a_line_feed_as_the_browser_does(): void
    {
        $this->assertSame('/x/{id}', (new RouteRedaction(['/x/{id<.>}']))->path('/x/%0A'));
    }

    public function test_negated_atoms_never_cross_a_slash(): void
    {
        $redaction = new RouteRedaction(['/x/{a<\\D+>}/end', '/y/{b<[^a]+>}/end', '/z/{c<\\W+>}/end']);
        $this->assertSame('/x/a/b/end', $redaction->path('/x/a/b/end'));
        $this->assertSame('/y/a/b/end', $redaction->path('/y/a/b/end'));
        $this->assertSame('/z/a/b/end', $redaction->path('/z/a/b/end'));
    }

    public function test_stray_brace_masks_the_matching_path(): void
    {
        $this->assertSame('/[redacted]', (new RouteRedaction(['/x/{a<b/c}']))->path('/x/zzz'));
    }

    public function test_unusable_route_masks_its_whole_shape(): void
    {
        $redaction = new RouteRedaction(['/x/{a<[>}/{b<\\d+>}']);
        $this->assertSame('/[redacted]', $redaction->path('/x/zz/abc'));
        $this->assertSame('/[redacted]', $redaction->path('/x/zz/12'));
        $this->assertSame('/x/zz', $redaction->path('/x/zz'));
    }

    /** @return iterable<string,array{string,string,string}> */
    public static function parityProbes(): iterable
    {
        yield 'first greedy capture does not decide' => ['/x/{p<\\d+>}x{q}', '/x/1x2x3', '/x/{p}x{q}'];
        yield 'dot covers a line feed byte' => ['/x/{p<.{3}>}', '/x/a%0Ab', '/x/{p}'];
        yield 'dot counts bytes, not characters' => ['/x/{p<.{2}>}', '/x/%C3%A9', '/x/{p}'];
        yield 'four byte character is not two dots' => ['/x/{p<.{2}>}', '/x/%F0%9F%99%82', '/x/%F0%9F%99%82'];
        yield 'nbsp is two bytes under dot' => ['/x/{p<.{2}>}', '/x/%C2%A0', '/x/{p}'];
        yield 'negated class counts bytes' => ['/x/{p<[^a]{2}>}', '/x/%C3%A9', '/x/{p}'];
        yield 'nbsp is not whitespace' => ['/x/{p<\\s>}', '/x/%C2%A0', '/x/%C2%A0'];
        yield 'space is whitespace' => ['/x/{p<\\s>}', '/x/%20', '/x/{p}'];
        yield 'negated word class counts bytes' => ['/x/{p<\\W{2}>}', '/x/%C3%A9', '/x/{p}'];
        yield 'single dot does not match a two byte character' => ['/x/{p<.>}', '/x/%C3%A9', '/x/%C3%A9'];
        yield 'utf8 literal does not fold to its uppercase' => ["/x/\u{e9}", '/x/%C3%89', '/x/%C3%89'];
        yield 'utf8 literal matches itself' => ["/x/\u{e9}", '/x/%C3%A9', "/x/\u{e9}"];
        yield 'utf8 literal does not fold to a neighbour' => ["/x/\u{c3}", '/x/%C3%A3', '/x/%C3%A3'];
        yield 'ascii literal still folds' => ['/x/a', '/x/A', '/x/a'];
        yield 'constraint of a dash range ending on a dash' => ['/x/{p<[!--]+>}', '/x/a', '/[redacted]'];
        yield 'constraint range spanning a slash' => ['/x/{p<[ -~]+>}', '/x/a', '/[redacted]'];
        yield 'constraint range spanning a slash in a negated class' => ['/x/{p<[!-~]+>}', '/x/a', '/[redacted]'];
        yield 'constraint with a non ascii byte range' => ['/x/{p<[ -~]+>}', '/x/%C3%A9', '/[redacted]'];
        yield 'brace in the remainder after a constraint' => ['/x/{p<a>b>}', '/x/ab', '/[redacted]'];
        yield 'angle bracket after an opening angle bracket' => ['/x/{p<<a>}', '/x/a', '/[redacted]'];
        yield 'slash inside a class' => ['/x/{p<[^/]>}', '/x/a', '/[redacted]'];
        yield 'constraint with an escaped slash' => ['/x/{p<\\/>}', '/x/a', '/[redacted]'];
        yield 'counted repetition with reversed bounds' => ['/x/{p<a{3,2}>}', '/x/aaa', '/[redacted]'];
        yield 'exact repetition is not counted' => ['/x/{p<a{3}>}', '/x/aaa', '/x/{p}'];
        yield 'exact repetitions are not counted across the route' => ['/x/{p<a{3}b{3}c{3}>}', '/x/aaabbbccc', '/x/{p}'];
        yield 'two route variables are usable' => ['/x/{a<\\d+>}/{b<\\d+>}', '/x/1/2', '/x/{a}/{b}'];
        yield 'three route variables make the route unusable' => ['/x/{a<\\d+>}/{b<\\d+>}/{c<\\d+>}', '/x/1/2/3', '/[redacted]'];
        yield 'three route variables leave a shorter path raw' => ['/x/{a<\\d+>}/{b<\\d+>}/{c<\\d+>}', '/x/1/2', '/x/1/2'];
        yield 'two constrained params in one segment' => ['/x/{a<\\d>}{b<\\d>}', '/x/12', '/[redacted]'];
        yield 'three unconstrained params in one segment' => ['/x/{a}{b}{c}', '/x/abc', '/[redacted]'];
        yield 'six unconstrained params in one segment' => ['/x/{a}{b}{c}{d}{e}{f}', '/x/abcdef', '/[redacted]'];
        yield 'four adjacent constrained params' => ['/x/{a<a*>}{b<a*>}{c<a*>}{d<a*>}', '/x/aaaa', '/[redacted]'];
        yield 'two params in a segment are fine' => ['/x/{a}/{b}', '/x/a/b', '/x/{a}/{b}'];
    }

    #[DataProvider('parityProbes')]
    public function test_parity_probe_matches_the_browser_sdk(string $route, string $path, string $expected): void
    {
        $this->assertSame($expected, (new RouteRedaction([$route]))->path($path));
    }

    /** @return iterable<string,array{string,string}> */
    public static function oneBadConstraintAmongGoodOnes(): iterable
    {
        yield 'reversed range next to a digit' => ['/x/{a<\\d+>}/{b<[z-a]>}', '/x/1/m'];
        yield 'hyphen after a class escape next to a digit' => ['/x/{a<\\d+>}/{b<[\\d-z]>}', '/x/1/a'];
        yield 'reversed counted repetition next to a digit' => ['/x/{a<\\d+>}/{b<a{3,2}>}', '/x/1/aaa'];
        yield 'escaped slash next to a digit' => ['/x/{a<\\d+>}/{b<\\/>}', '/x/1/a'];
        yield 'tab next to a digit' => ["/x/{a<\\d+>}/{b<a\tb>}", '/x/1/a'];
        yield 'control character U+0001 next to a digit' => ["/x/{a<\\d+>}/{b<a\x01b>}", '/x/1/a'];
        yield 'space range spanning a slash next to a digit' => ['/x/{a<\\d+>}/{b<[ -~]+>}', '/x/1/a'];
    }

    #[DataProvider('oneBadConstraintAmongGoodOnes')]
    public function test_one_bad_constraint_makes_the_whole_route_unusable(string $route, string $path): void
    {
        $this->assertSame('/[redacted]', (new RouteRedaction([$route]))->path($path));
    }


    public function test_unusable_segment_with_two_params_folds_to_one_joker(): void
    {
        $redaction = new RouteRedaction(['/x/{a<[>}{b}']);
        $this->assertSame('/[redacted]', $redaction->path('/x/a'));
        $this->assertSame('/[redacted]', $redaction->path('/x/ab'));
    }

    public function test_segment_limit_counts_bytes_not_characters(): void
    {
        $redaction = new RouteRedaction(['/x/{p<.+>}']);
        $this->assertSame('/x/{p}', $redaction->path('/x/'.str_repeat('é', 128)));
        $this->assertSame('/[redacted]', $redaction->path('/x/'.str_repeat('é', 129)));
    }

    public function test_setlocale_does_not_change_the_result(): void
    {
        if (setlocale(LC_CTYPE, 'fr_FR.UTF-8', 'C.UTF-8') === false) {
            $this->markTestSkipped('Aucune locale UTF-8 disponible.');
        }
        try {
            $this->assertSame('/x/{p}', (new RouteRedaction(['/x/{p<.{2}>}']))->path('/x/%C3%A9'));
            $this->assertSame('/x/%C2%A0', (new RouteRedaction(['/x/{p<\\s>}']))->path('/x/%C2%A0'));
            $this->assertSame('/x/%C3%89', (new RouteRedaction(["/x/\u{e9}"]))->path('/x/%C3%89'));
            $this->assertSame('/x/{p}', (new RouteRedaction(['/x/{p<\\d+>}']))->path('/x/12'));
        } finally {
            setlocale(LC_CTYPE, 'C');
        }
    }


    /** @return iterable<string,array{string,string}> */
    public static function ambiguousRoutes(): iterable
    {
        yield 'two segments with two variable quantifiers each' => ['/{a}{b}/{c}{d}', '/ab/cd'];
        yield 'constrained segment with two quantifiers next to a segment with two' => ['/{p<a*a*>}/{q}{r}', '/aa/xy'];
    }

    #[DataProvider('ambiguousRoutes')]
    public function test_ambiguity_degree_above_one_masks_the_route_shape(string $route, string $path): void
    {
        $this->assertSame('/[redacted]', (new RouteRedaction([$route]))->path($path));
    }

    public function test_ambiguity_degree_of_one_is_accepted(): void
    {
        $this->assertSame('/{a}{b}/{c}', (new RouteRedaction(['/{a}{b}/{c}']))->path('/ab/c'));
    }

    public function test_lone_undecodable_byte_is_one_byte_in_tolerant_mode(): void
    {
        $this->assertSame('/x/{p}', (new RouteRedaction(['/x/{p<.>}'], null, false))->path('/x/%FF'));
        $this->assertSame('/[redacted]', (new RouteRedaction(['/x/{p<.>}']))->path('/x/%FF'));
    }

    /** @return iterable<string,array{string,string}> */
    public static function routesWithTwoRestSegments(): iterable
    {
        yield 'two double stars' => ['/a/**/b/**', '/a/1/b/2'];
        yield 'named stars around a literal' => ['/*x/a/*y/b', '/1/a/2/b'];
        yield 'rest parameter and a plus-rest' => ['/a/[...rest]/b/:tail*', '/a/1/b/2'];
    }

    #[DataProvider('routesWithTwoRestSegments')]
    public function test_route_with_two_rest_segments_masks_its_shape(string $route, string $path): void
    {
        $this->assertSame('/[redacted]', (new RouteRedaction([$route]))->path($path));
    }

    public function test_route_with_one_rest_segment_is_accepted(): void
    {
        $this->assertSame('/a/*/b', (new RouteRedaction(['/a/**/b']))->path('/a/1/2/b'));
    }

    public function test_optional_parameter_counts_one_more_variable_in_the_degree(): void
    {
        $this->assertSame('/[redacted]', (new RouteRedaction(['/{a?}/{b}{c}']))->path('/1/23'));
        $this->assertSame('/{a}/{b}', (new RouteRedaction(['/{a?}/{b}']))->path('/1/2'));
    }

    public function test_overlong_segment_masks_even_when_the_constraint_breaks(): void
    {
        $redaction = new RouteRedaction(['/x/{p<\\d+>}']);
        $this->assertSame('/[redacted]', $redaction->path('/x/'.str_repeat('a', 257)));
        $this->assertSame('/x/'.str_repeat('a', 256), $redaction->path('/x/'.str_repeat('a', 256)));
    }

    public function test_optional_brace_constraint_is_enforced(): void
    {
        $redaction = new RouteRedaction(['/x/{id?<\\d>}']);
        $this->assertSame('/x/{id}', $redaction->path('/x/7'));
        $this->assertSame('/x/abc', $redaction->path('/x/abc'));
    }

    private static function encoded(string $path, int $times): string
    {
        for ($i = 0; $i < $times; $i++) {
            $path = rawurlencode($path);
        }

        return $path;
    }

    public function test_dot_segments_are_removed_before_matching(): void
    {
        $redaction = new RouteRedaction(['/admin/[x]']);
        $this->assertSame('/admin/[x]', $redaction->path('/orders/%2e%2e/admin/secret'));
        $this->assertSame('/admin/[x]', $redaction->path('/admin/x/../../admin/secret'));
    }

    public function test_page_keeps_a_non_default_port(): void
    {
        $redaction = new RouteRedaction(['/verify/[token]']);
        $this->assertSame('https://localhost:8000/verify/[token]', $redaction->page('https://localhost:8000/verify/abc'));
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
        $this->assertSame('/blog/{page<\\d+>}', RouteRedaction::browserPattern('/blog/{page<\\d+>}'));
        $this->assertSame('/reset/:code', RouteRedaction::browserPattern('/reset/:code'));
    }
}
