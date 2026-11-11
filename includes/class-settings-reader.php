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
 * Maps WordPress and WP Rocket settings to safe Rocket-Nginx values.
 */
final class Settings_Reader {

	/** WP Rocket's settings option. */
	const WP_ROCKET_OPTION = 'wp_rocket_settings';

	/** This plugin's settings option. */
	const OPTION_NAME = 'getpagespeed_cache_configuration_settings';

	/** Default mobile user-agent pattern used by Rocket-Nginx. */
	const DEFAULT_MOBILE_UA = '2.0 MMP|240x320|400X240|AvantGo|BlackBerry|Blazer|Cellphone|Danger|DoCoMo|Elaine/3.0|EudoraWeb|Googlebot-Mobile|hiptop|IEMobile|KYOCERA/WX310K|LG/U990|MIDP-2.|MMEF20|MOT-V|NetFront|Newt|Nintendo Wii|Nitro|Nokia|Opera Mini|Palm|PlayStation Portable|portalmmm|Proxinet|ProxiNet|SHARP-TQ-GX10|SHG-i900|Small|SonyEricsson|Symbian OS|SymbianOS|TS21i-10|UP.Browser|UP.Link|webOS|Windows CE|WinWAP|YahooSeeker/M1A1-R2D2|iPhone|iPad|iPod|Android';

	/** Query parameters that do not change page content. */
	const DEFAULT_QUERY_STRINGS_IGNORE = array(
		'utm_source',
		'utm_medium',
		'utm_campaign',
		'utm_term',
		'utm_content',
		'utm_expid',
		'_ga',
		'_gl',
		'_kx',
		'gclid',
		'gclsrc',
		'gad_source',
		'gbraid',
		'wbraid',
		'fbclid',
		'fb_action_ids',
		'fb_action_types',
		'fb_source',
		'mc_cid',
		'mc_eid',
		'msclkid',
		'mtm_source',
		'mtm_medium',
		'mtm_campaign',
		'mtm_keyword',
		'mtm_cid',
		'mtm_content',
		'trk_contact',
		'trk_msg',
		'trk_module',
		'trk_sid',
		'age-hierarchical-display',
		'ao_noptimize',
		'usqp',
	);

	/** Cookies that require WordPress/PHP. */
	const DEFAULT_COOKIES = array(
		'wordpress_logged_in_',
		'wp-postpass_',
		'woocommerce_items_in_cart',
		'woocommerce_cart_hash',
		'wptouch_switch_toggle',
		'comment_author_',
		'comment_author_email_',
	);

	/**
	 * Return WP Rocket settings.
	 *
	 * @return array
	 */
	public function get_wp_rocket_settings() {
		$settings = get_option( self::WP_ROCKET_OPTION, array() );
		return is_array( $settings ) ? $settings : array();
	}

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
	 * Whether WP Rocket creates separate mobile cache files.
	 *
	 * @return bool
	 */
	public function is_mobile_cache_enabled() {
		$settings = $this->get_wp_rocket_settings();
		return ! empty( $settings['cache_mobile'] ) && ! empty( $settings['do_caching_mobile_files'] );
	}

	/**
	 * Return the mobile user-agent regex or an intentionally impossible value.
	 *
	 * @return string
	 */
	public function get_mobile_user_agent() {
		$pattern = $this->is_mobile_cache_enabled() ? self::DEFAULT_MOBILE_UA : 'not_v_a_l_i_d';
		return $this->clean_nginx_value( (string) apply_filters( 'getpagespeed_cache_configuration_mobile_user_agent', $pattern ) );
	}

	/**
	 * Return cache-busting cookie regex fragments.
	 *
	 * @return array
	 */
	public function get_cache_reject_cookies() {
		$settings = $this->get_wp_rocket_settings();
		$cookies  = self::DEFAULT_COOKIES;

		if ( ! empty( $settings['cache_reject_cookies'] ) && is_array( $settings['cache_reject_cookies'] ) ) {
			$cookies = array_merge( $cookies, $settings['cache_reject_cookies'] );
		}

		return $this->clean_regex_fragments( apply_filters( 'getpagespeed_cache_configuration_cache_reject_cookies', $cookies ) );
	}

