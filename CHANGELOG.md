# vskstudio/takt-core-php

## Unreleased

### Breaking Changes

- `Takt` and `Options` refuse every endpoint that is not `https`, `localhost`
  and loopback included, and refuse a scheme-less endpoint unless it is a path
  starting with a single `/`. A `http://` or `//host` endpoint throws an
  `InvalidArgumentException` at construction, so the API key is never sent in
  clear or to an unexpected host. Local development serves its collector over
  local https.
- Route redaction masks a path that cannot be decoded (a malformed escape such
  as `%ZZ`, or invalid UTF-8 once decoded) as `/[redacted]` when a route is
  configured. The option `redactUndecodablePaths` (`Takt`, default `true`)
  switches to tolerant matching with `false`: such a path is then matched like
  any other, so a path matching a route goes out as its template, and only a
  path matching no route is sent raw. Previously such a path was sent raw.
- `RouteRedaction::path()` returns the route template when one is set, as the
  browser SDK does, instead of the path itself.
- A constrained parameter `{name<regex>}` in a route pattern is enforced: a
  segment whose value does not match the regex no longer matches the route, so
  its path is sent as it is. Previously the constraint was ignored and any
  value was masked. Browser patterns keep the brace form of a constrained
  parameter, which needs a browser SDK that reads it.

### Minor Changes

- Route redaction matching also tries the path decoded up to five times,
  ignores matrix parameters (`;...`) and removes `.` and `..` segments (RFC
  3986) before matching. A path still encoded after the five passes is sent as
  `/[redacted]`, and the raw path is never sent on failure. A masked page or
  pathname drops its query string and fragment, the `/[redacted]` fallback
  included, and so does a page sent under `routeTemplates`.
  The matching cost is up to 14 candidate forms compared per path and route:
  seven spellings (the raw path, the `decodeUri` form, and up to five
  `rawurldecode` passes), each also tried with its matrix parameters ignored.
- A constraint `{name<regex>}` is anchored on the whole segment, cannot match
  across a `/`, and is matched case-insensitively. The constraint must follow a
  restricted grammar, not PCRE: alternation only at the top level, atoms that
  are literals, `.`, `\d \D \w \W \s \S`, escaped ASCII punctuation (not
  `/`) or a class `[...]` without nesting, quantifiers `* + ? {n} {n,} {n,m}`
  with bounds up to 100, no lazy or possessive quantifiers, no two quantifiers
  in a row, at most two variable quantifiers per constraint (`{n,n}` counts),
  at most 100 characters, and no empty branch. Literals exclude `<` and `>`; a
  class range cannot end on `-`, an escape or `<`/`>`, nor span `/`. Groups,
  lookarounds, backreferences, anchors and `/` are refused. A constraint
  outside the grammar makes the route unusable: a path matching its shape,
  with that parameter read as any value, is masked as `/[redacted]`, and other
  paths are unaffected. A stray `{` or `}` in a segment makes the route
  unusable the same way. A value longer than 256 bytes for a constrained
  parameter is masked before the constraint is checked. A `/` inside `<...>`
  does not split the route into segments, and `.` never matches `/`.

## 0.6.1

### Patch Changes

- Bump the CDN/ESM tracker pins and the vendored `takt.auto.js` to
  `@vskstudio/takt-core@0.11.1`. Route redaction now also applies to
  outbound-link and file-download destinations and to the `404` path, and
  `exclude` matches case-insensitively on the percent-decoded path.
  `bin/sync-bundle.sh` now defaults to 0.11.1.

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
- Bump the CDN/ESM tracker pins and the vendored `takt.auto.js` to
  `@vskstudio/takt-core@0.10.0`, which `Mode::Sdk` requires for these options.
  `bin/sync-bundle.sh` now defaults to 0.10.0.

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
