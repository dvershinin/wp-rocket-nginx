<?php
/**
 * Contract between the generator and one page-cache plugin.
 *
 * @package GetPageSpeedCacheConfiguration
 */

namespace GetPageSpeedCacheConfiguration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One supported page-cache plugin: detection, settings and its Nginx template.
 */
interface Integration {

	/**
	 * Stable machine identifier, lowercase letters, digits and dashes.
	 *
	 * @return string
	 */
	public function get_id();

	/**
	 * Product name shown in the admin UI.
	 *
	 * @return string
	 */
	public function get_label();

	/**
	 * Whether the cache plugin is running and writing page-cache files.
	 *
	 * @return bool
	 */
	public function is_active();

	/**
	 * Absolute path of the Nginx template.
	 *
	 * @return string
	 */
	public function get_template_file();

	/**
	 * Cleaned placeholder values specific to this template.
	 *
	 * @return array Placeholder name => value.
	 */
	public function get_template_values();

	/**
	 * WordPress hooks that mean the cache plugin's settings changed.
	 *
	 * @return array
	 */
	public function get_regenerate_hooks();

	/**
	 * Fingerprint of settings stored where no hook fires, or an empty string.
	 *
	 * @return string
	 */
	public function get_change_token();

	/**
	 * Response header that reports HIT, MISS or BYPASS.
	 *
	 * @return string
	 */
	public function get_status_header();

	/**
	 * Plain-text warnings for the settings page.
	 *
	 * @return array
	 */
	public function get_notices();
}
