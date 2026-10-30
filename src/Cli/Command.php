<?php
/**
 * WP-CLI: wp axle-ai-guard.
 *
 * @package AryanX\Axle\AiGuard
 */

namespace AryanX\Axle\AiGuard\Cli;

use AryanX\Axle\AiGuard\Calls;
use AryanX\Axle\AiGuard\Cleanup;
use AryanX\Axle\AiGuard\Detectors;
use AryanX\Axle\AiGuard\KillSwitch;
use AryanX\Axle\AiGuard\Prices;
use AryanX\Axle\AiGuard\Reports;
use AryanX\Axle\AiGuard\Settings;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * See and control the AI calls plugins make: usage, calls, kill switches, budgets, rate limits, data rules and prices.
 */
final class Command {

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

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
	 * Clean-up.
	 *
	 * @var Cleanup
	 */
	private Cleanup $cleanup;

	/**
	 * Sets up the command.
	 *
	 * @param Settings   $settings  Settings.
	 * @param KillSwitch $kill      Kill switches.
	 * @param Detectors  $detectors Data rules.
	 * @param Prices     $prices    Prices.
	 * @param Calls      $calls     The call log.
	 * @param Reports    $reports   Reports.
	 * @param Cleanup    $cleanup   Clean-up.
	 */
	public function __construct( Settings $settings, KillSwitch $kill, Detectors $detectors, Prices $prices, Calls $calls, Reports $reports, Cleanup $cleanup ) {
		$this->settings  = $settings;
		$this->kill      = $kill;
		$this->detectors = $detectors;
		$this->prices    = $prices;
		$this->calls     = $calls;
		$this->reports   = $reports;
		$this->cleanup   = $cleanup;
	}

	/**
	 * Shows AI usage for today or this month.
	 *
	 * ## OPTIONS
	 *
	 * [--period=<period>]
	 * : day or month.
	 * ---
	 * default: month
	 * ---
	 *
	 * [--by=<group>]
	 * : plugin, provider, model or user.
	 * ---
	 * default: plugin
	 * ---
	 *
	 * [--format=<format>]
	 * : table, csv, json, yaml or count.
	 * ---
	 * default: table
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp axle-ai-guard usage --period=day --by=model
	 *
	 * @param array $args  Positional arguments.
	 * @param array $assoc Options.
	 */
	public function usage( $args, $assoc ): void {
		$period   = 'day' === ( $assoc['period'] ?? '' ) ? 'day' : 'month';
		$currency = $this->prices->currency();
		$rows     = array();
		foreach ( $this->reports->usage( $period, (string) ( $assoc['by'] ?? 'plugin' ) ) as $row ) {
			$row['cost'] = Prices::format( (int) $row['cost'], $currency );
			$rows[]      = $row;
		}
		$total = $this->reports->totals( $period );
		if ( 'table' === ( $assoc['format'] ?? 'table' ) ) {
			/* translators: 1: calls, 2: tokens, 3: cost, 4: blocked. */
			WP_CLI::log( sprintf( __( 'Total: %1$d calls, %2$d tokens, %3$s, %4$d blocked.', 'axle-ai-guard' ), $total['calls'], $total['tokens'], Prices::format( $total['cost'], $currency ), $total['blocked'] ) );
		}
		\WP_CLI\Utils\format_items( (string) ( $assoc['format'] ?? 'table' ), $rows, array( 'name', 'calls', 'tokens', 'cost', 'blocked' ) );
	}

