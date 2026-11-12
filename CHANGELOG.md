# Changelog

## 1.2.1 - 2026-11-12

- Render the Nginx configuration from a plain `templates/rocket-nginx.conf` with `{{placeholders}}` instead of a PHP template that echoed values (wordpress.org review).
- Derive the public content folder from `content_url()` rather than `WP_CONTENT_DIR`; the read-only legacy-path check now uses the uploads directory's parent.

## 1.2.0 - 2026-11-11

- Rename Rocket Nginx Bridge to GetPageSpeed Cache Configuration (slug `getpagespeed-cache-configuration`) following wordpress.org review.
- Write the configuration to `wp-content/uploads/getpagespeed-cache-configuration/` instead of `wp-content/wp-rocket-nginx/`. Update the Nginx `include` line once; an admin notice shows both paths while the old file exists.
- Rename options, hooks and filters to the `getpagespeed_cache_configuration_` prefix; options from 1.0.0 and 1.1.0 migrate automatically.

## 1.1.0 - 2026-11-07

- Rename WP Rocket Nginx to Rocket Nginx Bridge for wordpress.org submission.
- Rename options and hooks from `wp_rocket_nginx_` to `rocket_nginx_bridge_`; all filter and action names using that prefix changed, including `wp_rocket_nginx_config_written` to `rocket_nginx_bridge_config_written`.
- Automatically migrate existing options without overwriting new values.
- Keep the nginx include path unchanged.
- Remove the Update URI header.

## 1.0.0 - 2026-08-06

- Release the WordPress integration as a standalone plugin.
- Generate Rocket-Nginx configuration from active WP Rocket settings.
- Fix query-string paths used by separate mobile cache files.
- Add atomic configuration writes and block HTTP access to generated files.
- Add hermetic unit tests and WordPress/Nginx end-to-end tests.
