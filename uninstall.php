<?php
/**
 * Fires on uninstall, cleaning up what deactivation deliberately leaves behind.
 *
 * @package PublicCollaboration
 */

// If uninstall.php is not called by WordPress, exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/inc/class-collaboration-request.php';

use PublicCollaboration\Collaboration_Request;

/*
 * Deactivation (see deactivate_plugin() in inc/functions.php) only clears
 * *expired* collaboration requests, so it can run repeatedly without cutting
 * short a session that is still going — somebody may reactivate a moment later
 * with a collaborator still typing. Uninstalling is the one-way trip: nothing
 * is coming back to use a request afterwards, so this removes all of them.
 *
 * Deleting each request through the model, rather than the posts directly,
 * is what takes the temporary accounts with them. Anything a collaborator
 * wrote stays where it is — by then it belongs to the post, not to the link
 * that brought them in.
 *
 * In batches, not all at once: each one deletes an account as well as a post,
 * and a site that has run a lot of sessions could otherwise spend longer than
 * the request is given and stop half way through.
 */
do {
	$pubcol_request_ids = get_posts(
		[
			'post_type'      => Collaboration_Request::POST_TYPE,
			// Not 'any': it excludes trash and auto-draft (both have
			// exclude_from_search set), which would leave exactly the kind of
			// leftover posts this file exists to clean up.
			'post_status'    => get_post_stati(),
			'posts_per_page' => 100,
			'fields'         => 'ids',
		]
	);

	foreach ( $pubcol_request_ids as $pubcol_request_id ) {
		$pubcol_post = get_post( $pubcol_request_id );

		if ( ! $pubcol_post instanceof WP_Post ) {
			continue;
		}

		$pubcol_request = Collaboration_Request::from_post( $pubcol_post );

		if ( $pubcol_request instanceof Collaboration_Request ) {
			$pubcol_request->delete();
		}
	}
} while ( ! empty( $pubcol_request_ids ) );

// Belt and suspenders: deactivation already does this, but nothing
// guarantees deactivation ran before whatever triggered this uninstall.
wp_clear_scheduled_hook( 'public_collaboration_cleanup' );
