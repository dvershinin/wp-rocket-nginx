<?php
/**
 * Read and normalize settings used to generate the Nginx configuration.
 *
 * @package GetPageSpeedCacheConfiguration
 */

namespace GetPageSpeedCacheConfiguration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads this plugin's own settings and the values every integration shares.
 */
final class Settings_Reader {

	/** This plugin's settings option. */
	const OPTION_NAME = 'getpagespeed_cache_configuration_settings';

	/**
	 * Return this plugin's settings merged with defaults.
	 *
	 * @return array
	 */
	public function get_plugin_settings() {
		$settings = get_option( self::OPTION_NAME, array() );
		$settings = is_array( $settings ) ? $settings : array();
		return array_merge( array( 'debug' => false ), $settings );
	}

	/**
	 * Whether diagnostic response headers are enabled.
	 *
	 * @return bool
	 */
	public function is_debug_enabled() {
		$settings = $this->get_plugin_settings();
		return ! empty( $settings['debug'] );
	}

	/**
	 * Return the wp-content directory name used in public URLs.
	 *
	 * @return string
	 */
	public function get_wp_content_folder() {
		$path   = (string) wp_parse_url( content_url(), PHP_URL_PATH );
		$folder = sanitize_file_name( basename( untrailingslashit( $path ) ) );
		$folder = (string) apply_filters( 'getpagespeed_cache_configuration_wp_content_folder', $folder );
		return '' !== sanitize_file_name( $folder ) ? sanitize_file_name( $folder ) : 'wp-content';
	}

	/**
	 * Return the generated configuration directory.
	 *
	 * @return string
	 */
	public function get_output_path() {
		$uploads = wp_upload_dir( null, false );
		$default = trailingslashit( $uploads['basedir'] ) . 'getpagespeed-cache-configuration/';
		$path    = wp_normalize_path( (string) apply_filters( 'getpagespeed_cache_configuration_output_path', $default ) );

		if ( ! preg_match( '~^(?:[A-Za-z]:/|/)~', $path ) ) {
			$path = $default;
		}

		return trailingslashit( $path );
	}

	/**
	 * Return the output directory used before 1.2.0.
	 *
	 * Read-only: the plugin no longer writes here and only detects leftover files.
	 *
	 * @return string
	 */
	public function get_legacy_output_path() {
		// The pre-1.2.0 directory sat beside uploads in the content directory.
		$uploads = wp_upload_dir( null, false );
		return trailingslashit( dirname( wp_normalize_path( $uploads['basedir'] ) ) ) . 'wp-rocket-nginx/';
	}

	/**
	 * Return the generated configuration basename.
	 *
	 * @return string
	 */
	public function get_config_name() {
		$name = sanitize_file_name( (string) apply_filters( 'getpagespeed_cache_configuration_config_name', 'default' ) );
		return '' !== $name ? $name : 'default';
	}

	/**
	 * Return the Cache-Control value sent with cached pages.
	 *
	 * @param string $default_value Value the active integration prefers.
	 * @return string
	 */
	public function get_html_cache_control( $default_value = 'no-cache, no-store, must-revalidate' ) {
		return Nginx_Syntax::value( (string) apply_filters( 'getpagespeed_cache_configuration_html_cache_control', $default_value ) );
	}

	/** @return string */
	public function get_css_expiration() {
		return Nginx_Syntax::expiration( apply_filters( 'getpagespeed_cache_configuration_css_expiration', '90d' ) );
	}

	/** @return string */
	public function get_js_expiration() {
		return Nginx_Syntax::expiration( apply_filters( 'getpagespeed_cache_configuration_js_expiration', '90d' ) );
	}

	/** @return string */
	public function get_media_expiration() {
		return Nginx_Syntax::expiration( apply_filters( 'getpagespeed_cache_configuration_media_expiration', '90d' ) );
	}

	/** @return string */
	public function get_media_extensions() {
		return 'ico|gif|jpe?g|png|svg|eot|otf|woff2?|ttf|ogg|webp|avif';
	}
}
