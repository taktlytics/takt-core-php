<?php

namespace Vskstudio\Takt;

final class Options
{
    /** Hosted Takt origin used as the default ingest target out of the box. */
    public const HOSTED_ORIGIN = 'https://taktlytics.com';

    /** Full default ingest endpoint on the hosted Takt origin. */
    public const HOSTED_ENDPOINT = self::HOSTED_ORIGIN . '/api/event';

    /**
     * @param list<string> $fileExtensions
     * @param list<string> $queryParams
     * @param list<string> $exclude
     * @param list<string> $redactRoutes
     *
     * @param string $endpoint Where the tracker posts events. Defaults to the
     *   hosted Takt origin ({@see self::HOSTED_ENDPOINT}) so a bare setup works
     *   on static sites with no backend. Pass '/api/event' to restore the
     *   same-origin first-party proxy, or any absolute URL for a custom collector.
     */
    public function __construct(
        public readonly string $domain,
        public readonly string $endpoint = self::HOSTED_ENDPOINT,
        /** First-party origin to serve the tracker + derive the endpoint from ({origin}/api/event); overrides the hosted default, but endpoint wins over it. */
        public readonly ?string $scriptOrigin = null,
        public readonly bool $outbound = false,
        public readonly bool $files = false,
        public readonly bool $excludeLocalhost = true,
        public readonly ?string $nonce = null,
        public readonly Mode $mode = Mode::Inline,
        public readonly bool $notFound = false,
        public readonly bool $tagged = false,
        public readonly array $fileExtensions = [],
        // Advanced options. null = "unset" (tracker default applies); only a
        // non-default value is rendered. scrubUrl and exclude live only in the
        // full SDK, so they are expressible only in Mode::Sdk (SnippetRenderer
        // fails fast otherwise): the ≤ 1 kB minimal snippet carries neither.
        public readonly ?float $sampleRate = null,
        public readonly ?bool $trackQuery = null,
        public readonly array $queryParams = [],
        public readonly ?bool $respectDnt = null,
        public readonly ?bool $enabled = null,
        public readonly ?string $scrubUrl = null,
        /** Path prefixes never tracked (segment-bounded, checked at send time); Mode::Sdk only. */
        public readonly array $exclude = [],
        public readonly array $redactRoutes = [],
        public readonly bool $routeTemplates = false,
        public readonly ?string $routeTemplate = null,
    ) {
    }

    public function withRouteTemplate(?string $routeTemplate): self
    {
        return new self(
            domain: $this->domain,
            endpoint: $this->endpoint,
            scriptOrigin: $this->scriptOrigin,
            outbound: $this->outbound,
            files: $this->files,
            excludeLocalhost: $this->excludeLocalhost,
            nonce: $this->nonce,
            mode: $this->mode,
            notFound: $this->notFound,
            tagged: $this->tagged,
            fileExtensions: $this->fileExtensions,
            sampleRate: $this->sampleRate,
            trackQuery: $this->trackQuery,
            queryParams: $this->queryParams,
            respectDnt: $this->respectDnt,
            enabled: $this->enabled,
            scrubUrl: $this->scrubUrl,
            exclude: $this->exclude,
            redactRoutes: $this->redactRoutes,
            routeTemplates: $this->routeTemplates,
            routeTemplate: $routeTemplate,
        );
    }

    /** @param array<string,mixed> $a */
    public static function fromArray(array $a): self
    {
        $mode = match ($a['mode'] ?? 'inline') {
            'cdn', Mode::Cdn => Mode::Cdn,
            'asset', Mode::Asset => Mode::Asset,
            'sdk', Mode::Sdk => Mode::Sdk,
            default => Mode::Inline,
        };

        return new self(
            domain: self::str($a['domain'] ?? ''),
            endpoint: self::str($a['endpoint'] ?? self::HOSTED_ENDPOINT),
            scriptOrigin: isset($a['scriptOrigin']) ? self::str($a['scriptOrigin']) : null,
            outbound: (bool) ($a['outbound'] ?? false),
            files: (bool) ($a['files'] ?? false),
            excludeLocalhost: (bool) ($a['excludeLocalhost'] ?? ($a['exclude_localhost'] ?? true)),
            nonce: isset($a['nonce']) ? self::str($a['nonce']) : null,
            mode: $mode,
            notFound: (bool) ($a['notFound'] ?? ($a['not_found'] ?? false)),
            tagged: (bool) ($a['tagged'] ?? false),
            fileExtensions: self::strList($a['fileExtensions'] ?? ($a['file_extensions'] ?? [])),
            sampleRate: self::nullableFloat($a['sampleRate'] ?? ($a['sample_rate'] ?? null)),
            trackQuery: self::nullableBool($a['trackQuery'] ?? ($a['track_query'] ?? null)),
            queryParams: self::strList($a['queryParams'] ?? ($a['query_params'] ?? [])),
            respectDnt: self::nullableBool($a['respectDnt'] ?? ($a['respect_dnt'] ?? null)),
            enabled: self::nullableBool($a['enabled'] ?? null),
            scrubUrl: isset($a['scrubUrl']) || isset($a['scrub_url']) ? self::str($a['scrubUrl'] ?? $a['scrub_url']) : null,
            exclude: self::strList($a['exclude'] ?? []),
            redactRoutes: self::strList($a['redactRoutes'] ?? ($a['redact_routes'] ?? [])),
            routeTemplates: (bool) ($a['routeTemplates'] ?? ($a['route_templates'] ?? false)),
            routeTemplate: self::nullableStr($a['routeTemplate'] ?? ($a['route_template'] ?? null)),
        );
    }

    private static function str(mixed $v): string
    {
        return is_scalar($v) ? (string) $v : '';
    }

    private static function nullableStr(mixed $v): ?string
    {
        return is_scalar($v) && ($s = trim((string) $v)) !== '' ? $s : null;
    }

    private static function nullableFloat(mixed $v): ?float
    {
        return is_numeric($v) ? (float) $v : null;
    }

    private static function nullableBool(mixed $v): ?bool
    {
        return $v === null ? null : (bool) $v;
    }

    /** @return list<string> */
    private static function strList(mixed $v): array
    {
        if (!is_array($v)) {
            return [];
        }
        $out = [];
        foreach ($v as $item) {
            if (is_scalar($item) && ($s = trim((string) $item)) !== '') {
                $out[] = $s;
            }
        }

        return $out;
    }
}
