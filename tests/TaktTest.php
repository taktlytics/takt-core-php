<?php

namespace Vskstudio\Takt\Tests;

use Http\Mock\Client;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Vskstudio\Takt\Options;
use Vskstudio\Takt\Revenue;
use Vskstudio\Takt\Takt;

final class TaktTest extends TestCase
{
    private function makeClient(Client $mock): Takt
    {
        $psr17 = new Psr17Factory();
        return new Takt(
            endpoint: 'https://takt.example.com',
            domain: 'example.com',
            apiKey: 'k_test',
            httpClient: $mock,
            requestFactory: $psr17,
            streamFactory: $psr17,
        );
    }

    public function test_event_posts_raw_payload_with_bearer(): void
    {
        $mock = new Client();
        $mock->addResponse(new Response(202));
        $takt = $this->makeClient($mock);

        $takt->event('Signup', ['plan' => 'pro'], new Revenue('29.00', 'EUR'), 'https://example.com/p');

        $req = $mock->getLastRequest();
        $this->assertSame('POST', $req->getMethod());
        $this->assertSame('https://takt.example.com/api/event', (string) $req->getUri());
        $this->assertSame('Bearer k_test', $req->getHeaderLine('Authorization'));
        $body = (array) json_decode((string) $req->getBody(), true);
        $this->assertSame('Signup', $body['n']);
        $this->assertSame('example.com', $body['d']);
        $this->assertSame('https://example.com/p', $body['u']);
        $this->assertSame(['plan' => 'pro'], $body['p']);
        $this->assertSame(['a' => '29.00', 'c' => 'EUR'], $body['$']);
        $this->assertSame('', $body['r']);
        $this->assertArrayNotHasKey('w', $body);
    }

    public function test_referrer_is_forwarded_when_provided(): void
    {
        $mock = new Client();
        $mock->addResponse(new Response(202));
        $takt = $this->makeClient($mock);

        $takt->event('Signup', [], null, 'https://example.com/p', 'https://news.example/');

        $body = (array) json_decode((string) $mock->getLastRequest()->getBody(), true);
        $this->assertSame('https://news.example/', $body['r']);
    }

    public function test_forwards_ip_and_user_agent(): void
    {
        $mock = new Client();
        $mock->addResponse(new Response(202));
        $takt = $this->makeClient($mock)->withVisitor('203.0.113.7', 'Mozilla/5.0');
        $takt->pageview('https://example.com/');

        $req = $mock->getLastRequest();
        $this->assertSame('203.0.113.7', $req->getHeaderLine('X-Forwarded-For'));
        $this->assertSame('Mozilla/5.0', $req->getHeaderLine('User-Agent'));
    }

    public function test_strips_crlf_from_forwarded_headers(): void
    {
        $mock = new Client();
        $mock->addResponse(new Response(202));
        $takt = $this->makeClient($mock)->withVisitor(
            "203.0.113.7\r\nX-Injected: 1",
            "Evil\r\nSet-Cookie: a=b",
        );
        $takt->pageview('https://example.com/');

        $req = $mock->getLastRequest();
        $this->assertSame('203.0.113.7X-Injected: 1', $req->getHeaderLine('X-Forwarded-For'));
        $this->assertSame('EvilSet-Cookie: a=b', $req->getHeaderLine('User-Agent'));
        $this->assertFalse($req->hasHeader('X-Injected'));
        $this->assertFalse($req->hasHeader('Set-Cookie'));
    }

    public function test_strict_mode_throws_on_non_202(): void
    {
        $mock = new Client();
        $mock->addResponse(new Response(401));
        $takt = $this->makeClient($mock)->strict();

        $this->expectException(\RuntimeException::class);
        $takt->event('X');
    }

    public function test_default_mode_swallows_errors(): void
    {
        $mock = new Client();
        $mock->addException(new \RuntimeException('network down'));
        $takt = $this->makeClient($mock);

        $takt->event('X');
        $this->assertTrue(true);
    }

    public function test_non_string_props_are_coerced(): void
    {
        $mock = new \Http\Mock\Client();
        $mock->addResponse(new \Nyholm\Psr7\Response(202));
        $takt = $this->makeClient($mock);
        $takt->event('Buy', ['count' => 3, 'paid' => true]);
        $body = (array) json_decode((string) $mock->getLastRequest()->getBody(), true);
        $this->assertSame(['count' => '3', 'paid' => '1'], $body['p']);
    }

