=== GetPageSpeed Cache Configuration ===
Contributors: dvershinin
Tags: cache, nginx, performance, page cache, wp rocket
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.2.1
License: MIT
License URI: https://opensource.org/license/mit

Generates Nginx configuration from page-cache plugin settings so Nginx can serve cached pages without PHP.

== Description ==

GetPageSpeed Cache Configuration generates the Nginx configuration that locates
page-cache files on disk and serves them directly, without starting PHP.

The current release supports WP Rocket: it mirrors the WP Rocket settings that
decide how cache files are named and when a request must bypass the cache. WP
Rocket is still required to create and purge the page cache.

The Nginx template is derived from the MIT-licensed Rocket-Nginx project by
Maxime Jobin and SatelliteWP.

This plugin is made by GetPageSpeed. It is not affiliated with or endorsed by
WP Media (WP Rocket), SatelliteWP (Rocket-Nginx), or F5 (NGINX).

== Installation ==

1. Upload and activate the plugin.
2. Open Settings > GetPageSpeed Cache Configuration.
3. Include the displayed `default.conf` path in the site's Nginx server block.
4. Run `nginx -t` and reload Nginx.

== Frequently Asked Questions ==

= Does this replace WP Rocket? =

No. WP Rocket creates and purges the cache files. This plugin lets Nginx locate
and serve those files without starting PHP.

= Does the plugin reload Nginx? =

No. Reloading the web server is deliberately left to the server operator.

= Where is the configuration written? =

To the uploads directory: `wp-content/uploads/getpagespeed-cache-configuration/default.conf`.
The generated configuration denies HTTP access to that file.

== Changelog ==

= 1.2.1 =

* Render the Nginx configuration from a plain placeholder template instead of a PHP template.
* Derive the public content folder from the content URL.

= 1.2.0 =

* Rename to GetPageSpeed Cache Configuration.
* Write the configuration to the uploads directory. Update the Nginx include line once after upgrading.
* Rename options and hooks to the getpagespeed_cache_configuration_ prefix and migrate existing options automatically.

= 1.1.0 =

* Rename options and hooks and migrate existing options automatically.
* Remove Update URI.

= 1.0.0 =

* Initial standalone release.
* Generate configuration from WP Rocket settings.
* Fix query-string paths used with separate mobile caches.
* Add atomic writes and deny HTTP access to generated configuration files.

== Upgrade Notice ==

= 1.2.0 =

The configuration file moved to the uploads directory. Update the Nginx include line shown under Settings > GetPageSpeed Cache Configuration, then reload Nginx.
