<?php
/**
 * Minimal WordPress compatibility layer for fast hermetic unit tests.
 */

$test_root = sys_get_temp_dir() . '/rocket-nginx-bridge-unit-' . getmypid();
if ( ! is_dir( $test_root ) ) {
	mkdir( $test_root, 0777, true );
}

define( 'ABSPATH', $test_root . '/wordpress/' );
define( 'WP_CONTENT_DIR', $test_root . '/wordpress/wp-content' );
define( 'ROCKET_NGINX_BRIDGE_PLUGIN_DIR', dirname( __DIR__, 2 ) . '/' );

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

function wp_normalize_path( $value ) {
	return str_replace( '\\', '/', $value );
}

function sanitize_file_name( $value ) {
	$value = preg_replace( '/[^A-Za-z0-9._-]/', '-', (string) $value );
	return trim( $value, '.-' );
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

require_once ROCKET_NGINX_BRIDGE_PLUGIN_DIR . 'includes/class-settings-reader.php';
require_once ROCKET_NGINX_BRIDGE_PLUGIN_DIR . 'includes/class-config-writer.php';
