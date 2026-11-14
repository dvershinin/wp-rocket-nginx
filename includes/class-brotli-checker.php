<?php
/**
 * Check whether Nginx compresses pages with Brotli.
 *
 * @package GetPageSpeedCacheConfiguration
 */

namespace GetPageSpeedCacheConfiguration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Requests the front page as a client that accepts only Brotli and remembers
 * whether the answer was Brotli-encoded.
 *
 * PHP cannot see which modules Nginx loaded, and a module directive in the
 * generated file would stop Nginx from loading it on a server without that
 * module. The response of the site itself is the one signal available.
 */
final class Brotli_Checker {

	/** Last conclusive result. */
	const OPTION_NAME = 'getpagespeed_cache_configuration_brotli';

	/** The page came back Brotli-encoded. */
	const STATUS_ACTIVE = 'active';

	/** The page came back, but not Brotli-encoded. */
	const STATUS_INACTIVE = 'inactive';

	/** The page could not be requested, or was never requested. */
	const STATUS_UNKNOWN = 'unknown';

	/**
	 * Configuration writer.
	 *
	 * @var Config_Writer
	 */
	private $config_writer;

	/**
	 * Callable that requests a URL and returns array( 'code' => int, 'encoding' => string ) or a WP_Error.
	 *
	 * @var callable
	 */
	private $fetcher;

	/**
	 * Build a checker.
	 *
	 * @param Config_Writer $config_writer Configuration writer.
	 * @param callable|null $fetcher       HTTP adapter; defaults to the WordPress HTTP API.
	 */
	public function __construct( Config_Writer $config_writer, $fetcher = null ) {
		$this->config_writer = $config_writer;
		$this->fetcher       = is_callable( $fetcher ) ? $fetcher : array( $this, 'fetch' );
	}

	/**
	 * Run the check.
	 *
	 * @return string One of the STATUS_* constants.
	 */
	public function check() {
		return self::evaluate( call_user_func( $this->fetcher, home_url( '/' ) ) );
	}

	/**
	 * Classify a response.
	 *
	 * @param array|\WP_Error $response Response from the HTTP adapter.
	 * @return string One of the STATUS_* constants.
	 */
	public static function evaluate( $response ) {
		if ( ! is_array( $response ) || ! isset( $response['code'] ) || 200 !== (int) $response['code'] ) {
			return self::STATUS_UNKNOWN;
		}

		$encoding = isset( $response['encoding'] ) ? (string) $response['encoding'] : '';

		return preg_match( '/(?:^|,)\s*br\s*(?:,|$)/i', $encoding ) ? self::STATUS_ACTIVE : self::STATUS_INACTIVE;
	}

	/**
	 * Run the check and remember its result.
	 *
	 * @return bool Whether the generated configuration changed.
	 */
	public function refresh() {
		return $this->remember( $this->check() );
	}

	/**
	 * Remember a conclusive result and regenerate when it changed.
	 *
	 * An inconclusive check keeps the previous result, so that a failed request
	 * does not flip the generated configuration back and forth.
	 *
	 * @param string $status One of the STATUS_* constants.
	 * @return bool Whether the generated configuration changed.
	 */
	public function remember( $status ) {
		if ( ! in_array( $status, array( self::STATUS_ACTIVE, self::STATUS_INACTIVE ), true ) || get_option( self::OPTION_NAME ) === $status ) {
			return false;
		}

		update_option( self::OPTION_NAME, $status, false );

		$before = $this->config_writer->get_installed_probe_token();
		$this->config_writer->write();

		return $this->config_writer->get_installed_probe_token() !== $before;
	}

	/**
	 * Request a URL on this site through the WordPress HTTP API.
	 *
	 * @param string $url Front page URL.
	 * @return array|\WP_Error
	 */
	private function fetch( $url ) {
		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => 10,
				'redirection' => 0,
				// Only the Content-Encoding header matters; the body is never decoded.
				'decompress'  => false,
				/** This filter is documented in wp-includes/class-wp-http-streams.php */
				'sslverify'   => apply_filters( 'https_local_ssl_verify', false ),
				'headers'     => array(
					'Accept-Encoding' => 'br',
					'Cache-Control'   => 'no-cache',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$encoding = wp_remote_retrieve_header( $response, 'content-encoding' );

		return array(
			'code'     => (int) wp_remote_retrieve_response_code( $response ),
			'encoding' => is_array( $encoding ) ? implode( ',', $encoding ) : (string) $encoding,
		);
	}
}
