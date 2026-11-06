=== Rocket Nginx Bridge ===
Contributors: dvershinin
Tags: cache, nginx, performance, page cache, wp rocket
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.1.0
License: MIT
License URI: https://opensource.org/license/mit

Generates a Rocket-Nginx configuration from WP Rocket settings so Nginx can serve cached pages without PHP.

== Description ==

Rocket Nginx Bridge is an unofficial integration for Nginx servers running WP Rocket.
It generates the Nginx configuration that locates WP Rocket cache files and serves
them directly from disk.

This plugin is not affiliated with or endorsed by WP Media or WP Rocket.

WP Rocket is still required to create and purge the page cache. This plugin only
generates the matching Nginx configuration.

== Installation ==

1. Upload and activate the plugin.
2. Open Settings > Rocket Nginx Bridge.
3. Include the displayed `default.conf` path in the site's Nginx server block.
4. Run `nginx -t` and reload Nginx.

== Frequently Asked Questions ==

= Does this replace WP Rocket? =

No. WP Rocket creates and purges the cache files. This plugin lets Nginx locate
and serve those files without starting PHP.

= Does the plugin reload Nginx? =

No. Reloading the web server is deliberately left to the server operator.

= Where is the configuration written? =

By default it is written to `wp-content/wp-rocket-nginx/default.conf`.

== Changelog ==

= 1.1.0 =

* Rename WP Rocket Nginx to Rocket Nginx Bridge for wordpress.org.
* Rename options and hooks to the rocket_nginx_bridge_ prefix and migrate existing options automatically.
* Keep the nginx include path unchanged and remove Update URI.

= 1.0.0 =

* Initial standalone release.
* Generate configuration from WP Rocket settings.
* Fix query-string paths used with separate mobile caches.
* Add atomic writes and deny HTTP access to generated configuration files.

== Upgrade Notice ==

= 1.1.0 =

WP Rocket Nginx is now Rocket Nginx Bridge. Options migrate automatically; the nginx include path is unchanged. Update integrations to the rocket_nginx_bridge_ hook names.
