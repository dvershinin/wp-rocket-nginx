<?php
/**
 * WordPress admin UI.
 *
 * @package RocketNginxBridge
 */

namespace RocketNginxBridge;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Small settings and status page.
 */
final class Admin_Page {

	/**
	 * Configuration writer.
	 *
	 * @var Config_Writer
	 */
	private $config_writer;

	/**
	 * Build the admin page.
	 *
	 * @param Config_Writer $config_writer Configuration writer.
	 */
	public function __construct( Config_Writer $config_writer ) {
		$this->config_writer = $config_writer;
	}

	/** @return void */
	public function init() {
		add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_post_rocket_nginx_bridge_regenerate', array( $this, 'handle_regenerate' ) );
		add_action( 'admin_notices', array( $this, 'admin_notices' ) );
	}

	/** @return void */
	public function add_menu_page() {
		add_options_page(
			esc_html__( 'Rocket Nginx Bridge', 'rocket-nginx-bridge' ),
			esc_html__( 'Rocket Nginx Bridge', 'rocket-nginx-bridge' ),
			'manage_options',
			'rocket-nginx-bridge',
			array( $this, 'render_page' )
		);
	}

	/** @return void */
	public function register_settings() {
		register_setting(
			'rocket_nginx_bridge_settings',
			Settings_Reader::OPTION_NAME,
			array(
				'type'              => 'object',
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
				'default'           => array( 'debug' => false ),
			)
		);
	}

	/**
	 * Sanitize the intentionally small settings surface.
	 *
	 * @param mixed $input Submitted settings.
	 * @return array
	 */
	public function sanitize_settings( $input ) {
		$input = is_array( $input ) ? $input : array();
		return array( 'debug' => ! empty( $input['debug'] ) );
	}

	/** @return void */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings       = get_option( Settings_Reader::OPTION_NAME, array() );
		$debug          = ! empty( $settings['debug'] );
		$config_exists  = $this->config_writer->config_exists();
		$output_file    = $this->config_writer->get_output_file();
		$last_generated = $this->config_writer->get_last_generated();
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

			<div class="notice notice-info inline">
				<p>
					<strong><?php esc_html_e( 'Generated configuration:', 'rocket-nginx-bridge' ); ?></strong>
					<code><?php echo esc_html( $output_file ); ?></code>
					<?php echo $config_exists ? esc_html__( '(ready)', 'rocket-nginx-bridge' ) : esc_html__( '(not generated)', 'rocket-nginx-bridge' ); ?>
				</p>
				<?php if ( $last_generated ) : ?>
					<p>
						<?php
						echo esc_html(
							sprintf(
								/* translators: %s is a localized date and time. */
								__( 'Last generated: %s', 'rocket-nginx-bridge' ),
								date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $last_generated )
							)
						);
						?>
					</p>
				<?php endif; ?>
			</div>

			<form method="post" action="options.php">
				<?php settings_fields( 'rocket_nginx_bridge_settings' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Debug headers', 'rocket-nginx-bridge' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( Settings_Reader::OPTION_NAME ); ?>[debug]" value="1" <?php checked( $debug ); ?>>
								<?php esc_html_e( 'Expose X-Rocket-Nginx diagnostic headers', 'rocket-nginx-bridge' ); ?>
							</label>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="rocket_nginx_bridge_regenerate">
				<?php wp_nonce_field( 'rocket_nginx_bridge_regenerate', 'rocket_nginx_bridge_nonce' ); ?>
				<?php submit_button( esc_html__( 'Regenerate configuration', 'rocket-nginx-bridge' ), 'secondary', 'regenerate', false ); ?>
			</form>

			<h2><?php esc_html_e( 'Nginx setup', 'rocket-nginx-bridge' ); ?></h2>
			<p><?php esc_html_e( 'Include the generated file in the server block, then test and reload Nginx:', 'rocket-nginx-bridge' ); ?></p>
			<pre><code>include <?php echo esc_html( $output_file ); ?>;
nginx -t &amp;&amp; systemctl reload nginx</code></pre>
			<p><?php esc_html_e( 'The plugin regenerates the file when WP Rocket settings change. Reloading Nginx remains an explicit server operation.', 'rocket-nginx-bridge' ); ?></p>
		</div>
		<?php
	}

	/** @return void */
	public function handle_regenerate() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to regenerate this configuration.', 'rocket-nginx-bridge' ) );
		}

		check_admin_referer( 'rocket_nginx_bridge_regenerate', 'rocket_nginx_bridge_nonce' );
		$result = $this->config_writer->write();
		$status = is_wp_error( $result ) ? 'error' : 'success';

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'                   => 'rocket-nginx-bridge',
					'rocket_nginx_bridge_status' => $status,
				),
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}

	/** @return void */
	public function admin_notices() {
		$screen = get_current_screen();
		if ( ! $screen || 'settings_page_rocket-nginx-bridge' !== $screen->id ) {
			return;
		}

		// Display-only status flag set by the nonce-checked regenerate handler's redirect.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$status = isset( $_GET['rocket_nginx_bridge_status'] ) ? sanitize_key( wp_unslash( $_GET['rocket_nginx_bridge_status'] ) ) : '';
		if ( 'success' === $status ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Nginx configuration generated.', 'rocket-nginx-bridge' ) . '</p></div>';
		} elseif ( 'error' === $status ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $this->config_writer->get_last_error() ) . '</p></div>';
		}

		if ( ! defined( 'WP_ROCKET_VERSION' ) ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'WP Rocket is not active. The configuration can be generated, but there will be no WP Rocket cache files to serve.', 'rocket-nginx-bridge' ) . '</p></div>';
		}
	}
}
