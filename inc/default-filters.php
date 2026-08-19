<?php
/**
 * Adding actions and filters.
 *
 * @package PublicCollaboration
 */

declare(strict_types = 1);

namespace PublicCollaboration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'init', __NAMESPACE__ . '\register_post_type' );
add_action( 'init', __NAMESPACE__ . '\register_assets' );
add_action( 'rest_api_init', __NAMESPACE__ . '\register_rest_routes' );
add_action( 'rest_api_init', __NAMESPACE__ . '\register_rest_guards' );

add_action( 'enqueue_block_editor_assets', __NAMESPACE__ . '\enqueue_block_editor_assets' );

add_filter( 'template_include', __NAMESPACE__ . '\filter_template_include' );

add_filter( 'user_has_cap', __NAMESPACE__ . '\filter_user_has_cap', 10, 4 );
add_action( 'admin_init', __NAMESPACE__ . '\restrict_admin_access' );

add_filter( 'show_post_locked_dialog', __NAMESPACE__ . '\filter_show_post_locked_dialog' );
add_filter( 'update_post_metadata', __NAMESPACE__ . '\filter_post_lock_meta', 10, 3 );
add_action( 'admin_init', __NAMESPACE__ . '\unhook_post_lock_heartbeat' );

add_filter( 'cron_schedules', __NAMESPACE__ . '\filter_cron_schedules' ); // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected
add_action( 'public_collaboration_cleanup', __NAMESPACE__ . '\delete_expired_requests' );
