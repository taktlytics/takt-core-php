# takt-core-php


> 📚 **Documentation** — [taktlytics.com/docs/wrappers/php](https://taktlytics.com/docs/wrappers/php)

Framework-agnostic PHP core for [Takt](https://github.com/vskstudio) analytics: a browser snippet renderer and a server-to-server event client.

## Install

```bash
composer require vskstudio/takt-core-php
```

## SnippetRenderer

Render the tracking snippet server-side and echo it into your `<head>`.

```php
use Vskstudio\Takt\SnippetRenderer;
use Vskstudio\Takt\Options;

$renderer = new SnippetRenderer(new Options(
    domain: 'example.com',
    outbound: true,
    files: true,
));

echo $renderer->render();
```

### Modes

The `Mode` enum controls how the bundle is delivered:

- `Mode::Inline` (default) — embeds the bundle inline in a `<script>` tag that self-boots. No extra request, and CSP-friendly: pass `nonce:` in `Options` to emit a `nonce` attribute.
- `Mode::Cdn` — emits a deferred loader pointing at the jsDelivr-hosted bundle.
- `Mode::Asset` — emits a deferred loader pointing at a self-hosted `/takt/takt.auto.js`.
- `Mode::Sdk` — emits an ES-module `<script type="module">import{init}…;init({…})</script>` that boots the full SDK. The only mode able to express `scrubUrl` (see below). The module is loaded from `{scriptOrigin}/takt/takt.esm.js` when `scriptOrigin` is set, otherwise from jsDelivr. Self-hosting it is your responsibility — the vendored bundle only ships `takt.auto.js` (for `Mode::Asset`), not the ES module.

```php
use Vskstudio\Takt\Mode;

new Options(domain: 'example.com', mode: Mode::Cdn, nonce: $cspNonce);
```

The snippet honors `domain`, `endpoint`, `scriptOrigin`, `excludeLocalhost` and the advanced options below. SPA tracking is always on; Do-Not-Track respect is on by default and can only be turned off explicitly with `respectDnt: false`.

### Where events are sent

By default the snippet ingests to the hosted Takt origin — `https://taktlytics.com/api/event` — so a bare setup works out of the box, including on static sites with no backend. To override:

- `endpoint: '/api/event'` — restore the same-origin first-party proxy (you forward `/api/event` to Takt from your own server). Anti-adblock, but requires a backend.
- `endpoint: 'https://collect.example.com/api/event'` — any custom absolute collector URL.
- `scriptOrigin: 'https://t.example.com'` — let the tracker derive `{scriptOrigin}/api/event` (first-party, anti-adblock). `endpoint` wins over `scriptOrigin`.

Autocapture is opt-in and bundled into the vendored `takt.auto.js`. Each toggle adds a token to a single `data-auto` attribute the tracker reads:

- `outbound: true` — outbound link clicks (`outbound`)
- `files: true` — download clicks (`downloads`); narrow the matched extensions with `fileExtensions: ['pdf', 'zip']`, emitted as `data-downloads-ext`
- `tagged: true` — elements tagged in HTML with `data-takt-event` (`tagged`)
- `notFound: true` — 404 pageviews (`404`)

`scriptOrigin` sets a first-party origin to serve the tracker + derive the endpoint from (`{origin}/api/event`) — your Takt domain or a custom domain to dodge ad-blockers (`endpoint` wins over it). In `Mode::Asset` the loader `src` is also served from that origin (`{origin}/takt/takt.auto.js`).

### Advanced options

Each is `null` by default ("unset" — the tracker's own default applies); only a non-default value is rendered.

- `sampleRate: 0.5` — keep ~50% of hits (`data-sample-rate`).
- `trackQuery: true` — keep the raw query string + hash in tracked URLs (`data-track-query`); off by default URLs are stripped.
- `queryParams: ['utm_source', 'utm_medium']` — allowlist of params to keep when `trackQuery` is off (`data-query-params`).
- `respectDnt: false` — stop honoring the browser Do-Not-Track header (`data-respect-dnt`).
- `enabled: false` — kill-switch; the snippet still renders but the tracker boots disabled (`data-enabled`).
- `scrubUrl: '(u) => u.split("#")[0]'` — a **raw JS function** to rewrite every URL before it is sent.
- `exclude: ['/app', '/account']` — path prefixes never tracked (segment-bounded: `/app` matches `/app` and `/app/…` but not `/application`), checked at send time so it holds across SPA navigation.
- `redactRoutes: ['/verify/{token}', '/reset/[code]']`: sensitive routes, sent as their pattern instead of the real path. See [Route redaction](#route-redaction).
- `routeTemplates: true`: send every page as its route template. See [Route redaction](#route-redaction).
- `routeTemplate: '/users/{id}'`: the route template of the page being rendered, used when `routeTemplates` is on.

```php
new Options(domain: 'example.com', sampleRate: 0.5, queryParams: ['utm_source']);
```

In `Mode::Sdk`, `scrubUrl` rewrites the page URL and referrer, and since `@vskstudio/takt-core@0.9.0` also the `url` prop of autocaptured outbound link clicks (`outbound: true`) and file downloads (`files: true`). A token hidden in the path of an outbound or download link is therefore scrubbed like the page URL. The minimal snippet of the other modes only strips the query string and hash of those links.

`scrubUrl` cannot be expressed as a data-attribute, so it requires `Mode::Sdk`; constructing a `SnippetRenderer` with `scrubUrl` set in any other mode throws. It is injected **verbatim** into the page as JavaScript — it is **dev-controlled only**. Never build it from user input.

`exclude` likewise lives only in the full SDK (the ≤ 1 kB minimal snippet omits it), so it too requires `Mode::Sdk`; setting it in `inline`/`cdn`/`asset` mode throws rather than silently dropping the exclusion — a dropped privacy control would leak the very paths it is meant to hide.

```php
new Options(
    domain: 'example.com',
    mode: Mode::Sdk,
    scrubUrl: '(u) => u.split("?")[0]',
);
```

### Route redaction

Query strings are stripped by default, but path segments are sent as they are: `/verify/abc123` leaks the token. Two opt-in options replace real paths with route templates. Both live only in the full SDK, so they require `Mode::Sdk`; setting either in `inline`/`cdn`/`asset` mode throws.

```php
new Options(
    domain: 'example.com',
    mode: Mode::Sdk,
    redactRoutes: ['/verify/{token}', '/reset/:code', '/invoices/[id].pdf'],
);
```

`redactRoutes` lists the sensitive routes. A matching path is sent as the pattern, every other path keeps its real value. Patterns accept `[param]`, `[[optional]]`, `[...rest]`, `(group)`, `:param`, `:param?`, `*`, `**`, and the Laravel / Symfony syntax `{param}` and `{param?}`. The browser SDK does not read the brace syntax, so the renderer hands it `{token}` as `[token]` and `{page?}` as `[[page]]`: the browser sends `/verify/[token]` where the server client sends `/verify/{token}`. Write bracket patterns if both must report the same row.

```php
new Options(domain: 'example.com', mode: Mode::Sdk, routeTemplates: true, routeTemplate: '/users/{id}');
```

`routeTemplates: true` sends every page as its route template. PHP renders each page on the server, so pass the template of the current route as `routeTemplate`: it is emitted as a constant `routeTemplate: () => "/users/{id}"` resolver, in canonical form (`{id?}` becomes `{id}`). `Options::withRouteTemplate()` and `SnippetRenderer::withRouteTemplate()` return a copy with a per-request template. The Laravel and Symfony bridges fill it for you.

## Takt (server-to-server client)

Send events directly from your backend, attributed to the real visitor.

```php
use Vskstudio\Takt\Options;
use Vskstudio\Takt\Revenue;
use Vskstudio\Takt\Takt;

// $endpoint takes either form: a base origin ('/api/event' is appended) or the
// full collect URL. Options::HOSTED_ORIGIN and Options::HOSTED_ENDPOINT both
// target the hosted collector.
$takt = new Takt(Options::HOSTED_ORIGIN, 'example.com', $apiKey);

$takt
    ->withVisitor($request->ip(), $request->userAgent())
    ->event('Signup', ['plan' => 'pro'], new Revenue('29.00', 'EUR'), 'https://example.com/signup');

// or a pageview
$takt->withVisitor($ip, $userAgent)->pageview('https://example.com/welcome');

// sensitive routes are sent as their pattern
$takt = new Takt(Options::HOSTED_ORIGIN, 'example.com', $apiKey, redactRoutes: ['/verify/{token}']);
$takt->pageview('https://example.com/verify/abc123');
$takt->event('Verified', url: 'https://example.com/users/42', route: '/users/{id}');
```

- Requires an ingest-scoped API key bound to the domain.
- Use `->withVisitor($ip, $userAgent)` so events are attributed to the visitor rather than your server.
- Pass the page URL whenever you have one — it is what the event is attributed to. Omitted (or blank), it falls back to the site home derived from `domain` (`https://example.com/`): the ingest rejects a non-absolute URL outright, so an empty one would drop the event.
- Fire-and-forget by default: transport errors are swallowed. Call `->strict()` to get a client that throws on failure (handy in tests).
- Pass `redactRoutes: [...]` to the constructor to redact the page URL and same-origin referrer of every call, with the same patterns as the snippet.
- Pass `route: '/users/{id}'` to `pageview()` or `event()` to send that call under a route template, or set a default for every call of a client with `->withRoute('/users/{id}')` (a string, or a closure returning one, resolved at send time). An explicit `route:` wins over the default. In this mode a same-origin referrer is reduced to the site origin.
- The PSR-18 HTTP client and PSR-17 factories are auto-discovered (`php-http/discovery`). You may also inject your own.

## Wire payload

Events are posted as JSON with compact keys: `n` (name), `d` (domain), `u` (url), `r` (referrer), `p` (props) and `$` (revenue). Screen width is not sent server-side. `u` must be an absolute `http(s)` URL; `r` may be empty (direct access).

## License

MIT
