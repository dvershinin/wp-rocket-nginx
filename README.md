# GetPageSpeed Cache Configuration

GetPageSpeed Cache Configuration is an unofficial WordPress integration that generates a
[Rocket-Nginx](https://github.com/SatelliteWP/rocket-nginx) configuration from
the active WP Rocket settings. Nginx can then serve existing WP Rocket cache
files directly from disk without starting PHP or WordPress.

The plugin does not replace WP Rocket and does not create page-cache files.
It mirrors the settings that determine how those files are named and when a
request must bypass cache.

## Requirements

- WordPress 6.0 or newer
- PHP 7.4 or newer
- Nginx
- WP Rocket for production cache generation
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

The configuration regenerates when WP Rocket settings change. Reloading Nginx
remains an explicit server operation. This keeps a WordPress administrator from
executing server commands through PHP.

## What is generated

The default output is:

```text
wp-content/uploads/getpagespeed-cache-configuration/default.conf
```

It includes:

- direct page-cache lookup for desktop, mobile, HTTPS, and gzip variants;
- query-string rules sourced from WP Rocket;
- cache bypasses for POST, authenticated users, carts, and maintenance mode;
- direct Nginx locations for CSS, JavaScript, images, and fonts;
- a deny rule preventing generated `.conf` files from being downloaded;
- optional diagnostic `X-Rocket-Nginx-*` headers.

Custom Nginx fragments can be placed under
`wp-content/uploads/getpagespeed-cache-configuration/default/` using Rocket-Nginx's conventions:
`start.*.conf`, `preprocess.*.conf`, `global.*.conf`, `http.*.conf`,
`css.*.conf`, `js.*.conf`, and `media.*.conf`.

## Filters

Server operators can customize advanced values in code:

- `getpagespeed_cache_configuration_output_path`
- `getpagespeed_cache_configuration_config_name`
- `getpagespeed_cache_configuration_wp_content_folder`
- `getpagespeed_cache_configuration_query_strings_ignore`
- `getpagespeed_cache_configuration_cache_reject_cookies`
- `getpagespeed_cache_configuration_mobile_user_agent`
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

The E2E suite creates real WP Rocket-style cache files and verifies that Nginx
serves them. It covers the query-string/mobile path reported by James Garrigan
in [Rocket-Nginx issue #232](https://github.com/SatelliteWP/rocket-nginx/issues/232).

## Credits and license

The Nginx template is derived from Rocket-Nginx 3.1.2 by Maxime Jobin and
SatelliteWP. See [NOTICE](NOTICE) for attribution.

Released under the MIT License. WP Rocket is a trademark of WP Media. This
project is independent and is not affiliated with or endorsed by WP Media,
SatelliteWP, or the Rocket-Nginx project.