    public function test_url_falls_back_to_the_site_home_when_omitted(): void
    {
        $mock = new Client();
        $mock->addResponse(new Response(202));
        $takt = $this->makeClient($mock);

        $takt->event('Signup');

        $body = (array) json_decode((string) $mock->getLastRequest()->getBody(), true);
        $this->assertSame('https://example.com/', $body['u']);
    }

    public function test_blank_url_falls_back_to_the_site_home(): void
    {
        $mock = new Client();
        $mock->addResponse(new Response(202));
        $takt = $this->makeClient($mock);

        $takt->event('Signup', [], null, '   ');

        $body = (array) json_decode((string) $mock->getLastRequest()->getBody(), true);
        $this->assertSame('https://example.com/', $body['u']);
    }

    public function test_domain_already_carrying_a_scheme_is_not_prefixed_twice(): void
    {
        $psr17 = new Psr17Factory();
        $mock = new Client();
        $mock->addResponse(new Response(202));
        $takt = new Takt(
            endpoint: 'https://takt.example.com',
            domain: 'https://example.com',
            httpClient: $mock,
            requestFactory: $psr17,
            streamFactory: $psr17,
        );

        $takt->event('Signup');

        $body = (array) json_decode((string) $mock->getLastRequest()->getBody(), true);
        $this->assertSame('https://example.com/', $body['u']);
    }

    public function test_referrer_stays_empty_when_omitted(): void
    {
        $mock = new Client();
        $mock->addResponse(new Response(202));
        $takt = $this->makeClient($mock);

        $takt->event('Signup');

        $body = (array) json_decode((string) $mock->getLastRequest()->getBody(), true);
        $this->assertSame('', $body['r']);
    }

    /**
     * @return iterable<string,array{string,string}>
     */
    public static function endpointForms(): iterable
    {
        yield 'origin' => ['https://takt.example.com', 'https://takt.example.com/api/event'];
        yield 'origin with trailing slash' => ['https://takt.example.com/', 'https://takt.example.com/api/event'];
        yield 'full collect url' => ['https://takt.example.com/api/event', 'https://takt.example.com/api/event'];
        yield 'full collect url with trailing slash' => ['https://takt.example.com/api/event/', 'https://takt.example.com/api/event'];
        yield 'hosted origin' => [Options::HOSTED_ORIGIN, Options::HOSTED_ENDPOINT];
        yield 'hosted endpoint' => [Options::HOSTED_ENDPOINT, Options::HOSTED_ENDPOINT];
    }

    #[DataProvider('endpointForms')]
    public function test_endpoint_accepts_both_origin_and_full_collect_url(string $endpoint, string $expected): void
    {
        $psr17 = new Psr17Factory();
        $mock = new Client();
        $mock->addResponse(new Response(202));
        $takt = new Takt(
            endpoint: $endpoint,
            domain: 'example.com',
            httpClient: $mock,
            requestFactory: $psr17,
            streamFactory: $psr17,
        );

        $takt->pageview('https://example.com/');

        $this->assertSame($expected, (string) $mock->getLastRequest()->getUri());
    }

    /** @param list<string> $redactRoutes */
    private function makeRedactingClient(Client $mock, array $redactRoutes): Takt
    {
        $psr17 = new Psr17Factory();

        return new Takt(
            endpoint: 'https://takt.example.com',
            domain: 'example.com',
            httpClient: $mock,
            requestFactory: $psr17,
            streamFactory: $psr17,
            redactRoutes: $redactRoutes,
        );
    }

    /** @return array<mixed> */
    private static function lastBody(Client $mock): array
    {
        return (array) json_decode((string) $mock->getLastRequest()->getBody(), true);
    }

    public function test_redact_routes_rewrites_url_and_same_origin_referrer(): void
    {
        $mock = new Client();
        $mock->addResponse(new Response(202));
        $this->makeRedactingClient($mock, ['/verify/{token}'])
            ->pageview('https://example.com/verify/abc?x=1', 'https://example.com/verify/old');

        $body = self::lastBody($mock);
        $this->assertSame('https://example.com/verify/{token}?x=1', $body['u']);
        $this->assertSame('https://example.com/verify/{token}', $body['r']);
    }

