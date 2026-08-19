<?php
/**
 * Stands in for a redirect the plugin would otherwise exit on.
 *
 * @package PublicCollaboration
 */

declare(strict_types = 1);

namespace PublicCollaboration\Tests;

use Exception;

/**
 * Thrown in place of a redirect, carrying its target as the message.
 */
class Redirected extends Exception {}
