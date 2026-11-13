<?php
/**
 * Check that Nginx loaded the generated configuration.
 *
 * @package GetPageSpeedCacheConfiguration
 */

namespace GetPageSpeedCacheConfiguration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Requests the probe location the generated configuration defines and
 * compares its answer with the file on disk.
 */
final class Include_Checker {

	/** Probe location defined by templates/partials/protect.conf. */
	const PROBE_PATH = '/getpagespeed-cache-configuration-probe';

	/** Nginx answers with the token of the file on disk. */
	const STATUS_ACTIVE = 'active';

	/** Nginx answers with the token of an older file: it was not reloaded. */
	const STATUS_STALE = 'stale';

	/** Something other than the probe location answered. */
	const STATUS_MISSING = 'missing';

	/** WordPress could not request its own site. */
	const STATUS_UNREACHABLE = 'unreachable';

	/** There is no generated file to compare with. */
	const STATUS_NOT_GENERATED = 'not_generated';

	/**
	 * Configuration writer.
	 *
	 * @var Config_Writer
	 */
	private $config_writer;

	/**
	 * Callable that requests a URL and returns array( 'code' => int, 'body' => string ) or a WP_Error.
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
		$expected = $this->config_writer->get_installed_probe_token();
		if ( '' === $expected ) {
			return self::STATUS_NOT_GENERATED;
		}

		return self::evaluate( $expected, call_user_func( $this->fetcher, $this->get_probe_url() ) );
	}

	/**
	 * Classify a probe response.
	 *
	 * @param string          $expected Probe token of the file on disk.
	 * @param array|\WP_Error $response Response from the HTTP adapter.
	 * @return string One of the STATUS_* constants.
	 */
	public static function evaluate( $expected, $response ) {
		if ( ! is_array( $response ) ) {
			return self::STATUS_UNREACHABLE;
		}

		$code = isset( $response['code'] ) ? (int) $response['code'] : 0;
		$body = isset( $response['body'] ) ? trim( (string) $response['body'] ) : '';

		if ( 200 !== $code || ! preg_match( '/^gpscc:[a-z0-9-]+:[a-f0-9]{12}$/D', $body ) ) {
			return self::STATUS_MISSING;
		}

		return $body === $expected ? self::STATUS_ACTIVE : self::STATUS_STALE;
	}

	/**
	 * Return the probe URL on the site's own host.
	 *
	 * The probe is an exact-match location at the server root, so the path
	 * of a subdirectory installation is not part of it.
	 *
	 * @return string
	 */
	public function get_probe_url() {
		$home = wp_parse_url( home_url() );
		$url  = ( isset( $home['scheme'] ) ? $home['scheme'] : 'http' ) . '://' . ( isset( $home['host'] ) ? $home['host'] : 'localhost' );

		if ( isset( $home['port'] ) ) {
			$url .= ':' . (int) $home['port'];
		}

		return $url . self::PROBE_PATH;
	}

	/**
	 * Request a URL on this site through the WordPress HTTP API.
	 *
	 * @param string $url Probe URL.
	 * @return array|\WP_Error
	 */
	private function fetch( $url ) {
		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => 5,
				'redirection' => 0,
				/** This filter is documented in wp-includes/class-wp-http-streams.php */
				'sslverify'   => apply_filters( 'https_local_ssl_verify', false ),
				'headers'     => array( 'Cache-Control' => 'no-cache' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return array(
			'code' => (int) wp_remote_retrieve_response_code( $response ),
			'body' => (string) wp_remote_retrieve_body( $response ),
		);
	}
}
