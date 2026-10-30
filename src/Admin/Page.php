<?php
/**
 * Tools > AI Guard: overview, calls, budgets, data rules, prices and settings.
 *
 * @package AryanX\Axle\AiGuard
 */

namespace AryanX\Axle\AiGuard\Admin;

use AryanX\Axle\AiGuard\Calls;
use AryanX\Axle\AiGuard\Cleanup;
use AryanX\Axle\AiGuard\Detectors;
use AryanX\Axle\AiGuard\Guard;
use AryanX\Axle\AiGuard\KillSwitch;
use AryanX\Axle\AiGuard\Plugin;
use AryanX\Axle\AiGuard\Policy;
use AryanX\Axle\AiGuard\Prices;
use AryanX\Axle\AiGuard\Reports;
use AryanX\Axle\AiGuard\Service;
use AryanX\Axle\AiGuard\Settings;
use const AryanX\Axle\AiGuard\PLUGIN_FILE;
use const AryanX\Axle\AiGuard\VERSION;

defined( 'ABSPATH' ) || exit;

/**
 * The admin screen. Every form posts to admin-post.php with a nonce and the
 * AI Guard capability, and redirects back with a message.
 */
final class Page implements Service {

	public const SLUG = 'axle-ai-guard';
	public const TABS = array( 'overview', 'calls', 'budgets', 'rules', 'prices', 'settings' );

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Policy.
	 *
	 * @var Policy
	 */
	private Policy $policy;

	/**
	 * Kill switches.
	 *
	 * @var KillSwitch
	 */
	private KillSwitch $kill;

	/**
	 * Data rules.
	 *
	 * @var Detectors
	 */
	private Detectors $detectors;

	/**
	 * Prices.
	 *
	 * @var Prices
	 */
	private Prices $prices;

	/**
	 * The call log.
	 *
	 * @var Calls
	 */
	private Calls $calls;

	/**
	 * Reports.
	 *
	 * @var Reports
	 */
	private Reports $reports;

	/**
	 * Clean-up and recalculation.
	 *
	 * @var Cleanup
	 */
	private Cleanup $cleanup;

	/**
	 * Sets up the screen.
	 *
	 * @param Settings   $settings  Settings.
	 * @param Policy     $policy    Policy.
	 * @param KillSwitch $kill      Kill switches.
	 * @param Detectors  $detectors Data rules.
	 * @param Prices     $prices    Prices.
	 * @param Calls      $calls     The call log.
	 * @param Reports    $reports   Reports.
	 * @param Cleanup    $cleanup   Clean-up.
	 */
	public function __construct( Settings $settings, Policy $policy, KillSwitch $kill, Detectors $detectors, Prices $prices, Calls $calls, Reports $reports, Cleanup $cleanup ) {
		$this->settings  = $settings;
		$this->policy    = $policy;
		$this->kill      = $kill;
		$this->detectors = $detectors;
		$this->prices    = $prices;
		$this->calls     = $calls;
		$this->reports   = $reports;
		$this->cleanup   = $cleanup;
	}

	/**
	 * Adds the menu, notices and form handlers.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'admin_notices', array( $this, 'notices' ) );
		foreach ( array( 'kill', 'budgets', 'callers', 'rules', 'test_rules', 'prices', 'settings' ) as $action ) {
			add_action( 'admin_post_axle_ai_guard_' . $action, array( $this, 'handle_' . $action ) );
		}
	}

	/**
	 * URL of a tab.
	 *
	 * @param string $tab  Tab.
	 * @param array  $args Extra query arguments.
	 * @return string
	 */
	public static function url( string $tab = 'overview', array $args = array() ): string {
		return add_query_arg(
			array_merge(
				array(
					'page' => self::SLUG,
					'tab'  => $tab,
				),
				$args
			),
			admin_url( 'tools.php' )
		);
	}

	/**
	 * Adds Tools > AI Guard.
	 */
	public function add_page(): void {
		$hook = add_management_page( __( 'Axle AI Guard', 'axle-ai-guard' ), __( 'AI Guard', 'axle-ai-guard' ), Plugin::capability(), self::SLUG, array( $this, 'render' ) );
		if ( $hook ) {
			add_action( "admin_print_styles-{$hook}", array( $this, 'enqueue' ) );
		}
	}

	/**
	 * Styles, script and the data the script renders.
	 */
	public function enqueue(): void {
		wp_enqueue_style( 'axle-ai-guard-admin', plugins_url( 'assets/admin.css', PLUGIN_FILE ), array(), VERSION );
		wp_enqueue_script( 'axle-ai-guard-admin', plugins_url( 'assets/admin.js', PLUGIN_FILE ), array( 'wp-hooks', 'wp-api-fetch', 'wp-i18n' ), VERSION, true );
		wp_set_script_translations( 'axle-ai-guard-admin', 'axle-ai-guard' );

		$data = array(
			'currency' => $this->prices->currency(),
			'tab'      => $this->tab(),
		);
		if ( 'overview' === $this->tab() ) {
			$data['cards'] = $this->cards();
		}
		if ( 'calls' === $this->tab() ) {
			$data['calls'] = array_map( array( Reports::class, 'present' ), $this->calls_query()['rows'] );
		}
		wp_add_inline_script( 'axle-ai-guard-admin', 'window.axleAiGuardData = ' . wp_json_encode( $data ) . ';', 'before' );
	}

	/**
	 * The current tab.
	 *
	 * @return string
	 */
	private function tab(): string {
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'overview'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Navigation only.

		return in_array( $tab, self::TABS, true ) ? $tab : 'overview';
	}

