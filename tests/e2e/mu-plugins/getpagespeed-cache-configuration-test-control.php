<?php
/**
 * Plugin Name: GetPageSpeed Cache Configuration E2E Control
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// WP Super Cache's advanced-cache.php drop-in defines this before plugins load.
if ( file_exists( WP_CONTENT_DIR . '/wp-cache-config.php' ) && ! defined( 'WPCACHEHOME' ) ) {
	define( 'WPCACHEHOME', WP_CONTENT_DIR . '/plugins/wp-super-cache/' );
}

add_action(
	'rest_api_init',
	function () {
		register_rest_route(
			'getpagespeed-cache-configuration-test/v1',
			'/wp-super-cache',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => 'getpagespeed_cache_configuration_test_configure_wp_super_cache',
			)
		);

		register_rest_route(
			'getpagespeed-cache-configuration-test/v1',
			'/check',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => function () {
					$writer  = GetPageSpeedCacheConfiguration\getpagespeed_cache_configuration()->get_config_writer();
					$checker = new GetPageSpeedCacheConfiguration\Include_Checker( $writer );

					return array(
						'updated' => true,
						'status'  => $checker->check(),
					);
				},
			)
		);

		register_rest_route(
			'getpagespeed-cache-configuration-test/v1',
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
			'getpagespeed-cache-configuration-test/v1',
			'/settings',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => function ( WP_REST_Request $request ) {
					$settings = $request->get_json_params();
					update_option( 'wp_rocket_settings', is_array( $settings ) ? $settings : array() );
					$result = GetPageSpeedCacheConfiguration\getpagespeed_cache_configuration()->regenerate_config();

					if ( is_wp_error( $result ) ) {
						return $result;
					}

					return array( 'updated' => true );
				},
			)
		);

		register_rest_route(
			'getpagespeed-cache-configuration-test/v1',
			'/cache',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => 'getpagespeed_cache_configuration_test_create_cache',
			)
		);

		register_rest_route(
			'getpagespeed-cache-configuration-test/v1',
			'/asset',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => 'getpagespeed_cache_configuration_test_create_asset',
			)
		);
	}
);

/**
 * Write wp-cache-config.php in WP Super Cache's documented format and regenerate.
 *
 * @param WP_REST_Request $request Request.
 * @return array|WP_Error
 */
function getpagespeed_cache_configuration_test_configure_wp_super_cache( WP_REST_Request $request ) {
	$data   = $request->get_json_params();
	$config = "<?php\n\$cache_enabled = true;\n\$super_cache_enabled = true;\n\$cache_path = WP_CONTENT_DIR . '/cache/';\n";

	foreach ( array( 'cache_compression', 'wp_cache_mobile_enabled', 'wp_cache_not_logged_in' ) as $name ) {
		$config .= '$' . $name . ' = ' . ( empty( $data[ $name ] ) ? 0 : 1 ) . ";\n";
	}
	$config .= "\$wp_cache_mobile_browsers = 'Android, iPhone';\n\$wp_cache_mobile_prefixes = '';\n";

	$file = WP_CONTENT_DIR . '/wp-cache-config.php';
	if ( false === file_put_contents( $file, $config ) ) {
		return new WP_Error( 'write_failed', 'Could not write wp-cache-config.php.', array( 'status' => 500 ) );
	}
	if ( function_exists( 'opcache_invalidate' ) ) {
		opcache_invalidate( $file, true );
	}
	if ( ! defined( 'WPCACHEHOME' ) ) {
		define( 'WPCACHEHOME', WP_CONTENT_DIR . '/plugins/wp-super-cache/' );
	}

	// A fresh plugin instance is not available mid-request, so use the admin_init change detection path.
	$writer = GetPageSpeedCacheConfiguration\getpagespeed_cache_configuration()->get_config_writer();
	$writer->maybe_regenerate();

	if ( '' !== $writer->get_last_error() ) {
		return new WP_Error( 'generate_failed', $writer->get_last_error(), array( 'status' => 500 ) );
	}

	return array(
		'updated' => true,
		'token'   => $writer->get_installed_probe_token(),
	);
}

/**
 * Create one deterministic WP Rocket-style or WP Super Cache-style cache file.
 *
 * @param WP_REST_Request $request Request.
 * @return array|WP_Error
 */
function getpagespeed_cache_configuration_test_create_cache( WP_REST_Request $request ) {
	$data    = $request->get_json_params();
	$path    = isset( $data['path'] ) ? (string) $data['path'] : '/';
	$args    = isset( $data['args'] ) ? (string) $data['args'] : '';
	$content = isset( $data['content'] ) ? (string) $data['content'] : 'cached';

	if ( ! preg_match( '~^/[A-Za-z0-9/_-]*$~D', $path ) || ( '' !== $args && ! preg_match( '/^[A-Za-z0-9_.~=&-]+$/D', $args ) ) ) {
		return new WP_Error( 'invalid_path', 'Invalid cache path.', array( 'status' => 400 ) );
	}

	$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );

	if ( isset( $data['layout'] ) && 'supercache' === $data['layout'] ) {
		// supercache/<host><path>/index[-https].html, with an optional .gz sibling.
		$directory = WP_CONTENT_DIR . '/cache/supercache/' . $host . trailingslashit( $path );
		$filename  = $directory . ( empty( $data['https'] ) ? 'index.html' : 'index-https.html' );
		$written   = wp_mkdir_p( $directory ) && false !== file_put_contents( $filename, $content );

		if ( $written && isset( $data['gzip_content'] ) ) {
			$written = false !== file_put_contents( $filename . '.gz', gzencode( (string) $data['gzip_content'] ) );
		}

		return $written
			? array(
				'created' => true,
				'file'    => $filename,
			)
			: new WP_Error( 'write_failed', 'Could not create cache file.', array( 'status' => 500 ) );
	}

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
function getpagespeed_cache_configuration_test_create_asset( WP_REST_Request $request ) {
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
