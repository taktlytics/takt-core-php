<?php

namespace Vskstudio\Takt\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Vskstudio\Takt\Mode;
use Vskstudio\Takt\Options;
use Vskstudio\Takt\SnippetRenderer;

final class SnippetRendererTest extends TestCase
{
    public function test_cdn_mode_emits_loader_with_data_attrs(): void
    {
        $html = (new SnippetRenderer(new Options(domain: 'example.com', outbound: true, mode: Mode::Cdn)))->render();
        $this->assertStringContainsString('cdn.jsdelivr.net/npm/@vskstudio/takt-core@0.9.0/dist/takt.auto.js', $html);
        $this->assertStringContainsString('takt.auto.js', $html);
        $this->assertStringContainsString('data-domain="example.com"', $html);
        $this->assertStringContainsString('data-auto="outbound"', $html);
        $this->assertStringNotContainsString('data-files', $html);
        $this->assertStringNotContainsString('data-outbound', $html);
    }

    public function test_inline_mode_embeds_bundle_with_data_attrs(): void
    {
        $html = (new SnippetRenderer(new Options(domain: 'example.com', files: true, mode: Mode::Inline)))->render();
        $this->assertStringContainsString('<script', $html);
        $this->assertStringContainsString('data-domain="example.com"', $html);
        $this->assertStringContainsString('data-auto="downloads"', $html);
        $this->assertStringContainsString('var takt=', $html);
        $this->assertStringNotContainsString(' src=', $html);
    }

    public function test_data_auto_lists_all_enabled_features_in_order(): void
    {
        $html = (new SnippetRenderer(new Options(
            domain: 'example.com',
            outbound: true,
            files: true,
            mode: Mode::Cdn,
            notFound: true,
            tagged: true,
        )))->render();
        $this->assertStringContainsString('data-auto="outbound,downloads,tagged,404"', $html);
    }

    public function test_no_autocapture_omits_data_auto(): void
    {
        $html = (new SnippetRenderer(new Options(domain: 'example.com', mode: Mode::Cdn)))->render();
        $this->assertStringNotContainsString('data-auto', $html);
    }

    public function test_not_found_only_emits_404_token(): void
    {
        $html = (new SnippetRenderer(new Options(domain: 'example.com', mode: Mode::Cdn, notFound: true)))->render();
        $this->assertStringContainsString('data-auto="404"', $html);
    }

    public function test_file_extensions_emit_downloads_ext(): void
    {
        $html = (new SnippetRenderer(new Options(
            domain: 'example.com',
            files: true,
            mode: Mode::Cdn,
            fileExtensions: ['pdf', 'docx'],
        )))->render();
        $this->assertStringContainsString('data-auto="downloads"', $html);
        $this->assertStringContainsString('data-downloads-ext="pdf,docx"', $html);
    }

    public function test_file_extensions_empty_omits_downloads_ext(): void
    {
        $html = (new SnippetRenderer(new Options(domain: 'example.com', files: true, mode: Mode::Cdn)))->render();
        $this->assertStringNotContainsString('data-downloads-ext', $html);
    }

    public function test_nonce_is_applied(): void
    {
        $html = (new SnippetRenderer(new Options(domain: 'example.com', nonce: 'abc123', mode: Mode::Cdn)))->render();
        $this->assertStringContainsString('nonce="abc123"', $html);
    }

    public function test_domain_is_escaped(): void
    {
        $html = (new SnippetRenderer(new Options(domain: 'a"><x', mode: Mode::Cdn)))->render();
        $this->assertStringNotContainsString('a"><x', $html);
    }

    public function test_asset_mode_emits_self_hosted_loader(): void
    {
        $html = (new SnippetRenderer(new Options(domain: 'example.com', mode: Mode::Asset)))->render();
        $this->assertStringContainsString('src="/takt/takt.auto.js"', $html);
        $this->assertStringContainsString('data-domain="example.com"', $html);
    }

