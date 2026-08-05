<?php
/**
 * Fast unit tests for configuration generation.
 */

require __DIR__ . '/bootstrap.php';

use WPRocketNginx\Config_Writer;
use WPRocketNginx\Settings_Reader;

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
		assert_same( 'wp_rocket_nginx_config_written', $GLOBALS['wp_actions'][0][0], 'Integrations should receive the write action.' );
	}
);

test_case(
	'plugin entrypoint exposes a scope-independent singleton',
	function () {
		require_once WP_ROCKET_NGINX_PLUGIN_DIR . 'wp-rocket-nginx.php';
		$first  = WPRocketNginx\wp_rocket_nginx();
		$second = WPRocketNginx\wp_rocket_nginx();
		assert_true( $first instanceof WPRocketNginx\Plugin, 'Entrypoint must return the plugin instance.' );
		assert_true( $first === $second, 'Entrypoint must return the same instance on every call.' );
	}
);

echo "\n{$tests} tests, {$failures} failures\n";
exit( $failures > 0 ? 1 : 0 );
