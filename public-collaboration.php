<?php
/**
 * Plugin Name:       Public Collaboration
 * Plugin URI:        https://github.com/swissspidy/public-collaboration
 * Description:       Share a link that lets someone edit one post with you for a quarter of an hour. No account, no login, no invitation email.
 * Version:           0.1.0
 * Author:            Pascal Birchler
 * Author URI:        https://pascalbirchler.com
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 * Text Domain:       public-collaboration
 * Requires at least: 7.1
 * Requires PHP:      8.0
 * Requires Plugins:  gutenberg
 *
 * @package PublicCollaboration
 */

declare(strict_types = 1);

namespace PublicCollaboration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const VERSION = '0.1.0';

define( 'PUBLIC_COLLABORATION_FILE', __FILE__ );
define( 'PUBLIC_COLLABORATION_DIR', __DIR__ );

require_once __DIR__ . '/inc/class-collaboration-request.php';
require_once __DIR__ . '/inc/class-rest-collaboration-requests-controller.php';
require_once __DIR__ . '/inc/functions.php';
require_once __DIR__ . '/inc/default-filters.php';

register_activation_hook( __FILE__, __NAMESPACE__ . '\activate_plugin' );
register_deactivation_hook( __FILE__, __NAMESPACE__ . '\deactivate_plugin' );
