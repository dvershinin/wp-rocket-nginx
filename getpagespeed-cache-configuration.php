<?php
/**
 * Plugin Name: GetPageSpeed Cache Configuration
 * Plugin URI: https://github.com/dvershinin/getpagespeed-cache-configuration
 * Description: Generates Nginx configuration from page-cache plugin settings so Nginx can serve cached pages without PHP. Supports WP Rocket and WP Super Cache.
 * Version: 1.4.0
 * Author: Danila Vershinin
 * Author URI: https://www.getpagespeed.com/
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * License: MIT
 * License URI: https://opensource.org/license/mit
 * Text Domain: getpagespeed-cache-configuration
 *
 * @package GetPageSpeedCacheConfiguration
 */

namespace GetPageSpeedCacheConfiguration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'GETPAGESPEED_CACHE_CONFIGURATION_VERSION' ) ) {
	define( 'GETPAGESPEED_CACHE_CONFIGURATION_VERSION', '1.4.0' );
}
if ( ! defined( 'GETPAGESPEED_CACHE_CONFIGURATION_PLUGIN_FILE' ) ) {
	define( 'GETPAGESPEED_CACHE_CONFIGURATION_PLUGIN_FILE', __FILE__ );
}
if ( ! defined( 'GETPAGESPEED_CACHE_CONFIGURATION_PLUGIN_DIR' ) ) {
	define( 'GETPAGESPEED_CACHE_CONFIGURATION_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
}

require_once GETPAGESPEED_CACHE_CONFIGURATION_PLUGIN_DIR . 'includes/class-nginx-syntax.php';
require_once GETPAGESPEED_CACHE_CONFIGURATION_PLUGIN_DIR . 'includes/class-settings-reader.php';
require_once GETPAGESPEED_CACHE_CONFIGURATION_PLUGIN_DIR . 'includes/interface-integration.php';
require_once GETPAGESPEED_CACHE_CONFIGURATION_PLUGIN_DIR . 'includes/integrations/class-wp-rocket.php';
require_once GETPAGESPEED_CACHE_CONFIGURATION_PLUGIN_DIR . 'includes/integrations/class-wp-super-cache.php';
require_once GETPAGESPEED_CACHE_CONFIGURATION_PLUGIN_DIR . 'includes/class-integrations.php';
require_once GETPAGESPEED_CACHE_CONFIGURATION_PLUGIN_DIR . 'includes/class-config-writer.php';
require_once GETPAGESPEED_CACHE_CONFIGURATION_PLUGIN_DIR . 'includes/class-include-checker.php';
require_once GETPAGESPEED_CACHE_CONFIGURATION_PLUGIN_DIR . 'includes/class-brotli-checker.php';
require_once GETPAGESPEED_CACHE_CONFIGURATION_PLUGIN_DIR . 'includes/class-admin-page.php';

/**
 * Main plugin class.
 */
final class Plugin {

	/**
	 * Settings reader.
	 *
	 * @var Settings_Reader
	 */
	private $settings_reader;

	/**
	 * Configuration writer.
	 *
	 * @var Config_Writer
	 */
	private $config_writer;

	/**
	 * Admin page.
	 *
	 * @var Admin_Page
	 */
	private $admin_page;

	/**
	 * Build the plugin services.
	 */
	public function __construct() {
		$this->settings_reader = new Settings_Reader();
		$this->config_writer   = new Config_Writer( $this->settings_reader );
		$this->admin_page      = new Admin_Page( $this->config_writer );
	}

	/**
	 * Register hooks after all plugins load.
	 *
	 * @return void
	 */
	public function init() {
		$this->migrate_options();

		foreach ( $this->config_writer->get_integrations()->all() as $integration ) {
			foreach ( $integration->get_regenerate_hooks() as $hook ) {
				add_action( $hook, array( $this, 'regenerate_config' ), 10, 0 );
			}
		}
		add_action( 'update_option_getpagespeed_cache_configuration_settings', array( $this, 'regenerate_config' ), 10, 0 );
		// Catches a newly activated cache plugin and settings stored in files, which fire no hook.
		add_action( 'admin_init', array( $this, 'maybe_regenerate_config' ) );

		$this->admin_page->init();

		add_filter(
			'plugin_action_links_' . plugin_basename( GETPAGESPEED_CACHE_CONFIGURATION_PLUGIN_FILE ),
			array( $this, 'add_action_links' )
		);
	}

	/**
	 * Migrate the 1.0.0 and 1.1.0 option names once without replacing existing values.
	 *
	 * @return void
	 */
	public function migrate_options() {
		if ( get_option( 'getpagespeed_cache_configuration_options_migrated', false ) ) {
			return;
		}

		$missing = new \stdClass();
		// Newest prefix first, so a 1.1.0 value wins over a 1.0.0 leftover.
		foreach ( array( 'rocket_nginx_bridge_', 'wp_rocket_nginx_' ) as $old_prefix ) {
			foreach ( array( 'settings', 'last_generated', 'last_error' ) as $suffix ) {
				$old_name = $old_prefix . $suffix;
				$new_name = 'getpagespeed_cache_configuration_' . $suffix;
				if ( $missing === get_option( $new_name, $missing ) ) {
					$value = get_option( $old_name, $missing );
					if ( $missing !== $value ) {
						update_option( $new_name, $value, false );
						delete_option( $old_name );
					}
				}
			}
		}
		update_option( 'getpagespeed_cache_configuration_options_migrated', true, false );
	}

	/**
	 * Regenerate the Nginx configuration.
	 *
	 * Hook arguments are intentionally ignored.
	 *
	 * @return bool|\WP_Error True on success or an error object.
	 */
	public function regenerate_config() {
		return $this->config_writer->write();
	}

	/**
	 * Regenerate when the cache plugin or its settings changed since the last write.
	 *
	 * admin_init also fires for anonymous admin-ajax.php and admin-post.php
	 * requests, which must not be able to trigger a file write.
	 *
	 * @return bool Whether a regeneration was attempted.
	 */
	public function maybe_regenerate_config() {
		if ( wp_doing_ajax() || ! current_user_can( 'manage_options' ) ) {
			return false;
		}

		return $this->config_writer->maybe_regenerate();
	}

	/**
	 * Add the settings link on the Plugins screen.
	 *
	 * @param array $links Existing action links.
	 * @return array
	 */
	public function add_action_links( $links ) {
		$settings_link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'options-general.php?page=getpagespeed-cache-configuration' ) ),
			esc_html__( 'Settings', 'getpagespeed-cache-configuration' )
		);

		array_unshift( $links, $settings_link );
		return $links;
	}

	/**
	 * Return the configuration writer for integrations and WP-CLI.
	 *
	 * @return Config_Writer
	 */
	public function get_config_writer() {
		return $this->config_writer;
	}
}

/**
 * Generate the initial configuration during activation.
 *
 * Activation remains successful if the web-server user cannot write the
 * destination. The error is persisted and shown in the admin UI.
 *
 * @return void
 */
function activate() {
	$writer = new Config_Writer( new Settings_Reader() );
	$writer->write();
}

/**
 * Return the active plugin instance.
 *
 * @return Plugin|null
 */
function getpagespeed_cache_configuration() {
	static $instance = null;

	if ( null === $instance ) {
		$instance = new Plugin();
	}

	return $instance;
}

register_activation_hook( GETPAGESPEED_CACHE_CONFIGURATION_PLUGIN_FILE, __NAMESPACE__ . '\\activate' );
add_action( 'plugins_loaded', array( getpagespeed_cache_configuration(), 'init' ) );
