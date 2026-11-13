<?php
/**
 * Registry of supported page-cache plugins.
 *
 * @package GetPageSpeedCacheConfiguration
 */

namespace GetPageSpeedCacheConfiguration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Picks the integration that matches the active cache plugin.
 */
final class Integrations {

	/**
	 * Integrations in detection order.
	 *
	 * @var Integration[]
	 */
	private $integrations = array();

	/**
	 * Build the registry.
	 *
	 * @param Settings_Reader $settings_reader Settings reader.
	 */
	public function __construct( Settings_Reader $settings_reader ) {
		foreach ( array( new Integrations\WP_Rocket( $settings_reader ), new Integrations\WP_Super_Cache( $settings_reader ) ) as $integration ) {
			$this->integrations[ $integration->get_id() ] = $integration;
		}
	}

	/**
	 * Return every integration keyed by identifier.
	 *
	 * @return Integration[]
	 */
	public function all() {
		return $this->integrations;
	}

	/**
	 * Return the integration whose cache plugin is running, or null.
	 *
	 * @return Integration|null
	 */
	public function get_detected() {
		foreach ( $this->integrations as $integration ) {
			if ( $integration->is_active() ) {
				return $integration;
			}
		}

		return null;
	}

	/**
	 * Return the integration used to generate the configuration.
	 *
	 * Without a detected cache plugin this stays WP Rocket, which is what
	 * every release before 1.3.0 generated.
	 *
	 * @return Integration
	 */
	public function get_active() {
		$forced = (string) apply_filters( 'getpagespeed_cache_configuration_integration', '' );
		if ( isset( $this->integrations[ $forced ] ) ) {
			return $this->integrations[ $forced ];
		}

		$detected = $this->get_detected();
		return $detected ? $detected : $this->integrations['wp-rocket'];
	}

	/**
	 * Fingerprint of the active integration and of settings no hook reports.
	 *
	 * @return string
	 */
	public function get_change_token() {
		$integration = $this->get_active();
		return $integration->get_id() . ':' . $integration->get_change_token();
	}
}
