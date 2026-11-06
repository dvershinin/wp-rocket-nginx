<?php
/**
 * Fast unit tests for configuration generation.
 */

require __DIR__ . '/bootstrap.php';

use RocketNginxBridge\Config_Writer;
use RocketNginxBridge\Settings_Reader;

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

test_case(
	'default settings are safe and deterministic',
	function () {
		$reader = new Settings_Reader();
		assert_same( false, $reader->is_debug_enabled(), 'Debug must default off.' );
		assert_same( '90d', $reader->get_css_expiration(), 'Static expiration should track Rocket-Nginx 3.1.2.' );
		assert_true( in_array( 'utm_source', $reader->get_query_strings_ignore(), true ), 'UTM parameters should be ignored.' );
		assert_same( 'not_v_a_l_i_d', $reader->get_mobile_user_agent(), 'Mobile matching must be disabled without separate mobile cache files.' );
	}
);

test_case(
	'WP Rocket query strings and cookies are normalized',
	function () {
		$GLOBALS['wp_options'][ Settings_Reader::WP_ROCKET_OPTION ] = array(
			'cache_query_strings'    => array( 'amp', 'country', 'bad; include evil', 'amp' ),
			'cache_reject_cookies'   => array( 'membership_[0-9]+', "bad\nset evil" ),
			'cache_mobile'           => 1,
			'do_caching_mobile_files' => 1,
		);

		$reader = new Settings_Reader();
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
		assert_true( false !== strpos( $config, 'wp-rocket-nginx/.*\\.conf$' ), 'Generated configuration must be denied over HTTP.' );
		assert_true( false === strpos( $config, 'proxy_pass' ), 'Static assets must not be proxied.' );
		assert_true( false === strpos( $config, 'exec(' ), 'Generated configuration must not expose shell execution.' );
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
		assert_same( 'rocket_nginx_bridge_config_written', $GLOBALS['wp_actions'][0][0], 'Integrations should receive the write action.' );
	}
);

test_case(
	'plugin entrypoint exposes a scope-independent singleton',
	function () {
		require_once ROCKET_NGINX_BRIDGE_PLUGIN_DIR . 'rocket-nginx-bridge.php';
		$first  = RocketNginxBridge\rocket_nginx_bridge();
		$second = RocketNginxBridge\rocket_nginx_bridge();
		assert_true( $first instanceof RocketNginxBridge\Plugin, 'Entrypoint must return the plugin instance.' );
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
		RocketNginxBridge\rocket_nginx_bridge()->init();
		foreach ( array( 'settings' => array( 'debug' => true ), 'last_generated' => 123, 'last_error' => false ) as $suffix => $value ) {
			assert_same( $value, get_option( 'rocket_nginx_bridge_' . $suffix ), 'Legacy value must be copied.' );
			assert_true( ! array_key_exists( 'wp_rocket_nginx_' . $suffix, $GLOBALS['wp_options'] ), 'Migrated old option must be deleted.' );
		}
		assert_same( true, get_option( 'rocket_nginx_bridge_options_migrated' ), 'Migration must be marked complete.' );
		$after = $GLOBALS['wp_options'];
		RocketNginxBridge\rocket_nginx_bridge()->migrate_options();
		assert_same( $after, $GLOBALS['wp_options'], 'Repeated migration must be idempotent.' );
		$GLOBALS['wp_options']['wp_rocket_nginx_last_error'] = 'late legacy value';
		RocketNginxBridge\rocket_nginx_bridge()->migrate_options();
		assert_same( 'late legacy value', get_option( 'wp_rocket_nginx_last_error' ), 'Completed migration must skip legacy options.' );
	}
);

test_case(
	'existing new options are untouched by migration',
	function () {
		$GLOBALS['wp_options'] = array();
		foreach ( array( 'settings', 'last_generated', 'last_error' ) as $suffix ) {
			$GLOBALS['wp_options'][ 'wp_rocket_nginx_' . $suffix ] = 'old';
			$GLOBALS['wp_options'][ 'rocket_nginx_bridge_' . $suffix ] = false;
		}
		RocketNginxBridge\rocket_nginx_bridge()->migrate_options();
		foreach ( array( 'settings', 'last_generated', 'last_error' ) as $suffix ) {
			assert_same( false, get_option( 'rocket_nginx_bridge_' . $suffix ), 'Existing new value must remain untouched, including false.' );
			assert_same( 'old', get_option( 'wp_rocket_nginx_' . $suffix ), 'Unmigrated old value must remain untouched.' );
		}
	}
);

test_case(
	'migration with neither option present creates no data options',
	function () {
		$GLOBALS['wp_options'] = array();
		RocketNginxBridge\rocket_nginx_bridge()->migrate_options();
		assert_same( array( 'rocket_nginx_bridge_options_migrated' => true ), $GLOBALS['wp_options'], 'Only the one-time guard may be created.' );
	}
);

echo "\n{$tests} tests, {$failures} failures\n";
exit( $failures > 0 ? 1 : 0 );
