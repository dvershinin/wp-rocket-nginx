# Stack extensions

Research from 2026-11-14 on features that work better, or only work, with the
GetPageSpeed stack (the packages repository and its NGINX modules, Amplify).
Module facts are read from `nginx-extras-docs` and `apt-nginx-extras-docs`,
Amplify facts from its source, guideline text from wordpress.org on that date.

Nothing here is implemented. Daniel picks; unpicked items stay recorded.

## Constraints that shape every option

- **PHP cannot learn which modules Nginx loaded.** No module in the repository
  exposes that in a variable or response, Amplify stores `nginx -V` output but
  serves it only on its logged-in host page, and shelling out is ruled out. A
  module directive in the generated file would fail `nginx -t` on every server
  without that module, so the generated file never contains one.
- **The plugin can detect effects, not modules.** The self-request behind
  "Check Nginx include" can send `Accept-Encoding: br` or read response
  headers. That is enough to adapt stock-safe logic and to make a
  recommendation that is true for this server.
- **Module directives travel through operator-owned files.** The generated
  file already includes `<config>/{start,preprocess,global,http,css,js,media}.*.conf`
  by glob. A documented snippet the operator drops there is the only safe way
  to ship a module directive: the person who loaded the module adds the line.
- **Funnel yield is bounded by installs.** The plugin had 0 active installs and
  56 downloads on 2026-11-14. The prior case, Proxy Cache Purge to Amplify
  (50k installs, live since 5.10.0), produced about 10 clicks in its first
  seven weeks and 2 attributed signups (`vhp-amplify-e2e`, both in June 2026)
  out of 323 Amplify users. Users of this plugin all operate their own Nginx,
  so they are better qualified, but there are none yet. Reach comes from the
  integrations track (`integration-candidates.md`), not from this one.
- **Commercial model to state honestly in any copy.** Standard modules install
  without an account; license-enforced ones have a 30-day grace period, then
  add a GetPageSpeed notice to HTML responses until the server is subscribed.
  Pro modules (immutable, cache-purge, immerse, sorted-args) need the Pro plan
  ($20/month per server) from the start. Brotli, zstd, security-headers,
  headers-more and var are not Pro. Amplify has a free plan (1 host); its API
  is Pro ($29/month) only.

## Shortlist

Ranked by revenue potential, effort and review risk.

| # | Feature | Stack piece | Delivery | Effort | Review risk |
|---|---|---|---|---|---|
| 1 | Brotli-aware serving | `nginx-module-brotli` | Probe detects Brotli; template adapts; contextual link when absent | Small | Low |
| 2 | Security headers on cached pages | `nginx-module-security-headers` | Probe reads headers of a cached page; diagnosis plus fix options | Medium | Low |
| 3 | Amplify recommendation | Amplify free plan | Settings-page line and readme section with a `ref` tag | Very small | Low |
| 4 | Cache hit ratio in Amplify | Amplify agent and server | Log variable to `nginx.cache.*` metrics; documented `log_format` | Large, three repos | Low |
| 5 | Amplify-connected verification | Amplify API | Opt-in API key in the plugin | Large | Medium |

### 1. Brotli-aware serving (recommended)

The WP Rocket template inherits a Rocket-Nginx rule: a client that accepts
`br` is given the uncompressed file so that a Brotli module can compress it.
Every browser sends `br` over HTTPS, so on stock Nginx the pre-compressed
`_gzip` files WP Rocket writes are almost never served and Nginx gzips each
cached page again on every request.

The probe requests a page with `Accept-Encoding: br` and records whether the
answer is Brotli-encoded. Without Brotli the template drops the `br` override
and serves `_gzip` directly, which improves stock Nginx. With Brotli it keeps
today's behaviour. The settings page reports the result and, when Brotli is
absent, links to the module. No module directive is generated, a wrong
detection only costs the other compression path, and the feature works with
any Brotli build, so nothing is locked (guideline 5). zstd can follow the same
shape later. The WP Super Cache template has no `br` override and needs only
the status line.

### 2. Security headers on cached pages

