# Changelog

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