	/**
	 * Notices on every admin screen: kill switches on, and a degraded guard.
	 */
	public function notices(): void {
		if ( ! current_user_can( Plugin::capability() ) ) {
			return;
		}

		$degraded = get_transient( Guard::DEGRADED );
		if ( is_string( $degraded ) && '' !== $degraded ) {
			printf(
				'<div class="notice notice-warning aag-notice"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %s: error message. */
						__( 'Axle AI Guard hit an error and is letting AI calls through without its checks: %s', 'axle-ai-guard' ),
						$degraded
					)
				)
			);
		}

		if ( $this->kill->global_on() ) {
			printf(
				'<div class="notice notice-error aag-notice aag-kill-notice"><p>%s <a href="%s">%s</a></p></div>',
				esc_html__( 'All AI calls on this site are switched off by Axle AI Guard.', 'axle-ai-guard' ),
				esc_url( self::url() ),
				esc_html__( 'Open AI Guard', 'axle-ai-guard' )
			);
		}
	}

	/**
	 * Renders the screen.
	 */
	public function render(): void {
		if ( ! current_user_can( Plugin::capability() ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage AI Guard.', 'axle-ai-guard' ) );
		}

		$tab    = $this->tab();
		$labels = array(
			'overview' => __( 'Overview', 'axle-ai-guard' ),
			'calls'    => __( 'Calls', 'axle-ai-guard' ),
			'budgets'  => __( 'Budgets and limits', 'axle-ai-guard' ),
			'rules'    => __( 'Data rules', 'axle-ai-guard' ),
			'prices'   => __( 'Prices', 'axle-ai-guard' ),
			'settings' => __( 'Settings', 'axle-ai-guard' ),
		);

		echo '<div class="wrap aag-wrap">';
		echo '<h1>' . esc_html__( 'Axle AI Guard', 'axle-ai-guard' ) . '</h1>';
		$this->done_notice();

		echo '<nav class="nav-tab-wrapper aag-tabs" aria-label="' . esc_attr__( 'AI Guard sections', 'axle-ai-guard' ) . '">';
		foreach ( $labels as $key => $label ) {
			printf(
				'<a href="%s" class="nav-tab%s"%s>%s</a>',
				esc_url( self::url( $key ) ),
				$key === $tab ? ' nav-tab-active' : '',
				$key === $tab ? ' aria-current="page"' : '',
				esc_html( $label )
			);
		}
		echo '</nav>';

		$method = 'render_' . $tab;
		$this->$method();
		echo '</div>';
	}

	/**
	 * The message after a form was handled.
	 */
	private function done_notice(): void {
		$done = isset( $_GET['done'] ) ? sanitize_key( wp_unslash( $_GET['done'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
		$map  = array(
			'kill_on'      => __( 'Switched off. Calls in that scope are blocked now.', 'axle-ai-guard' ),
			'kill_off'     => __( 'Switched back on.', 'axle-ai-guard' ),
			'kill_same'    => __( 'Nothing changed: the switch was already in that state.', 'axle-ai-guard' ),
			'budgets'      => __( 'Budgets and limits saved.', 'axle-ai-guard' ),
			'callers'      => __( 'Caller updated.', 'axle-ai-guard' ),
			'rules'        => __( 'Data rules saved.', 'axle-ai-guard' ),
			'prices'       => __( 'Prices saved.', 'axle-ai-guard' ),
			'prices_reset' => __( 'Prices are back to the shipped table.', 'axle-ai-guard' ),
			'recalc'       => __( 'Costs of the last 30 days were recalculated with the current prices.', 'axle-ai-guard' ),
			'settings'     => __( 'Settings saved.', 'axle-ai-guard' ),
		);
		if ( isset( $map[ $done ] ) ) {
			printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( $map[ $done ] ) );
		}
	}

	/**
	 * The overview cards, rendered by the script.
	 *
	 * @return array<int, array{id: string, label: string, value: int, kind: string}>
	 */
	private function cards(): array {
		$cards = array();
		foreach ( array( 'day', 'month' ) as $period ) {
			$totals = $this->reports->totals( $period );
			$when   = 'day' === $period ? __( 'today', 'axle-ai-guard' ) : __( 'this month', 'axle-ai-guard' );
			$cards  = array_merge(
				$cards,
				array(
					/* translators: %s: today or this month. */
					array( 'id' => $period . '-calls', 'label' => sprintf( __( 'Calls %s', 'axle-ai-guard' ), $when ), 'value' => $totals['calls'], 'kind' => 'number' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
					/* translators: %s: today or this month. */
					array( 'id' => $period . '-tokens', 'label' => sprintf( __( 'Tokens %s', 'axle-ai-guard' ), $when ), 'value' => $totals['tokens'], 'kind' => 'number' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
					/* translators: %s: today or this month. */
					array( 'id' => $period . '-cost', 'label' => sprintf( __( 'Estimated cost %s', 'axle-ai-guard' ), $when ), 'value' => $totals['cost'], 'kind' => 'cost' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
					/* translators: %s: today or this month. */
					array( 'id' => $period . '-blocked', 'label' => sprintf( __( 'Blocked %s', 'axle-ai-guard' ), $when ), 'value' => $totals['blocked'], 'kind' => 'number' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
				)
			);
		}

		return $cards;
	}

	/**
	 * A money amount.
	 *
	 * @param int $micros Micro-units.
	 * @return string
	 */
	private function money( int $micros ): string {
		return Prices::format( $micros, $this->prices->currency() );
	}

	/**
	 * A hidden nonce and action for an admin-post form.
	 *
	 * @param string $action Action without the prefix.
	 */
	private static function form_fields( string $action ): void {
		echo '<input type="hidden" name="action" value="' . esc_attr( 'axle_ai_guard_' . $action ) . '">';
		wp_nonce_field( 'axle_ai_guard_' . $action );
	}

	/**
	 * A kill switch button form.
	 *
	 * @param string $scope   Scope.
	 * @param string $subject Subject.
	 * @param bool   $on      Whether the button turns it on.
	 * @param string $label   Button text.
	 * @param string $css     Button class.
	 */
	private function kill_button( string $scope, string $subject, bool $on, string $label, string $css = 'button' ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="aag-kill-form">';
		self::form_fields( 'kill' );
		printf(
			'<input type="hidden" name="scope" value="%1$s"><input type="hidden" name="subject" value="%2$s"><input type="hidden" name="on" value="%3$s"><button type="submit" class="%4$s aag-kill-toggle" data-scope="%1$s" data-subject="%2$s" data-on="%3$s">%5$s</button>',
			esc_attr( $scope ),
			esc_attr( $subject ),
			$on ? '1' : '0',
			esc_attr( $css ),
			esc_html( $label )
		);
		echo '</form>';
	}

	/**
	 * Overview tab.
	 */
	private function render_overview(): void {
		// The global switch.
		echo '<section class="aag-section aag-global" aria-labelledby="aag-global-title">';
		echo '<h2 id="aag-global-title">' . esc_html__( 'Kill switch', 'axle-ai-guard' ) . '</h2>';
		if ( $this->kill->global_on() ) {
			echo '<p class="aag-status aag-status-off">' . esc_html__( 'All AI calls on this site are switched off.', 'axle-ai-guard' ) . '</p>';
			if ( KillSwitch::constant_on() || KillSwitch::network() ) {
				echo '<p>' . esc_html__( 'The AXLE_AI_GUARD_KILL constant or the network switch keeps it off. Change it there.', 'axle-ai-guard' ) . '</p>';
			}
			if ( $this->kill->state()['global'] ) {
				$this->kill_button( 'global', '', false, __( 'Turn AI back on', 'axle-ai-guard' ), 'button button-primary' );
			}
		} else {
			echo '<p class="aag-status">' . esc_html__( 'AI calls are allowed, within the budgets and rules below.', 'axle-ai-guard' ) . '</p>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="aag-inline-form">';
			self::form_fields( 'kill' );
			echo '<input type="hidden" name="scope" value="global"><input type="hidden" name="on" value="1">';
			echo '<label for="aag-kill-reason">' . esc_html__( 'Reason', 'axle-ai-guard' ) . '</label> ';
			echo '<input type="text" id="aag-kill-reason" name="reason" class="regular-text"> ';
			echo '<button type="submit" class="button aag-danger">' . esc_html__( 'Switch all AI off', 'axle-ai-guard' ) . '</button>';
			echo '</form>';
		}
		echo '</section>';

		echo '<section class="aag-section" aria-labelledby="aag-cards-title"><h2 id="aag-cards-title">' . esc_html__( 'Usage', 'axle-ai-guard' ) . '</h2>';
		echo '<ul class="aag-cards" data-aag-cards></ul>';
		echo '<p class="description">' . esc_html(
			sprintf(
				/* translators: %s: date. */
				__( 'Costs are estimates from the price table (prices as of %s), not your provider bill.', 'axle-ai-guard' ),
				$this->prices->as_of()
			)
		) . '</p></section>';

		$this->render_callers_table();
		$this->render_breakdown();
		$this->render_switches();
		$this->render_recent_blocks();
	}

	/**
	 * Per caller: usage this month, budget bar, last call, status and kill toggle.
	 */
	private function render_callers_table(): void {
		$month   = $this->reports->usage( 'month', 'plugin' );
		$by_name = array();
		foreach ( $month as $row ) {
			$by_name[ $row['name'] ] = $row;
		}
		$last = array();
		foreach ( $this->calls->breakdown( 'caller_slug', gmdate( 'Y-m-d H:i:s', time() - 90 * DAY_IN_SECONDS ) ) as $row ) {
			$last[ (string) $row['name'] ] = (string) $row['last_call'];
		}
		$names = array_unique( array_merge( array_keys( $this->settings->callers() ), array_keys( $by_name ) ) );
		sort( $names );

		echo '<section class="aag-section" aria-labelledby="aag-callers-title"><h2 id="aag-callers-title">' . esc_html__( 'Plugins making AI calls this month', 'axle-ai-guard' ) . '</h2>';
		echo '<table class="widefat striped aag-table aag-callers"><thead><tr>';
		foreach ( array( __( 'Caller', 'axle-ai-guard' ), __( 'Calls', 'axle-ai-guard' ), __( 'Tokens', 'axle-ai-guard' ), __( 'Cost', 'axle-ai-guard' ), __( 'Monthly budget', 'axle-ai-guard' ), __( 'Last call', 'axle-ai-guard' ), __( 'Status', 'axle-ai-guard' ), __( 'Kill switch', 'axle-ai-guard' ) ) as $head ) {
			echo '<th scope="col">' . esc_html( $head ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		if ( ! $names ) {
			echo '<tr><td colspan="8" class="aag-empty">' . esc_html__( 'No AI calls yet. Plugins show up here after their first call.', 'axle-ai-guard' ) . '</td></tr>';
		}

		foreach ( $names as $name ) {
			$row    = $by_name[ $name ] ?? array(
				'calls'   => 0,
				'tokens'  => 0,
				'cost'    => 0,
				'blocked' => 0,
			);
			$killed = $this->kill->is_on( 'plugin', $name );
			$status = $this->settings->caller_status( $name );

			echo '<tr data-caller="' . esc_attr( $name ) . '">';
			echo '<th scope="row"><code>' . esc_html( $name ) . '</code></th>';
			echo '<td>' . esc_html( number_format_i18n( $row['calls'] ) ) . ( $row['blocked'] ? ' <span class="aag-muted">' . esc_html( sprintf( /* translators: %s: number of blocked calls. */ __( '(%s blocked)', 'axle-ai-guard' ), number_format_i18n( $row['blocked'] ) ) ) . '</span>' : '' ) . '</td>';
			echo '<td>' . esc_html( number_format_i18n( $row['tokens'] ) ) . '</td>';
			echo '<td>' . esc_html( $this->money( $row['cost'] ) ) . '</td>';
			echo '<td>';
			$this->budget_bar( 'plugin:' . $name );
			echo '</td>';
			echo '<td>' . esc_html( isset( $last[ $name ] ) ? self::when( $last[ $name ] ) : '-' ) . '</td>';
			echo '<td>' . esc_html( $killed ? __( 'Switched off', 'axle-ai-guard' ) : self::status_label( $status ) ) . '</td>';
			echo '<td>';
			$this->kill_button( 'plugin', $name, ! $killed, $killed ? __( 'Turn on', 'axle-ai-guard' ) : __( 'Switch off', 'axle-ai-guard' ), $killed ? 'button button-primary' : 'button' );
			echo '</td></tr>';
		}
		echo '</tbody></table></section>';
	}

	/**
	 * A caller's approval status in words.
	 *
	 * @param string $status Status.
	 * @return string
	 */
	private static function status_label( string $status ): string {
		$map = array(
			'approved' => __( 'Allowed', 'axle-ai-guard' ),
			'pending'  => __( 'Waiting for approval', 'axle-ai-guard' ),
			'denied'   => __( 'Denied', 'axle-ai-guard' ),
		);

		return $map[ $status ] ?? __( 'Allowed', 'axle-ai-guard' );
	}

	/**
	 * A UTC datetime as local time.
	 *
	 * @param string $utc UTC datetime.
	 * @return string
	 */
	private static function when( string $utc ): string {
		$time = strtotime( $utc . ' UTC' );

		return $time ? wp_date( 'Y-m-d H:i', $time ) : '-';
	}

	/**
	 * The monthly budget bar of a subject: money first, else tokens.
	 *
	 * @param string $subject Subject.
	 */
	private function budget_bar( string $subject ): void {
		$budget = $this->policy->budget( $subject );
		$used   = $this->policy->used( $subject );
		$key    = $budget['monthly_cost'] > 0 ? 'monthly_cost' : ( $budget['monthly_tokens'] > 0 ? 'monthly_tokens' : '' );
		if ( '' === $key ) {
			echo '<span class="aag-muted">' . esc_html__( 'None', 'axle-ai-guard' ) . '</span>';
			return;
		}

		$money = 'monthly_cost' === $key;
		$text  = $money
			? sprintf( '%s / %s', $this->money( $used[ $key ] ), $this->money( $budget[ $key ] ) )
			: sprintf( '%s / %s', number_format_i18n( $used[ $key ] ), number_format_i18n( $budget[ $key ] ) );
		$pct   = (int) min( 100, round( $used[ $key ] / max( 1, $budget[ $key ] ) * 100 ) );

		printf(
			'<span class="aag-bar%1$s"><progress max="100" value="%2$d" aria-label="%3$s"></progress> <span>%4$s</span></span>',
			$pct >= 100 ? ' aag-bar-full' : ( $pct >= (int) $this->settings->get( 'soft_ratio' ) ? ' aag-bar-soft' : '' ),
			(int) $pct,
			/* translators: 1: subject, 2: percentage. */
			esc_attr( sprintf( __( 'Budget of %1$s: %2$d%% used', 'axle-ai-guard' ), $subject, $pct ) ),
			esc_html( $text )
		);
	}

	/**
	 * Per provider and model, this month.
	 */
	private function render_breakdown(): void {
		$rows = $this->calls->breakdown( 'model', gmdate( 'Y-m-01 00:00:00' ) );
		echo '<section class="aag-section" aria-labelledby="aag-models-title"><h2 id="aag-models-title">' . esc_html__( 'Providers and models this month', 'axle-ai-guard' ) . '</h2>';
		$providers = $this->reports->usage( 'month', 'provider' );
		if ( ! $providers && ! $rows ) {
			echo '<p class="aag-muted">' . esc_html__( 'Nothing yet.', 'axle-ai-guard' ) . '</p></section>';
			return;
		}

		echo '<table class="widefat striped aag-table aag-models"><thead><tr><th scope="col">' . esc_html__( 'Provider', 'axle-ai-guard' ) . '</th><th scope="col">' . esc_html__( 'Calls', 'axle-ai-guard' ) . '</th><th scope="col">' . esc_html__( 'Tokens', 'axle-ai-guard' ) . '</th><th scope="col">' . esc_html__( 'Cost', 'axle-ai-guard' ) . '</th><th scope="col">' . esc_html__( 'Kill switch', 'axle-ai-guard' ) . '</th></tr></thead><tbody>';
		foreach ( $providers as $row ) {
			$killed = $this->kill->is_on( 'provider', $row['name'] );
			echo '<tr><th scope="row"><code>' . esc_html( $row['name'] ) . '</code></th><td>' . esc_html( number_format_i18n( $row['calls'] ) ) . '</td><td>' . esc_html( number_format_i18n( $row['tokens'] ) ) . '</td><td>' . esc_html( $this->money( $row['cost'] ) ) . '</td><td>';
			$this->kill_button( 'provider', $row['name'], ! $killed, $killed ? __( 'Turn on', 'axle-ai-guard' ) : __( 'Switch off', 'axle-ai-guard' ) );
			echo '</td></tr>';
		}
		echo '</tbody></table>';

		echo '<table class="widefat striped aag-table aag-models"><thead><tr><th scope="col">' . esc_html__( 'Model', 'axle-ai-guard' ) . '</th><th scope="col">' . esc_html__( 'Calls', 'axle-ai-guard' ) . '</th><th scope="col">' . esc_html__( 'Tokens', 'axle-ai-guard' ) . '</th><th scope="col">' . esc_html__( 'Cost', 'axle-ai-guard' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			echo '<tr><th scope="row"><code>' . esc_html( '' !== (string) $row['name'] ? (string) $row['name'] : '-' ) . '</code></th><td>' . esc_html( number_format_i18n( (int) $row['calls'] ) ) . '</td><td>' . esc_html( number_format_i18n( (int) $row['tokens'] ) ) . '</td><td>' . esc_html( $this->money( (int) $row['cost'] ) ) . '</td></tr>';
		}
		echo '</tbody></table></section>';
	}

	/**
	 * Switches that are on, and a form to switch off one provider or context.
	 */
	private function render_switches(): void {
		echo '<section class="aag-section" aria-labelledby="aag-switches-title"><h2 id="aag-switches-title">' . esc_html__( 'Kill switches that are on', 'axle-ai-guard' ) . '</h2>';
		$active = $this->kill->active();
		if ( ! $active ) {
			echo '<p class="aag-muted">' . esc_html__( 'None.', 'axle-ai-guard' ) . '</p>';
		} else {
			echo '<ul class="aag-switches">';
			foreach ( $active as $entry ) {
				$user = $entry['by'] ? get_userdata( $entry['by'] ) : null;
				echo '<li><strong>' . esc_html( ucfirst( KillSwitch::describe( $entry['scope'], $entry['subject'] ) ) ) . '</strong> ';
				echo '<span class="aag-muted">' . esc_html(
					trim(
						( '' !== $entry['reason'] ? $entry['reason'] . '. ' : '' ) .
						( $entry['at'] ? sprintf( /* translators: 1: date, 2: who. */ __( 'Since %1$s, by %2$s.', 'axle-ai-guard' ), wp_date( 'Y-m-d H:i', $entry['at'] ), $user ? $user->display_name : __( 'AI Guard', 'axle-ai-guard' ) ) : '' )
					)
				) . '</span> ';
				if ( 'site' === $entry['source'] ) {
					$this->kill_button( $entry['scope'], $entry['subject'], false, __( 'Turn on', 'axle-ai-guard' ) );
				}
				echo '</li>';
			}
			echo '</ul>';
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="aag-inline-form aag-context-kill">';
		self::form_fields( 'kill' );
		echo '<input type="hidden" name="scope" value="context"><input type="hidden" name="on" value="1">';
		echo '<label for="aag-kill-context">' . esc_html__( 'Switch AI off in one context', 'axle-ai-guard' ) . '</label> <select id="aag-kill-context" name="subject">';
		foreach ( self::context_labels() as $context => $label ) {
			echo '<option value="' . esc_attr( $context ) . '">' . esc_html( $label ) . '</option>';
		}
		echo '</select> <button type="submit" class="button">' . esc_html__( 'Switch off', 'axle-ai-guard' ) . '</button></form>';
		echo '</section>';
	}

	/**
	 * Contexts in words.
	 *
	 * @return array<string, string>
	 */
	private static function context_labels(): array {
		return array(
			'front' => __( 'Front end', 'axle-ai-guard' ),
			'admin' => __( 'wp-admin', 'axle-ai-guard' ),
			'rest'  => __( 'REST API', 'axle-ai-guard' ),
			'ajax'  => __( 'Ajax', 'axle-ai-guard' ),
			'cron'  => __( 'Cron', 'axle-ai-guard' ),
			'cli'   => __( 'WP-CLI', 'axle-ai-guard' ),
		);
	}

	/**
	 * The last blocked calls.
	 */
	private function render_recent_blocks(): void {
		$rows = $this->calls->query(
			array(
				'status'   => 'blocked',
				'per_page' => 10,
			)
		)['rows'];

		echo '<section class="aag-section" aria-labelledby="aag-blocks-title"><h2 id="aag-blocks-title">' . esc_html__( 'Recent blocks', 'axle-ai-guard' ) . '</h2>';
		if ( ! $rows ) {
			echo '<p class="aag-muted">' . esc_html__( 'No blocked calls.', 'axle-ai-guard' ) . '</p></section>';
			return;
		}
		echo '<table class="widefat striped aag-table aag-blocks"><thead><tr><th scope="col">' . esc_html__( 'When', 'axle-ai-guard' ) . '</th><th scope="col">' . esc_html__( 'Caller', 'axle-ai-guard' ) . '</th><th scope="col">' . esc_html__( 'Rule', 'axle-ai-guard' ) . '</th><th scope="col">' . esc_html__( 'Context', 'axle-ai-guard' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			echo '<tr><td>' . esc_html( self::when( (string) $row['started_at'] ) ) . '</td><td><code>' . esc_html( (string) $row['caller_slug'] ) . '</code></td><td><code>' . esc_html( (string) $row['block_rule'] ) . '</code></td><td>' . esc_html( (string) $row['context'] ) . '</td></tr>';
		}
		echo '</tbody></table>';
		echo '<p><a href="' . esc_url( self::url( 'calls', array( 'status' => 'blocked' ) ) ) . '">' . esc_html__( 'All blocked calls', 'axle-ai-guard' ) . '</a></p></section>';
	}

	/**
	 * The Calls filters from the URL.
	 *
	 * @return array<string, mixed>
	 */
	private function call_filters(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only filters.
		$get     = static function ( string $key ): string {
			return isset( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) : '';
		};
		$filters = array(
			'status'   => in_array( $get( 'status' ), Calls::STATUSES, true ) ? $get( 'status' ) : '',
			'caller'   => $get( 'plugin' ),
			'provider' => $get( 'provider' ),
			'model'    => $get( 'model' ),
			'context'  => in_array( $get( 'context' ), Settings::CONTEXTS, true ) ? $get( 'context' ) : '',
			'user_id'  => absint( $get( 'user' ) ),
			'date'     => preg_match( '/^\d{4}-\d{2}-\d{2}$/', $get( 'date' ) ) ? $get( 'date' ) : '',
			'page'     => max( 1, absint( $get( 'paged' ) ) ),
		);
		// phpcs:enable

		return $filters;
	}

	/**
	 * The calls for the current filters.
	 *
	 * @return array{rows: array, total: int}
	 */
	private function calls_query(): array {
		$filters = $this->call_filters();
		$args    = $filters;
		if ( '' !== $filters['date'] ) {
			$args['since'] = $filters['date'] . ' 00:00:00';
			$args['until'] = $filters['date'] . ' 23:59:59';
		}
		$args['per_page'] = 50;

		return $this->calls->query( $args );
	}

	/**
	 * Calls tab: filters, the table (rendered by the script) and the detail dialog.
	 */
	private function render_calls(): void {
		$filters = $this->call_filters();
		$result  = $this->calls_query();

		echo '<form method="get" class="aag-filters" aria-label="' . esc_attr__( 'Filter calls', 'axle-ai-guard' ) . '">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '"><input type="hidden" name="tab" value="calls">';
		$selects = array(
			'status'   => array( __( 'Status', 'axle-ai-guard' ), array_combine( Calls::STATUSES, Calls::STATUSES ), $filters['status'] ),
			'plugin'   => array( __( 'Caller', 'axle-ai-guard' ), self::pairs( $this->calls->distinct( 'caller_slug' ) ), $filters['caller'] ),
			'provider' => array( __( 'Provider', 'axle-ai-guard' ), self::pairs( $this->calls->distinct( 'provider' ) ), $filters['provider'] ),
			'model'    => array( __( 'Model', 'axle-ai-guard' ), self::pairs( $this->calls->distinct( 'model' ) ), $filters['model'] ),
			'context'  => array( __( 'Context', 'axle-ai-guard' ), self::context_labels(), $filters['context'] ),
		);
		foreach ( $selects as $name => $select ) {
			echo '<span class="aag-filter"><label for="aag-filter-' . esc_attr( $name ) . '">' . esc_html( $select[0] ) . '</label> <select id="aag-filter-' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '"><option value="">' . esc_html__( 'All', 'axle-ai-guard' ) . '</option>';
			foreach ( $select[1] as $value => $label ) {
				echo '<option value="' . esc_attr( (string) $value ) . '"' . selected( (string) $value, $select[2], false ) . '>' . esc_html( (string) $label ) . '</option>';
			}
			echo '</select></span> ';
		}
		echo '<span class="aag-filter"><label for="aag-filter-user">' . esc_html__( 'User ID', 'axle-ai-guard' ) . '</label> <input type="number" min="0" id="aag-filter-user" name="user" value="' . esc_attr( $filters['user_id'] ? (string) $filters['user_id'] : '' ) . '" class="small-text"></span> ';
		echo '<span class="aag-filter"><label for="aag-filter-date">' . esc_html__( 'Date', 'axle-ai-guard' ) . '</label> <input type="date" id="aag-filter-date" name="date" value="' . esc_attr( $filters['date'] ) . '"></span> ';
		echo '<button type="submit" class="button">' . esc_html__( 'Filter', 'axle-ai-guard' ) . '</button></form>';

		echo '<p class="aag-count">' . esc_html( sprintf( /* translators: %s: number of calls. */ _n( '%s call', '%s calls', $result['total'], 'axle-ai-guard' ), number_format_i18n( $result['total'] ) ) ) . '</p>';
		echo '<div class="aag-calls" data-aag-calls><p class="aag-muted">' . esc_html__( 'Loading...', 'axle-ai-guard' ) . '</p></div>';

		$pages = (int) ceil( $result['total'] / 50 );
		if ( $pages > 1 ) {
			echo '<nav class="aag-pages" aria-label="' . esc_attr__( 'Pages', 'axle-ai-guard' ) . '">';
			echo wp_kses_post(
				(string) paginate_links(
					array(
						'base'    => add_query_arg( 'paged', '%#%' ),
						'format'  => '',
						'current' => $filters['page'],
						'total'   => $pages,
					)
				)
			);
			echo '</nav>';
		}

		echo '<dialog class="aag-modal" aria-labelledby="aag-modal-title"><div class="aag-modal-inner"><h2 id="aag-modal-title">' . esc_html__( 'Call details', 'axle-ai-guard' ) . '</h2><div class="aag-modal-body" data-aag-detail></div><p><button type="button" class="button aag-modal-close">' . esc_html__( 'Close', 'axle-ai-guard' ) . '</button></p></div></dialog>';
	}

	/**
	 * A list as value => label pairs.
	 *
	 * @param string[] $values Values.
	 * @return array<string, string>
	 */
	private static function pairs( array $values ): array {
		return $values ? array_combine( $values, $values ) : array();
	}

	/**
	 * A money field value from micro-units.
	 *
	 * @param int $micros Micro-units.
	 * @return string
	 */
	private static function money_field( int $micros ): string {
		return $micros > 0 ? rtrim( rtrim( number_format( $micros / 1000000, 6, '.', '' ), '0' ), '.' ) : '';
	}

	/**
	 * Budgets tab.
	 */
	private function render_budgets(): void {
		$all = $this->settings->all();

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="aag-budgets">';
		self::form_fields( 'budgets' );

		echo '<h2>' . esc_html__( 'Rate limits', 'axle-ai-guard' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Calls per minute. 0 turns a limit off.', 'axle-ai-guard' ) . '</p>';
		echo '<table class="form-table" role="presentation"><tbody>';
		$rates = array(
			'rate_global' => __( 'Whole site', 'axle-ai-guard' ),
			'rate_plugin' => __( 'Per plugin (default)', 'axle-ai-guard' ),
			'rate_user'   => __( 'Per logged-in user', 'axle-ai-guard' ),
			'rate_ip'     => __( 'Per visitor IP address, not logged in', 'axle-ai-guard' ),
		);
		foreach ( $rates as $key => $label ) {
			printf(
				'<tr><th scope="row"><label for="aag-%1$s">%2$s</label></th><td><input type="number" min="0" id="aag-%1$s" name="settings[%1$s]" value="%3$d" class="small-text"></td></tr>',
				esc_attr( $key ),
				esc_html( $label ),
				(int) $all[ $key ]
			);
		}
		printf(
			'<tr><th scope="row"><label for="aag-soft_ratio">%1$s</label></th><td><input type="number" min="1" max="100" id="aag-soft_ratio" name="settings[soft_ratio]" value="%2$d" class="small-text"> %3$s</td></tr>',
			esc_html__( 'Soft limit alert at', 'axle-ai-guard' ),
			(int) $all['soft_ratio'],
			esc_html__( '% of a budget', 'axle-ai-guard' )
		);
		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Budgets and rules', 'axle-ai-guard' ) . '</h2>';
		echo '<p class="description">' . esc_html(
			sprintf(
				/* translators: %s: currency code. */
				__( 'Empty means no limit. Money in %s. A call that is already running can go past a hard limit by its own size.', 'axle-ai-guard' ),
				$this->prices->currency()
			)
		) . '</p>';

		$subjects = array( 'global' );
		foreach ( array_keys( $this->settings->callers() ) as $caller ) {
			$subjects[] = 'plugin:' . $caller;
		}
		foreach ( array_keys( $this->settings->policies() ) as $subject ) {
			$subjects[] = $subject;
		}
		foreach ( array_unique( $subjects ) as $index => $subject ) {
			$this->render_policy( $subject, (int) $index );
		}

		echo '<p><label for="aag-new-subject">' . esc_html__( 'Add a plugin by its folder name', 'axle-ai-guard' ) . '</label> <input type="text" id="aag-new-subject" name="new_subject" class="regular-text"></p>';
		submit_button( __( 'Save budgets and limits', 'axle-ai-guard' ) );
		echo '</form>';

		$this->render_approvals();
	}

	/**
	 * The fieldset of one subject's policy.
	 *
	 * @param string $subject Subject.
	 * @param int    $index   Position, for unique ids.
	 */
	private function render_policy( string $subject, int $index ): void {
		$policy = $this->settings->policy( $subject );
		$id     = 'aag-p' . $index;
		$name   = 'policy[' . $subject . ']';
		$title  = 'global' === $subject ? __( 'Whole site', 'axle-ai-guard' ) : substr( $subject, strpos( $subject, ':' ) + 1 );

		echo '<fieldset class="aag-policy" data-subject="' . esc_attr( $subject ) . '"><legend>' . esc_html( $title ) . '</legend><div class="aag-grid">';
		$fields = array(
			'daily_tokens'   => array( __( 'Tokens per day', 'axle-ai-guard' ), $policy['daily_tokens'] ? (string) $policy['daily_tokens'] : '' ),
			'monthly_tokens' => array( __( 'Tokens per month', 'axle-ai-guard' ), $policy['monthly_tokens'] ? (string) $policy['monthly_tokens'] : '' ),
			'daily_cost'     => array( __( 'Money per day', 'axle-ai-guard' ), self::money_field( $policy['daily_cost'] ) ),
			'monthly_cost'   => array( __( 'Money per month', 'axle-ai-guard' ), self::money_field( $policy['monthly_cost'] ) ),
			'per_minute'     => array( __( 'Calls per minute (empty: default)', 'axle-ai-guard' ), $policy['per_minute'] >= 0 ? (string) $policy['per_minute'] : '' ),
		);
		foreach ( $fields as $key => $field ) {
			printf(
				'<p><label for="%1$s-%2$s">%3$s</label><input type="text" inputmode="decimal" id="%1$s-%2$s" name="%4$s[%2$s]" value="%5$s" class="small-text"></p>',
				esc_attr( $id ),
				esc_attr( $key ),
				esc_html( $field[0] ),
				esc_attr( $name ),
				esc_attr( $field[1] )
			);
		}
		$lists = array(
			'providers' => __( 'Allowed providers (comma separated, empty: all)', 'axle-ai-guard' ),
			'models'    => __( 'Allowed models (comma separated, * at the end matches more, empty: all)', 'axle-ai-guard' ),
			'roles'     => __( 'Allowed roles (comma separated, anonymous for visitors, empty: all)', 'axle-ai-guard' ),
		);
		foreach ( $lists as $key => $label ) {
			printf(
				'<p class="aag-wide"><label for="%1$s-%2$s">%3$s</label><input type="text" id="%1$s-%2$s" name="%4$s[%2$s]" value="%5$s" class="regular-text"></p>',
				esc_attr( $id ),
				esc_attr( $key ),
				esc_html( $label ),
				esc_attr( $name ),
				esc_attr( implode( ', ', (array) $policy[ $key ] ) )
			);
		}
		echo '</div><fieldset class="aag-contexts"><legend>' . esc_html__( 'Allowed contexts (none ticked: all)', 'axle-ai-guard' ) . '</legend>';
		foreach ( self::context_labels() as $context => $label ) {
			printf(
				'<label><input type="checkbox" name="%1$s[contexts][]" value="%2$s"%3$s> %4$s</label> ',
				esc_attr( $name ),
				esc_attr( $context ),
				checked( in_array( $context, (array) $policy['contexts'], true ), true, false ),
				esc_html( $label )
			);
		}
		echo '</fieldset>';
		printf(
			'<p><label><input type="checkbox" name="%1$s[block_files]" value="1"%2$s> %3$s</label></p>',
			esc_attr( $name ),
			checked( (bool) $policy['block_files'], true, false ),
			esc_html__( 'Block calls that attach files (images, PDFs). Files cannot be scanned for personal data.', 'axle-ai-guard' )
		);
		echo '</fieldset>';
	}

	/**
	 * Callers waiting for approval, and denied ones.
	 */
	private function render_approvals(): void {
		echo '<h2>' . esc_html__( 'Callers', 'axle-ai-guard' ) . '</h2>';
		$callers = $this->settings->callers();
		if ( ! $callers ) {
			echo '<p class="aag-muted">' . esc_html__( 'No caller has made an AI call yet.', 'axle-ai-guard' ) . '</p>';
			return;
		}
		echo '<table class="widefat striped aag-table aag-approvals"><thead><tr><th scope="col">' . esc_html__( 'Caller', 'axle-ai-guard' ) . '</th><th scope="col">' . esc_html__( 'Type', 'axle-ai-guard' ) . '</th><th scope="col">' . esc_html__( 'First seen in', 'axle-ai-guard' ) . '</th><th scope="col">' . esc_html__( 'Status', 'axle-ai-guard' ) . '</th><th scope="col">' . esc_html__( 'Change', 'axle-ai-guard' ) . '</th></tr></thead><tbody>';
		foreach ( $callers as $key => $caller ) {
			$status = (string) ( $caller['status'] ?? 'approved' );
			echo '<tr><th scope="row"><code>' . esc_html( (string) $key ) . '</code></th><td>' . esc_html( (string) ( $caller['type'] ?? '' ) ) . '</td><td><code>' . esc_html( (string) ( $caller['file'] ?? '' ) ) . '</code></td><td>' . esc_html( self::status_label( $status ) ) . '</td><td>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="aag-inline-form">';
			self::form_fields( 'callers' );
			echo '<input type="hidden" name="caller" value="' . esc_attr( (string) $key ) . '">';
			if ( 'approved' !== $status ) {
				echo '<button type="submit" name="status" value="approved" class="button button-primary">' . esc_html__( 'Approve', 'axle-ai-guard' ) . '</button> ';
			}
			if ( 'denied' !== $status ) {
				echo '<button type="submit" name="status" value="denied" class="button">' . esc_html__( 'Deny', 'axle-ai-guard' ) . '</button>';
			}
			echo '</form></td></tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Data rules tab.
	 */
	private function render_rules(): void {
		$modes = array(
			'off'    => __( 'Off', 'axle-ai-guard' ),
			'log'    => __( 'Log', 'axle-ai-guard' ),
			'redact' => __( 'Redact', 'axle-ai-guard' ),
			'block'  => __( 'Block', 'axle-ai-guard' ),
		);

		echo '<p class="description">' . esc_html__( 'Each detector looks at what a call sends. Log records that it was found. Redact replaces it with a placeholder before the request leaves the site. Block stops the call. Files sent with a prompt cannot be scanned; block them per plugin under Budgets and limits.', 'axle-ai-guard' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		self::form_fields( 'rules' );
		echo '<table class="widefat striped aag-table aag-rules"><thead><tr><th scope="col">' . esc_html__( 'Detector', 'axle-ai-guard' ) . '</th><th scope="col">' . esc_html__( 'Mode', 'axle-ai-guard' ) . '</th></tr></thead><tbody>';
		foreach ( $this->detectors->all() as $id => $detector ) {
			if ( 0 === strpos( $id, 'custom-' ) ) {
				continue;
			}
			echo '<tr><th scope="row"><label for="aag-rule-' . esc_attr( $id ) . '">' . esc_html( $detector['label'] ) . '</label></th><td><select id="aag-rule-' . esc_attr( $id ) . '" name="modes[' . esc_attr( $id ) . ']">';
			foreach ( $modes as $mode => $label ) {
				echo '<option value="' . esc_attr( $mode ) . '"' . selected( $mode, $this->detectors->mode( $id ), false ) . '>' . esc_html( $label ) . '</option>';
			}
			echo '</select></td></tr>';
		}
		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Custom rules', 'axle-ai-guard' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'A string matches without regard to case. A regex needs delimiters, such as /ORD-\d{6}/.', 'axle-ai-guard' ) . '</p>';
		echo '<table class="widefat striped aag-table aag-custom"><thead><tr><th scope="col">' . esc_html__( 'Label', 'axle-ai-guard' ) . '</th><th scope="col">' . esc_html__( 'Type', 'axle-ai-guard' ) . '</th><th scope="col">' . esc_html__( 'Pattern', 'axle-ai-guard' ) . '</th><th scope="col">' . esc_html__( 'Mode', 'axle-ai-guard' ) . '</th></tr></thead><tbody>';
		$custom   = $this->detectors->custom();
		$custom[] = array(
			'label'   => '',
			'type'    => 'string',
			'pattern' => '',
			'mode'    => 'redact',
		);
		foreach ( $custom as $i => $rule ) {
			/* translators: %d: rule number. */
			$row = sprintf( __( 'Custom rule %d', 'axle-ai-guard' ), $i + 1 );
			echo '<tr>';
			printf( '<td><input type="text" name="custom[%1$d][label]" value="%2$s" aria-label="%3$s"></td>', (int) $i, esc_attr( $rule['label'] ), esc_attr( $row . ': ' . __( 'label', 'axle-ai-guard' ) ) );
			printf( '<td><select name="custom[%1$d][type]" aria-label="%2$s"><option value="string"%3$s>%4$s</option><option value="regex"%5$s>%6$s</option></select></td>', (int) $i, esc_attr( $row . ': ' . __( 'type', 'axle-ai-guard' ) ), selected( $rule['type'], 'string', false ), esc_html__( 'String', 'axle-ai-guard' ), selected( $rule['type'], 'regex', false ), esc_html__( 'Regex', 'axle-ai-guard' ) );
			printf( '<td><input type="text" name="custom[%1$d][pattern]" value="%2$s" class="regular-text" aria-label="%3$s"></td>', (int) $i, esc_attr( $rule['pattern'] ), esc_attr( $row . ': ' . __( 'pattern', 'axle-ai-guard' ) ) );
			printf( '<td><select name="custom[%1$d][mode]" aria-label="%2$s">', (int) $i, esc_attr( $row . ': ' . __( 'mode', 'axle-ai-guard' ) ) );
			foreach ( $modes as $mode => $label ) {
				echo '<option value="' . esc_attr( $mode ) . '"' . selected( $mode, $rule['mode'], false ) . '>' . esc_html( $label ) . '</option>';
			}
			echo '</select></td></tr>';
		}
		echo '</tbody></table>';
		submit_button( __( 'Save data rules', 'axle-ai-guard' ) );
		echo '</form>';

		$this->render_test_box();
	}

	/**
	 * The "test a rule" box and its last result.
	 */
	private function render_test_box(): void {
		$result = get_transient( 'axle_ai_guard_test_' . get_current_user_id() );

		echo '<section class="aag-section aag-test" aria-labelledby="aag-test-title"><h2 id="aag-test-title">' . esc_html__( 'Test the rules', 'axle-ai-guard' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		self::form_fields( 'test_rules' );
		echo '<p><label for="aag-test-text">' . esc_html__( 'Paste text. Nothing is sent to any AI provider.', 'axle-ai-guard' ) . '</label></p>';
		echo '<textarea id="aag-test-text" name="text" rows="5" class="large-text">' . esc_textarea( is_array( $result ) ? (string) $result['text'] : '' ) . '</textarea>';
		submit_button( __( 'Test', 'axle-ai-guard' ), 'secondary', 'submit', false );
		echo '</form>';

		if ( is_array( $result ) ) {
			echo '<div class="aag-test-result" role="status">';
			if ( ! $result['findings'] ) {
				echo '<p>' . esc_html__( 'Nothing found.', 'axle-ai-guard' ) . '</p>';
			} else {
				echo '<p><strong>' . esc_html( $result['blocked'] ? __( 'A call with this text would be blocked.', 'axle-ai-guard' ) : __( 'A call with this text would go out.', 'axle-ai-guard' ) ) . '</strong></p><ul>';
				foreach ( $result['findings'] as $finding ) {
					/* translators: 1: detector label, 2: count, 3: mode. */
					echo '<li>' . esc_html( sprintf( __( '%1$s: %2$d found, mode %3$s', 'axle-ai-guard' ), $finding['label'], $finding['count'], $finding['mode'] ) ) . '</li>';
				}
				echo '</ul><p>' . esc_html__( 'What the provider would get:', 'axle-ai-guard' ) . '</p><pre class="aag-pre">' . esc_html( (string) $result['redacted'] ) . '</pre>';
			}
			echo '</div>';
		}
		echo '</section>';
	}

	/**
	 * Prices tab.
	 */
	private function render_prices(): void {
		echo '<p class="description">' . esc_html(
			sprintf(
				/* translators: 1: date, 2: currency. */
				__( 'Prices per million tokens, in %2$s. %3$s Prices as of %1$s: check them against your provider, they change. A key ending in * matches every model id that starts with the rest.', 'axle-ai-guard' ),
				$this->prices->as_of(),
				$this->prices->currency(),
				$this->prices->edited() ? __( 'Edited on this site.', 'axle-ai-guard' ) : __( 'The shipped list prices.', 'axle-ai-guard' )
			)
		) . '</p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		self::form_fields( 'prices' );
		echo '<table class="widefat striped aag-table aag-prices"><thead><tr><th scope="col">' . esc_html__( 'Provider/model', 'axle-ai-guard' ) . '</th><th scope="col">' . esc_html__( 'Input', 'axle-ai-guard' ) . '</th><th scope="col">' . esc_html__( 'Output', 'axle-ai-guard' ) . '</th><th scope="col">' . esc_html__( 'Thought', 'axle-ai-guard' ) . '</th></tr></thead><tbody>';
		$rows = array();
		foreach ( $this->prices->table() as $key => $row ) {
			$rows[] = array( 'key' => $key ) + $row;
		}
		for ( $i = 0; $i < 3; $i++ ) {
			$rows[] = array(
				'key'     => '',
				'input'   => '',
				'output'  => '',
				'thought' => '',
			);
		}
		foreach ( $rows as $i => $row ) {
			/* translators: %d: row number. */
			$label = sprintf( __( 'Price row %d', 'axle-ai-guard' ), $i + 1 );
			echo '<tr>';
			printf( '<td><input type="text" name="prices[%1$d][key]" value="%2$s" class="regular-text" aria-label="%3$s"></td>', (int) $i, esc_attr( (string) $row['key'] ), esc_attr( $label . ': ' . __( 'provider/model', 'axle-ai-guard' ) ) );
			foreach ( array( 'input', 'output', 'thought' ) as $field ) {
				printf( '<td><input type="text" inputmode="decimal" name="prices[%1$d][%2$s]" value="%3$s" class="small-text" aria-label="%4$s"></td>', (int) $i, esc_attr( $field ), esc_attr( (string) $row[ $field ] ), esc_attr( $label . ': ' . $field ) );
			}
			echo '</tr>';
		}
		echo '</tbody></table><p class="submit">';
		echo '<button type="submit" name="do" value="save" class="button button-primary">' . esc_html__( 'Save prices', 'axle-ai-guard' ) . '</button> ';
		echo '<button type="submit" name="do" value="recalc" class="button">' . esc_html__( 'Recalculate the last 30 days', 'axle-ai-guard' ) . '</button> ';
		echo '<button type="submit" name="do" value="reset" class="button">' . esc_html__( 'Back to the shipped prices', 'axle-ai-guard' ) . '</button>';
		echo '</p></form>';
	}

	/**
	 * Settings tab.
	 */
	private function render_settings(): void {
		$all = $this->settings->all();

		if ( self::ai_plugin_feature( 'connector-approval' ) ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html__( 'The AI plugin\'s Connector Approval experiment is on. Approve connectors per plugin there; AI Guard does not ask again. Use "Allow" or "Allow and alert" below so the two do not both hold new plugins.', 'axle-ai-guard' ) . '</p></div>';
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		self::form_fields( 'settings' );
		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row"><label for="aag-new_caller">' . esc_html__( 'A plugin making its first AI call', 'axle-ai-guard' ) . '</label></th><td><select id="aag-new_caller" name="settings[new_caller]">';
		foreach ( array(
			'allow' => __( 'Allow', 'axle-ai-guard' ),
			'alert' => __( 'Allow and alert', 'axle-ai-guard' ),
			'deny'  => __( 'Block until approved', 'axle-ai-guard' ),
		) as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '"' . selected( $value, $all['new_caller'], false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></td></tr>';

		self::text_row( 'currency', __( 'Currency (ISO code)', 'axle-ai-guard' ), (string) $all['currency'], 'small-text' );
		self::number_row( 'retention_calls', __( 'Keep calls for (days)', 'axle-ai-guard' ), (int) $all['retention_calls'] );
		self::number_row( 'retention_usage', __( 'Keep usage totals for (days)', 'axle-ai-guard' ), (int) $all['retention_usage'] );
		self::check_row( 'store_preview', __( 'Previews', 'axle-ai-guard' ), __( 'Store the first 300 characters of each prompt, after redaction', 'axle-ai-guard' ), (bool) $all['store_preview'] );
		self::check_row( 'store_prompts', __( 'Prompt store', 'axle-ai-guard' ), __( 'Store each full prompt, with every finding redacted, for debugging', 'axle-ai-guard' ), (bool) $all['store_prompts'] );
		self::number_row( 'retention_prompts', __( 'Keep stored prompts for (days)', 'axle-ai-guard' ), (int) $all['retention_prompts'] );
		self::check_row( 'alert_email', __( 'Email alerts', 'axle-ai-guard' ), __( 'Email alerts for budgets, kill switches, data rule blocks, new plugins and error spikes', 'axle-ai-guard' ), (bool) $all['alert_email'] );
		self::text_row( 'alert_recipients', __( 'Alert recipients (empty: the site admin email)', 'axle-ai-guard' ), (string) $all['alert_recipients'], 'regular-text' );
		self::text_row( 'webhook_url', __( 'Alert webhook URL (JSON POST)', 'axle-ai-guard' ), (string) $all['webhook_url'], 'regular-text' );
		self::check_row( 'direct_monitoring', __( 'Direct calls', 'axle-ai-guard' ), __( 'Watch requests to known AI provider hosts that skip the AI Client', 'axle-ai-guard' ), (bool) $all['direct_monitoring'] );
		self::check_row( 'autotrip_budget', __( 'Budget auto-trip', 'axle-ai-guard' ), __( 'Switch all AI off when the monthly money budget of the whole site is reached', 'axle-ai-guard' ), (bool) $all['autotrip_budget'] );
		self::number_row( 'autotrip_errors', __( 'Switch all AI off when this % of calls fail in 5 minutes (0: never)', 'axle-ai-guard' ), (int) $all['autotrip_errors'] );

		echo '</tbody></table>';
		submit_button( __( 'Save settings', 'axle-ai-guard' ) );
		echo '</form>';
	}

	/**
	 * A text settings row.
	 *
	 * @param string $key   Key.
	 * @param string $label Label.
	 * @param string $value Value.
	 * @param string $css   Input class.
	 */
	private static function text_row( string $key, string $label, string $value, string $css ): void {
		printf( '<tr><th scope="row"><label for="aag-%1$s">%2$s</label></th><td><input type="text" id="aag-%1$s" name="settings[%1$s]" value="%3$s" class="%4$s"></td></tr>', esc_attr( $key ), esc_html( $label ), esc_attr( $value ), esc_attr( $css ) );
	}

	/**
	 * A number settings row.
	 *
	 * @param string $key   Key.
	 * @param string $label Label.
	 * @param int    $value Value.
	 */
	private static function number_row( string $key, string $label, int $value ): void {
		printf( '<tr><th scope="row"><label for="aag-%1$s">%2$s</label></th><td><input type="number" min="0" id="aag-%1$s" name="settings[%1$s]" value="%3$d" class="small-text"></td></tr>', esc_attr( $key ), esc_html( $label ), (int) $value );
	}

	/**
	 * A checkbox settings row.
	 *
	 * @param string $key     Key.
	 * @param string $heading Row heading.
	 * @param string $label   Checkbox label.
	 * @param bool   $checked Whether on.
	 */
	private static function check_row( string $key, string $heading, string $label, bool $checked ): void {
		printf( '<tr><th scope="row">%2$s</th><td><input type="hidden" name="settings[%1$s]" value="0"><label><input type="checkbox" name="settings[%1$s]" value="1"%3$s> %4$s</label></td></tr>', esc_attr( $key ), esc_html( $heading ), checked( $checked, true, false ), esc_html( $label ) );
	}

	/**
	 * Whether a feature of the official AI plugin is on.
	 *
	 * @param string $feature Feature id, such as connector-approval or ai-request-logging.
	 * @return bool
	 */
	public static function ai_plugin_feature( string $feature ): bool {
		$active = (array) get_option( 'active_plugins', array() );

		return in_array( 'ai/ai.php', $active, true ) && (bool) get_option( 'wpai_feature_' . $feature . '_enabled' );
	}

	/**
	 * Checks the capability and nonce of a form.
	 *
	 * @param string $action Action without the prefix.
	 */
	private static function verify( string $action ): void {
		if ( ! current_user_can( Plugin::capability() ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage AI Guard.', 'axle-ai-guard' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'axle_ai_guard_' . $action );
	}

	/**
	 * Back to a tab with a message.
	 *
	 * @param string $tab  Tab.
	 * @param string $done Message key.
	 */
	private static function back( string $tab, string $done ): void {
		wp_safe_redirect( self::url( $tab, array( 'done' => $done ) ) );
		exit;
	}

	/**
	 * A posted field.
	 *
	 * @param string $key Key.
	 * @return mixed
	 */
	private static function posted( string $key ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Checked in verify(); every value is sanitised where it is used.
		return isset( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : null;
	}

	/**
	 * Handles a kill switch form.
	 */
	public function handle_kill(): void {
		self::verify( 'kill' );
		$scope   = sanitize_key( (string) self::posted( 'scope' ) );
		$subject = sanitize_text_field( (string) self::posted( 'subject' ) );
		$on      = '1' === (string) self::posted( 'on' );
		$reason  = sanitize_text_field( (string) self::posted( 'reason' ) );

		$changed = $on ? $this->kill->engage( $scope, $subject, $reason, get_current_user_id() ) : $this->kill->release( $scope, $subject, get_current_user_id() );
		self::back( 'overview', $changed ? ( $on ? 'kill_on' : 'kill_off' ) : 'kill_same' );
	}

	/**
	 * Handles the budgets form.
	 */
	public function handle_budgets(): void {
		self::verify( 'budgets' );
		$settings = (array) self::posted( 'settings' );
		$this->settings->save( array_intersect_key( $settings, array_flip( array( 'rate_global', 'rate_plugin', 'rate_user', 'rate_ip', 'soft_ratio' ) ) ) );

		foreach ( (array) self::posted( 'policy' ) as $subject => $fields ) {
			$fields = (array) $fields;
			foreach ( array( 'daily_cost', 'monthly_cost' ) as $money ) {
				$fields[ $money ] = Prices::to_micros( $fields[ $money ] ?? '' );
			}
			$fields['contexts']    = (array) ( $fields['contexts'] ?? array() );
			$fields['block_files'] = ! empty( $fields['block_files'] );
			$this->settings->save_policy( (string) $subject, $fields );
		}

		$new = sanitize_key( (string) self::posted( 'new_subject' ) );
		if ( '' !== $new ) {
			$this->settings->save_policy( 'plugin:' . $new, array() );
		}

		self::back( 'budgets', 'budgets' );
	}

	/**
	 * Handles approve and deny of a caller.
	 */
	public function handle_callers(): void {
		self::verify( 'callers' );
		$this->settings->set_caller_status( sanitize_text_field( (string) self::posted( 'caller' ) ), sanitize_key( (string) self::posted( 'status' ) ) );
		self::back( 'budgets', 'callers' );
	}

	/**
	 * Handles the data rules form.
	 */
	public function handle_rules(): void {
		self::verify( 'rules' );
		$modes = array_map( 'sanitize_key', (array) self::posted( 'modes' ) );
		$this->detectors->save( $modes, array_values( (array) self::posted( 'custom' ) ) );
		self::back( 'rules', 'rules' );
	}

	/**
	 * Handles the rule test.
	 */
	public function handle_test_rules(): void {
		self::verify( 'test_rules' );
		$text   = sanitize_textarea_field( (string) self::posted( 'text' ) );
		$result = $this->reports->test_rules( $text ) + array( 'text' => $text );
		set_transient( 'axle_ai_guard_test_' . get_current_user_id(), $result, 10 * MINUTE_IN_SECONDS );
		wp_safe_redirect( self::url( 'rules', array( 'tested' => 1 ) ) . '#aag-test-title' );
		exit;
	}

	/**
	 * Handles the prices form: save, reset or recalculate.
	 */
	public function handle_prices(): void {
		self::verify( 'prices' );
		$do = sanitize_key( (string) self::posted( 'do' ) );

		if ( 'reset' === $do ) {
			$this->prices->reset();
			self::back( 'prices', 'prices_reset' );
		}
		if ( 'recalc' === $do ) {
			$this->cleanup->recalculate( 30 );
			self::back( 'prices', 'recalc' );
		}

		$table = array();
		foreach ( (array) self::posted( 'prices' ) as $row ) {
			$row = (array) $row;
			$key = Prices::key( sanitize_text_field( (string) ( $row['key'] ?? '' ) ) );
			if ( '' === $key || ! is_numeric( $row['input'] ?? '' ) || ! is_numeric( $row['output'] ?? '' ) ) {
				continue;
			}
			$table[ $key ] = array(
				'input'   => (float) $row['input'],
				'output'  => (float) $row['output'],
				'thought' => is_numeric( $row['thought'] ?? '' ) ? (float) $row['thought'] : '',
			);
		}
		$this->prices->save( $table );
		self::back( 'prices', 'prices' );
	}

	/**
	 * Handles the settings form.
	 */
	public function handle_settings(): void {
		self::verify( 'settings' );
		$this->settings->save( (array) self::posted( 'settings' ) );
		self::back( 'settings', 'settings' );
	}
}