    public function test_redact_routes_keeps_cross_origin_referrer_and_other_paths(): void
    {
        $mock = new Client();
        $mock->addResponse(new Response(202));
        $this->makeRedactingClient($mock, ['/verify/[token]'])
            ->event('Signup', [], null, 'https://example.com/pricing', 'https://other.example/verify/abc');

        $body = self::lastBody($mock);
        $this->assertSame('https://example.com/pricing', $body['u']);
        $this->assertSame('https://other.example/verify/abc', $body['r']);
    }

    public function test_route_argument_replaces_the_path_of_one_call(): void
    {
        $mock = new Client();
        $mock->addResponse(new Response(202));
        $mock->addResponse(new Response(202));
        $takt = $this->makeRedactingClient($mock, []);

        $takt->event('Signup', [], null, 'https://example.com/users/42', 'https://example.com/users/41', route: '/users/{id?}');
        $body = self::lastBody($mock);
        $this->assertSame('https://example.com/users/{id}', $body['u']);
        $this->assertSame('https://example.com/', $body['r']);

        $takt->pageview('https://example.com/users/42');
        $this->assertSame('https://example.com/users/42', self::lastBody($mock)['u']);
    }

    public function test_pageview_accepts_a_route(): void
    {
        $mock = new Client();
        $mock->addResponse(new Response(202));
        $this->makeRedactingClient($mock, [])->pageview('https://example.com/blog/hello', route: '/blog/[slug]');

        $this->assertSame('https://example.com/blog/[slug]', self::lastBody($mock)['u']);
    }

    public function test_route_applies_to_the_site_home_fallback(): void
    {
        $mock = new Client();
        $mock->addResponse(new Response(202));
        $this->makeRedactingClient($mock, [])->event('Purchase', route: '/checkout/{order}');

        $this->assertSame('https://example.com/checkout/{order}', self::lastBody($mock)['u']);
    }

    public function test_blank_route_falls_back_to_redact_routes(): void
    {
        $mock = new Client();
        $mock->addResponse(new Response(202));
        $this->makeRedactingClient($mock, ['/verify/[token]'])->pageview('https://example.com/verify/abc', route: '  ');

        $this->assertSame('https://example.com/verify/[token]', self::lastBody($mock)['u']);
    }

    public function test_with_route_sets_a_default_route_that_a_call_can_override(): void
    {
        $mock = new Client();
        $mock->addResponse(new Response(202));
        $mock->addResponse(new Response(202));
        $base = $this->makeRedactingClient($mock, []);
        $takt = $base->withRoute('/users/{id}');

        $takt->pageview('https://example.com/users/42');
        $this->assertSame('https://example.com/users/{id}', self::lastBody($mock)['u']);

        $takt->pageview('https://example.com/users/42', route: '/profile/{id}');
        $this->assertSame('https://example.com/profile/{id}', self::lastBody($mock)['u']);

        $this->assertNotSame($base, $takt);
    }

    public function test_with_route_accepts_a_lazy_resolver(): void
    {
        $mock = new Client();
        $mock->addResponse(new Response(202));
        $mock->addResponse(new Response(202));
        $current = null;
        $takt = $this->makeRedactingClient($mock, [])->withRoute(static function () use (&$current): ?string {
            return $current;
        });

        $takt->pageview('https://example.com/users/42');
        $this->assertSame('https://example.com/users/42', self::lastBody($mock)['u']);

        $current = '/users/{id}';
        $takt->pageview('https://example.com/users/42');
        $this->assertSame('https://example.com/users/{id}', self::lastBody($mock)['u']);
    }

    public function test_a_failing_route_resolver_does_not_break_tracking(): void
    {
        $mock = new Client();
        $mock->addResponse(new Response(202));
        $this->makeRedactingClient($mock, [])
            ->withRoute(static fn (): string => throw new \LogicException('no route'))
            ->pageview('https://example.com/users/42');

        $this->assertSame('https://example.com/users/42', self::lastBody($mock)['u']);
    }
}
