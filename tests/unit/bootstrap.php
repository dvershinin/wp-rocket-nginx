<?php
/**
 * Minimal WordPress compatibility layer for fast hermetic unit tests.
 */

$test_root = sys_get_temp_dir() . '/getpagespeed-cache-configuration-unit-' . getmypid();
if ( ! is_dir( $test_root ) ) {
	mkdir( $test_root, 0777, true );
}

define( 'ABSPATH', $test_root . '/wordpress/' );
define( 'WP_CONTENT_DIR', $test_root . '/wordpress/wp-content' );
define( 'GETPAGESPEED_CACHE_CONFIGURATION_PLUGIN_DIR', dirname( __DIR__, 2 ) . '/' );

$GLOBALS['wp_options'] = array();
$GLOBALS['wp_filters'] = array();
$GLOBALS['wp_actions'] = array();

class WP_Error {
	private $code;
	private $message;

	public function __construct( $code, $message ) {
		$this->code    = $code;
		$this->message = $message;
	}

	public function get_error_code() {
		return $this->code;
	}

	public function get_error_message() {
		return $this->message;
	}
}

function get_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['wp_options'] ) ? $GLOBALS['wp_options'][ $name ] : $default;
}

function update_option( $name, $value, $autoload = null ) {
	$GLOBALS['wp_options'][ $name ] = $value;
	return true;
}

function delete_option( $name ) {
	unset( $GLOBALS['wp_options'][ $name ] );
	return true;
}

function is_wp_error( $value ) {
	return $value instanceof WP_Error;
}

function apply_filters( $tag, $value ) {
	if ( isset( $GLOBALS['wp_filters'][ $tag ] ) ) {
		foreach ( $GLOBALS['wp_filters'][ $tag ] as $callback ) {
			$value = $callback( $value );
		}
	}
	return $value;
}

function do_action( $tag ) {
	$GLOBALS['wp_actions'][] = array( $tag, array_slice( func_get_args(), 1 ) );
}

function trailingslashit( $value ) {
	return rtrim( $value, '/\\' ) . '/';
}

function untrailingslashit( $value ) {
	return rtrim( $value, '/\\' );
}

function content_url() {
	return isset( $GLOBALS['content_url'] ) ? $GLOBALS['content_url'] : 'https://example.com/wp-content';
}

function wp_parse_url( $url, $component = -1 ) {
	return parse_url( $url, $component );
}

function wp_normalize_path( $value ) {
	return str_replace( '\\', '/', $value );
}

function sanitize_file_name( $value ) {
	$value = preg_replace( '/[^A-Za-z0-9._-]/', '-', (string) $value );
	return trim( $value, '.-' );
}

function wp_upload_dir( $time = null, $create_dir = true ) {
	return array( 'basedir' => WP_CONTENT_DIR . '/uploads' );
}

function wp_mkdir_p( $path ) {
	return is_dir( $path ) || mkdir( $path, 0777, true );
}

function wp_delete_file( $file ) {
	if ( file_exists( $file ) ) {
		unlink( $file );
	}
}

function plugin_dir_path( $file ) {
	return trailingslashit( dirname( $file ) );
}

function plugin_basename( $file ) {
	return basename( $file );
}

function register_activation_hook( $file, $callback ) {
	$GLOBALS['activation_hook'] = array( $file, $callback );
}

function add_action( $tag, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['registered_actions'][ $tag ][] = $callback;
}

function add_filter( $tag, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['registered_filters'][ $tag ][] = $callback;
}

function home_url( $path = '' ) {
	return ( isset( $GLOBALS['home_url'] ) ? $GLOBALS['home_url'] : 'https://example.com' ) . $path;
}

function wp_json_encode( $value ) {
	return json_encode( $value );
}

function has_filter( $tag ) {
	return ! empty( $GLOBALS['wp_filters'][ $tag ] );
}

function wp_doing_ajax() {
	return ! empty( $GLOBALS['doing_ajax'] );
}

function current_user_can( $capability ) {
	return ! empty( $GLOBALS['current_user_caps'][ $capability ] );
}

function __( $text, $domain = 'default' ) {
	return $text;
}

foreach ( array( 'class-nginx-syntax', 'class-settings-reader', 'interface-integration', 'integrations/class-wp-rocket', 'integrations/class-wp-super-cache', 'class-integrations', 'class-config-writer', 'class-include-checker', 'class-brotli-checker', 'class-admin-page' ) as $file ) {
	require_once GETPAGESPEED_CACHE_CONFIGURATION_PLUGIN_DIR . 'includes/' . $file . '.php';
}
