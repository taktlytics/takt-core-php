<?php

namespace Vskstudio\Takt\Tests;

use PHPUnit\Framework\TestCase;
use Vskstudio\Takt\Mode;
use Vskstudio\Takt\Options;

final class OptionsTest extends TestCase
{
    public function test_defaults(): void
    {
        $o = new Options(domain: 'example.com');
        $this->assertSame('https://taktlytics.com/api/event', $o->endpoint);
        $this->assertSame(Options::HOSTED_ENDPOINT, $o->endpoint);
        $this->assertFalse($o->outbound);
        $this->assertFalse($o->files);
        $this->assertTrue($o->excludeLocalhost);
        $this->assertNull($o->nonce);
        $this->assertNull($o->scriptOrigin);
        $this->assertSame(Mode::Inline, $o->mode);
        $this->assertFalse($o->notFound);
        $this->assertFalse($o->tagged);
        $this->assertSame([], $o->fileExtensions);
        $this->assertNull($o->sampleRate);
        $this->assertNull($o->trackQuery);
        $this->assertSame([], $o->queryParams);
        $this->assertNull($o->respectDnt);
        $this->assertNull($o->enabled);
        $this->assertNull($o->scrubUrl);
        $this->assertSame([], $o->redactRoutes);
        $this->assertFalse($o->routeTemplates);
        $this->assertNull($o->routeTemplate);
    }

    public function test_from_array_reads_route_options_in_both_casings(): void
    {
        $camel = Options::fromArray([
            'domain' => 'a.com',
            'redactRoutes' => ['/verify/[token]', ' ', '/reset/:code'],
            'routeTemplates' => true,
            'routeTemplate' => '/verify/{token}',
        ]);
        $this->assertSame(['/verify/[token]', '/reset/:code'], $camel->redactRoutes);
        $this->assertTrue($camel->routeTemplates);
        $this->assertSame('/verify/{token}', $camel->routeTemplate);

        $snake = Options::fromArray([
            'domain' => 'a.com',
            'redact_routes' => ['/verify/{token}'],
            'route_templates' => true,
            'route_template' => '/verify/{token}',
        ]);
        $this->assertSame(['/verify/{token}'], $snake->redactRoutes);
        $this->assertTrue($snake->routeTemplates);
        $this->assertSame('/verify/{token}', $snake->routeTemplate);
    }

    public function test_with_route_template_returns_a_copy(): void
    {
        $base = new Options(domain: 'a.com', mode: Mode::Sdk, routeTemplates: true, redactRoutes: ['/a/[b]']);
        $copy = $base->withRouteTemplate('/users/{id}');
        $this->assertNull($base->routeTemplate);
        $this->assertSame('/users/{id}', $copy->routeTemplate);
        $this->assertSame(Mode::Sdk, $copy->mode);
        $this->assertSame(['/a/[b]'], $copy->redactRoutes);
        $this->assertTrue($copy->routeTemplates);
    }

    public function test_from_array_overrides(): void
    {
        $o = Options::fromArray(['domain' => 'a.com', 'outbound' => true, 'mode' => 'cdn']);
        $this->assertTrue($o->outbound);
        $this->assertSame(Mode::Cdn, $o->mode);
    }

    public function test_from_array_parses_autocapture_options(): void
    {
        $o = Options::fromArray([
            'domain' => 'a.com',
            'not_found' => true,
            'tagged' => true,
            'file_extensions' => ['pdf', ' docx ', '', 7],
        ]);
        $this->assertTrue($o->notFound);
        $this->assertTrue($o->tagged);
        $this->assertSame(['pdf', 'docx', '7'], $o->fileExtensions);
    }

    public function test_from_array_reads_snake_and_camel_keys(): void
    {
        $snake = Options::fromArray(['domain' => 'a.com', 'exclude_localhost' => false, 'not_found' => true]);
        $this->assertFalse($snake->excludeLocalhost);
        $this->assertTrue($snake->notFound);

        $camel = Options::fromArray(['domain' => 'a.com', 'fileExtensions' => ['zip']]);
        $this->assertSame(['zip'], $camel->fileExtensions);
    }

    public function test_from_array_defaults_endpoint_to_hosted_origin(): void
    {
        $o = Options::fromArray(['domain' => 'a.com']);
        $this->assertSame(Options::HOSTED_ENDPOINT, $o->endpoint);
    }

    public function test_from_array_keeps_same_origin_proxy_override(): void
    {
        $o = Options::fromArray(['domain' => 'a.com', 'endpoint' => '/api/event']);
        $this->assertSame('/api/event', $o->endpoint);
    }

    public function test_from_array_parses_script_origin(): void
    {
        $o = Options::fromArray(['domain' => 'a.com', 'scriptOrigin' => 'https://t.a.com']);
        $this->assertSame('https://t.a.com', $o->scriptOrigin);
    }

    public function test_from_array_parses_advanced_options_snake_case(): void
    {
        $o = Options::fromArray([
            'domain' => 'a.com',
            'sample_rate' => '0.25',
            'track_query' => true,
            'query_params' => ['utm_source', ' utm_medium ', '', 9],
            'respect_dnt' => false,
            'enabled' => false,
            'scrub_url' => '(u)=>u.split("?")[0]',
            'exclude' => ['/app', ' /account ', '', 5],
        ]);
        $this->assertSame(0.25, $o->sampleRate);
        $this->assertTrue($o->trackQuery);
        $this->assertSame(['utm_source', 'utm_medium', '9'], $o->queryParams);
        $this->assertSame(['/app', '/account', '5'], $o->exclude);
        $this->assertFalse($o->respectDnt);
        $this->assertFalse($o->enabled);
        $this->assertSame('(u)=>u.split("?")[0]', $o->scrubUrl);
    }

    public function test_from_array_parses_advanced_options_camel_case(): void
    {
        $o = Options::fromArray([
            'domain' => 'a.com',
            'sampleRate' => 0.5,
            'trackQuery' => false,
            'queryParams' => ['ref'],
            'respectDnt' => true,
            'enabled' => true,
        ]);
        $this->assertSame(0.5, $o->sampleRate);
        $this->assertFalse($o->trackQuery);
        $this->assertSame(['ref'], $o->queryParams);
        $this->assertTrue($o->respectDnt);
        $this->assertTrue($o->enabled);
        $this->assertNull($o->scrubUrl);
    }

    public function test_from_array_parses_sdk_mode(): void
    {
        $o = Options::fromArray(['domain' => 'a.com', 'mode' => 'sdk']);
        $this->assertSame(Mode::Sdk, $o->mode);
    }
}