	/**
	 * Lists logged AI calls, newest first.
	 *
	 * ## OPTIONS
	 *
	 * [--status=<status>]
	 * : pending, ok, error or blocked.
	 *
	 * [--plugin=<caller>]
	 * : Caller key, such as a plugin folder.
	 *
	 * [--provider=<provider>]
	 * : Provider id.
	 *
	 * [--since=<date>]
	 * : UTC date or datetime.
	 *
	 * [--limit=<limit>]
	 * : How many.
	 * ---
	 * default: 20
	 * ---
	 *
	 * [--format=<format>]
	 * : table, csv, json, yaml, ids or count.
	 * ---
	 * default: table
	 * ---
	 *
	 * @param array $args  Positional arguments.
	 * @param array $assoc Options.
	 */
	public function calls( $args, $assoc ): void {
		$since = isset( $assoc['since'] ) ? strtotime( (string) $assoc['since'] . ' UTC' ) : false;
		$rows  = $this->calls->query(
			array(
				'status'   => (string) ( $assoc['status'] ?? '' ),
				'caller'   => (string) ( $assoc['plugin'] ?? '' ),
				'provider' => (string) ( $assoc['provider'] ?? '' ),
				'since'    => $since ? gmdate( 'Y-m-d H:i:s', $since ) : '',
				'per_page' => (int) ( $assoc['limit'] ?? 20 ),
			)
		)['rows'];

		$items = array();
		foreach ( $rows as $row ) {
			$items[] = array(
				'id'         => $row['id'],
				'started_at' => $row['started_at'],
				'status'     => $row['status'],
				'rule'       => $row['block_rule'],
				'caller'     => $row['caller_slug'],
				'context'    => $row['context'],
				'provider'   => $row['provider'],
				'model'      => $row['model'],
				'tokens'     => $row['total_tokens'],
				'cost'       => Prices::format( (int) $row['cost_micros'], '' !== (string) $row['currency'] ? (string) $row['currency'] : $this->prices->currency() ),
				'http'       => $row['http_status'],
				'path'       => $row['path'],
			);
		}
		\WP_CLI\Utils\format_items( (string) ( $assoc['format'] ?? 'table' ), $items, array( 'id', 'started_at', 'status', 'rule', 'caller', 'context', 'provider', 'model', 'tokens', 'cost', 'http', 'path' ) );
	}

