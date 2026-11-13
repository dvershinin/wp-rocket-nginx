<?php
/**
 * Fast unit tests for configuration generation.
 */

require __DIR__ . '/bootstrap.php';

use GetPageSpeedCacheConfiguration\Admin_Page;
use GetPageSpeedCacheConfiguration\Config_Writer;
use GetPageSpeedCacheConfiguration\Include_Checker;
use GetPageSpeedCacheConfiguration\Integrations;
use GetPageSpeedCacheConfiguration\Integrations\WP_Rocket;
use GetPageSpeedCacheConfiguration\Integrations\WP_Super_Cache;
use GetPageSpeedCacheConfiguration\Settings_Reader;

$failures = 0;
$tests    = 0;

function test_case( $name, $callback ) {
	global $failures, $tests;
	++$tests;

	try {
		$callback();
		echo "PASS {$name}\n";
	} catch ( Throwable $error ) {
		++$failures;
		fwrite( STDERR, "FAIL {$name}: {$error->getMessage()}\n" );
	}
}

function assert_true( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function assert_same( $expected, $actual, $message ) {
	if ( $expected !== $actual ) {
		throw new RuntimeException(
			$message . ' Expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true )
		);
	}
}

function new_writer() {
	return new Config_Writer( new Settings_Reader() );
}

function new_rocket() {
	return new WP_Rocket( new Settings_Reader() );
}

/**
 * Run a callback while a simulated WP Super Cache configuration file exists.
 *
 * The file uses the documented wp-cache-config.php format: plain PHP variable assignments.
 */
function with_wpsc_config( $assignments, $callback ) {
	if ( ! defined( 'WPCACHEHOME' ) ) {
		define( 'WPCACHEHOME', WP_CONTENT_DIR . '/plugins/wp-super-cache/' );
	}

	$file = WP_CONTENT_DIR . '/wp-cache-config.php';
	wp_mkdir_p( WP_CONTENT_DIR );
	file_put_contents( $file, "<?php\n\$cache_enabled = true;\n\$super_cache_enabled = true;\n\$cache_path = WP_CONTENT_DIR . '/cache/';\n" . $assignments . "\n" );

	try {
		$callback();
	} finally {
		unlink( $file );
	}
}

test_case(
	'default settings are safe and deterministic',
	function () {
		$reader = new Settings_Reader();
		assert_same( false, $reader->is_debug_enabled(), 'Debug must default off.' );
		assert_same( '90d', $reader->get_css_expiration(), 'Static expiration should track Rocket-Nginx 3.1.2.' );
		assert_true( in_array( 'utm_source', new_rocket()->get_query_strings_ignore(), true ), 'UTM parameters should be ignored.' );
		assert_same( 'not_v_a_l_i_d', new_rocket()->get_mobile_user_agent(), 'Mobile matching must be disabled without separate mobile cache files.' );
	}
);

test_case(
	'WP Rocket query strings and cookies are normalized',
	function () {
		$GLOBALS['wp_options'][ WP_Rocket::OPTION_NAME ] = array(
			'cache_query_strings'    => array( 'amp', 'country', 'bad; include evil', 'amp' ),
			'cache_reject_cookies'   => array( 'membership_[0-9]+', "bad\nset evil" ),
			'cache_mobile'           => 1,
			'do_caching_mobile_files' => 1,
		);

		$reader = new_rocket();
		assert_same( array( 'amp', 'country' ), $reader->get_query_strings_cache(), 'Only unique valid parameter names are allowed.' );
		assert_true( in_array( 'membership_[0-9]+', $reader->get_cache_reject_cookies(), true ), 'Valid cookie regexes should survive.' );
		assert_true( ! in_array( "bad\nset evil", $reader->get_cache_reject_cookies(), true ), 'Directive injection must be rejected.' );
		assert_true( false !== strpos( $reader->get_mobile_user_agent(), 'iPhone' ), 'Mobile matching should follow WP Rocket settings.' );
	}
);

test_case(
	'generated config contains the query and mobile regression fix',
	function () {
		$GLOBALS['wp_options'][ Settings_Reader::OPTION_NAME ] = array( 'debug' => true );
		$config = new_writer()->generate();
		assert_true( ! is_wp_error( $config ), 'Generation should succeed.' );
		assert_true( false !== strpos( $config, 'set $rocket_args $args;' ), 'Arguments must always be initialized.' );
		assert_true( false !== strpos( $config, 'set $rocket_args "#$rocket_args";' ), 'Cached arguments must use WP Rocket folder syntax.' );
		assert_true( false !== strpos( $config, '$rocket_uri_path/$rocket_args/.mobile-active' ), 'Mobile marker must use normalized arguments.' );
		assert_true(
			strpos( $config, 'set $rocket_args "#$rocket_args";' ) < strpos( $config, 'set $rocket_mobile_detection' ),
			'Mobile detection must be calculated after argument normalization.'
		);
		assert_true( false !== strpos( $config, 'X-Rocket-Nginx-Serving-Static' ), 'Debug headers should be generated.' );
		assert_true( false !== strpos( $config, 'X-Rocket-Nginx-Serving-Static $rocket_is_bypassed always;' ), 'Debug headers must survive error responses.' );
	}
);

test_case(
	'generated config serves assets directly and protects configuration files',
	function () {
		$config = new_writer()->generate();
		assert_true( false !== strpos( $config, 'location ~* \\.css$' ), 'CSS must have an Nginx static location.' );
		assert_true( false !== strpos( $config, '/(?:getpagespeed-cache-configuration|wp-rocket-nginx)/.*\\.conf$' ), 'Generated and legacy configuration must be denied over HTTP.' );
		assert_true( false === strpos( $config, 'proxy_pass' ), 'Static assets must not be proxied.' );
		assert_true( false === strpos( $config, 'exec(' ), 'Generated configuration must not expose shell execution.' );
	}
);

test_case(
	'template placeholders are all filled and no PHP is rendered',
	function () {
		$config = new_writer()->generate();
		assert_true( ! is_wp_error( $config ), 'Generation should succeed.' );
		assert_same( 0, preg_match( '/\{\{\w+\}\}/', $config ), 'Every template placeholder must be replaced.' );
		assert_true( false === strpos( $config, '<?php' ), 'The template must not contain PHP.' );
		assert_same( 1, preg_match( '/^set \$rocket_debug [01];$/m', $config ), 'Debug must render as a 0/1 flag.' );
	}
);

test_case(
	'content folder is derived from the content URL',
	function () {
		assert_same( 'wp-content', ( new Settings_Reader() )->get_wp_content_folder(), 'Default content folder.' );
		$GLOBALS['content_url'] = 'https://example.com/blog/assets/';
		try {
			assert_same( 'assets', ( new Settings_Reader() )->get_wp_content_folder(), 'A renamed content URL must be honored.' );
			assert_true( false !== strpos( new_writer()->generate(), 'location ~ /assets/cache/wp-rocket/.*html$' ), 'Cache locations must use the content URL folder.' );
		} finally {
			unset( $GLOBALS['content_url'] );
		}
	}
);

test_case(
	'WP Rocket stays the default integration and a filter can force another',
	function () {
		$integrations = new Integrations( new Settings_Reader() );
		assert_same( array( 'wp-rocket', 'wp-super-cache' ), array_keys( $integrations->all() ), 'Both integrations must be registered as peers.' );
		assert_same( null, $integrations->get_detected(), 'Nothing is detected without a cache plugin.' );
		assert_same( 'wp-rocket', $integrations->get_active()->get_id(), 'Pre-1.3.0 behavior must be kept when nothing is detected.' );
		assert_true( false !== strpos( new_writer()->generate(), 'Serves WP Rocket cache files' ), 'The default configuration must be the WP Rocket one.' );

		$GLOBALS['wp_filters']['getpagespeed_cache_configuration_integration'] = array(
			function () {
				return 'wp-super-cache';
			},
		);
		try {
			assert_same( 'wp-super-cache', $integrations->get_active()->get_id(), 'The filter must force an integration.' );
		} finally {
			unset( $GLOBALS['wp_filters']['getpagespeed_cache_configuration_integration'] );
		}
	}
);

test_case(
	'the probe token identifies the generated content',
	function () {
		$GLOBALS['wp_options'][ Settings_Reader::OPTION_NAME ] = array( 'debug' => false );
		$first = new_writer()->generate();
		assert_same( 1, preg_match( '/return 200 "(gpscc:wp-rocket:[a-f0-9]{12})";/', $first, $one ), 'The probe location must carry a token.' );
		assert_same( $first, new_writer()->generate(), 'Generation must be deterministic.' );

		$GLOBALS['wp_options'][ Settings_Reader::OPTION_NAME ] = array( 'debug' => true );
		preg_match( '/return 200 "(gpscc:wp-rocket:[a-f0-9]{12})";/', new_writer()->generate(), $two );
		assert_true( $one[1] !== $two[1], 'Different content must produce a different token.' );
	}
);

test_case(
	'WP Super Cache is detected from its configuration file',
	function () {
		with_wpsc_config(
			'',
			function () {
				$integrations = new Integrations( new Settings_Reader() );
				assert_same( 'wp-super-cache', $integrations->get_detected()->get_id(), 'Enabled caching must be detected.' );

				$config = new_writer()->generate();
				assert_true( ! is_wp_error( $config ), 'Generation should succeed.' );
				assert_same( 0, preg_match( '/\{\{\w+\}\}/', $config ), 'Every template placeholder must be replaced.' );
				assert_true( false !== strpos( $config, 'set $gpscc_url "/wp-content/cache/supercache/$host$gpscc_dir/index$gpscc_suffix.html";' ), 'The lookup must follow the supercache layout.' );
				assert_true( false !== strpos( $config, 'location ~ ^/wp\-content/cache/supercache/.*\.html$ {' ), 'Cache files need a dedicated location.' );
				assert_true( false !== strpos( $config, '(comment_author_|wordpress_logged_in|wp-postpass_)' ), 'The vendor cookie rule must be mirrored.' );
				assert_true( false !== strpos( $config, 'add_header Cache-Control "max-age=3, must-revalidate";' ), 'The vendor Cache-Control must be the default.' );
				assert_true( false !== strpos( $config, 'gpscc:wp-super-cache:' ), 'The probe must name the integration.' );
				assert_true( false !== strpos( $config, 'location ~* \\.css$' ), 'Static asset locations are shared by every integration.' );
				assert_true( false === strpos( $config, '$rocket_' ) && false === strpos( $config, 'X-Rocket-Nginx' ), 'No WP Rocket names may leak into another integration.' );
				assert_same( array(), $integrations->get_active()->get_notices(), 'A healthy setup has no warnings.' );
			}
		);

		with_wpsc_config(
			'$cache_enabled = false;',
			function () {
				$integrations = new Integrations( new Settings_Reader() );
				assert_same( null, $integrations->get_detected(), 'Disabled caching must not be detected.' );
				assert_same( 1, count( $integrations->all()['wp-super-cache']->get_notices() ), 'Disabled caching must be reported.' );
			}
		);
	}
);

test_case(
	'WP Super Cache settings switch the optional rules',
	function () {
		with_wpsc_config(
			'',
			function () {
				$GLOBALS['wp_options']['permalink_structure'] = '/%postname%';
				$config = new_writer()->generate();
				assert_true( false === strpos( $config, 'No trailing slash"' ), 'No slash requirement without trailing-slash permalinks.' );
				assert_true( false === strpos( $config, '$gpscc_gzip' ), 'No gzip lookup while compression is off.' );
				assert_true( false === strpos( $config, 'Mobile user agent' ), 'No mobile rule while mobile support is off.' );
				assert_true( false === strpos( $config, 'if ($http_cookie != "")' ), 'Unrelated cookies must not bypass by default.' );
				assert_true( false === strpos( $config, 'utm_source' ), 'Tracking parameters are stripped only when WP Super Cache ignores them.' );
			}
		);

		with_wpsc_config(
			'$cache_compression = 1; $wp_cache_not_logged_in = 1; $wp_cache_mobile_enabled = 1;'
			. '$wp_cache_mobile_browsers = "Android, Elaine/3.0, 2.0 MMP"; $wp_cache_mobile_prefixes = "w3c , acs-";'
			. '$wpsc_ignore_tracking_parameters = 1; $wpsc_tracking_parameters = array( "utm_source", "bad name" );'
			. '$wpsc_cookies = array( "currency" ); $wpsc_rejected_cookies = array( "shop_[0-9]+" );',
			function () {
				$GLOBALS['wp_options']['permalink_structure'] = '/%postname%/';
				$config = new_writer()->generate();
				assert_true( false !== strpos( $config, 'if ($gpscc_uri_path !~ "/$") {' ), 'Trailing-slash permalinks require the slash.' );
				assert_true( false !== strpos( $config, 'if (-f "$gpscc_file$gpscc_gzip") {' ), 'Compression must look for the .gz sibling.' );
				assert_true( false !== strpos( $config, 'if ($http_cookie != "") {' ), 'The any-cookie setting must bypass.' );
				assert_true( false !== strpos( $config, 'if ($http_user_agent ~* "(Android|Elaine/3.0|2.0 MMP)") {' ), 'Mobile browsers must be matched as WP Super Cache writes them.' );
				assert_true( false !== strpos( $config, 'if ($http_user_agent ~* "^(w3c|acs-)") {' ), 'Mobile prefixes must be anchored.' );
				assert_true( false !== strpos( $config, 'utm_source=[^&]*' ), 'Configured tracking parameters must be stripped.' );
				assert_true( false === strpos( $config, 'bad name' ), 'Invalid parameter names must be dropped.' );
				assert_true( false !== strpos( $config, '|currency|shop_[0-9]+)' ), 'Extra and rejected cookies must bypass.' );
				unset( $GLOBALS['wp_options']['permalink_structure'] );
			}
		);
	}
);

test_case(
	'WP Super Cache values cannot inject Nginx directives',
	function () {
		with_wpsc_config(
			'$cache_path = "/srv/elsewhere/cache/"; $wp_cache_mobile_enabled = 1;'
			. '$wp_cache_mobile_browsers = "Android, evil\") { return 200; } #, open(, tail\\\\"; $wp_cache_mobile_prefixes = array( "x" );'
			. '$wpsc_cookies = array( "ok", "bad\"; return 200 \"x", "new\nline", "trail\\\\", "shop_(" );'
			. '$wpsc_rejected_cookies = array( "a\"b", "c;d", array( "x" ), "x)(?=NEVER", "dotted\\.name" );'
			. '$wpsc_ignore_tracking_parameters = 1; $wpsc_tracking_parameters = "not-an-array";',
			function () {
				$GLOBALS['wp_options']['blog_charset'] = 'UTF-8; return 200';
				$integration = new WP_Super_Cache( new Settings_Reader() );
				$config      = new_writer()->generate();

				assert_same( 'wp-content/cache/supercache', $integration->get_cache_uri(), 'A cache path outside the content directory must fall back to the default.' );
				assert_same( 1, count( $integration->get_notices() ), 'An unservable cache path must be reported.' );
				assert_true( false === strpos( $config, 'elsewhere' ), 'A foreign path must never reach the configuration.' );
				assert_true( false === strpos( $config, 'return 200;' ) && false === strpos( $config, 'return 200 "x' ), 'Directive injection must be rejected.' );
				assert_true( false !== strpos( $config, 'if ($http_user_agent ~* "(Android)") {' ), 'Only clean mobile browsers survive.' );
				assert_true( false !== strpos( $config, '(comment_author_|wordpress_logged_in|wp-postpass_|ok|dotted\.name)' ), 'Only clean, complete cookie patterns survive.' );
				assert_same( 1, count( $integration->get_notices() ), 'Sanity: still one notice.' );
				assert_true( false !== strpos( $config, 'charset UTF-8;' ), 'An invalid charset must fall back to UTF-8.' );
				assert_same( 2, preg_match_all( '/^\s*charset /m', $config ), 'Only the two cache locations declare a charset.' );
				unset( $GLOBALS['wp_options']['blog_charset'] );
			}
		);

		with_wpsc_config(
			'$cache_path = WP_CONTENT_DIR . "/cache/../../etc/";',
			function () {
				assert_same( 'wp-content/cache/supercache', ( new WP_Super_Cache( new Settings_Reader() ) )->get_cache_uri(), 'Parent-directory segments must be rejected.' );
			}
		);

		with_wpsc_config(
			'$cache_path = WP_CONTENT_DIR . "/site.cache/pages";',
			function () {
				$config = new_writer()->generate();
				assert_true( false !== strpos( $config, '"/wp-content/site.cache/pages/supercache/$host' ), 'A relocated cache inside the content directory must be honored.' );
				assert_true( false !== strpos( $config, 'location ~ ^/wp\-content/site\.cache/pages/supercache/.*\.html$ {' ), 'The location regex must escape the path.' );
			}
		);
	}
);

test_case(
	'a broken WP Super Cache configuration file falls back to defaults',
	function () {
		with_wpsc_config(
			'this is not php (',
			function () {
				$integrations = new Integrations( new Settings_Reader() );
				assert_same( null, $integrations->get_detected(), 'An unreadable configuration must not be detected.' );
			}
		);
	}
);

test_case(
	'a changed cache plugin or configuration file regenerates once',
	function () {
		$GLOBALS['wp_options'] = array();
		$writer                = new_writer();
		assert_same( true, $writer->maybe_regenerate(), 'The first run after an upgrade must regenerate.' );
		assert_same( 'wp-rocket:', get_option( Config_Writer::SOURCE_TOKEN_OPTION ), 'The source token must be recorded.' );
		assert_same( false, $writer->maybe_regenerate(), 'An unchanged source must not regenerate.' );

		with_wpsc_config(
			'',
			function () {
				assert_same( true, new_writer()->maybe_regenerate(), 'Switching the cache plugin must regenerate.' );
				assert_true( false !== strpos( file_get_contents( new_writer()->get_output_file() ), 'gpscc:wp-super-cache:' ), 'The installed file must follow the detected plugin.' );
				assert_same( false, new_writer()->maybe_regenerate(), 'An unchanged configuration file must not regenerate.' );

				file_put_contents( WP_CONTENT_DIR . '/wp-cache-config.php', "\n\$cache_compression = 1;\n", FILE_APPEND );
				assert_same( true, new_writer()->maybe_regenerate(), 'A rewritten configuration file must regenerate.' );
				assert_true( false !== strpos( file_get_contents( new_writer()->get_output_file() ), '$gpscc_gzip' ), 'The new setting must reach the installed file.' );
			}
		);

		assert_same( true, new_writer()->maybe_regenerate(), 'Deactivating the cache plugin must regenerate.' );
	}
);

test_case(
	'only an administrator page load can trigger change detection',
	function () {
		require_once GETPAGESPEED_CACHE_CONFIGURATION_PLUGIN_DIR . 'getpagespeed-cache-configuration.php';
		$plugin = GetPageSpeedCacheConfiguration\getpagespeed_cache_configuration();
		$GLOBALS['wp_options'] = array();

		assert_same( false, $plugin->maybe_regenerate_config(), 'An anonymous admin-post request must not write.' );
		$GLOBALS['current_user_caps'] = array( 'manage_options' => true );
		$GLOBALS['doing_ajax']        = true;
		assert_same( false, $plugin->maybe_regenerate_config(), 'An AJAX request must not write.' );
		assert_same( false, get_option( Config_Writer::SOURCE_TOKEN_OPTION ), 'Nothing may be recorded for refused requests.' );
		$GLOBALS['doing_ajax'] = false;
		assert_same( true, $plugin->maybe_regenerate_config(), 'An administrator page load regenerates.' );
		unset( $GLOBALS['current_user_caps'], $GLOBALS['doing_ajax'] );
	}
);

test_case(
	'a foreign rewrite-condition filter is reported',
	function () {
		with_wpsc_config(
			'',
			function () {
				$GLOBALS['wp_filters']['supercacherewriteconditions'] = array( 'strval' );
				try {
					assert_same( 1, count( ( new WP_Super_Cache( new Settings_Reader() ) )->get_notices() ), 'Untranslatable vendor conditions must be reported.' );
				} finally {
					unset( $GLOBALS['wp_filters']['supercacherewriteconditions'] );
				}
			}
		);
	}
);

test_case(
	'regex fragments must be complete patterns',
	function () {
		$kept = GetPageSpeedCacheConfiguration\Nginx_Syntax::regex_fragments( array( 'membership_[0-9]+', 'a|b', 'end$', 'open(', 'close)', 'x)(?=y', 'trail\\', 'two\\\\', "ctl\x01", '[bad' ) );
		assert_same( array( 'membership_[0-9]+', 'a|b', 'end$' ), $kept, 'Unbalanced, dangling and control-character fragments must be dropped.' );
	}
);

test_case(
	'the include check tells active, stale, missing and unreachable apart',
	function () {
		$writer = new_writer();
		assert_same( true, $writer->write(), 'Write should succeed.' );
		$token = $writer->get_installed_probe_token();
		assert_same( 1, preg_match( '/^gpscc:wp-rocket:[a-f0-9]{12}$/', $token ), 'The installed file must expose its token.' );

		$requested = array();
		$check     = function ( $response ) use ( $writer, &$requested ) {
			$checker = new Include_Checker(
				$writer,
				function ( $url ) use ( $response, &$requested ) {
					$requested[] = $url;
					return $response;
				}
			);
			return $checker->check();
		};

		assert_same( Include_Checker::STATUS_ACTIVE, $check( array( 'code' => 200, 'body' => $token . "\n" ) ), 'A matching token means Nginx loaded this file.' );
		assert_same( Include_Checker::STATUS_STALE, $check( array( 'code' => 200, 'body' => 'gpscc:wp-rocket:000000000000' ) ), 'Another token means Nginx was not reloaded.' );
		assert_same( Include_Checker::STATUS_MISSING, $check( array( 'code' => 404, 'body' => '<html>Not found</html>' ) ), 'A WordPress 404 means the include is missing.' );
		assert_same( Include_Checker::STATUS_MISSING, $check( array( 'code' => 200, 'body' => '<html>Home page</html>' ) ), 'A page that is not the probe means the include is missing.' );
		assert_same( Include_Checker::STATUS_MISSING, $check( array( 'code' => 403, 'body' => $token ) ), 'Only a 200 answer counts.' );
		assert_same( Include_Checker::STATUS_UNREACHABLE, $check( new WP_Error( 'http_request_failed', 'timeout' ) ), 'A failed request is inconclusive.' );
		assert_same( 'https://example.com/getpagespeed-cache-configuration-probe', $requested[0], 'The probe is requested on the site host.' );

		$GLOBALS['home_url'] = 'http://example.com:8080/blog';
		try {
			$check( array() );
			assert_same( 'http://example.com:8080/getpagespeed-cache-configuration-probe', end( $requested ), 'A subdirectory install is probed at the server root.' );
		} finally {
			unset( $GLOBALS['home_url'] );
		}

		unlink( $writer->get_output_file() );
		assert_same( Include_Checker::STATUS_NOT_GENERATED, $check( array( 'code' => 200, 'body' => $token ) ), 'Nothing to compare without a generated file.' );
	}
);

test_case(
	'every include-check status has a notice and unknown input has none',
	function () {
		$page = new Admin_Page( new_writer() );
		foreach ( array( 'active', 'stale', 'missing', 'unreachable', 'not_generated' ) as $status ) {
			$notice = $page->get_check_notice( $status );
			assert_true( is_array( $notice ) && 0 === strpos( $notice[0], 'notice-' ) && '' !== $notice[1], "Status {$status} needs a notice." );
		}
		assert_same( null, $page->get_check_notice( 'anything-else' ), 'Unknown query values must render nothing.' );
		assert_same( null, $page->get_check_notice( '' ), 'No flag must render nothing.' );
	}
);

test_case(
	'configuration is written under the uploads directory',
	function () {
		$reader = new Settings_Reader();
		assert_same( WP_CONTENT_DIR . '/uploads/getpagespeed-cache-configuration/', $reader->get_output_path(), 'Default output must live in uploads.' );
		assert_same( WP_CONTENT_DIR . '/uploads/getpagespeed-cache-configuration/default.conf', new_writer()->get_output_file(), 'Default file must live in uploads.' );
		assert_same( WP_CONTENT_DIR . '/wp-rocket-nginx/default.conf', new_writer()->get_legacy_output_file(), 'Legacy path is only reported for the move notice.' );
	}
);

test_case(
	'write installs a complete file and records success',
	function () {
		$writer = new_writer();
		$result = $writer->write();
		assert_same( true, $result, 'Write should succeed.' );
		assert_true( is_file( $writer->get_output_file() ), 'Output file should exist.' );
		assert_true( filesize( $writer->get_output_file() ) > 1000, 'Output file should be complete.' );
		assert_true( is_int( $writer->get_last_generated() ), 'Generation time should be recorded.' );
		assert_same( '', $writer->get_last_error(), 'Successful write should clear old errors.' );
		assert_same( 'getpagespeed_cache_configuration_config_written', $GLOBALS['wp_actions'][0][0], 'Integrations should receive the write action.' );
	}
);

test_case(
	'plugin entrypoint exposes a scope-independent singleton',
	function () {
		require_once GETPAGESPEED_CACHE_CONFIGURATION_PLUGIN_DIR . 'getpagespeed-cache-configuration.php';
		$first  = GetPageSpeedCacheConfiguration\getpagespeed_cache_configuration();
		$second = GetPageSpeedCacheConfiguration\getpagespeed_cache_configuration();
		assert_true( $first instanceof GetPageSpeedCacheConfiguration\Plugin, 'Entrypoint must return the plugin instance.' );
		assert_true( $first === $second, 'Entrypoint must return the same instance on every call.' );
	}
);

test_case(
	'legacy options migrate once and old values are deleted',
	function () {
		$GLOBALS['wp_options'] = array(
			'wp_rocket_nginx_settings'       => array( 'debug' => true ),
			'wp_rocket_nginx_last_generated' => 123,
			'wp_rocket_nginx_last_error'     => false,
		);
		GetPageSpeedCacheConfiguration\getpagespeed_cache_configuration()->init();
		foreach ( array( 'settings' => array( 'debug' => true ), 'last_generated' => 123, 'last_error' => false ) as $suffix => $value ) {
			assert_same( $value, get_option( 'getpagespeed_cache_configuration_' . $suffix ), 'Legacy value must be copied.' );
			assert_true( ! array_key_exists( 'wp_rocket_nginx_' . $suffix, $GLOBALS['wp_options'] ), 'Migrated old option must be deleted.' );
		}
		assert_same( true, get_option( 'getpagespeed_cache_configuration_options_migrated' ), 'Migration must be marked complete.' );
		$after = $GLOBALS['wp_options'];
		GetPageSpeedCacheConfiguration\getpagespeed_cache_configuration()->migrate_options();
		assert_same( $after, $GLOBALS['wp_options'], 'Repeated migration must be idempotent.' );
		$GLOBALS['wp_options']['wp_rocket_nginx_last_error'] = 'late legacy value';
		GetPageSpeedCacheConfiguration\getpagespeed_cache_configuration()->migrate_options();
		assert_same( 'late legacy value', get_option( 'wp_rocket_nginx_last_error' ), 'Completed migration must skip legacy options.' );
	}
);

test_case(
	'1.1.0 options migrate and win over 1.0.0 leftovers',
	function () {
		$GLOBALS['wp_options'] = array(
			'rocket_nginx_bridge_settings' => array( 'debug' => true ),
			'wp_rocket_nginx_settings'     => array( 'debug' => false ),
			'wp_rocket_nginx_last_error'   => 'oldest',
		);
		GetPageSpeedCacheConfiguration\getpagespeed_cache_configuration()->migrate_options();
		assert_same( array( 'debug' => true ), get_option( 'getpagespeed_cache_configuration_settings' ), 'The 1.1.0 value must win.' );
		assert_same( 'oldest', get_option( 'getpagespeed_cache_configuration_last_error' ), 'A 1.0.0 value fills a gap.' );
		assert_true( ! array_key_exists( 'rocket_nginx_bridge_settings', $GLOBALS['wp_options'] ), 'Migrated 1.1.0 option must be deleted.' );
	}
);

test_case(
	'existing new options are untouched by migration',
	function () {
		$GLOBALS['wp_options'] = array();
		foreach ( array( 'settings', 'last_generated', 'last_error' ) as $suffix ) {
			$GLOBALS['wp_options'][ 'wp_rocket_nginx_' . $suffix ] = 'old';
			$GLOBALS['wp_options'][ 'getpagespeed_cache_configuration_' . $suffix ] = false;
		}
		GetPageSpeedCacheConfiguration\getpagespeed_cache_configuration()->migrate_options();
		foreach ( array( 'settings', 'last_generated', 'last_error' ) as $suffix ) {
			assert_same( false, get_option( 'getpagespeed_cache_configuration_' . $suffix ), 'Existing new value must remain untouched, including false.' );
			assert_same( 'old', get_option( 'wp_rocket_nginx_' . $suffix ), 'Unmigrated old value must remain untouched.' );
		}
	}
);

test_case(
	'migration with neither option present creates no data options',
	function () {
		$GLOBALS['wp_options'] = array();
		GetPageSpeedCacheConfiguration\getpagespeed_cache_configuration()->migrate_options();
		assert_same( array( 'getpagespeed_cache_configuration_options_migrated' => true ), $GLOBALS['wp_options'], 'Only the one-time guard may be created.' );
	}
);

echo "\n{$tests} tests, {$failures} failures\n";
exit( $failures > 0 ? 1 : 0 );
