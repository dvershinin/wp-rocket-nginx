# GetPageSpeed Cache Configuration

GetPageSpeed Cache Configuration is an unofficial WordPress plugin that generates an
Nginx configuration from the settings of the active page-cache plugin. Nginx can
then serve the existing cache files directly from disk without starting PHP or
WordPress.

Supported cache plugins:

| Cache plugin | Source of the rules |
|---|---|
| WP Rocket | [Rocket-Nginx](https://github.com/SatelliteWP/rocket-nginx) 3.1.2 template, fed from WP Rocket settings |
| WP Super Cache | The conditions of the mod_rewrite rules WP Super Cache writes for Apache, fed from `wp-cache-config.php` |

The plugin does not replace the cache plugin and does not create page-cache files.
It mirrors the settings that determine how those files are named and when a
request must bypass cache. The integration is picked from the active cache
plugin; WP Rocket is the default when none is detected.

## Requirements

- WordPress 6.0 or newer
- PHP 7.4 or newer
- Nginx
- WP Rocket or WP Super Cache for production cache generation
- Permission for the PHP worker to write under the uploads directory

## Installation

1. Download `getpagespeed-cache-configuration.zip` from the latest GitHub release.
2. Install and activate it in WordPress.
3. Open **Settings > GetPageSpeed Cache Configuration** and confirm that `default.conf` exists.
4. Include the generated file inside the site's Nginx `server` block:

   ```nginx
   include /absolute/path/to/wp-content/uploads/getpagespeed-cache-configuration/default.conf;
   ```

5. Validate and reload Nginx:

   ```bash
   nginx -t && systemctl reload nginx
   ```

The configuration regenerates when the cache plugin's settings change. WP Super
Cache keeps its settings in a file and fires no hook, so its changes are picked
up on the next WordPress admin request. Reloading Nginx remains an explicit
server operation. This keeps a WordPress administrator from executing server
commands through PHP.

6. Press **Check Nginx include** on the settings page. The generated file defines
   the location `/getpagespeed-cache-configuration-probe`, which answers with a
   token of the file's content. The check reports whether Nginx loaded the
   current file, loaded an older one (not reloaded yet), or does not include it.

## What is generated

The default output is:

```text
wp-content/uploads/getpagespeed-cache-configuration/default.conf
```

It includes:

- direct page-cache lookup for the variants the cache plugin writes (HTTPS, gzip, and for WP Rocket mobile);
- query-string rules sourced from the cache plugin;
- cache bypasses for POST, authenticated users, carts, and maintenance mode;
- direct Nginx locations for CSS, JavaScript, images, and fonts;
- a deny rule preventing generated `.conf` files from being downloaded;
- the probe location used by the include check;
- optional diagnostic headers: `X-Rocket-Nginx-*` for WP Rocket, `X-GetPageSpeed-Cache*` for WP Super Cache.

### WP Super Cache notes

- Works in both "Simple" and "Expert" delivery modes; the supercache files are the same.
- URLs containing uppercase letters are stored lowercased by WP Super Cache. Nginx does not find them and PHP answers, as before.
- With mobile device support on, mobile browsers are sent to PHP, as the vendor's Apache rules do.
- Expired files are served until WP Super Cache's garbage collection removes them, as with the vendor's Apache rules.
- The cache location must stay inside the WordPress content directory.
- Plugins that change file names through the `supercache_dir` or `supercache_filename_str` filters are not supported.

Custom Nginx fragments can be placed under
`wp-content/uploads/getpagespeed-cache-configuration/default/` using Rocket-Nginx's conventions:
`start.*.conf`, `preprocess.*.conf`, `global.*.conf`, `http.*.conf`,
`css.*.conf`, `js.*.conf`, and `media.*.conf`.

## Filters

Server operators can customize advanced values in code:

- `getpagespeed_cache_configuration_output_path`
- `getpagespeed_cache_configuration_config_name`
- `getpagespeed_cache_configuration_wp_content_folder`
- `getpagespeed_cache_configuration_integration` (`wp-rocket` or `wp-super-cache`)
- `getpagespeed_cache_configuration_query_strings_ignore` (WP Rocket)
- `getpagespeed_cache_configuration_cache_reject_cookies` (WP Rocket)
- `getpagespeed_cache_configuration_mobile_user_agent` (WP Rocket)
- `getpagespeed_cache_configuration_html_cache_control`
- `getpagespeed_cache_configuration_css_expiration`
- `getpagespeed_cache_configuration_js_expiration`
- `getpagespeed_cache_configuration_media_expiration`

The action `getpagespeed_cache_configuration_config_written` fires after an atomic write.

## Development

```bash
make test       # Fast PHP and configuration checks
make e2e        # Real WordPress + Nginx Docker tests
make benchmark  # Compare identical responses through Nginx and WordPress/PHP
make package    # Build the release zip from committed files
```

The E2E suite creates WP Rocket-style and WP Super Cache-style cache files and
settings, and verifies that Nginx serves them. It covers the query-string/mobile path reported by James Garrigan
in [Rocket-Nginx issue #232](https://github.com/SatelliteWP/rocket-nginx/issues/232).

## Credits and license

The Nginx template is derived from Rocket-Nginx 3.1.2 by Maxime Jobin and
SatelliteWP. See [NOTICE](NOTICE) for attribution.

Released under the MIT License. WP Rocket is a trademark of WP Media. WP Super
Cache is a product of Automattic. This project is independent and is not
affiliated with or endorsed by WP Media, Automattic, SatelliteWP, or the
Rocket-Nginx project.