	/**
	 * Shows one call with its data profile.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Call id.
	 *
	 * @param array $args  Positional arguments.
	 * @param array $assoc Options.
	 */
	public function call( $args, $assoc ): void {
		unset( $assoc );
		$row = $this->calls->get( (int) ( $args[0] ?? 0 ) );
		if ( ! $row ) {
			WP_CLI::error( __( 'No call with that ID.', 'axle-ai-guard' ) );
			return;
		}
		$data           = Reports::present( $row, true );
		$data['prompt'] = $this->calls->prompt( (int) $row['id'] );
		WP_CLI::line( (string) wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
	}

	/**
	 * Switches AI calls off: for the site, a plugin, a provider or a context.
	 *
	 * ## OPTIONS
	 *
	 * [--plugin=<caller>]
	 * : Caller key.
	 *
	 * [--provider=<provider>]
	 * : Provider id.
	 *
	 * [--context=<context>]
	 * : admin, front, cron, rest, ajax or cli.
	 *
	 * [--reason=<reason>]
	 * : Why.
	 *
	 * ## EXAMPLES
	 *
	 *     wp axle-ai-guard kill --plugin=chatbot --reason="Loop on the contact page"
	 *     wp axle-ai-guard kill
	 *
	 * @param array $args  Positional arguments.
	 * @param array $assoc Options.
	 */
	public function kill( $args, $assoc ): void {
		list( $scope, $subject ) = self::scope( $assoc );
		$changed                 = $this->kill->engage( $scope, $subject, (string) ( $assoc['reason'] ?? '' ), get_current_user_id() );
		$what                    = KillSwitch::describe( $scope, $subject );
		/* translators: %s: what was switched off. */
		WP_CLI::success( $changed ? sprintf( __( 'AI calls switched off for %s.', 'axle-ai-guard' ), $what ) : sprintf( __( 'AI calls were already off for %s.', 'axle-ai-guard' ), $what ) );
	}

	/**
	 * Switches AI calls back on.
	 *
	 * ## OPTIONS
	 *
	 * [--plugin=<caller>]
	 * : Caller key.
	 *
	 * [--provider=<provider>]
	 * : Provider id.
	 *
	 * [--context=<context>]
	 * : Context.
	 *
	 * [--all]
	 * : Every switch on this site.
	 *
	 * @param array $args  Positional arguments.
	 * @param array $assoc Options.
	 */
	public function resume( $args, $assoc ): void {
		if ( ! empty( $assoc['all'] ) ) {
			/* translators: %d: number of switches. */
			WP_CLI::success( sprintf( __( 'Turned %d switches off.', 'axle-ai-guard' ), $this->kill->release_all( get_current_user_id() ) ) );
			return;
		}
		list( $scope, $subject ) = self::scope( $assoc );
		$changed                 = $this->kill->release( $scope, $subject, get_current_user_id() );
		$what                    = KillSwitch::describe( $scope, $subject );
		/* translators: %s: what was switched on. */
		WP_CLI::success( $changed ? sprintf( __( 'AI calls switched back on for %s.', 'axle-ai-guard' ), $what ) : sprintf( __( 'No switch was on for %s.', 'axle-ai-guard' ), $what ) );
		if ( 'global' === $scope && $this->kill->global_on() ) {
			WP_CLI::warning( __( 'AI is still off: the AXLE_AI_GUARD_KILL constant or the network switch is on.', 'axle-ai-guard' ) );
		}
	}

	/**
	 * Lists the switches that are on.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table, csv, json or yaml.
	 * ---
	 * default: table
	 * ---
	 *
	 * @subcommand switches
	 *
	 * @param array $args  Positional arguments.
	 * @param array $assoc Options.
	 */
	public function switches( $args, $assoc ): void {
		\WP_CLI\Utils\format_items( (string) ( $assoc['format'] ?? 'table' ), $this->kill->active(), array( 'scope', 'subject', 'reason', 'by', 'at', 'source' ) );
	}

	/**
	 * Lists or sets budgets.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : list or set.
	 *
	 * [<subject>]
	 * : For set: global or plugin:<caller>.
	 *
	 * [--daily-tokens=<n>]
	 * : Tokens per day, 0 for none.
	 *
	 * [--monthly-tokens=<n>]
	 * : Tokens per month.
	 *
	 * [--daily-cost=<amount>]
	 * : Money per day, in the site currency, such as 2.50.
	 *
	 * [--monthly-cost=<amount>]
	 * : Money per month.
	 *
	 * [--format=<format>]
	 * : For list.
	 * ---
	 * default: table
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp axle-ai-guard budget set global --monthly-cost=50
	 *     wp axle-ai-guard budget set plugin:chatbot --daily-tokens=200000
	 *
	 * @param array $args  Positional arguments.
	 * @param array $assoc Options.
	 */
	public function budget( $args, $assoc ): void {
		if ( 'set' === ( $args[0] ?? '' ) ) {
			$subject = Settings::subject( (string) ( $args[1] ?? '' ) );
			if ( '' === $subject ) {
				WP_CLI::error( __( 'Give a subject: global or plugin:<caller>.', 'axle-ai-guard' ) );
				return;
			}
			$values = array();
			foreach ( array( 'daily-tokens', 'monthly-tokens' ) as $key ) {
				if ( isset( $assoc[ $key ] ) ) {
					$values[ str_replace( '-', '_', $key ) ] = max( 0, (int) $assoc[ $key ] );
				}
			}
			foreach ( array( 'daily-cost', 'monthly-cost' ) as $key ) {
				if ( isset( $assoc[ $key ] ) ) {
					$values[ str_replace( '-', '_', $key ) ] = Prices::to_micros( $assoc[ $key ] );
				}
			}
			$this->settings->save_policy( $subject, $values );
			/* translators: %s: subject. */
			WP_CLI::success( sprintf( __( 'Budget of %s saved.', 'axle-ai-guard' ), $subject ) );
			return;
		}

		$currency = $this->prices->currency();
		$rows     = array();
		foreach ( $this->reports->limits()['budgets'] as $entry ) {
			$rows[] = array(
				'subject'        => $entry['subject'],
				'daily_tokens'   => $entry['used']['daily_tokens'] . ' / ' . ( $entry['budget']['daily_tokens'] ? $entry['budget']['daily_tokens'] : '-' ),
				'monthly_tokens' => $entry['used']['monthly_tokens'] . ' / ' . ( $entry['budget']['monthly_tokens'] ? $entry['budget']['monthly_tokens'] : '-' ),
				'daily_cost'     => Prices::format( $entry['used']['daily_cost'], $currency ) . ' / ' . ( $entry['budget']['daily_cost'] ? Prices::format( $entry['budget']['daily_cost'], $currency ) : '-' ),
				'monthly_cost'   => Prices::format( $entry['used']['monthly_cost'], $currency ) . ' / ' . ( $entry['budget']['monthly_cost'] ? Prices::format( $entry['budget']['monthly_cost'], $currency ) : '-' ),
				'per_minute'     => $entry['per_minute'],
			);
		}
		\WP_CLI\Utils\format_items( (string) ( $assoc['format'] ?? 'table' ), $rows, array( 'subject', 'daily_tokens', 'monthly_tokens', 'daily_cost', 'monthly_cost', 'per_minute' ) );
	}

	/**
	 * Sets a rate limit.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : set.
	 *
	 * <subject>
	 * : global, plugin:<caller>, or the defaults: plugins, users, ips.
	 *
	 * --per-minute=<n>
	 * : Calls per minute. 0 turns it off; -1 puts a plugin back on the default.
	 *
	 * ## EXAMPLES
	 *
	 *     wp axle-ai-guard limit set plugin:chatbot --per-minute=10
	 *     wp axle-ai-guard limit set ips --per-minute=3
	 *
	 * @param array $args  Positional arguments.
	 * @param array $assoc Options.
	 */
	public function limit( $args, $assoc ): void {
		$subject = strtolower( (string) ( $args[1] ?? '' ) );
		$value   = (int) ( $assoc['per-minute'] ?? 0 );
		$map     = array(
			'plugins' => 'rate_plugin',
			'users'   => 'rate_user',
			'ips'     => 'rate_ip',
		);

		if ( isset( $map[ $subject ] ) ) {
			$this->settings->save( array( $map[ $subject ] => max( 0, $value ) ) );
		} elseif ( 'global' === $subject ) {
			$this->settings->save( array( 'rate_global' => max( 0, $value ) ) );
		} elseif ( '' !== Settings::subject( $subject ) ) {
			$this->settings->save_policy( $subject, array( 'per_minute' => max( -1, $value ) ) );
		} else {
			WP_CLI::error( __( 'Give a subject: global, plugin:<caller>, plugins, users or ips.', 'axle-ai-guard' ) );
			return;
		}
		/* translators: %s: subject. */
		WP_CLI::success( sprintf( __( 'Rate limit of %s saved.', 'axle-ai-guard' ), $subject ) );
	}

	/**
	 * Lists, sets or tests the data rules.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : list, set or test.
	 *
	 * [<value>...]
	 * : For set: detector and mode (off, log, redact, block). For test: the text, or - to read standard input.
	 *
	 * [--format=<format>]
	 * : For list.
	 * ---
	 * default: table
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp axle-ai-guard rules set email redact
	 *     wp axle-ai-guard rules test "Mail admin@example.com"
	 *     cat prompt.txt | wp axle-ai-guard rules test -
	 *
	 * @param array $args  Positional arguments.
	 * @param array $assoc Options.
	 */
	public function rules( $args, $assoc ): void {
		$action = (string) ( $args[0] ?? 'list' );

		if ( 'set' === $action ) {
			$id   = sanitize_key( (string) ( $args[1] ?? '' ) );
			$mode = (string) ( $args[2] ?? '' );
			if ( ! isset( $this->detectors->all()[ $id ] ) || ! in_array( $mode, Detectors::MODES, true ) ) {
				WP_CLI::error( __( 'Give a detector id from "rules list" and a mode: off, log, redact or block.', 'axle-ai-guard' ) );
				return;
			}
			$this->detectors->save( array( $id => $mode ) );
			/* translators: 1: detector, 2: mode. */
			WP_CLI::success( sprintf( __( '%1$s is now %2$s.', 'axle-ai-guard' ), $id, $mode ) );
			return;
		}

		if ( 'test' === $action ) {
			$text = implode( ' ', array_slice( $args, 1 ) );
			if ( '-' === $text ) {
				$text = (string) file_get_contents( 'php://stdin' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsRemoteFile -- Standard input.
			}
			WP_CLI::line( (string) wp_json_encode( $this->reports->test_rules( $text ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			return;
		}

		$rows = array();
		foreach ( $this->detectors->all() as $id => $detector ) {
			$rows[] = array(
				'id'    => $id,
				'label' => $detector['label'],
				'mode'  => $this->detectors->mode( $id ),
			);
		}
		\WP_CLI\Utils\format_items( (string) ( $assoc['format'] ?? 'table' ), $rows, array( 'id', 'label', 'mode' ) );
	}

	/**
	 * Lists or sets prices, resets them or recalculates costs.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : list, set, reset or recalc.
	 *
	 * [<model>]
	 * : For set: provider/model, such as openai/gpt-5-mini (a * at the end matches more).
	 *
	 * [--input=<price>]
	 * : Input price per million tokens.
	 *
	 * [--output=<price>]
	 * : Output price per million tokens.
	 *
	 * [--thought=<price>]
	 * : Thought price per million tokens. Default: the output price.
	 *
	 * [--days=<days>]
	 * : For recalc.
	 * ---
	 * default: 30
	 * ---
	 *
	 * [--format=<format>]
	 * : For list.
	 * ---
	 * default: table
	 * ---
	 *
	 * @param array $args  Positional arguments.
	 * @param array $assoc Options.
	 */
	public function prices( $args, $assoc ): void {
		$action = (string) ( $args[0] ?? 'list' );

		if ( 'set' === $action ) {
			if ( ! isset( $assoc['input'], $assoc['output'] ) || ! is_numeric( $assoc['input'] ) || ! is_numeric( $assoc['output'] ) ) {
				WP_CLI::error( __( 'Give --input and --output prices per million tokens.', 'axle-ai-guard' ) );
				return;
			}
			$thought = isset( $assoc['thought'] ) && is_numeric( $assoc['thought'] ) ? (float) $assoc['thought'] : null;
			if ( ! $this->prices->set( (string) ( $args[1] ?? '' ), (float) $assoc['input'], (float) $assoc['output'], $thought ) ) {
				WP_CLI::error( __( 'Give the model as provider/model.', 'axle-ai-guard' ) );
				return;
			}
			WP_CLI::success( __( 'Price saved.', 'axle-ai-guard' ) );
			return;
		}
		if ( 'reset' === $action ) {
			$this->prices->reset();
			WP_CLI::success( __( 'Prices are back to the shipped table.', 'axle-ai-guard' ) );
			return;
		}
		if ( 'recalc' === $action ) {
			$result = $this->cleanup->recalculate( (int) ( $assoc['days'] ?? 30 ) );
			/* translators: 1: changed rows, 2: rows checked. */
			WP_CLI::success( sprintf( __( 'Recalculated: %1$d of %2$d calls changed.', 'axle-ai-guard' ), $result['changed'], $result['rows'] ) );
			return;
		}

		$rows = array();
		foreach ( $this->prices->table() as $key => $row ) {
			$rows[] = array( 'model' => $key ) + $row;
		}
		if ( 'table' === ( $assoc['format'] ?? 'table' ) ) {
			/* translators: 1: currency, 2: date. */
			WP_CLI::log( sprintf( __( 'Per million tokens, in %1$s. Prices as of %2$s.', 'axle-ai-guard' ), $this->prices->currency(), $this->prices->as_of() ) );
		}
		\WP_CLI\Utils\format_items( (string) ( $assoc['format'] ?? 'table' ), $rows, array( 'model', 'input', 'output', 'thought' ) );
	}

	/**
	 * Lists callers with their approval status.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table, csv, json or yaml.
	 * ---
	 * default: table
	 * ---
	 *
	 * @param array $args  Positional arguments.
	 * @param array $assoc Options.
	 */
	public function callers( $args, $assoc ): void {
		$rows = array();
		foreach ( $this->settings->callers() as $key => $caller ) {
			$rows[] = array(
				'caller' => $key,
				'type'   => (string) ( $caller['type'] ?? '' ),
				'status' => (string) ( $caller['status'] ?? '' ),
				'file'   => (string) ( $caller['file'] ?? '' ),
			);
		}
		\WP_CLI\Utils\format_items( (string) ( $assoc['format'] ?? 'table' ), $rows, array( 'caller', 'type', 'status', 'file' ) );
	}

	/**
	 * Approves a caller, so its AI calls are allowed.
	 *
	 * ## OPTIONS
	 *
	 * <caller>
	 * : Caller key.
	 *
	 * @param array $args  Positional arguments.
	 * @param array $assoc Options.
	 */
	public function approve( $args, $assoc ): void {
		unset( $assoc );
		$this->set_caller( (string) ( $args[0] ?? '' ), 'approved' );
	}

	/**
	 * Denies a caller, so its AI calls are blocked.
	 *
	 * ## OPTIONS
	 *
	 * <caller>
	 * : Caller key.
	 *
	 * @param array $args  Positional arguments.
	 * @param array $assoc Options.
	 */
	public function deny( $args, $assoc ): void {
		unset( $assoc );
		$this->set_caller( (string) ( $args[0] ?? '' ), 'denied' );
	}

	/**
	 * Shows or changes settings.
	 *
	 * ## OPTIONS
	 *
	 * [<action>]
	 * : list or set.
	 * ---
	 * default: list
	 * ---
	 *
	 * [<key>]
	 * : Setting name, for set.
	 *
	 * [<value>]
	 * : New value, for set.
	 *
	 * [--format=<format>]
	 * : For list.
	 * ---
	 * default: table
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp axle-ai-guard settings set direct_monitoring 1
	 *     wp axle-ai-guard settings set new_caller deny
	 *
	 * @param array $args  Positional arguments.
	 * @param array $assoc Options.
	 */
	public function settings( $args, $assoc ): void {
		if ( 'set' === ( $args[0] ?? 'list' ) ) {
			$key = (string) ( $args[1] ?? '' );
			if ( ! array_key_exists( $key, Settings::defaults() ) ) {
				WP_CLI::error( __( 'Unknown setting. Run "wp axle-ai-guard settings" for the list.', 'axle-ai-guard' ) );
				return;
			}
			$saved = $this->settings->save( array( $key => (string) ( $args[2] ?? '' ) ) );
			/* translators: 1: setting, 2: value. */
			WP_CLI::success( sprintf( __( '%1$s is now %2$s.', 'axle-ai-guard' ), $key, is_bool( $saved[ $key ] ) ? ( $saved[ $key ] ? 'on' : 'off' ) : (string) $saved[ $key ] ) );
			return;
		}

		$rows = array();
		foreach ( $this->settings->all() as $key => $value ) {
			$rows[] = array(
				'setting' => $key,
				'value'   => is_bool( $value ) ? ( $value ? 'on' : 'off' ) : (string) $value,
			);
		}
		\WP_CLI\Utils\format_items( (string) ( $assoc['format'] ?? 'table' ), $rows, array( 'setting', 'value' ) );
	}

	/**
	 * Deletes old calls, usage rows and stored prompts.
	 *
	 * ## OPTIONS
	 *
	 * [--older-than=<days>]
	 * : Delete calls older than this many days. Default: the retention setting.
	 *
	 * @param array $args  Positional arguments.
	 * @param array $assoc Options.
	 */
	public function prune( $args, $assoc ): void {
		$counts = $this->cleanup->prune( isset( $assoc['older-than'] ) ? (int) $assoc['older-than'] : null );
		/* translators: 1: calls, 2: usage rows, 3: prompts. */
		WP_CLI::success( sprintf( __( 'Deleted %1$d calls, %2$d usage rows and %3$d stored prompts.', 'axle-ai-guard' ), $counts['calls'], $counts['usage'], $counts['prompts'] ) );
	}

	/**
	 * Changes a caller's status.
	 *
	 * @param string $caller Caller key.
	 * @param string $status approved or denied.
	 */
	private function set_caller( string $caller, string $status ): void {
		if ( ! $this->settings->set_caller_status( $caller, $status ) ) {
			WP_CLI::error( __( 'No caller with that key. Run "wp axle-ai-guard callers".', 'axle-ai-guard' ) );
			return;
		}
		/* translators: 1: caller, 2: status. */
		WP_CLI::success( sprintf( __( '%1$s is now %2$s.', 'axle-ai-guard' ), $caller, $status ) );
	}

	/**
	 * The scope and subject from --plugin, --provider or --context.
	 *
	 * @param array $assoc Options.
	 * @return array{0: string, 1: string}
	 */
	private static function scope( array $assoc ): array {
		foreach ( array( 'plugin', 'provider', 'context' ) as $scope ) {
			if ( ! empty( $assoc[ $scope ] ) ) {
				return array( $scope, strtolower( (string) $assoc[ $scope ] ) );
			}
		}

		return array( 'global', '' );
	}
}
