<?php
/**
 * WordPress admin UI.
 *
 * @package GetPageSpeedCacheConfiguration
 */

namespace GetPageSpeedCacheConfiguration;

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
	 * Include checker.
	 *
	 * @var Include_Checker
	 */
	private $include_checker;

	/**
	 * Build the admin page.
	 *
	 * @param Config_Writer        $config_writer   Configuration writer.
	 * @param Include_Checker|null $include_checker Include checker.
	 */
	public function __construct( Config_Writer $config_writer, $include_checker = null ) {
		$this->config_writer   = $config_writer;
		$this->include_checker = $include_checker instanceof Include_Checker ? $include_checker : new Include_Checker( $config_writer );
	}

	/** @return void */
	public function init() {
		add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_post_getpagespeed_cache_configuration_regenerate', array( $this, 'handle_regenerate' ) );
		add_action( 'admin_post_getpagespeed_cache_configuration_check', array( $this, 'handle_check' ) );
		add_action( 'admin_notices', array( $this, 'admin_notices' ) );
	}

	/** @return void */
	public function add_menu_page() {
		add_options_page(
			esc_html__( 'GetPageSpeed Cache Configuration', 'getpagespeed-cache-configuration' ),
			esc_html__( 'GetPageSpeed Cache Configuration', 'getpagespeed-cache-configuration' ),
			'manage_options',
			'getpagespeed-cache-configuration',
			array( $this, 'render_page' )
		);
	}

	/** @return void */
	public function register_settings() {
		register_setting(
			'getpagespeed_cache_configuration_settings',
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
		$integrations   = $this->config_writer->get_integrations();
		$integration    = $integrations->get_active();
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

			<div class="notice notice-info inline">
				<p>
					<strong><?php esc_html_e( 'Generated configuration:', 'getpagespeed-cache-configuration' ); ?></strong>
					<code><?php echo esc_html( $output_file ); ?></code>
					<?php echo $config_exists ? esc_html__( '(ready)', 'getpagespeed-cache-configuration' ) : esc_html__( '(not generated)', 'getpagespeed-cache-configuration' ); ?>
				</p>
				<p>
					<strong><?php esc_html_e( 'Cache plugin:', 'getpagespeed-cache-configuration' ); ?></strong>
					<?php echo esc_html( $integration->get_label() ); ?>
					<?php echo $integrations->get_detected() ? esc_html__( '(detected)', 'getpagespeed-cache-configuration' ) : esc_html__( '(not detected, used by default)', 'getpagespeed-cache-configuration' ); ?>
				</p>
				<?php if ( $last_generated ) : ?>
					<p>
						<?php
						echo esc_html(
							sprintf(
								/* translators: %s is a localized date and time. */
								__( 'Last generated: %s', 'getpagespeed-cache-configuration' ),
								date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $last_generated )
							)
						);
						?>
					</p>
				<?php endif; ?>
			</div>

			<form method="post" action="options.php">
				<?php settings_fields( 'getpagespeed_cache_configuration_settings' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Debug headers', 'getpagespeed-cache-configuration' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( Settings_Reader::OPTION_NAME ); ?>[debug]" value="1" <?php checked( $debug ); ?>>
								<?php
								echo esc_html(
									sprintf(
										/* translators: %s is an HTTP response header name. */
										__( 'Expose diagnostic response headers (%s and related)', 'getpagespeed-cache-configuration' ),
										$integration->get_status_header()
									)
								);
								?>
							</label>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="getpagespeed_cache_configuration_regenerate">
				<?php wp_nonce_field( 'getpagespeed_cache_configuration_regenerate', 'getpagespeed_cache_configuration_nonce' ); ?>
				<?php submit_button( esc_html__( 'Regenerate configuration', 'getpagespeed-cache-configuration' ), 'secondary', 'regenerate', false ); ?>
			</form>

			<h2><?php esc_html_e( 'Nginx setup', 'getpagespeed-cache-configuration' ); ?></h2>
			<p><?php esc_html_e( 'Include the generated file in the server block, then test and reload Nginx:', 'getpagespeed-cache-configuration' ); ?></p>
			<pre><code>include <?php echo esc_html( $output_file ); ?>;
nginx -t &amp;&amp; systemctl reload nginx</code></pre>
			<p><?php esc_html_e( 'The plugin regenerates the file when the cache plugin settings change. Reloading Nginx remains an explicit server operation.', 'getpagespeed-cache-configuration' ); ?></p>

			<h2><?php esc_html_e( 'Check the include', 'getpagespeed-cache-configuration' ); ?></h2>
			<p><?php esc_html_e( 'Requests a probe address on this site to confirm that Nginx loaded the generated file and was reloaded after the last change.', 'getpagespeed-cache-configuration' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="getpagespeed_cache_configuration_check">
				<?php wp_nonce_field( 'getpagespeed_cache_configuration_check', 'getpagespeed_cache_configuration_nonce' ); ?>
				<?php submit_button( esc_html__( 'Check Nginx include', 'getpagespeed-cache-configuration' ), 'secondary', 'check', false ); ?>
			</form>
		</div>
		<?php
	}

	/** @return void */
	public function handle_regenerate() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to regenerate this configuration.', 'getpagespeed-cache-configuration' ) );
		}

		check_admin_referer( 'getpagespeed_cache_configuration_regenerate', 'getpagespeed_cache_configuration_nonce' );
		$result = $this->config_writer->write();
		$status = is_wp_error( $result ) ? 'error' : 'success';

		$this->redirect_to_page( 'getpagespeed_cache_configuration_status', $status );
	}

	/** @return void */
	public function handle_check() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to check this configuration.', 'getpagespeed-cache-configuration' ) );
		}

		check_admin_referer( 'getpagespeed_cache_configuration_check', 'getpagespeed_cache_configuration_nonce' );

		$this->redirect_to_page( 'getpagespeed_cache_configuration_check', $this->include_checker->check() );
	}

	/**
	 * Return to the settings page with one display-only result flag.
	 *
	 * @param string $name  Query argument.
	 * @param string $value Result.
	 * @return void
	 */
	private function redirect_to_page( $name, $value ) {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page' => 'getpagespeed-cache-configuration',
					$name  => $value,
				),
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}

	/**
	 * Return the notice for an include-check result.
	 *
	 * @param string $status One of the Include_Checker::STATUS_* constants.
	 * @return array|null Notice class and message, or null for an unknown status.
	 */
	public function get_check_notice( $status ) {
		$notices = array(
			Include_Checker::STATUS_ACTIVE        => array( 'notice-success', __( 'Nginx is serving the current generated configuration.', 'getpagespeed-cache-configuration' ) ),
			Include_Checker::STATUS_STALE         => array( 'notice-warning', __( 'Nginx includes the configuration but has not been reloaded since it was last generated. Run: nginx -t && systemctl reload nginx', 'getpagespeed-cache-configuration' ) ),
			Include_Checker::STATUS_MISSING       => array( 'notice-error', __( 'Nginx did not answer the probe. The include line is probably missing from the server block of this site, or another server in front of Nginx answered instead.', 'getpagespeed-cache-configuration' ) ),
			Include_Checker::STATUS_UNREACHABLE   => array( 'notice-warning', __( 'WordPress could not request its own site address, so the check is inconclusive.', 'getpagespeed-cache-configuration' ) ),
			Include_Checker::STATUS_NOT_GENERATED => array( 'notice-error', __( 'There is no generated configuration to check. Regenerate the configuration first.', 'getpagespeed-cache-configuration' ) ),
		);

		return isset( $notices[ $status ] ) ? $notices[ $status ] : null;
	}

	/** @return void */
	public function admin_notices() {
		$screen = get_current_screen();
		if ( ! $screen || 'settings_page_getpagespeed-cache-configuration' !== $screen->id ) {
			return;
		}

		// Display-only status flag set by the nonce-checked regenerate handler's redirect.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$status = isset( $_GET['getpagespeed_cache_configuration_status'] ) ? sanitize_key( wp_unslash( $_GET['getpagespeed_cache_configuration_status'] ) ) : '';
		if ( 'success' === $status ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Nginx configuration generated.', 'getpagespeed-cache-configuration' ) . '</p></div>';
		} elseif ( 'error' === $status ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $this->config_writer->get_last_error() ) . '</p></div>';
		}

		// Display-only result flag set by the nonce-checked check handler's redirect.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$check  = isset( $_GET['getpagespeed_cache_configuration_check'] ) ? sanitize_key( wp_unslash( $_GET['getpagespeed_cache_configuration_check'] ) ) : '';
		$notice = $this->get_check_notice( $check );
		if ( $notice ) {
			echo '<div class="notice ' . esc_attr( $notice[0] ) . '"><p>' . esc_html( $notice[1] ) . '</p></div>';
		}

		$legacy_file = $this->config_writer->get_legacy_output_file();
		if ( $legacy_file !== $this->config_writer->get_output_file() && file_exists( $legacy_file ) ) {
			echo '<div class="notice notice-warning"><p>' . esc_html(
				sprintf(
					/* translators: 1: old configuration path, 2: new configuration path. */
					__( 'The generated configuration moved. Replace %1$s with %2$s in the Nginx server block, reload Nginx, then delete the old file.', 'getpagespeed-cache-configuration' ),
					$legacy_file,
					$this->config_writer->get_output_file()
				)
			) . '</p></div>';
		}

		$integrations = $this->config_writer->get_integrations();
		if ( ! $integrations->get_detected() ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'No supported cache plugin is active (WP Rocket or WP Super Cache). The configuration can be generated, but there will be no cache files to serve.', 'getpagespeed-cache-configuration' ) . '</p></div>';
		}

		foreach ( $integrations->get_active()->get_notices() as $message ) {
			echo '<div class="notice notice-warning"><p>' . esc_html( $message ) . '</p></div>';
		}
	}
}
