<?php
/**
 * Personal data export, erase and the privacy policy text.
 *
 * @package AryanX\Axle\AiGuard
 */

namespace AryanX\Axle\AiGuard;

defined( 'ABSPATH' ) || exit;

/**
 * The call log holds user IDs, anonymised IP addresses of visitors, and,
 * only when turned on, redacted previews and prompts. The exporter lists a
 * user's calls; the eraser removes the user, IP address, preview and stored
 * prompt but keeps the record that a call happened, so usage stays right.
 */
final class Privacy implements Service {

	public const ID   = 'axle-ai-guard';
	private const PER = 100;

	/**
	 * The call log.
	 *
	 * @var Calls
	 */
	private Calls $calls;

	/**
	 * Sets up the privacy tools.
	 *
	 * @param Calls $calls The call log.
	 */
	public function __construct( Calls $calls ) {
		$this->calls = $calls;
	}

	/**
	 * Adds the hooks.
	 */
	public function register(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'add_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'add_eraser' ) );
		add_action( 'admin_init', array( $this, 'policy_text' ) );
	}

	/**
	 * Registers the exporter.
	 *
	 * @param mixed $exporters Exporters.
	 * @return array
	 */
	public function add_exporter( $exporters ): array {
		$exporters             = (array) $exporters;
		$exporters[ self::ID ] = array(
			'exporter_friendly_name' => __( 'AI calls', 'axle-ai-guard' ),
			'callback'               => array( $this, 'export' ),
		);

		return $exporters;
	}

	/**
	 * Registers the eraser.
	 *
	 * @param mixed $erasers Erasers.
	 * @return array
	 */
	public function add_eraser( $erasers ): array {
		$erasers             = (array) $erasers;
		$erasers[ self::ID ] = array(
			'eraser_friendly_name' => __( 'AI calls', 'axle-ai-guard' ),
			'callback'             => array( $this, 'erase' ),
		);

		return $erasers;
	}

	/**
	 * Exports one page of a user's AI calls.
	 *
	 * @param string $email Email address.
	 * @param int    $page  Page, from 1.
	 * @return array{data: array, done: bool}
	 */
	public function export( $email, $page = 1 ): array {
		$user = get_user_by( 'email', (string) $email );
		$data = array();
		if ( ! $user ) {
			return array(
				'data' => $data,
				'done' => true,
			);
		}

		$rows = $this->calls->for_user( (int) $user->ID, max( 1, (int) $page ), self::PER );
		foreach ( $rows as $row ) {
			$fields = array(
				__( 'Time (UTC)', 'axle-ai-guard' ) => (string) $row['started_at'],
				__( 'Status', 'axle-ai-guard' )     => (string) $row['status'],
				__( 'Caller', 'axle-ai-guard' )     => (string) $row['caller_slug'],
				__( 'Provider', 'axle-ai-guard' )   => (string) $row['provider'],
				__( 'Model', 'axle-ai-guard' )      => (string) $row['model'],
				__( 'Tokens', 'axle-ai-guard' )     => (string) $row['total_tokens'],
				__( 'IP address', 'axle-ai-guard' ) => (string) $row['ip'],
				__( 'Preview', 'axle-ai-guard' )    => (string) $row['preview'],
			);
			$item   = array();
			foreach ( $fields as $name => $value ) {
				$item[] = array(
					'name'  => $name,
					'value' => $value,
				);
			}
			$data[] = array(
				'group_id'    => 'axle-ai-guard-calls',
				'group_label' => __( 'AI calls', 'axle-ai-guard' ),
				'item_id'     => 'axle-ai-guard-call-' . (int) $row['id'],
				'data'        => $item,
			);
		}

		return array(
			'data' => $data,
			'done' => count( $rows ) < self::PER,
		);
	}

	/**
	 * Removes a user's personal data from the log, a batch at a time.
	 *
	 * @param string $email Email address.
	 * @param int    $page  Page; each run takes the next batch.
	 * @return array{items_removed: bool, items_retained: bool, messages: string[], done: bool}
	 */
	public function erase( $email, $page = 1 ): array {
		unset( $page );
		$user    = get_user_by( 'email', (string) $email );
		$changed = $user ? $this->calls->erase_user( (int) $user->ID, self::PER ) : 0;

		return array(
			'items_removed'  => $changed > 0,
			'items_retained' => $changed > 0,
			'messages'       => $changed > 0 ? array( __( 'AI calls were kept without the user, IP address, preview or stored prompt, so usage totals stay correct.', 'axle-ai-guard' ) ) : array(),
			'done'           => $changed < self::PER,
		);
	}

	/**
	 * Suggested privacy policy text.
	 */
	public function policy_text(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		wp_add_privacy_policy_content(
			__( 'Axle AI Guard', 'axle-ai-guard' ),
			'<p>' . esc_html__( 'This site logs the AI calls its plugins make: when, which plugin, the provider and model, token counts and cost, the user ID of the logged-in user who triggered the call, and for visitors who are not logged in an anonymised IP address. The text of prompts is not stored unless an administrator turns on previews or the prompt store; stored text has personal data such as user email addresses removed. Call records are kept for 90 days, stored prompts for 7 days.', 'axle-ai-guard' ) . '</p>'
		);
	}
}
