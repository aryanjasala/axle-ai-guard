<?php
/**
 * Network Admin: the network kill switch and usage per site.
 *
 * @package AryanX\Axle\AiGuard
 */

namespace AryanX\Axle\AiGuard\Admin;

use AryanX\Axle\AiGuard\KillSwitch;
use AryanX\Axle\AiGuard\Prices;
use AryanX\Axle\AiGuard\Schema;
use AryanX\Axle\AiGuard\Service;
use AryanX\Axle\AiGuard\Usage;

defined( 'ABSPATH' ) || exit;

/**
 * Budgets stay per site. The network switch turns AI off on every site and
 * wins over each site's own switches.
 */
final class Network implements Service {

	public const SLUG = 'axle-ai-guard-network';

	/**
	 * Usage counters.
	 *
	 * @var Usage
	 */
	private Usage $usage;

	/**
	 * Sets up the network screen.
	 *
	 * @param Usage $usage Usage counters.
	 */
	public function __construct( Usage $usage ) {
		$this->usage = $usage;
	}

	/**
	 * Adds the menu and the form handler on multisite.
	 */
	public function register(): void {
		if ( ! is_multisite() ) {
			return;
		}
		add_action( 'network_admin_menu', array( $this, 'add_page' ) );
		add_action( 'admin_post_axle_ai_guard_network_kill', array( $this, 'handle' ) );
	}

	/**
	 * Adds Settings > AI Guard in Network Admin.
	 */
	public function add_page(): void {
		add_submenu_page( 'settings.php', __( 'Axle AI Guard', 'axle-ai-guard' ), __( 'AI Guard', 'axle-ai-guard' ), 'manage_network_options', self::SLUG, array( $this, 'render' ) );
	}

	/**
	 * Renders the screen.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_network_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage AI Guard.', 'axle-ai-guard' ) );
		}

		$on = KillSwitch::network();
		echo '<div class="wrap aag-wrap"><h1>' . esc_html__( 'Axle AI Guard', 'axle-ai-guard' ) . '</h1>';
		echo '<form method="post" action="' . esc_url( network_admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="axle_ai_guard_network_kill">';
		wp_nonce_field( 'axle_ai_guard_network_kill' );
		if ( $on ) {
			echo '<p>' . esc_html__( 'AI calls are switched off on every site of the network.', 'axle-ai-guard' ) . '</p>';
			echo '<input type="hidden" name="on" value="0"><button type="submit" class="button button-primary">' . esc_html__( 'Turn AI back on for the network', 'axle-ai-guard' ) . '</button>';
		} else {
			echo '<p><label for="aag-network-reason">' . esc_html__( 'Reason', 'axle-ai-guard' ) . '</label> <input type="text" id="aag-network-reason" name="reason" class="regular-text"></p>';
			echo '<input type="hidden" name="on" value="1"><button type="submit" class="button">' . esc_html__( 'Switch AI off on every site', 'axle-ai-guard' ) . '</button>';
		}
		echo '</form>';

		echo '<h2>' . esc_html__( 'Usage this month per site', 'axle-ai-guard' ) . '</h2>';
		echo '<table class="widefat striped"><thead><tr><th scope="col">' . esc_html__( 'Site', 'axle-ai-guard' ) . '</th><th scope="col">' . esc_html__( 'Calls', 'axle-ai-guard' ) . '</th><th scope="col">' . esc_html__( 'Tokens', 'axle-ai-guard' ) . '</th><th scope="col">' . esc_html__( 'Cost', 'axle-ai-guard' ) . '</th><th scope="col">' . esc_html__( 'Blocked', 'axle-ai-guard' ) . '</th></tr></thead><tbody>';
		foreach ( get_sites(
			array(
				'number' => 200,
				'fields' => 'ids',
			)
		) as $site_id ) {
			switch_to_blog( (int) $site_id );
			$row      = Schema::ready() ? $this->usage->get( Usage::periods()['month'], 'global' ) : null;
			$name     = (string) get_option( 'blogname' );
			$settings = get_option( 'axle_ai_guard_settings', array() );
			$currency = is_array( $settings ) && ! empty( $settings['currency'] ) ? (string) $settings['currency'] : 'USD';
			restore_current_blog();

			echo '<tr><th scope="row">' . esc_html( $name ) . '</th>';
			if ( $row ) {
				echo '<td>' . esc_html( number_format_i18n( $row['calls'] ) ) . '</td><td>' . esc_html( number_format_i18n( $row['tokens'] ) ) . '</td><td>' . esc_html( Prices::format( $row['cost'], $currency ) ) . '</td><td>' . esc_html( number_format_i18n( $row['blocked'] ) ) . '</td>';
			} else {
				echo '<td colspan="4">' . esc_html__( 'Not active on this site.', 'axle-ai-guard' ) . '</td>';
			}
			echo '</tr>';
		}
		echo '</tbody></table></div>';
	}

	/**
	 * Turns the network switch on or off.
	 */
	public function handle(): void {
		if ( ! current_user_can( 'manage_network_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage AI Guard.', 'axle-ai-guard' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'axle_ai_guard_network_kill' );

		$on = isset( $_POST['on'] ) && '1' === sanitize_key( wp_unslash( $_POST['on'] ) );
		if ( $on ) {
			update_site_option(
				KillSwitch::NETWORK_OPTION,
				array(
					'on'     => true,
					'reason' => isset( $_POST['reason'] ) ? sanitize_text_field( wp_unslash( $_POST['reason'] ) ) : '',
					'by'     => get_current_user_id(),
					'at'     => time(),
				)
			);
			/** This action is documented in src/KillSwitch.php */
			do_action( 'axle_ai_guard_kill_switch_on', 'network', '', array( 'by' => get_current_user_id() ) );
		} else {
			delete_site_option( KillSwitch::NETWORK_OPTION );
			/** This action is documented in src/KillSwitch.php */
			do_action( 'axle_ai_guard_kill_switch_off', 'network', '', get_current_user_id() );
		}

		wp_safe_redirect( network_admin_url( 'settings.php?page=' . self::SLUG ) );
		exit;
	}
}