The cache locations use `add_header`, which discards every `add_header` the
operator set at server level: cached pages lose HSTS, CSP and the rest while
uncached pages keep them. `security_headers on` from the module is a filter and
is not affected ("Does not suffer the `add_header` directive's pitfalls").
Stock fixes exist: `add_header_inherit merge` since Nginx 1.29.3, or the
headers repeated in `http.*.conf`.

The probe compares headers of a cache hit against the expected set and the
settings page explains the gap with all three fixes. A security finding gets
more attention than a performance one, and the same finding is what gixy
reports in Amplify, so this option can carry both links. It needs a cached URL
to probe and careful wording for the false-positive cases.

### 3. Amplify recommendation

One line under "Check the include" and a readme section, following the Proxy
Cache Purge wording and `ref` convention (`gcc-amplify-settings`,
`gcc-amplify-readme`; the tag must match `^[a-z0-9][a-z0-9-]{0,39}$`). There
is no WordPress or page-cache landing page; `/nginx-security-check` is the
closest and currently drops `ref` because `CaptureRefMiddleware` is not
mounted on it. Cheap enough to ride along with option 1 or 2 rather than be a
release of its own. Expected yield near zero until installs exist.

### 4. Cache hit ratio in Amplify

The agent builds `nginx.cache.{hit,miss,bypass,...}` from
`$upstream_cache_status` only, through a fixed whitelist of log variables;
`$sent_http_*` is ignored. Showing the HIT ratio of this plugin needs agent
support for a configurable variable, server-side charting, and an operator
edit of `log_format`. The best long-term Amplify tie-in and the only option
that creates a reason to stay subscribed, but it is Amplify work first and
plugin work last.

### 5. Amplify-connected verification

The plugin would call Amplify to confirm the include line and the loaded
modules. The API is read-only, Pro only, and has no route for configuration or
modules, so both the endpoints and a free-tier path would have to be built.
Needs opt-in consent and readme disclosure (guidelines 6 and 7).

## Rejected

- **immutable** for static assets: Pro module, undetectable, and
  `add_header Cache-Control "... immutable"` does the same on stock Nginx.
- **Lower-case URIs for WP Super Cache** (`var $x lower ...` from
  `nginx-module-var`; `set-misc` has no such function): needs a module
  directive in the generated file. Could be a documented `preprocess.*.conf`
  snippet; niche.
- **cache-purge** as a second layer: it purges Nginx `*_cache` zones, not the
  files on disk this plugin serves, and FastCGI cache with purge is already
  Proxy Cache Purge territory.
- **dynamic-etag, immerse, webp, sorted-args, compression-normalize**: no
  effect on files served from disk with `etag on`, or a different product.

## wordpress.org guidelines that apply

From the Detailed Plugin Guidelines and the review checklist.

- 5: "Plugins may not contain functionality that is restricted or locked, only
  to be made available by payment or upgrade." Also: "Attempting to upsell the
  user on ad-hoc products and features is acceptable, provided it falls within
  bounds of guideline 11".
- 6: an interface to an external service is allowed, "even for paid services",
  if the service has "functionality of substance" and is "clearly documented in
  the readme"; a plugin that is only a storefront is not.
- 7: "plugins may not contact external servers without explicit and authorized
  consent." The self-request to the site's own address is not external; an
  Amplify API call would be.
- 9: no "Implying users must pay to unlock included features".
- 10: no links or credits on the public site unless optional and off by
  default. The repository's license notice in HTML comes from the module, not
  the plugin, but copy that recommends a module must say it exists.
- 11: "Upgrade prompts, notices, alerts, and the like must be limited in scope
  and used sparingly, be that contextually or only on the plugin's setting
  page." Also: "tracking referrals via those ads is not permitted (see
  guideline 7)". A static `ref` tag identifies the plugin, not the user, and
  Proxy Cache Purge ships one; it is still the sentence a reviewer would cite.
- 12: at most five tags, links "within moderation", no undisclosed affiliate
  links.
- Review checklist: "Upselling is permitted from plugin settings screen or a
  link on their entry on the plugin list page." Third-party services must be
  disclosed in the readme with terms or privacy links, "even if you are the
  3rd party service".
