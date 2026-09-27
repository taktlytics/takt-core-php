<?php

namespace Vskstudio\Takt;

final class SnippetRenderer
{
    private const CDN_BASE = 'https://cdn.jsdelivr.net/npm/@vskstudio/takt-core@0.9.0/dist/takt.auto.js';
    private const ESM_CDN = 'https://cdn.jsdelivr.net/npm/@vskstudio/takt-core@0.9.0/+esm';
    private const ASSET_PATH = '/takt/takt.auto.js';
    private const ESM_PATH = '/takt/takt.esm.js';

    public function __construct(private readonly Options $options)
    {
        // scrubUrl is a raw JS function: impossible to express as a data-attribute,
        // so it is only valid in Mode::Sdk. Fail fast rather than silently drop it.
        if ($options->scrubUrl !== null && $options->scrubUrl !== '' && $options->mode !== Mode::Sdk) {
            throw new \InvalidArgumentException('Takt: scrubUrl is a JS function and requires Mode::Sdk; it cannot be rendered in inline/cdn/asset mode.');
        }
        // exclude lives only in the full SDK (the ≤ 1 kB minimal snippet omits it).
        // Fail fast rather than silently drop a privacy control the caller believes
        // is active — a dropped exclusion would leak the very paths it should hide.
        if ($options->exclude !== [] && $options->mode !== Mode::Sdk) {
            throw new \InvalidArgumentException('Takt: exclude requires Mode::Sdk; the minimal snippet does not support path exclusion.');
        }
    }

    public function render(): string
    {
        return match ($this->options->mode) {
            Mode::Cdn => $this->renderLoader(self::CDN_BASE),
            Mode::Asset => $this->renderLoader($this->assetSrc()),
            Mode::Inline => $this->renderInline(),
            Mode::Sdk => $this->renderSdk(),
        };
    }

    private function assetSrc(): string
    {
        $origin = $this->options->scriptOrigin;

        return $origin !== null && $origin !== ''
            ? rtrim($origin, '/') . self::ASSET_PATH
            : self::ASSET_PATH;
    }

    private function renderLoader(string $src): string
    {
        return sprintf(
            '<script defer src="%s"%s%s></script>',
            htmlspecialchars($src, ENT_QUOTES),
            $this->dataAttrs(),
            $this->nonceAttr(),
        );
    }

    private function renderInline(): string
    {
        $path = __DIR__ . '/../resources/takt.auto.js';
        $bundle = file_get_contents($path);
        if ($bundle === false) {
            throw new \RuntimeException("Takt: unable to read inline bundle at {$path}");
        }

        return sprintf(
            "<script%s%s>%s</script>",
            $this->dataAttrs(),
            $this->nonceAttr(),
            self::neutralizeScriptClose($bundle),
        );
    }

    /**
     * Neutralize any "</script" inside an inline bundle so it cannot break out of
     * the surrounding <script> tag. Matched case-insensitively because the HTML
     * parser closes the block on "</script" regardless of case.
     */
    public static function neutralizeScriptClose(string $js): string
    {
        return preg_replace('#</(script)#i', '<\\\\/$1', $js) ?? $js;
    }

    /**
     * Decide the endpoint to render, or null to omit it entirely.
     *
     * - A custom endpoint (anything other than the hosted default, including the
     *   same-origin proxy '/api/event') is always emitted verbatim.
     * - The hosted default with a scriptOrigin set is omitted, so the tracker
     *   derives {scriptOrigin}/api/event (first-party, anti-adblock).
     * - The hosted default with no scriptOrigin is pinned explicitly to the
     *   hosted origin, so a bare snippet ingests to https://taktlytics.com/api/event
     *   regardless of the vendored tracker version's own built-in default.
     */
    private function resolvedEndpoint(): ?string
    {
        $o = $this->options;
        if ($o->endpoint !== Options::HOSTED_ENDPOINT) {
            return $o->endpoint;
        }
        if ($o->scriptOrigin !== null && $o->scriptOrigin !== '') {
            return null;
        }

        return Options::HOSTED_ENDPOINT;
    }

