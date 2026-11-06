<?php
/**
 * Plugin Name: Rocket Nginx Bridge
 * Plugin URI: https://github.com/dvershinin/wp-rocket-nginx
 * Description: Generates a Rocket-Nginx configuration from WP Rocket settings so Nginx can serve cached pages without PHP.
 * Version: 1.1.0
 * Author: Danila Vershinin
 * Author URI: https://www.getpagespeed.com/
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * License: MIT
 * License URI: https://opensource.org/license/mit
 * Text Domain: rocket-nginx-bridge
 *
 * @package RocketNginxBridge
 */

namespace RocketNginxBridge;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'ROCKET_NGINX_BRIDGE_VERSION' ) ) {
	define( 'ROCKET_NGINX_BRIDGE_VERSION', '1.1.0' );
}
if ( ! defined( 'ROCKET_NGINX_BRIDGE_PLUGIN_FILE' ) ) {
	define( 'ROCKET_NGINX_BRIDGE_PLUGIN_FILE', __FILE__ );
}
if ( ! defined( 'ROCKET_NGINX_BRIDGE_PLUGIN_DIR' ) ) {
	define( 'ROCKET_NGINX_BRIDGE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
}

require_once ROCKET_NGINX_BRIDGE_PLUGIN_DIR . 'includes/class-settings-reader.php';
require_once ROCKET_NGINX_BRIDGE_PLUGIN_DIR . 'includes/class-config-writer.php';
require_once ROCKET_NGINX_BRIDGE_PLUGIN_DIR . 'includes/class-admin-page.php';

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

		add_action( 'update_option_wp_rocket_settings', array( $this, 'regenerate_config' ), 10, 2 );
		add_action( 'rocket_after_save_options', array( $this, 'regenerate_config' ) );
		add_action( 'update_option_rocket_nginx_bridge_settings', array( $this, 'regenerate_config' ) );

		$this->admin_page->init();

		add_filter(
			'plugin_action_links_' . plugin_basename( ROCKET_NGINX_BRIDGE_PLUGIN_FILE ),
			array( $this, 'add_action_links' )
		);
	}

	/**
	 * Migrate the 1.0.0 option names once without replacing existing values.
	 *
	 * @return void
	 */
	public function migrate_options() {
		if ( get_option( 'rocket_nginx_bridge_options_migrated', false ) ) {
			return;
		}

		$missing = new \stdClass();
		foreach ( array( 'settings', 'last_generated', 'last_error' ) as $suffix ) {
			$old_name = 'wp_rocket_nginx_' . $suffix;
			$new_name = 'rocket_nginx_bridge_' . $suffix;
			if ( $missing === get_option( $new_name, $missing ) ) {
				$value = get_option( $old_name, $missing );
				if ( $missing !== $value ) {
					update_option( $new_name, $value, false );
					delete_option( $old_name );
				}
			}
		}
		update_option( 'rocket_nginx_bridge_options_migrated', true, false );
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
	 * Add the settings link on the Plugins screen.
	 *
	 * @param array $links Existing action links.
	 * @return array
	 */
	public function add_action_links( $links ) {
		$settings_link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'options-general.php?page=rocket-nginx-bridge' ) ),
			esc_html__( 'Settings', 'rocket-nginx-bridge' )
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
function rocket_nginx_bridge() {
	static $instance = null;

	if ( null === $instance ) {
		$instance = new Plugin();
	}

	return $instance;
}

register_activation_hook( ROCKET_NGINX_BRIDGE_PLUGIN_FILE, __NAMESPACE__ . '\\activate' );
add_action( 'plugins_loaded', array( rocket_nginx_bridge(), 'init' ) );
