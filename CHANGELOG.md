# vskstudio/takt-core-php

## 0.6.0

### Minor Changes

- Route redaction. `Options` gains `redactRoutes` (`redact_routes`), a list of
  sensitive route patterns sent as the pattern instead of the real path
  (`/verify/abc` becomes `/verify/{token}`), `routeTemplates`
  (`route_templates`), which sends every page as its route template, and
  `routeTemplate` (`route_template`), the template of the page being rendered.
  In `Mode::Sdk` they are handed to the browser SDK, the template as a constant
  `routeTemplate` resolver; in `inline`/`cdn`/`asset` mode `redactRoutes` and
  `routeTemplates` throw, since the minimal snippet cannot honor them.
  Patterns accept `[p]`, `[[p]]`, `[...p]`, `(group)`, `:p`, `:p?`, `*`, `**`
  and the Laravel / Symfony `{p}` and `{p?}`.
- The server-to-server `Takt` client takes `redactRoutes` in its constructor,
  applied to the page URL and same-origin referrer, a `route:` argument on
  `pageview()` and `event()` that sends one call under a route template, and
  `withRoute()` to set a default route for every call of a client.
- Requires `@vskstudio/takt-core@0.10.0` in the browser for `Mode::Sdk`.

## 0.5.2

### Patch Changes

- Bump the CDN/ESM tracker pins to `@vskstudio/takt-core@0.9.0`. In `Mode::Sdk`,
  `scrubUrl` now also rewrites the `url` prop of autocaptured outbound link
  clicks and file downloads, not only the page URL and referrer, so a secret in
  the path of such a link no longer reaches Takt unscrubbed.
- Re-vendor the inline browser tracker (`resources/takt.auto.js`) from
  `@vskstudio/takt-core@0.9.0` (it was still the 0.5.1 build). Rendered snippets
  are unchanged in behavior: the endpoint is always pinned explicitly when no
  `scriptOrigin` is set. `bin/sync-bundle.sh` now defaults to 0.9.0.

## 0.5.1

### Patch Changes

- Fix the server-to-server client sending an empty `u` (URL) when the caller
  passed none. The ingest requires an absolute `http(s)` URL and rejects the
  whole event with a `400` otherwise, so every URL-less `Takt::event()` — the
  README's own `Signup` example, and the WooCommerce `Purchase` hook — was
  silently dropped. Omitting the key would not have helped (a missing key
  decodes to the same empty string server-side): the URL now falls back to the
  site home derived from `domain` (`example.com` → `https://example.com/`).
  The referrer `r` is unaffected — it is legitimately empty for direct access.
- `Takt`'s `$endpoint` now accepts both readings of the setting. It used to mean
  a base origin only (always appending `/api/event`), while `Options::$endpoint`
  and the JS SDK mean the full collect URL — so passing `Options::HOSTED_ENDPOINT`
  produced `…/api/event/api/event` and a silent `404`. A value already ending in
  `/api/event` is now used verbatim; the base-origin form is unchanged, so no
  existing configuration breaks.
- Docs: the README `Takt` example no longer omits the page URL, documents both
  endpoint forms, and no longer claims Do-Not-Track respect is "always on" (it
  is on by default but `respectDnt: false` turns it off).

## 0.5.0

### Minor Changes

- Add an `exclude` option (a list of path prefixes never tracked) mirroring
  `@vskstudio/takt-core@0.8.0`. Exclusion is segment-bounded (`/app` matches
  `/app` and `/app/…` but not `/application`) and checked at send time, so it
  holds across SPA navigation. Like `scrubUrl`, `exclude` lives only in the full
  SDK — the ≤ 1 kB minimal snippet omits it — so it requires `Mode::Sdk`;
  `SnippetRenderer` throws if it is set in `inline`/`cdn`/`asset` mode rather than
  silently dropping a privacy control the caller believes is active. The vendored
  CDN/ESM tracker pins are bumped to `@vskstudio/takt-core@0.8.0`.

## 0.4.0

### Minor Changes

- The default client-side ingest endpoint is now the hosted Takt origin
  (`https://taktlytics.com/api/event`) instead of the same-origin relative path
  `/api/event`, mirroring `@vskstudio/takt-core@0.6.0`. A bare `Options` /
  `SnippetRenderer` setup now works out of the box, including on static sites
  with no backend (the old `/api/event` default 405s there). To restore the
  same-origin first-party proxy, pass `endpoint: '/api/event'`; `endpoint` and
  `scriptOrigin` overrides keep working as before. A new `Options::HOSTED_ORIGIN`
  / `Options::HOSTED_ENDPOINT` constant holds the hosted origin. The
  server-to-server `Takt` client is unchanged: its `$endpoint` is a required base
  origin (it appends `/api/event`), so it has no default to migrate.

## 0.3.2

### Patch Changes

- Re-bundle the inline browser tracker (`resources/takt.auto.js`) from
  `@vskstudio/takt-core@0.5.1`: outbound/download autocapture now matches the
  link protocol explicitly against `http:`/`https:`.

## 0.3.1

### Patch Changes

- Harden the server-to-server client: control characters (incl. CR/LF) are now
  stripped from the `Authorization`, `X-Forwarded-For` and `User-Agent` header
  values before they are sent, so a spoofed buyer IP/User-Agent can never split
  or inject headers — defense in depth on top of the PSR-7 layer's validation.
- `Revenue` now rejects non-numeric amounts (only the 3-letter currency code was
  validated before).

## 0.3.0 and earlier

See the Git tags (`v0.1.2` … `v0.3.0`) for the history that predates this
changelog: the advanced tracker options (`sampleRate`, `trackQuery`,
`queryParams`, `respectDnt`, `enabled`, `scrubUrl`) and `Mode::Sdk`, the
autocapture parity (`404`/`tagged`/`fileExtensions`) and the initial S2S client.