    private function dataAttrs(): string
    {
        $o = $this->options;
        $attrs = sprintf(' data-domain="%s"', htmlspecialchars($o->domain, ENT_QUOTES));
        if (($endpoint = $this->resolvedEndpoint()) !== null) {
            $attrs .= sprintf(' data-endpoint="%s"', htmlspecialchars($endpoint, ENT_QUOTES));
        }
        if ($o->scriptOrigin !== null && $o->scriptOrigin !== '') {
            $attrs .= sprintf(' data-script-origin="%s"', htmlspecialchars($o->scriptOrigin, ENT_QUOTES));
        }
        // Autocapture is opt-in and driven by a single data-auto token list read
        // by takt.auto.js : outbound clicks, downloads, HTML-tagged events, 404.
        $auto = [];
        if ($o->outbound) {
            $auto[] = 'outbound';
        }
        if ($o->files) {
            $auto[] = 'downloads';
        }
        if ($o->tagged) {
            $auto[] = 'tagged';
        }
        if ($o->notFound) {
            $auto[] = '404';
        }
        if ($auto !== []) {
            $attrs .= sprintf(' data-auto="%s"', implode(',', $auto));
        }
        if ($o->fileExtensions !== []) {
            $attrs .= sprintf(' data-downloads-ext="%s"', htmlspecialchars(implode(',', $o->fileExtensions), ENT_QUOTES));
        }
        if (!$o->excludeLocalhost) {
            $attrs .= ' data-exclude-localhost="false"';
        }
        // Advanced options. Booleans only emit their non-default case; the tracker
        // applies its own default when the attribute is absent.
        if ($o->enabled === false) {
            $attrs .= ' data-enabled="false"';
        }
        if ($o->respectDnt === false) {
            $attrs .= ' data-respect-dnt="false"';
        }
        if ($o->sampleRate !== null) {
            $attrs .= sprintf(' data-sample-rate="%s"', htmlspecialchars(self::numberStr($o->sampleRate), ENT_QUOTES));
        }
        if ($o->trackQuery === true) {
            $attrs .= ' data-track-query="true"';
        }
        if ($o->queryParams !== []) {
            $attrs .= sprintf(' data-query-params="%s"', htmlspecialchars(implode(',', $o->queryParams), ENT_QUOTES));
        }

        return $attrs;
    }

    private function renderSdk(): string
    {
        $o = $this->options;
        $arg = $this->sdkConfigJson();
        if ($o->scrubUrl !== null && $o->scrubUrl !== '') {
            // scrubUrl is grafted as a raw JS function, outside the JSON literal.
            $arg = sprintf('Object.assign(%s,{scrubUrl:%s})', $arg, $o->scrubUrl);
        }
        $body = sprintf('import{init}from%s;init(%s)', json_encode($this->esmSrc()), $arg);

        return sprintf('<script type="module"%s>%s</script>', $this->nonceAttr(), self::neutralizeScriptClose($body));
    }

    private function esmSrc(): string
    {
        $origin = $this->options->scriptOrigin;

        return $origin !== null && $origin !== ''
            ? rtrim($origin, '/') . self::ESM_PATH
            : self::ESM_CDN;
    }

    private function sdkConfigJson(): string
    {
        $o = $this->options;
        $c = ['domain' => $o->domain];
        if (($endpoint = $this->resolvedEndpoint()) !== null) {
            $c['endpoint'] = $endpoint;
        }
        if ($o->scriptOrigin !== null && $o->scriptOrigin !== '') {
            $c['scriptOrigin'] = $o->scriptOrigin;
        }
        if (!$o->excludeLocalhost) {
            $c['excludeLocalhost'] = false;
        }
        if ($o->respectDnt === false) {
            $c['respectDnt'] = false;
        }
        if ($o->enabled === false) {
            $c['enabled'] = false;
        }
        if ($o->sampleRate !== null) {
            $c['sampleRate'] = $o->sampleRate;
        }
        if ($o->trackQuery === true) {
            $c['trackQuery'] = true;
        }
        if ($o->queryParams !== []) {
            $c['queryParams'] = $o->queryParams;
        }
        if ($o->exclude !== []) {
            $c['exclude'] = $o->exclude;
        }
        if ($o->outbound) {
            $c['outbound'] = true;
        }
        if ($o->files) {
            $c['files'] = true;
        }
        if ($o->fileExtensions !== []) {
            $c['fileExtensions'] = $o->fileExtensions;
        }
        if ($o->notFound) {
            $c['notFound'] = true;
        }
        if ($o->tagged) {
            $c['tagged'] = true;
        }

        return json_encode($c, JSON_THROW_ON_ERROR);
    }

    /** Render a float without a trailing ".0" (0.5 → "0.5", 1.0 → "1"). */
    private static function numberStr(float $n): string
    {
        return rtrim(rtrim(sprintf('%.10F', $n), '0'), '.');
    }

    private function nonceAttr(): string
    {
        return $this->options->nonce !== null
            ? sprintf(' nonce="%s"', htmlspecialchars($this->options->nonce, ENT_QUOTES))
            : '';
    }
}
