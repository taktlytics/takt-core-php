<?php

namespace Vskstudio\Takt;

use Http\Discovery\Psr17FactoryDiscovery;
use Http\Discovery\Psr18ClientDiscovery;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

final class Takt
{
    private bool $strict = false;
    private ?string $userIp = null;
    private ?string $userAgent = null;
    private string|\Closure|null $route = null;

    private ClientInterface $httpClient;
    private RequestFactoryInterface $requestFactory;
    private StreamFactoryInterface $streamFactory;

    /**
     * @param string $endpoint Where events are posted. Both forms are accepted
     *   and normalized to the same collect URL:
     *   - a base origin — {@see Options::HOSTED_ORIGIN} ('https://taktlytics.com')
     *     or your own first-party origin — to which '/api/event' is appended;
     *   - a full collect URL already ending in '/api/event', such as
     *     {@see Options::HOSTED_ENDPOINT}, used verbatim.
     *   The second form matches {@see Options::$endpoint} and the JS SDK, where
     *   'endpoint' always means the full collect URL. Required, no default.
     * @param list<string> $redactRoutes
     */
    public function __construct(
        private readonly string $endpoint,
        private readonly string $domain,
        private readonly ?string $apiKey = null,
        ?ClientInterface $httpClient = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
        private readonly array $redactRoutes = [],
    ) {
        $this->httpClient = $httpClient ?? Psr18ClientDiscovery::find();
        $this->requestFactory = $requestFactory ?? Psr17FactoryDiscovery::findRequestFactory();
        $this->streamFactory = $streamFactory ?? Psr17FactoryDiscovery::findStreamFactory();
    }

    public function withVisitor(?string $ip, ?string $userAgent): self
    {
        $clone = clone $this;
        $clone->userIp = $ip;
        $clone->userAgent = $userAgent;
        return $clone;
    }

    /** @param string|\Closure(): ?string|null $route */
    public function withRoute(string|\Closure|null $route): self
    {
        $clone = clone $this;
        $clone->route = $route;
        return $clone;
    }

    public function strict(): self
    {
        $clone = clone $this;
        $clone->strict = true;
        return $clone;
    }

    /**
     * @param array<string,scalar> $props
     * @param string|null          $url   Absolute http(s) URL of the page the event
     *   belongs to. Omitted or blank, it falls back to the site home derived from
     *   $domain: the ingest rejects a non-absolute URL, so an empty one would drop
     *   the event entirely.
     */
    public function event(string $name, array $props = [], ?Revenue $revenue = null, ?string $url = null, ?string $referrer = null, ?string $route = null): void
    {
        $pageUrl = $this->resolveUrl($url);
        $redaction = new RouteRedaction($this->redactRoutes, $route ?? $this->defaultRoute());
        $payload = [
            'n' => $name,
            'd' => $this->domain,
            'u' => $redaction->page($pageUrl),
            // The referrer, unlike the URL, is optional server-side: empty = direct.
            'r' => $referrer !== null && $referrer !== '' ? $redaction->referrer($referrer, $pageUrl) : '',
        ];
        if ($props !== []) {
            $payload['p'] = array_map(static fn ($v) => (string) $v, $props);
        }
        if ($revenue !== null) {
            $payload['$'] = $revenue->toArray();
        }

        $this->send($payload);
    }

    public function pageview(?string $url = null, ?string $referrer = null, ?string $route = null): void
    {
        $this->event('pageview', [], null, $url, $referrer, $route);
    }

    private function defaultRoute(): ?string
    {
        if ($this->route instanceof \Closure) {
            try {
                $route = ($this->route)();
            } catch (\Throwable) {
                return null;
            }

            return is_string($route) ? $route : null;
        }

        return $this->route;
    }

    /**
     * The ingest requires an absolute http(s) URL and rejects the whole event
     * (400) otherwise — a missing key decodes to the same empty string as an
     * explicit one, so omitting it would not help. Callers with no page context
     * (a WooCommerce purchase hook, a queued job) therefore get the site home.
     */
    private function resolveUrl(?string $url): string
    {
        $trimmed = trim($url ?? '');

        return $trimmed !== '' ? $trimmed : $this->siteHomeUrl();
    }

    /** Home page of the configured site, e.g. 'example.com' → 'https://example.com/'. */
    private function siteHomeUrl(): string
    {
        $domain = trim($this->domain);
        if ($domain === '') {
            return '';
        }
        $origin = preg_match('#^https?://#i', $domain) === 1 ? $domain : 'https://' . $domain;

        return rtrim($origin, '/') . '/';
    }

    /**
     * Normalize the configured endpoint to the collect URL, accepting both a base
     * origin ('/api/event' appended) and a full collect URL (used verbatim), so
     * neither reading of the setting produces a doubled '/api/event/api/event'.
     */
    private function collectUrl(): string
    {
        $base = rtrim(trim($this->endpoint), '/');

        return str_ends_with($base, '/api/event') ? $base : $base . '/api/event';
    }

    /** @param array<string,mixed> $payload */
    private function send(array $payload): void
    {
        try {
            $request = $this->requestFactory
                ->createRequest('POST', $this->collectUrl())
                ->withHeader('Content-Type', 'application/json')
                ->withBody($this->streamFactory->createStream(
                    json_encode($payload, JSON_THROW_ON_ERROR)
                ));

            if ($this->apiKey !== null) {
                $request = $request->withHeader('Authorization', 'Bearer ' . self::headerSafe($this->apiKey));
            }
            if ($this->userIp !== null) {
                $request = $request->withHeader('X-Forwarded-For', self::headerSafe($this->userIp));
            }
            if ($this->userAgent !== null) {
                $request = $request->withHeader('User-Agent', self::headerSafe($this->userAgent));
            }

            $response = $this->httpClient->sendRequest($request);

            if ($this->strict && $response->getStatusCode() !== 202) {
                throw new \RuntimeException('Takt ingest returned ' . $response->getStatusCode());
            }
        } catch (\Throwable $e) {
            if ($this->strict) {
                throw $e instanceof \RuntimeException ? $e : new \RuntimeException($e->getMessage(), 0, $e);
            }
        }
    }

    /**
     * Strip control characters (incl. CR/LF) from an outgoing header value, so a
     * spoofed buyer IP/User-Agent or a mangled key can never split or inject
     * headers — defense in depth on top of the PSR-7 layer's own validation.
     */
    private static function headerSafe(string $value): string
    {
        return preg_replace('/[\x00-\x1F\x7F]/', '', $value) ?? '';
    }
}
