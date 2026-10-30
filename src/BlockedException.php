<?php
/**
 * The exception that stops a call once its model is known.
 *
 * @package AryanX\Axle\AiGuard
 */

namespace AryanX\Axle\AiGuard;

defined( 'ABSPATH' ) || exit;

/**
 * Thrown from the wp_ai_client_before_generate_result action. The core prompt
 * builder catches every Exception and hands the caller a WP_Error
 * ( prompt_builder_error, 500 ) with this message, which names the rule.
 */
class BlockedException extends \Exception {

	/**
	 * The rule that blocked the call.
	 *
	 * @var string
	 */
	private string $rule;

	/**
	 * Sets up the exception.
	 *
	 * @param string $rule    The rule that blocked the call, such as budget or data:user_email.
	 * @param string $message The message the caller sees.
	 */
	public function __construct( string $rule, string $message ) {
		parent::__construct( $message );
		$this->rule = $rule;
	}

	/**
	 * The rule that blocked the call.
	 *
	 * @return string
	 */
	public function rule(): string {
		return $this->rule;
	}
}