	/**
	 * Return query parameters WP Rocket explicitly caches.
	 *
	 * @return array
	 */
	public function get_query_strings_cache() {
		$settings = $this->get_wp_rocket_settings();
		$values   = ! empty( $settings['cache_query_strings'] ) && is_array( $settings['cache_query_strings'] )
			? $settings['cache_query_strings']
			: array();

		return $this->clean_parameter_names( $values );
	}

	/**
	 * Return tracking parameters that can be stripped before cache lookup.
	 *
	 * @return array
	 */
	public function get_query_strings_ignore() {
		$values = apply_filters( 'getpagespeed_cache_configuration_query_strings_ignore', self::DEFAULT_QUERY_STRINGS_IGNORE );
		return $this->clean_parameter_names( is_array( $values ) ? $values : array() );
	}

	/**
	 * Return the wp-content directory name used in public URLs.
	 *
	 * @return string
	 */
	public function get_wp_content_folder() {
		$folder = sanitize_file_name( basename( wp_normalize_path( WP_CONTENT_DIR ) ) );
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
		return trailingslashit( wp_normalize_path( WP_CONTENT_DIR ) ) . 'wp-rocket-nginx/';
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

	/** @return string */
	public function get_html_cache_control() {
		$value = (string) apply_filters( 'getpagespeed_cache_configuration_html_cache_control', 'no-cache, no-store, must-revalidate' );
		return $this->clean_nginx_value( $value );
	}

	/** @return string */
	public function get_css_expiration() {
		return $this->clean_expiration( apply_filters( 'getpagespeed_cache_configuration_css_expiration', '90d' ) );
	}

	/** @return string */
	public function get_js_expiration() {
		return $this->clean_expiration( apply_filters( 'getpagespeed_cache_configuration_js_expiration', '90d' ) );
	}

	/** @return string */
	public function get_media_expiration() {
		return $this->clean_expiration( apply_filters( 'getpagespeed_cache_configuration_media_expiration', '90d' ) );
	}

	/** @return string */
	public function get_media_extensions() {
		return 'ico|gif|jpe?g|png|svg|eot|otf|woff2?|ttf|ogg|webp|avif';
	}

	/**
	 * Keep only valid URI query parameter names.
	 *
	 * @param array $values Candidate names.
	 * @return array
	 */
	private function clean_parameter_names( $values ) {
		$clean = array();

		foreach ( $values as $value ) {
			$value = trim( (string) $value );
			if ( preg_match( '/^[A-Za-z0-9_.~-]+$/D', $value ) ) {
				$clean[] = $value;
			}
		}

		return array_values( array_unique( $clean ) );
	}

	/**
	 * Reject characters that can escape an Nginx quoted regex.
	 *
	 * @param mixed $values Candidate fragments.
	 * @return array
	 */
	private function clean_regex_fragments( $values ) {
		$clean = array();
		$values = is_array( $values ) ? $values : array();

		foreach ( $values as $value ) {
			$value = trim( (string) $value );
			if ( '' !== $value && ! preg_match( '/[\r\n";{}]/', $value ) ) {
				$clean[] = $value;
			}
		}

		return array_values( array_unique( $clean ) );
	}

	/**
	 * Strip control and quote characters from a quoted Nginx value.
	 *
	 * @param string $value Candidate value.
	 * @return string
	 */
	private function clean_nginx_value( $value ) {
		return trim( str_replace( array( "\r", "\n", '"' ), '', $value ) );
	}

	/**
	 * Validate an Nginx expiration duration.
	 *
	 * @param mixed $value Candidate duration.
	 * @return string
	 */
	private function clean_expiration( $value ) {
		$value = trim( (string) $value );
		return preg_match( '/^(?:-1|max|off|[0-9]+[smhdwMy])$/D', $value ) ? $value : '90d';
	}
}
