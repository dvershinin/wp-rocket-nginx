<?php
/**
 * Generate and atomically write the Nginx configuration.
 *
 * @package GetPageSpeedCacheConfiguration
 */

namespace GetPageSpeedCacheConfiguration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Configuration generator and writer.
 */
final class Config_Writer {

	/** Last successful generation timestamp option. */
	const LAST_GENERATED_OPTION = 'getpagespeed_cache_configuration_last_generated';

	/** Last generation error option. */
	const LAST_ERROR_OPTION = 'getpagespeed_cache_configuration_last_error';

	/** Change token of the settings the installed file was generated from. */
	const SOURCE_TOKEN_OPTION = 'getpagespeed_cache_configuration_source_token';

	/** Shared template fragments, substituted before placeholder values. */
	const PARTIALS = array( 'protect', 'static-assets' );

	/**
	 * Settings reader.
	 *
	 * @var Settings_Reader
	 */
	private $settings_reader;

	/**
	 * Supported cache plugins.
	 *
	 * @var Integrations
	 */
	private $integrations;

	/**
	 * Build a writer.
	 *
	 * @param Settings_Reader   $settings_reader Settings reader.
	 * @param Integrations|null $integrations    Supported cache plugins.
	 */
	public function __construct( Settings_Reader $settings_reader, $integrations = null ) {
		$this->settings_reader = $settings_reader;
		$this->integrations    = $integrations instanceof Integrations ? $integrations : new Integrations( $settings_reader );
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
			return $this->error( 'mkdir_failed', 'Could not create the Nginx configuration directory.' );
		}

		$filename = $this->get_output_file();
		$tempfile = tempnam( $output_path, '.getpagespeed-cache-configuration-' );

		if ( false === $tempfile ) {
			return $this->error( 'tempfile_failed', 'Could not create a temporary configuration file.' );
		}

		$written = file_put_contents( $tempfile, $config, LOCK_EX );
		// Atomic replace: Nginx may read the file at any moment, so it must never see a partial write.
		// WP_Filesystem::move() copies and deletes, which is not atomic.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod, WordPress.WP.AlternativeFunctions.rename_rename
		if ( false === $written || ! chmod( $tempfile, 0644 ) || ! rename( $tempfile, $filename ) ) {
			if ( file_exists( $tempfile ) ) {
				wp_delete_file( $tempfile );
			}
			return $this->error( 'write_failed', 'Could not atomically write the Nginx configuration file.' );
		}

		$generated_at = time();
		update_option( self::LAST_GENERATED_OPTION, $generated_at, false );
		update_option( self::SOURCE_TOKEN_OPTION, $this->integrations->get_change_token(), false );
		delete_option( self::LAST_ERROR_OPTION );

		/**
		 * Fires after a new configuration has been atomically installed.
		 *
		 * @param string $filename     Absolute configuration path.
		 * @param string $config       Generated configuration.
		 * @param int    $generated_at Unix timestamp.
		 */
		do_action( 'getpagespeed_cache_configuration_config_written', $filename, $config, $generated_at );

		return true;
	}

	/**
	 * Generate the Nginx configuration without writing it.
	 *
	 * @return string|\WP_Error Configuration or an error object.
	 */
	public function generate() {
		$integration = $this->integrations->get_active();
		$template    = $this->read_template( $integration->get_template_file() );
		if ( is_wp_error( $template ) ) {
			return $template;
		}

		foreach ( self::PARTIALS as $partial ) {
			$content = $this->read_template( GETPAGESPEED_CACHE_CONFIGURATION_PLUGIN_DIR . 'templates/partials/' . $partial . '.conf' );
			if ( is_wp_error( $content ) ) {
				return $content;
			}
			$template = str_replace( '{{partial_' . str_replace( '-', '_', $partial ) . '}}', rtrim( $content ), $template );
		}

		$config_name = $this->settings_reader->get_config_name();
		$output_path = $this->settings_reader->get_output_path();

		$values = array(
			'debug'            => $this->settings_reader->is_debug_enabled() ? '1' : '0',
			'integration_id'   => $integration->get_id(),
			'wp_content_uri'   => $this->settings_reader->get_wp_content_folder(),
			'css_expiration'   => $this->settings_reader->get_css_expiration(),
			'js_expiration'    => $this->settings_reader->get_js_expiration(),
			'media_expiration' => $this->settings_reader->get_media_expiration(),
			'media_extensions' => $this->settings_reader->get_media_extensions(),
		);

		foreach ( array( 'start', 'preprocess', 'global', 'http', 'css', 'js', 'media' ) as $section ) {
			$values[ 'include_' . $section ] = $this->generate_include( $output_path, $config_name, $section );
		}

		$values = array_merge( $integration->get_template_values(), $values );

		$replacements = array();
		foreach ( $values as $name => $value ) {
			$replacements[ '{{' . $name . '}}' ] = (string) $value;
		}

		$config = strtr( $template, $replacements );

		// Identifies this exact content, so the probe location can tell a reloaded Nginx from a stale one.
		return str_replace( '{{fingerprint}}', substr( md5( $config ), 0, 12 ), $config );
	}

	/**
	 * Read a template or partial shipped with the plugin.
	 *
	 * @param string $file Absolute path.
	 * @return string|\WP_Error Contents or an error object.
	 */
	private function read_template( $file ) {
		if ( ! file_exists( $file ) ) {
			return $this->error( 'template_not_found', 'The Nginx configuration template was not found.' );
		}

		// The template is a plain Nginx file read from the plugin directory, never output to a browser.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$template = is_readable( $file ) ? file_get_contents( $file ) : false;
		if ( false === $template ) {
			return $this->error( 'template_not_readable', 'The Nginx configuration template is not readable.' );
		}

		return $template;
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

	/**
	 * Return the configuration path used before 1.2.0.
	 *
	 * @return string
	 */
	public function get_legacy_output_file() {
		return $this->settings_reader->get_legacy_output_path() . $this->settings_reader->get_config_name() . '.conf';
	}

	/**
	 * Return the probe token of the configuration file currently on disk.
	 *
	 * @return string Token, or an empty string when no generated file carries one.
	 */
	public function get_installed_probe_token() {
		$file = $this->get_output_file();
		// A local file this plugin wrote; WP_Filesystem is not loaded outside admin requests.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$config = is_readable( $file ) ? (string) file_get_contents( $file ) : '';

		return preg_match( '/return 200 "(gpscc:[a-z0-9-]+:[a-f0-9]{12})";/', $config, $matches ) ? $matches[1] : '';
	}

	/**
	 * Return the registry of supported cache plugins.
	 *
	 * @return Integrations
	 */
	public function get_integrations() {
		return $this->integrations;
	}

	/**
	 * Regenerate when the active cache plugin or its file-based settings changed.
	 *
	 * @return bool Whether a regeneration was attempted.
	 */
	public function maybe_regenerate() {
		$token = $this->integrations->get_change_token();
		if ( get_option( self::SOURCE_TOKEN_OPTION, null ) === $token ) {
			return false;
		}

		// Recorded first so a destination that cannot be written is not retried on every request.
		update_option( self::SOURCE_TOKEN_OPTION, $token, false );
		$this->write();

		return true;
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