    public function test_exclude_localhost_false_emits_attr(): void
    {
        $html = (new SnippetRenderer(new Options(domain: 'example.com', excludeLocalhost: false, mode: Mode::Cdn)))->render();
        $this->assertStringContainsString('data-exclude-localhost="false"', $html);
    }

    public function test_endpoint_attr_is_emitted(): void
    {
        $html = (new SnippetRenderer(new Options(domain: 'example.com', endpoint: '/collect', mode: Mode::Cdn)))->render();
        $this->assertStringContainsString('data-endpoint="/collect"', $html);
    }

    public function test_default_endpoint_targets_hosted_origin(): void
    {
        $html = (new SnippetRenderer(new Options(domain: 'example.com', mode: Mode::Cdn)))->render();
        $this->assertStringContainsString('data-endpoint="https://taktlytics.com/api/event"', $html);
    }

    public function test_same_origin_proxy_endpoint_is_emitted(): void
    {
        $html = (new SnippetRenderer(new Options(domain: 'example.com', endpoint: '/api/event', mode: Mode::Cdn)))->render();
        $this->assertStringContainsString('data-endpoint="/api/event"', $html);
    }

    public function test_sdk_mode_defaults_endpoint_to_hosted_origin(): void
    {
        $html = (new SnippetRenderer(new Options(domain: 'example.com', mode: Mode::Sdk)))->render();
        $this->assertStringContainsString('"endpoint":"https:\/\/taktlytics.com\/api\/event"', $html);
    }

    public function test_script_origin_emits_data_attr(): void
    {
        $html = (new SnippetRenderer(new Options(domain: 'example.com', scriptOrigin: 'https://t.example.com', mode: Mode::Cdn)))->render();
        $this->assertStringContainsString('data-script-origin="https://t.example.com"', $html);
    }

    public function test_script_origin_with_default_endpoint_omits_data_endpoint(): void
    {
        $html = (new SnippetRenderer(new Options(domain: 'example.com', scriptOrigin: 'https://t.example.com', mode: Mode::Cdn)))->render();
        $this->assertStringContainsString('data-script-origin="https://t.example.com"', $html);
        $this->assertStringNotContainsString('data-endpoint', $html);
    }

    public function test_asset_mode_src_uses_script_origin(): void
    {
        $html = (new SnippetRenderer(new Options(domain: 'example.com', scriptOrigin: 'https://t.example.com/', mode: Mode::Asset)))->render();
        $this->assertStringContainsString('src="https://t.example.com/takt/takt.auto.js"', $html);
        $this->assertStringContainsString('data-script-origin="https://t.example.com/"', $html);
    }

    public function test_inline_neutralizes_script_close_case_insensitively(): void
    {
        $this->assertSame('<\\/script>', SnippetRenderer::neutralizeScriptClose('</script>'));
        $this->assertSame('<\\/SCRIPT >', SnippetRenderer::neutralizeScriptClose('</SCRIPT >'));
        $this->assertSame('var takt={};', SnippetRenderer::neutralizeScriptClose('var takt={};'));
    }

    public function test_advanced_options_emit_data_attrs(): void
    {
        $html = (new SnippetRenderer(new Options(
            domain: 'example.com',
            mode: Mode::Cdn,
            sampleRate: 0.5,
            trackQuery: true,
            queryParams: ['utm_source', 'utm_medium'],
            respectDnt: false,
            enabled: false,
        )))->render();
        $this->assertStringContainsString('data-enabled="false"', $html);
        $this->assertStringContainsString('data-respect-dnt="false"', $html);
        $this->assertStringContainsString('data-sample-rate="0.5"', $html);
        $this->assertStringContainsString('data-track-query="true"', $html);
        $this->assertStringContainsString('data-query-params="utm_source,utm_medium"', $html);
    }

