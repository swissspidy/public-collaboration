<?php
/**
 * PHPStan bootstrap file.
 *
 * Defines the constants the plugin sets at runtime, without running the plugin
 * file itself — loading that would call WordPress functions that do not exist
 * during analysis.
 *
 * @package PublicCollaboration
 */

declare(strict_types = 1);

define( 'PUBLIC_COLLABORATION_FILE', dirname( __DIR__, 2 ) . '/public-collaboration.php' );
define( 'PUBLIC_COLLABORATION_DIR', dirname( __DIR__, 2 ) );
