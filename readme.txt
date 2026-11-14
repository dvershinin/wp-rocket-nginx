=== GetPageSpeed Cache Configuration ===
Contributors: dvershinin
Tags: cache, nginx, page cache, wp rocket, wp super cache
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.4.0
License: MIT
License URI: https://opensource.org/license/mit

Generates Nginx configuration from page-cache plugin settings so Nginx can serve cached pages without PHP.

== Description ==

GetPageSpeed Cache Configuration generates the Nginx configuration that locates
page-cache files on disk and serves them directly, without starting PHP.

It works with WP Rocket and WP Super Cache. The plugin detects which one is
active and mirrors the settings that decide how cache files are named and when a
request must bypass the cache. The cache plugin is still required to create and
purge the page cache.

A built-in check confirms that Nginx actually loaded the generated file, and
tells you when Nginx has not been reloaded since the last change.

The same check finds out whether Nginx compresses pages with Brotli. WP Rocket
writes gzip files only. When Brotli is in use, clients that accept it get the
page compressed with Brotli by Nginx. When it is not, they get WP Rocket's
pre-compressed gzip file instead of a page that Nginx gzips again on every
request.

The WP Rocket template is derived from the MIT-licensed Rocket-Nginx project by
Maxime Jobin and SatelliteWP. The WP Super Cache rules follow the conditions of
the Apache rules that WP Super Cache itself generates.

This plugin is made by GetPageSpeed. It is not affiliated with or endorsed by
WP Media (WP Rocket), Automattic (WP Super Cache), SatelliteWP (Rocket-Nginx),
or F5 (NGINX).

= Brotli =

Brotli makes pages smaller than gzip, and Nginx needs a module for it. One is
available from the [GetPageSpeed repository](https://nginx-extras.getpagespeed.com/modules/brotli/),
a subscription service with a free 30-day trial. Any Brotli module works: the
plugin looks for Brotli in the responses of your site, not for a particular
package.

= Monitor your server =

This plugin lets Nginx answer without PHP, but is Nginx itself healthy? If you
run your own server, [GetPageSpeed Amplify](https://amplify.getpagespeed.com/?ref=gcc-amplify-readme)
charts its requests, errors and configuration issues over time, with a free tier.

== Installation ==

1. Upload and activate the plugin.
2. Open Settings > GetPageSpeed Cache Configuration.
3. Include the displayed `default.conf` path in the site's Nginx server block.
4. Run `nginx -t` and reload Nginx.
5. Press "Check Nginx include" on the settings page to confirm Nginx loaded the file.

== Frequently Asked Questions ==

= Does this replace WP Rocket or WP Super Cache? =

No. The cache plugin creates and purges the cache files. This plugin lets Nginx
locate and serve those files without starting PHP.

= Which cache plugin is used when I switch? =

The active one. The configuration is regenerated the next time you open the
WordPress admin; reload Nginx afterwards.

= Do WP Super Cache settings apply? =

Yes: compression, mobile device support, extra and rejected cookies, ignored
tracking parameters and the "disable caching for visitors who have a cookie"
option. Addresses containing uppercase letters are answered by PHP.

= Does the plugin reload Nginx? =

No. Reloading the web server is deliberately left to the server operator.

= I enabled Brotli in Nginx. What now? =

Press "Check Nginx include". The check sees Brotli in the response of the front
page and regenerates the configuration; reload Nginx once more to apply it.

= Does the plugin contact external servers? =

No. The checks request addresses on your own site only. The links to
GetPageSpeed services on the settings page are plain links.

= Where is the configuration written? =

To the uploads directory: `wp-content/uploads/getpagespeed-cache-configuration/default.conf`.
The generated configuration denies HTTP access to that file.

== Changelog ==

= 1.4.0 =

* Detect whether Nginx compresses pages with Brotli as part of "Check Nginx include", and show the result on the settings page.
* WP Rocket: when Brotli is not in use, serve the pre-compressed gzip file to clients that accept Brotli instead of gzipping the page again on every request.

= 1.3.0 =

* Add WP Super Cache support, selected automatically from the active cache plugin.
* Add a "Check Nginx include" button that confirms Nginx loaded the current configuration.
* Regenerate automatically when the active cache plugin changes.

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

= 1.4.0 =

Press "Check Nginx include" once after upgrading so the plugin learns whether Brotli is in use, then reload Nginx if it reports a regenerated configuration.

= 1.3.0 =

Adds WP Super Cache support and an include check. The include line does not change; reload Nginx once to enable the check.

= 1.2.0 =

The configuration file moved to the uploads directory. Update the Nginx include line shown under Settings > GetPageSpeed Cache Configuration, then reload Nginx.
