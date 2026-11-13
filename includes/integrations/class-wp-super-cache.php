<?php
/**
 * WP Super Cache integration.
 *
 * @package GetPageSpeedCacheConfiguration
 */

namespace GetPageSpeedCacheConfiguration\Integrations;

use GetPageSpeedCacheConfiguration\Integration;
use GetPageSpeedCacheConfiguration\Nginx_Syntax;
use GetPageSpeedCacheConfiguration\Settings_Reader;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Maps WP Super Cache settings to the rules its own mod_rewrite block applies.
 *
 * WP Super Cache keeps its settings as PHP variables in wp-cache-config.php,
 * not in the options table, so this class reads that file.
 */
final class WP_Super_Cache implements Integration {

	/** Cookies WP Super Cache never serves a static file to. */
	const DEFAULT_COOKIES = array(
		'comment_author_',
		'wordpress_logged_in',
		'wp-postpass_',
	);

	/**
	 * Settings reader.
	 *
	 * @var Settings_Reader
	 */
	private $settings_reader;

	/**
	 * Variables defined by wp-cache-config.php, loaded once per request.
	 *
	 * @var array|null
	 */
	private $config = null;

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
		return 'wp-super-cache';
	}

	/** @return string */
	public function get_label() {
		return 'WP Super Cache';
	}

	/** @return bool */
	public function is_active() {
		// Checked first: loading the configuration file below defines WPCACHEHOME as a side effect.
		if ( ! defined( 'WPCACHEHOME' ) ) {
			return false;
		}

		$config = $this->get_config();
		return ! empty( $config['cache_enabled'] ) && ! empty( $config['super_cache_enabled'] );
	}

	/** @return string */
	public function get_template_file() {
		return GETPAGESPEED_CACHE_CONFIGURATION_PLUGIN_DIR . 'templates/wp-super-cache.conf';
	}

	/** @return array */
	public function get_template_values() {
		$cache_uri = $this->get_cache_uri();

		return array(
			'cache_uri'            => $cache_uri,
			'cache_uri_regex'      => preg_quote( $cache_uri ),
			'charset'              => $this->generate_charset(),
			'html_cache_control'   => $this->get_html_cache_control(),
			'html_vary'            => $this->get_html_vary(),
			'query_string_ignore'  => Nginx_Syntax::strip_query_parameters( 'gpscc_args', $this->get_tracking_parameters() ),
			'gzip_lookup'          => $this->generate_gzip_lookup(),
			'trailing_slash_guard' => $this->generate_trailing_slash_guard(),
			'cookie_bypass'        => implode( '|', $this->get_bypass_cookies() ),
			'any_cookie_guard'     => $this->generate_any_cookie_guard(),
			'mobile_guard'         => $this->generate_mobile_guard(),
		);
	}

	/** @return array */
	public function get_regenerate_hooks() {
		return array( 'update_option_permalink_structure' );
	}

	/**
	 * WP Super Cache fires no hook when it rewrites its configuration file, and
	 * constants and options feed the rules too, so the token covers every
	 * value that reaches the template.
	 *
	 * @return string
	 */
	public function get_change_token() {
		return md5( (string) wp_json_encode( $this->get_template_values() ) );
	}

	/** @return string */
	public function get_status_header() {
		return 'X-GetPageSpeed-Cache';
	}

	/** @return array */
	public function get_notices() {
		$notices = array();
		$config  = $this->get_config();

		if ( defined( 'WPCACHEHOME' ) && ( empty( $config['cache_enabled'] ) || empty( $config['super_cache_enabled'] ) ) ) {
			$notices[] = __( 'WP Super Cache is installed but caching is turned off, so there are no cache files for Nginx to serve.', 'getpagespeed-cache-configuration' );
		}

		if ( has_filter( 'supercacherewriteconditions' ) ) {
			$notices[] = __( 'Another plugin adds its own conditions to the WP Super Cache rewrite rules. They cannot be translated for Nginx, so check that the pages it protects are not served from cache.', 'getpagespeed-cache-configuration' );
		}

		if ( null === $this->get_cache_path_in_content() ) {
			$notices[] = __( 'The WP Super Cache cache location is outside the WordPress content directory. Nginx cannot serve it with this configuration and every request will reach PHP.', 'getpagespeed-cache-configuration' );
		}

		return $notices;
	}

	/**
	 * Return the WP Super Cache configuration file path.
	 *
	 * @return string
	 */
	public function get_config_file() {
		$directory = defined( 'WPCACHECONFIGPATH' ) ? constant( 'WPCACHECONFIGPATH' ) : WP_CONTENT_DIR;
		return untrailingslashit( wp_normalize_path( (string) $directory ) ) . '/wp-cache-config.php';
	}

	/**
	 * Return the supercache directory as a URI path without a leading slash.
	 *
	 * @return string
	 */
	public function get_cache_uri() {
		$relative = $this->get_cache_path_in_content();
		$relative = null === $relative ? 'cache' : $relative;

		return $this->settings_reader->get_wp_content_folder() . ( '' !== $relative ? '/' . $relative : '' ) . '/supercache';
	}

	/**
	 * Return cookie-name regex fragments that send the request to PHP.
	 *
	 * @return array
	 */
	public function get_bypass_cookies() {
		$config  = $this->get_config();
		$cookies = self::DEFAULT_COOKIES;

		// A custom LOGGED_IN_COOKIE that does not share the default prefix needs its own entry.
		if ( defined( 'LOGGED_IN_COOKIE' ) && 0 !== strpos( (string) constant( 'LOGGED_IN_COOKIE' ), 'wordpress_logged_in' ) ) {
			$cookies[] = (string) constant( 'LOGGED_IN_COOKIE' );
		}

		// Cookies registered by other plugins through wpsc_add_cookie().
		if ( ! empty( $config['wpsc_cookies'] ) && is_array( $config['wpsc_cookies'] ) ) {
			$cookies = array_merge( $cookies, $config['wpsc_cookies'] );
		}

		if ( ! empty( $config['wpsc_rejected_cookies'] ) && is_array( $config['wpsc_rejected_cookies'] ) ) {
			$cookies = array_merge( $cookies, $config['wpsc_rejected_cookies'] );
		}

		// WP Super Cache inserts these into its own rewrite rules as regular expressions.
		return Nginx_Syntax::regex_fragments( $cookies );
	}

	/**
	 * Return the tracking parameters WP Super Cache is configured to ignore.
	 *
	 * @return array
	 */
	public function get_tracking_parameters() {
		$config = $this->get_config();

		if ( empty( $config['wpsc_ignore_tracking_parameters'] ) || empty( $config['wpsc_tracking_parameters'] ) ) {
			return array();
		}

		return Nginx_Syntax::parameter_names( $config['wpsc_tracking_parameters'] );
	}

	/**
	 * Return the Cache-Control value WP Super Cache sends for static files.
	 *
	 * @return string
	 */
	public function get_html_cache_control() {
		$default = defined( 'WPSC_CACHE_CONTROL_HEADER' ) && constant( 'WPSC_CACHE_CONTROL_HEADER' )
			? (string) constant( 'WPSC_CACHE_CONTROL_HEADER' )
			: 'max-age=3, must-revalidate';

		return $this->settings_reader->get_html_cache_control( $default );
	}

	/**
	 * Return the Vary value WP Super Cache sends for static files.
	 *
	 * @return string
	 */
	public function get_html_vary() {
		$vary = defined( 'WPSC_VARY_HEADER' ) && constant( 'WPSC_VARY_HEADER' )
			? Nginx_Syntax::value( (string) constant( 'WPSC_VARY_HEADER' ) )
			: '';

		return '' !== $vary ? $vary : 'Accept-Encoding, Cookie';
	}

	/**
	 * Return the cache directory relative to the content directory.
	 *
	 * @return string|null Relative path without slashes at either end, or null when it cannot be served.
	 */
	private function get_cache_path_in_content() {
		$config     = $this->get_config();
		$content    = trailingslashit( wp_normalize_path( WP_CONTENT_DIR ) );
		$cache_path = isset( $config['cache_path'] ) && is_string( $config['cache_path'] ) && '' !== $config['cache_path']
			? trailingslashit( wp_normalize_path( $config['cache_path'] ) )
			: $content . 'cache/';

		if ( 0 !== strpos( $cache_path, $content ) ) {
			return null;
		}

		$relative = trim( substr( $cache_path, strlen( $content ) ), '/' );
		foreach ( '' === $relative ? array() : explode( '/', $relative ) as $segment ) {
			if ( '..' === $segment || ! preg_match( '/^[A-Za-z0-9._-]+$/D', $segment ) ) {
				return null;
			}
		}

		return $relative;
	}

	/**
	 * Declare the response charset the way WP Super Cache's AddDefaultCharset does.
	 *
	 * @return string
	 */
	private function generate_charset() {
		$config = $this->get_config();
		if ( ! empty( $config['wp_cache_disable_utf8'] ) ) {
			return '';
		}

		$charset = (string) get_option( 'blog_charset', 'UTF-8' );
		$charset = preg_match( '/^[A-Za-z0-9._-]+$/D', $charset ) ? $charset : 'UTF-8';

		return 'charset ' . $charset . ';';
	}

	/**
	 * Prefer the pre-compressed sibling when WP Super Cache writes one.
	 *
	 * @return string
	 */
	private function generate_gzip_lookup() {
		$config = $this->get_config();
		if ( empty( $config['cache_compression'] ) ) {
			return '# WP Super Cache compression is off: no pre-compressed files to look for.';
		}

		$result  = "set \$gpscc_gzip \"\";\n";
		$result .= "if (\$http_accept_encoding ~ gzip) {\n";
		$result .= "    set \$gpscc_gzip \".gz\";\n";
		$result .= "}\n";
		$result .= "if (-f \"\$gpscc_file\$gpscc_gzip\") {\n";
		$result .= "    set \$gpscc_file \"\$gpscc_file\$gpscc_gzip\";\n";
		$result .= "    set \$gpscc_url \"\$gpscc_url\$gpscc_gzip\";\n";
		$result .= '}';

		return $result;
	}

	/**
	 * With trailing-slash permalinks, a URL without the slash must reach
	 * WordPress so it can redirect to the canonical URL.
	 *
	 * @return string
	 */
	private function generate_trailing_slash_guard() {
		if ( '/' !== substr( (string) get_option( 'permalink_structure', '' ), -1 ) ) {
			return '# Permalinks do not end with a slash: no trailing-slash requirement.';
		}

		return $this->generate_bypass( '$gpscc_uri_path !~ "/$"', 'No trailing slash' );
	}

	/**
	 * Honor "Disable caching for visitors who have a cookie set in their browser".
	 *
	 * @return string
	 */
	private function generate_any_cookie_guard() {
		$config = $this->get_config();
		if ( ! isset( $config['wp_cache_not_logged_in'] ) || 1 !== (int) $config['wp_cache_not_logged_in'] ) {
			return '# Visitors with unrelated cookies may receive cached pages.';
		}

		return $this->generate_bypass( '$http_cookie != ""', 'Cookie' );
	}

	/**
	 * With mobile support on, WP Super Cache sends mobile browsers to PHP.
	 *
	 * @return string
	 */
	private function generate_mobile_guard() {
		$config = $this->get_config();
		if ( empty( $config['wp_cache_mobile_enabled'] ) ) {
			return '# WP Super Cache mobile support is off: every device shares one cache file.';
		}

		$guards = array();
		$groups = array(
			'wp_cache_mobile_browsers' => '(%s)',
			'wp_cache_mobile_prefixes' => '^(%s)',
		);

		foreach ( $groups as $name => $pattern ) {
			$fragments = isset( $config[ $name ] ) && is_string( $config[ $name ] )
				? Nginx_Syntax::regex_fragments( explode( ',', $config[ $name ] ) )
				: array();

			if ( $fragments ) {
				$guards[] = $this->generate_bypass(
					'$http_user_agent ~* "' . sprintf( $pattern, implode( '|', $fragments ) ) . '"',
					'Mobile user agent'
				);
			}
		}

		return $guards ? implode( "\n", $guards ) : '# WP Super Cache mobile support is on but lists no mobile browsers.';
	}

	/**
	 * Generate one bypass block.
	 *
	 * @param string $condition Nginx condition built from cleaned values.
	 * @param string $reason    Diagnostic reason.
	 * @return string
	 */
	private function generate_bypass( $condition, $reason ) {
		$result  = 'if (' . $condition . ") {\n";
		$result .= "    set \$gpscc_serve 0;\n";
		$result .= "    set \$gpscc_status \"BYPASS\";\n";
		$result .= '    set $gpscc_reason "' . $reason . "\";\n";
		$result .= '}';

		return $result;
	}

	/**
	 * Return the variables defined by wp-cache-config.php.
	 *
	 * @return array
	 */
	private function get_config() {
		if ( null === $this->config ) {
			$file         = $this->get_config_file();
			$this->config = is_readable( $file ) ? self::load_config_file( $file ) : array();
		}

		return $this->config;
	}

	/**
	 * Evaluate the configuration file in an empty scope and return its variables.
	 *
	 * WordPress already includes this file on every request through
	 * advanced-cache.php. It only assigns variables, and reading it again
	 * returns the saved values even when the globals are not in scope.
	 *
	 * @return array
	 */
	private static function load_config_file() {
		ob_start();
		try {
			include func_get_arg( 0 );
		} catch ( \Throwable $error ) {
			// A broken configuration file also stops WP Super Cache; generate with defaults.
			unset( $error );
		}
		ob_end_clean();

		return get_defined_vars();
	}
}