    public function test_advanced_option_defaults_are_omitted(): void
    {
        $html = (new SnippetRenderer(new Options(
            domain: 'example.com',
            mode: Mode::Cdn,
            trackQuery: false,
            respectDnt: true,
            enabled: true,
        )))->render();
        $this->assertStringNotContainsString('data-enabled', $html);
        $this->assertStringNotContainsString('data-respect-dnt', $html);
        $this->assertStringNotContainsString('data-sample-rate', $html);
        $this->assertStringNotContainsString('data-track-query', $html);
        $this->assertStringNotContainsString('data-query-params', $html);
    }

    public function test_sample_rate_renders_without_trailing_zero(): void
    {
        $html = (new SnippetRenderer(new Options(domain: 'example.com', mode: Mode::Cdn, sampleRate: 1.0)))->render();
        $this->assertStringContainsString('data-sample-rate="1"', $html);
    }

    public function test_sdk_mode_emits_module_import_and_init(): void
    {
        $html = (new SnippetRenderer(new Options(
            domain: 'example.com',
            mode: Mode::Sdk,
            outbound: true,
            sampleRate: 0.25,
            queryParams: ['ref'],
        )))->render();
        $this->assertStringContainsString('<script type="module"', $html);
        $this->assertStringContainsString('import{init}from', $html);
        $this->assertStringContainsString('@vskstudio\/takt-core@0.9.0\/+esm', $html);
        $this->assertStringContainsString('init({', $html);
        $this->assertStringContainsString('"domain":"example.com"', $html);
        $this->assertStringContainsString('"sampleRate":0.25', $html);
        $this->assertStringContainsString('"queryParams":["ref"]', $html);
        $this->assertStringContainsString('"outbound":true', $html);
        $this->assertStringNotContainsString(' src=', $html);
    }

    public function test_sdk_mode_uses_script_origin_esm_path(): void
    {
        $html = (new SnippetRenderer(new Options(domain: 'example.com', scriptOrigin: 'https://t.example.com/', mode: Mode::Sdk)))->render();
        $this->assertStringContainsString('t.example.com\/takt\/takt.esm.js', $html);
        $this->assertStringContainsString('"scriptOrigin":"https:\/\/t.example.com\/"', $html);
    }

    public function test_sdk_mode_grafts_scrub_url_as_raw_js(): void
    {
        $html = (new SnippetRenderer(new Options(
            domain: 'example.com',
            mode: Mode::Sdk,
            scrubUrl: '(u)=>u.split("#")[0]',
        )))->render();
        $this->assertStringContainsString('Object.assign({', $html);
        $this->assertStringContainsString('scrubUrl:(u)=>u.split("#")[0]', $html);
    }

