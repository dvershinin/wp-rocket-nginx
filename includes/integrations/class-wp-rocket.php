<?php
/**
 * WP Rocket integration.
 *
 * @package GetPageSpeedCacheConfiguration
 */

namespace GetPageSpeedCacheConfiguration\Integrations;

use GetPageSpeedCacheConfiguration\Brotli_Checker;
use GetPageSpeedCacheConfiguration\Integration;
use GetPageSpeedCacheConfiguration\Nginx_Syntax;
use GetPageSpeedCacheConfiguration\Settings_Reader;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Maps WP Rocket settings to safe Rocket-Nginx values.
 */
final class WP_Rocket implements Integration {

	/** WP Rocket's settings option. */
	const OPTION_NAME = 'wp_rocket_settings';

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
	 * Settings reader.
	 *
	 * @var Settings_Reader
	 */
	private $settings_reader;

	/**
	 * Build the integration.
	 *
	 * @param Settings_Reader $settings_reader Settings reader.
	 */
	public function __construct( Settings_Reader $settings_reader ) {
		$this->settings_reader = $settings_reader;
	}

	/** @return string */
	public function get_id() {
		return 'wp-rocket';
	}

	/** @return string */
	public function get_label() {
		return 'WP Rocket';
	}

	/** @return bool */
	public function is_active() {
		return defined( 'WP_ROCKET_VERSION' );
	}

	/** @return string */
	public function get_template_file() {
		return GETPAGESPEED_CACHE_CONFIGURATION_PLUGIN_DIR . 'templates/rocket-nginx.conf';
	}

	/** @return array */
	public function get_template_values() {
		return array(
			'html_cache_control'  => $this->settings_reader->get_html_cache_control(),
			'cookie_invalidate'   => implode( '|', $this->get_cache_reject_cookies() ),
			'query_string_ignore' => Nginx_Syntax::strip_query_parameters( 'rocket_args', $this->get_query_strings_ignore() ),
			'query_string_cache'  => $this->generate_query_strings_cache(),
			'mobile_user_agent'   => $this->get_mobile_user_agent(),
			'brotli_override'     => $this->generate_brotli_override(),
		);
	}

	/** @return array */
	public function get_regenerate_hooks() {
		return array( 'update_option_' . self::OPTION_NAME, 'rocket_after_save_options' );
	}

	/** @return string */
	public function get_change_token() {
		return '';
	}

	/** @return string */
	public function get_status_header() {
		return 'X-Rocket-Nginx-Serving-Static';
	}

	/** @return array */
	public function get_notices() {
		return array();
	}

	/**
	 * Return WP Rocket settings.
	 *
	 * @return array
	 */
	public function get_wp_rocket_settings() {
		$settings = get_option( self::OPTION_NAME, array() );
		return is_array( $settings ) ? $settings : array();
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
		return Nginx_Syntax::value( (string) apply_filters( 'getpagespeed_cache_configuration_mobile_user_agent', $pattern ) );
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

		return Nginx_Syntax::regex_fragments( apply_filters( 'getpagespeed_cache_configuration_cache_reject_cookies', $cookies ) );
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

		return Nginx_Syntax::parameter_names( $values );
	}

	/**
	 * Return tracking parameters that can be stripped before cache lookup.
	 *
	 * @return array
	 */
	public function get_query_strings_ignore() {
		$values = apply_filters( 'getpagespeed_cache_configuration_query_strings_ignore', self::DEFAULT_QUERY_STRINGS_IGNORE );
		return Nginx_Syntax::parameter_names( $values );
	}

	/**
	 * Generate the rule that keeps the gzip file away from Brotli clients.
	 *
	 * WP Rocket writes no Brotli files, so a Brotli client is given the plain
	 * file for Nginx to compress. Without Brotli in Nginx that only replaces
	 * the pre-compressed file with gzip done again on every request, so the
	 * rule is left out once a check has shown that Brotli is not in use.
	 *
	 * @return string
	 */
	private function generate_brotli_override() {
		if ( Brotli_Checker::STATUS_INACTIVE === $this->settings_reader->get_brotli_status() ) {
			return '# Brotli was not detected on this server: clients that accept it get the gzip file as well.';
		}

		$result  = "# Give clients that accept Brotli the plain file, so that Nginx compresses it with Brotli.\n";
		$result .= "if (\$http_accept_encoding ~ br) {\n";
		$result .= "    set \$rocket_encryption \"\";\n";
		$result .= '}';

		return $result;
	}

	/**
	 * Generate statements that map explicitly cached query strings to folders.
	 *
	 * @return string
	 */
	private function generate_query_strings_cache() {
		$query_strings = $this->get_query_strings_cache();
		$result        = "set \$rocket_has_query_cache \"\";\n";
		$result       .= "set \$rocket_args_tmp \$rocket_args;\n";

		foreach ( $query_strings as $parameter ) {
			$result .= 'if ($rocket_args_tmp ~ (.*)(?:&|^)' . preg_quote( $parameter, '/' ) . '=[^&]*(.*)) { ';
			$result .= 'set $rocket_has_query_cache 1; }' . "\n";
		}

		$result .= "\nif (\$rocket_has_query_cache = 1) {\n";
		$result .= "\tset \$rocket_args \"#\$rocket_args\";\n";
		$result .= "\tset \$rocket_is_args \"\";\n";
		$result .= '}';

		return $result;
	}
}
