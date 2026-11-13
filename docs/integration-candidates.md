# Integration candidates

Research from 2026-11-13, read from each plugin's source at the version listed.
Installs are the wordpress.org `active_installs` figure on that date. A candidate
must write plain HTML to a path Nginx can build from the request without hashing.

Shipped: WP Rocket (1.0.0), WP Super Cache (1.3.0).

## Feasible, not implemented

Ranked by reach, effort and overlap with the vendor's own Nginx guidance.

| Plugin | Installs | Cache layout | Settings | Notes |
|---|---|---|---|---|
| WP Fastest Cache 1.5.5 | 1M+ | `wp-content/cache/all/<REQUEST_URI>/index.html`; a host directory only on multisite, WPML or Polylang | options `WpFastestCache`, `WpFastestCacheExclude` (JSON) | No precompressed files; mobile cache is Premium-only. Vendor rules exclude administrators by username in the login cookie, so the file must regenerate when administrators change. No vendor Nginx guidance. Not checked: expiry enforcement, the exact "cache enabled" key. |
| Cache Enabler 1.8.17 | 100k+ | `wp-content/cache/cache-enabler/<host><path>/{http-,https-}index[-mobile][-webp].html[.gz,.br]` | option `cache_enabler` | Exclusions are PHP regexes with delimiters. With compression on only the compressed variant is written, so the template must pick the exact name and set `Content-Encoding`. The vendor snippet is static and says it ignores expiry and some exclusions. |
| WP-Optimize 4.7.1 | 1M+ | `wp-content/cache/wpo-cache/<host><path>/[mobile.]index[.webp][-cookie=..][-qvar=..].html` plus `.gz` | option `wpo_cache_config` | Path is lowercased and the trailing slash folded; names over 240 characters get a sha1 suffix. Add-ons inject cookie and query variants. Expired files can linger for about twice the lifetime. No vendor Nginx config. File naming rests on one reading of the source. |
| Hyper Cache 3.4.4 | 7k+ | `wp-content/cache/hyper-cache/<host>/<path>/index[-https].html[.gz]` | option `hyper-cache` | Path characters outside `[A-Za-z0-9/_-]` become `_`. Small reach. |

## Not worth doing

- W3 Total Cache 2.11.0 (900k+): generates its own complete `nginx.conf` for Disk: Enhanced. Point users at it.
- Powered Cache, Swift Performance Lite, FlyingPress: the vendor ships or documents an Nginx config.

## Not servable by stock Nginx

- Breeze 2.6.4: SHA-256 file names and PHP-serialized bodies.
- Hummingbird 4.0.2: an md5 in the file name.
- Speed Optimizer 7.8.6: a secret in the path, regenerated on every full purge.
- Comet Cache: serialized headers prepended to each file.
- LiteSpeed Cache: assumed to need a LiteSpeed server; not checked in source.

## wordpress.org listing rules that apply

At most five tags. Names of related, non-competing products are allowed in tags
and in "works with" text; vendor logos are not allowed in banner or icon, and
nothing may imply official status. Whether specific cache-plugin names are on
the review team's restricted list was not confirmed.