    public function test_scrub_url_outside_sdk_mode_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SnippetRenderer(new Options(domain: 'example.com', mode: Mode::Inline, scrubUrl: '(u)=>u'));
    }

    public function test_sdk_mode_emits_exclude_as_json_array(): void
    {
        $html = (new SnippetRenderer(new Options(
            domain: 'example.com',
            mode: Mode::Sdk,
            exclude: ['/app', '/account'],
        )))->render();
        $this->assertStringContainsString('"exclude":["\/app","\/account"]', $html);
    }

    public function test_exclude_outside_sdk_mode_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SnippetRenderer(new Options(domain: 'example.com', mode: Mode::Cdn, exclude: ['/app']));
    }

    public function test_sdk_mode_neutralizes_script_close_in_scrub_url(): void
    {
        $html = (new SnippetRenderer(new Options(
            domain: 'example.com',
            mode: Mode::Sdk,
            scrubUrl: '(u)=>{return "</script>"}',
        )))->render();
        $this->assertStringNotContainsString('</script>"', $html);
        $this->assertStringContainsString('<\\/script>', $html);
    }

    public function test_sdk_mode_emits_redact_routes_in_browser_syntax(): void
    {
        $html = (new SnippetRenderer(new Options(
            domain: 'example.com',
            mode: Mode::Sdk,
            redactRoutes: ['/verify/[token]', '/reset/{code}', '/posts/{page?}'],
        )))->render();
        $this->assertStringContainsString('"redactRoutes":["\/verify\/[token]","\/reset\/[code]","\/posts\/[[page]]"]', $html);
        $this->assertStringNotContainsString('routeTemplates', $html);
    }

    public function test_sdk_mode_grafts_the_route_template_as_a_resolver(): void
    {
        $html = (new SnippetRenderer(new Options(
            domain: 'example.com',
            mode: Mode::Sdk,
            routeTemplates: true,
            routeTemplate: 'posts/{page?}',
        )))->render();
        $this->assertStringContainsString('"routeTemplates":true', $html);
        $this->assertStringContainsString('Object.assign({', $html);
        $this->assertStringContainsString('routeTemplate:()=>"\/posts\/{page}"', $html);
    }

    public function test_sdk_mode_grafts_scrub_url_and_route_template_together(): void
    {
        $html = (new SnippetRenderer(new Options(
            domain: 'example.com',
            mode: Mode::Sdk,
            scrubUrl: '(u)=>u',
            routeTemplates: true,
            routeTemplate: '/users/{id}',
        )))->render();
        $this->assertStringContainsString(',{scrubUrl:(u)=>u,routeTemplate:()=>"\/users\/{id}"})', $html);
    }

    public function test_route_template_cannot_break_out_of_the_script(): void
    {
        $html = (new SnippetRenderer(new Options(
            domain: 'example.com',
            mode: Mode::Sdk,
            routeTemplates: true,
            routeTemplate: '/a/</script><script>alert(1)</script>',
        )))->render();
        $this->assertSame(1, substr_count($html, '</script>'));
        $this->assertStringNotContainsString('<script>alert', $html);
    }

    public function test_route_template_is_ignored_without_route_templates(): void
    {
        $html = (new SnippetRenderer(new Options(domain: 'example.com', mode: Mode::Sdk, routeTemplate: '/users/{id}')))->render();
        $this->assertStringNotContainsString('routeTemplate', $html);
    }

    public function test_route_templates_without_a_template_emits_only_the_flag(): void
    {
        $html = (new SnippetRenderer(new Options(domain: 'example.com', mode: Mode::Sdk, routeTemplates: true)))->render();
        $this->assertStringContainsString('"routeTemplates":true', $html);
        $this->assertStringNotContainsString('routeTemplate:', $html);
        $this->assertStringNotContainsString('Object.assign', $html);
    }

    /** @return iterable<string,array{Mode}> */
    public static function minimalModes(): iterable
    {
        yield 'inline' => [Mode::Inline];
        yield 'cdn' => [Mode::Cdn];
        yield 'asset' => [Mode::Asset];
    }

    #[DataProvider('minimalModes')]
    public function test_redact_routes_outside_sdk_mode_throws(Mode $mode): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('redactRoutes');
        new SnippetRenderer(new Options(domain: 'example.com', mode: $mode, redactRoutes: ['/verify/[token]']));
    }

    #[DataProvider('minimalModes')]
    public function test_route_templates_outside_sdk_mode_throws(Mode $mode): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('routeTemplates');
        new SnippetRenderer(new Options(domain: 'example.com', mode: $mode, routeTemplates: true));
    }

    public function test_a_bare_route_template_is_harmless_outside_sdk_mode(): void
    {
        $html = (new SnippetRenderer(new Options(domain: 'example.com', mode: Mode::Cdn, routeTemplate: '/users/{id}')))->render();
        $this->assertStringNotContainsString('users', $html);
    }

    public function test_with_route_template_renders_a_copy_for_the_current_page(): void
    {
        $base = new SnippetRenderer(new Options(domain: 'example.com', mode: Mode::Sdk, routeTemplates: true));
        $page = $base->withRouteTemplate('/users/{id}');

        $this->assertNotSame($base, $page);
        $this->assertStringContainsString('routeTemplate:()=>"\/users\/{id}"', $page->render());
        $this->assertStringNotContainsString('routeTemplate:', $base->render());
    }
}
