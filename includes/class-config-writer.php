<?php
/**
 * Generate and atomically write the Nginx configuration.
 *
 * @package WPRocketNginx
 */

namespace WPRocketNginx;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Configuration generator and writer.
 */
final class Config_Writer {

	/** Last successful generation timestamp option. */
	const LAST_GENERATED_OPTION = 'wp_rocket_nginx_last_generated';

	/** Last generation error option. */
	const LAST_ERROR_OPTION = 'wp_rocket_nginx_last_error';

	/**
	 * Settings reader.
	 *
	 * @var Settings_Reader
	 */
	private $settings_reader;

	/**
	 * Template path.
	 *
	 * @var string
	 */
	private $template_file;

	/**
	 * Build a writer.
	 *
	 * @param Settings_Reader $settings_reader Settings reader.
	 */
	public function __construct( Settings_Reader $settings_reader ) {
		$this->settings_reader = $settings_reader;
		$this->template_file   = WP_ROCKET_NGINX_PLUGIN_DIR . 'templates/rocket-nginx.php';
	}

	/**
	 * Generate and write the Nginx configuration.
	 *
	 * @return bool|\WP_Error True on success or an error object.
	 */
	public function write() {
		$config = $this->generate();
		if ( is_wp_error( $config ) ) {
			return $config;
		}

		$output_path = $this->settings_reader->get_output_path();
		$config_name = $this->settings_reader->get_config_name();
		$config_dir  = $output_path . $config_name . '/';

		if ( ! wp_mkdir_p( $output_path ) || ! wp_mkdir_p( $config_dir ) ) {
			return $this->error( 'mkdir_failed', 'Could not create the Rocket-Nginx configuration directory.' );
		}

		$filename = $this->get_output_file();
		$tempfile = tempnam( $output_path, '.wp-rocket-nginx-' );

		if ( false === $tempfile ) {
			return $this->error( 'tempfile_failed', 'Could not create a temporary configuration file.' );
		}

		$written = file_put_contents( $tempfile, $config, LOCK_EX );
		if ( false === $written || ! chmod( $tempfile, 0644 ) || ! rename( $tempfile, $filename ) ) {
			if ( file_exists( $tempfile ) ) {
				unlink( $tempfile );
			}
			return $this->error( 'write_failed', 'Could not atomically write the Rocket-Nginx configuration file.' );
		}

		$generated_at = time();
		update_option( self::LAST_GENERATED_OPTION, $generated_at, false );
		delete_option( self::LAST_ERROR_OPTION );

		/**
		 * Fires after a new configuration has been atomically installed.
		 *
		 * @param string $filename     Absolute configuration path.
		 * @param string $config       Generated configuration.
		 * @param int    $generated_at Unix timestamp.
		 */
		do_action( 'wp_rocket_nginx_config_written', $filename, $config, $generated_at );

		return true;
	}

	/**
	 * Generate the Nginx configuration without writing it.
	 *
	 * @return string|\WP_Error Configuration or an error object.
	 */
	public function generate() {
		if ( ! file_exists( $this->template_file ) ) {
			return $this->error( 'template_not_found', 'The Rocket-Nginx template was not found.' );
		}

		if ( ! is_readable( $this->template_file ) ) {
			return $this->error( 'template_not_readable', 'The Rocket-Nginx template is not readable.' );
		}

		$debug               = $this->settings_reader->is_debug_enabled() ? 1 : 0;
		$wp_content_uri      = $this->settings_reader->get_wp_content_folder();
		$html_cache_control  = $this->settings_reader->get_html_cache_control();
		$cookie_invalidate   = implode( '|', $this->settings_reader->get_cache_reject_cookies() );
		$query_string_ignore = $this->generate_query_strings_ignore();
		$query_string_cache  = $this->generate_query_strings_cache();
		$css_expiration      = $this->settings_reader->get_css_expiration();
		$js_expiration       = $this->settings_reader->get_js_expiration();
		$media_expiration    = $this->settings_reader->get_media_expiration();
		$media_extensions    = $this->settings_reader->get_media_extensions();
		$mobile_user_agent   = $this->settings_reader->get_mobile_user_agent();

		$config_name = $this->settings_reader->get_config_name();
		$output_path = $this->settings_reader->get_output_path();

		$include_start      = $this->generate_include( $output_path, $config_name, 'start' );
		$include_preprocess = $this->generate_include( $output_path, $config_name, 'preprocess' );
		$include_global     = $this->generate_include( $output_path, $config_name, 'global' );
		$include_http       = $this->generate_include( $output_path, $config_name, 'http' );
		$include_css        = $this->generate_include( $output_path, $config_name, 'css' );
		$include_js         = $this->generate_include( $output_path, $config_name, 'js' );
		$include_media      = $this->generate_include( $output_path, $config_name, 'media' );

		ob_start();
		include $this->template_file;
		return (string) ob_get_clean();
	}

	/**
	 * Generate statements that remove non-content-changing query parameters.
	 *
	 * @return string
	 */
	private function generate_query_strings_ignore() {
		$result = "set \$rocket_args \$args;\n";

		foreach ( $this->settings_reader->get_query_strings_ignore() as $parameter ) {
			$result .= 'if ($rocket_args ~ (.*)(?:&|^)' . preg_quote( $parameter, '/' ) . '=[^&]*(.*)) { ';
			$result .= 'set $rocket_args $1$2; }' . "\n";
		}

		$result .= "\n# Normalize separators left after removing tracking parameters.\n";
		$result .= 'if ($rocket_args ~ ^&(.*)) { set $rocket_args $1; }' . "\n";
		$result .= 'if ($rocket_args ~ (.*)&$) { set $rocket_args $1; }' . "\n";
		$result .= 'if ($rocket_args ~ (.*)&&+(.*)) { set $rocket_args $1&$2; }';

		return $result;
	}

	/**
	 * Generate statements that map explicitly cached query strings to folders.
	 *
	 * @return string
	 */
	private function generate_query_strings_cache() {
		$query_strings = $this->settings_reader->get_query_strings_cache();
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

	/**
	 * Generate an optional custom-include statement.
	 *
	 * @param string $output_path Output directory.
	 * @param string $config_name Configuration basename.
	 * @param string $section     Include section.
	 * @return string
	 */
	private function generate_include( $output_path, $config_name, $section ) {
		$include_dir = str_replace( array( '\\', '"' ), array( '/', '\\"' ), $output_path . $config_name . '/' );
		return 'include "' . $include_dir . $section . '.*.conf";';
	}

	/**
	 * Return the generated configuration path.
	 *
	 * @return string
	 */
	public function get_output_file() {
		return $this->settings_reader->get_output_path() . $this->settings_reader->get_config_name() . '.conf';
	}

	/** @return bool */
	public function config_exists() {
		return file_exists( $this->get_output_file() );
	}

	/** @return int|false */
	public function get_last_generated() {
		return get_option( self::LAST_GENERATED_OPTION, false );
	}

	/** @return string */
	public function get_last_error() {
		return (string) get_option( self::LAST_ERROR_OPTION, '' );
	}

	/**
	 * Persist and return a generation error.
	 *
	 * @param string $code    Machine-readable code.
	 * @param string $message Human-readable message.
	 * @return \WP_Error
	 */
	private function error( $code, $message ) {
		update_option( self::LAST_ERROR_OPTION, $message, false );
		return new \WP_Error( $code, $message );
	}
}
