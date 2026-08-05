<?php
/**
 * Plugin Name: WP Rocket Nginx E2E Control
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action(
	'rest_api_init',
	function () {
		register_rest_route(
			'wp-rocket-nginx-test/v1',
			'/health',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => function () {
					return array( 'status' => 'ok' );
				},
			)
		);

		register_rest_route(
			'wp-rocket-nginx-test/v1',
			'/settings',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => function ( WP_REST_Request $request ) {
					$settings = $request->get_json_params();
					update_option( 'wp_rocket_settings', is_array( $settings ) ? $settings : array() );
					$result = WPRocketNginx\wp_rocket_nginx()->regenerate_config();

					if ( is_wp_error( $result ) ) {
						return $result;
					}

					return array( 'updated' => true );
				},
			)
		);

		register_rest_route(
			'wp-rocket-nginx-test/v1',
			'/cache',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => 'wp_rocket_nginx_test_create_cache',
			)
		);

		register_rest_route(
			'wp-rocket-nginx-test/v1',
			'/asset',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => 'wp_rocket_nginx_test_create_asset',
			)
		);
	}
);

/**
 * Create one deterministic WP Rocket-style cache file.
 *
 * @param WP_REST_Request $request Request.
 * @return array|WP_Error
 */
function wp_rocket_nginx_test_create_cache( WP_REST_Request $request ) {
	$data    = $request->get_json_params();
	$path    = isset( $data['path'] ) ? (string) $data['path'] : '/';
	$args    = isset( $data['args'] ) ? (string) $data['args'] : '';
	$content = isset( $data['content'] ) ? (string) $data['content'] : 'cached';

	if ( ! preg_match( '~^/[A-Za-z0-9/_-]*$~D', $path ) || ( '' !== $args && ! preg_match( '/^[A-Za-z0-9_.~=&-]+$/D', $args ) ) ) {
		return new WP_Error( 'invalid_path', 'Invalid cache path.', array( 'status' => 400 ) );
	}

	$host      = (string) wp_parse_url( home_url(), PHP_URL_HOST );
	$directory = WP_CONTENT_DIR . '/cache/wp-rocket/' . $host . trailingslashit( $path );
	if ( '' !== $args ) {
		$directory .= '#' . $args . '/';
	}

	if ( ! wp_mkdir_p( $directory ) ) {
		return new WP_Error( 'mkdir_failed', 'Could not create cache directory.', array( 'status' => 500 ) );
	}

	$filename = 'index';
	if ( ! empty( $data['mobile'] ) ) {
		$filename .= '-mobile';
	}
	if ( ! empty( $data['https'] ) ) {
		$filename .= '-https';
	}
	$filename .= '.html';

	if ( false === file_put_contents( $directory . $filename, $content ) ) {
		return new WP_Error( 'write_failed', 'Could not create cache file.', array( 'status' => 500 ) );
	}

	if ( ! empty( $data['mobile_marker'] ) ) {
		file_put_contents( $directory . '.mobile-active', '' );
	}

	return array(
		'created' => true,
		'file'    => $directory . $filename,
	);
}

/**
 * Create a static asset inside wp-content.
 *
 * @param WP_REST_Request $request Request.
 * @return array|WP_Error
 */
function wp_rocket_nginx_test_create_asset( WP_REST_Request $request ) {
	$data    = $request->get_json_params();
	$path    = isset( $data['path'] ) ? (string) $data['path'] : '';
	$content = isset( $data['content'] ) ? (string) $data['content'] : '';

	if ( ! preg_match( '~^/[A-Za-z0-9/_.-]+$~D', $path ) || false !== strpos( $path, '..' ) ) {
		return new WP_Error( 'invalid_path', 'Invalid asset path.', array( 'status' => 400 ) );
	}

	$filename = WP_CONTENT_DIR . $path;
	if ( ! wp_mkdir_p( dirname( $filename ) ) || false === file_put_contents( $filename, $content ) ) {
		return new WP_Error( 'write_failed', 'Could not create asset.', array( 'status' => 500 ) );
	}

	return array( 'created' => true );
}
