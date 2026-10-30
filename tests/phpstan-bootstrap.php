<?php
/**
 * What PHPStan needs to know about: the optional wp-config.php constant, and
 * the AI Client SDK classes, which the WordPress stubs (6.9) do not have yet.
 *
 * @package AryanX\Axle\AiGuard
 */

defined( 'AXLE_AI_GUARD_KILL' ) || define( 'AXLE_AI_GUARD_KILL', false );

require __DIR__ . '/Stubs/ai-client.php';
