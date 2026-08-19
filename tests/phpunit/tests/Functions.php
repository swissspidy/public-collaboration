<?php
/**
 * Tests for the plugin functions.
 *
 * @package PublicCollaboration
 */

declare(strict_types = 1);

namespace PublicCollaboration\Tests;

use PublicCollaboration\Collaboration_Request;
use WP_REST_Request;
use WP_UnitTestCase;

use function PublicCollaboration\delete_expired_requests;
use function PublicCollaboration\enqueue_block_editor_assets;
use function PublicCollaboration\filter_cron_schedules;
use function PublicCollaboration\filter_rest_pre_insert;
use function PublicCollaboration\get_collaborator_data;
use function PublicCollaboration\get_editor_url;
use function PublicCollaboration\filter_post_lock_meta;
use function PublicCollaboration\filter_show_post_locked_dialog;
use function PublicCollaboration\restrict_admin_access;
use function PublicCollaboration\unhook_post_lock_heartbeat;

/**
 * Tests for the plugin's functions.
 */
class Test_Functions extends WP_UnitTestCase {
	use Intercepts_Redirects;

	/**
	 * Administrator user ID.
	 *
	 * @var int
	 */
	protected static int $admin_id;

	/**
	 * ID of the post being collaborated on.
	 *
	 * @var int
	 */
	protected static int $post_id;

	/**
	 * ID of a post nobody was invited to.
	 *
	 * @var int
	 */
	protected static int $other_post_id;

	/**
	 * Sets up shared fixtures.
	 *
	 * @param \WP_UnitTest_Factory $factory Factory.
	 * @return void
	 */
	public static function wpSetUpBeforeClass( $factory ): void {
		self::$admin_id = $factory->user->create( [ 'role' => 'administrator' ] );

		self::$post_id = $factory->post->create(
			[
				'post_status' => 'publish',
				'post_title'  => 'A post to work on together',
				'post_author' => self::$admin_id,
			]
		);

		self::$other_post_id = $factory->post->create(
			[
				'post_status' => 'publish',
				'post_author' => self::$admin_id,
			]
		);
	}

	/**
	 * Sets up each test.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		wp_set_current_user( self::$admin_id );
	}

	/**
	 * Creates a collaboration request, and the account that would follow it.
	 *
	 * @param string[] $capabilities Capabilities to grant.
	 * @param int|null $post_id      Optional. Post to collaborate on.
	 * @return array{0: Collaboration_Request, 1: int} The request and the collaborator's user ID.
	 */
	private function create_collaborator( array $capabilities = [ 'edit', 'upload' ], ?int $post_id = null ): array {
		$request = Collaboration_Request::create(
			[
				'post'         => $post_id ?? self::$post_id,
				'capabilities' => $capabilities,
			]
		);

		$this->assertInstanceOf( Collaboration_Request::class, $request );

		$user_id = $request->get_or_create_user();

		$this->assertNotWPError( $user_id );

		return [ $request, $user_id ];
	}

	/**
	 * @covers \PublicCollaboration\register_post_type
	 */
	public function test_post_type_is_registered_but_hidden(): void {
		$post_type = get_post_type_object( Collaboration_Request::POST_TYPE );

		$this->assertNotNull( $post_type );
		$this->assertFalse( $post_type->public );
		$this->assertFalse( $post_type->show_ui );
		$this->assertFalse( $post_type->show_in_rest );
		// The link has to be reachable by URL, or it is not a link.
		$this->assertTrue( $post_type->publicly_queryable );
		$this->assertTrue( $post_type->exclude_from_search );
		// So that a site on plain permalinks addresses requests by token, not by ID.
		$this->assertSame( Collaboration_Request::POST_TYPE, $post_type->query_var );
	}

	/**
	 * WordPress refuses to register anything longer, without failing loudly at runtime.
	 *
	 * @covers \PublicCollaboration\register_post_type
	 */
	public function test_post_type_name_fits(): void {
		$this->assertLessThanOrEqual( 20, \strlen( Collaboration_Request::POST_TYPE ) );
	}

	/**
	 * @covers \PublicCollaboration\filter_cron_schedules
	 */
	public function test_filter_cron_schedules(): void {
		$schedules = filter_cron_schedules( [] );

		$this->assertArrayHasKey( 'public_collaboration_quarter_hourly', $schedules );
		$this->assertSame( 15 * MINUTE_IN_SECONDS, $schedules['public_collaboration_quarter_hourly']['interval'] );
	}

	/**
	 * @covers \PublicCollaboration\delete_expired_requests
	 */
	public function test_delete_expired_requests(): void {
		[ $expired, $expired_user ] = $this->create_collaborator();
		[ $live, $live_user ]       = $this->create_collaborator();

		update_post_meta( $expired->get_post()->ID, Collaboration_Request::META_EXPIRES_AT, time() - 1 );

		delete_expired_requests();

		$this->assertNull( get_post( $expired->get_post()->ID ) );
		$this->assertFalse( get_userdata( $expired_user ) );

		$this->assertNotNull( get_post( $live->get_post()->ID ) );
		$this->assertNotFalse( get_userdata( $live_user ) );
	}

	/**
	 * @covers \PublicCollaboration\get_editor_url
	 */
	public function test_get_editor_url(): void {
		$url = get_editor_url( self::$post_id );

		$this->assertStringContainsString( 'post.php', $url );
		$this->assertStringContainsString( 'post=' . self::$post_id, $url );
		$this->assertStringContainsString( 'action=edit', $url );
	}

	/**
	 * @covers \PublicCollaboration\get_collaborator_data
	 */
	public function test_get_collaborator_data(): void {
		[ $request, $user_id ] = $this->create_collaborator( [ 'edit' ] );

		wp_set_current_user( $user_id );

		$data = get_collaborator_data( $request );

		$this->assertSame( self::$post_id, $data['postId'] );
		$this->assertSame( 'A post to work on together', $data['postTitle'] );
		$this->assertSame( get_userdata( self::$admin_id )->display_name, $data['sharedBy'] );
		$this->assertSame( get_userdata( $user_id )->display_name, $data['displayName'] );
		$this->assertTrue( $data['canEdit'] );
		$this->assertFalse( $data['canUpload'] );
		$this->assertSame( $request->get_expires_at(), $data['expiresAt'] );
	}

	/**
	 * @covers \PublicCollaboration\filter_user_has_cap
	 */
	public function test_collaborator_can_edit_the_post_they_were_invited_to(): void {
		[ , $user_id ] = $this->create_collaborator( [ 'edit' ] );

		$this->assertTrue( user_can( $user_id, 'edit_post', self::$post_id ) );
		$this->assertTrue( user_can( $user_id, 'read_post', self::$post_id ) );
	}

	/**
	 * @covers \PublicCollaboration\filter_user_has_cap
	 */
	public function test_collaborator_cannot_edit_any_other_post(): void {
		[ , $user_id ] = $this->create_collaborator( [ 'edit' ] );

		$this->assertFalse( user_can( $user_id, 'edit_post', self::$other_post_id ) );
		$this->assertFalse( user_can( $user_id, 'delete_post', self::$post_id ) );
		$this->assertFalse( user_can( $user_id, 'publish_posts' ) );
		$this->assertFalse( user_can( $user_id, 'edit_others_posts' ) );
		$this->assertFalse( user_can( $user_id, 'manage_options' ) );
		$this->assertFalse( user_can( $user_id, 'list_users' ) );
	}

	/**
	 * The editor will not load without it, so it is granted on purpose — but on
	 * its own it must not be enough to touch anybody else's post.
	 *
	 * @covers \PublicCollaboration\filter_user_has_cap
	 */
	public function test_collaborator_gets_the_bare_capabilities_the_editor_needs(): void {
		[ , $user_id ] = $this->create_collaborator( [ 'edit' ] );

		$this->assertTrue( user_can( $user_id, 'read' ) );
		$this->assertTrue( user_can( $user_id, 'edit_posts' ) );
	}

	/**
	 * @covers \PublicCollaboration\filter_user_has_cap
	 */
	public function test_collaborator_can_upload_only_when_the_link_says_so(): void {
		[ , $with ]    = $this->create_collaborator( [ 'edit', 'upload' ] );
		[ , $without ] = $this->create_collaborator( [ 'edit' ] );

		$this->assertTrue( user_can( $with, 'upload_files' ) );
		$this->assertFalse( user_can( $without, 'upload_files' ) );
	}

	/**
	 * @covers \PublicCollaboration\filter_user_has_cap
	 */
	public function test_collaborator_can_edit_only_when_the_link_says_so(): void {
		[ , $user_id ] = $this->create_collaborator( [ 'upload' ] );

		$this->assertFalse( user_can( $user_id, 'edit_post', self::$post_id ) );
		$this->assertTrue( user_can( $user_id, 'upload_files' ) );
	}

	/**
	 * Unticking a box takes effect on the page the collaborator is already looking at.
	 *
	 * @covers \PublicCollaboration\filter_user_has_cap
	 */
	public function test_capabilities_follow_the_request_as_it_changes(): void {
		[ $request, $user_id ] = $this->create_collaborator( [ 'edit', 'upload' ] );

		$this->assertTrue( user_can( $user_id, 'edit_post', self::$post_id ) );

		$request->set_capabilities( [ 'upload' ] );

		$this->assertFalse( user_can( $user_id, 'edit_post', self::$post_id ) );
	}

	/**
	 * @covers \PublicCollaboration\filter_user_has_cap
	 */
	public function test_an_expired_link_grants_nothing(): void {
		[ $request, $user_id ] = $this->create_collaborator();

		update_post_meta( $request->get_post()->ID, Collaboration_Request::META_EXPIRES_AT, time() - 1 );

		$this->assertFalse( user_can( $user_id, 'edit_post', self::$post_id ) );
		$this->assertFalse( user_can( $user_id, 'upload_files' ) );
		$this->assertFalse( user_can( $user_id, 'edit_posts' ) );
		$this->assertFalse( user_can( $user_id, 'read' ) );
	}

	/**
	 * @covers \PublicCollaboration\filter_user_has_cap
	 */
	public function test_everybody_else_is_left_alone(): void {
		$this->create_collaborator();

		$subscriber_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );

		$this->assertFalse( user_can( $subscriber_id, 'edit_post', self::$post_id ) );
		$this->assertFalse( user_can( $subscriber_id, 'edit_posts' ) );
		$this->assertTrue( user_can( self::$admin_id, 'edit_post', self::$post_id ) );
	}

	/**
	 * A collaborator on a draft must not be able to reach a published post either.
	 *
	 * @covers \PublicCollaboration\filter_user_has_cap
	 */
	public function test_collaborator_on_a_draft(): void {
		$draft_id = self::factory()->post->create(
			[
				'post_status' => 'draft',
				'post_author' => self::$admin_id,
			]
		);

		[ , $user_id ] = $this->create_collaborator( [ 'edit' ], $draft_id );

		$this->assertTrue( user_can( $user_id, 'edit_post', $draft_id ) );
		$this->assertFalse( user_can( $user_id, 'edit_post', self::$post_id ) );
	}

	/**
	 * @covers \PublicCollaboration\filter_rest_pre_insert
	 */
	public function test_collaborator_cannot_create_posts_over_rest(): void {
		[ , $user_id ] = $this->create_collaborator();

		wp_set_current_user( $user_id );

		$prepared = filter_rest_pre_insert(
			(object) [ 'post_title' => 'Sneaky' ],
			new WP_REST_Request( 'POST', '/wp/v2/posts' )
		);

		$this->assertWPError( $prepared );
		$this->assertSame( 'public_collaboration_cannot_create', $prepared->get_error_code() );
	}

	/**
	 * @covers \PublicCollaboration\filter_rest_pre_insert
	 */
	public function test_collaborator_can_still_update_the_post(): void {
		[ , $user_id ] = $this->create_collaborator();

		wp_set_current_user( $user_id );

		$prepared = (object) [
			'ID'         => self::$post_id,
			'post_title' => 'Edited together',
		];

		$this->assertSame(
			$prepared,
			filter_rest_pre_insert( $prepared, new WP_REST_Request( 'PUT', '/wp/v2/posts/' . self::$post_id ) )
		);
	}

	/**
	 * @covers \PublicCollaboration\filter_rest_pre_insert
	 */
	public function test_everybody_else_can_still_create_posts(): void {
		$prepared = (object) [ 'post_title' => 'Business as usual' ];

		$this->assertSame(
			$prepared,
			filter_rest_pre_insert( $prepared, new WP_REST_Request( 'POST', '/wp/v2/posts' ) )
		);
	}

	/**
	 * Visits an admin screen as a given user, reporting where they end up.
	 *
	 * @param int      $user_id User to visit as.
	 * @param string   $screen  Admin screen being requested.
	 * @param int|null $post_id Optional. Post ID in the query string.
	 * @return string|null Where they were sent, or null if they were left alone.
	 */
	private function go_to_admin( int $user_id, string $screen, ?int $post_id = null ): ?string {
		global $pagenow;

		$previous = $pagenow;
		$pagenow  = $screen;

		if ( null !== $post_id ) {
			$_GET['post'] = (string) $post_id;
		} else {
			unset( $_GET['post'] );
		}

		wp_set_current_user( $user_id );

		try {
			return $this->catch_redirect( 'PublicCollaboration\\restrict_admin_access' );
		} finally {
			$pagenow = $previous;
			unset( $_GET['post'] );
		}
	}

	/**
	 * @covers \PublicCollaboration\restrict_admin_access
	 */
	public function test_collaborator_stays_on_their_post(): void {
		[ , $user_id ] = $this->create_collaborator();

		$this->assertNull( $this->go_to_admin( $user_id, 'post.php', self::$post_id ) );
	}

	/**
	 * @covers \PublicCollaboration\restrict_admin_access
	 */
	public function test_collaborator_is_sent_back_from_anywhere_else(): void {
		[ , $user_id ] = $this->create_collaborator();

		$this->assertStringContainsString(
			'post=' . self::$post_id,
			(string) $this->go_to_admin( $user_id, 'post.php', self::$other_post_id )
		);

		$this->assertStringContainsString(
			'post=' . self::$post_id,
			(string) $this->go_to_admin( $user_id, 'index.php' )
		);

		$this->assertStringContainsString(
			'post=' . self::$post_id,
			(string) $this->go_to_admin( $user_id, 'post-new.php' )
		);

		$this->assertStringContainsString(
			'post=' . self::$post_id,
			(string) $this->go_to_admin( $user_id, 'users.php' )
		);
	}

	/**
	 * @covers \PublicCollaboration\restrict_admin_access
	 */
	public function test_everybody_else_goes_where_they_like(): void {
		$this->assertNull( $this->go_to_admin( self::$admin_id, 'users.php' ) );
		$this->assertNull( $this->go_to_admin( 0, 'index.php' ) );
	}

	/**
	 * @covers \PublicCollaboration\filter_show_post_locked_dialog
	 */
	public function test_a_collaborator_is_not_told_the_post_is_locked(): void {
		[ , $user_id ] = $this->create_collaborator();

		wp_set_current_user( $user_id );
		$this->assertFalse( filter_show_post_locked_dialog( true ) );

		wp_set_current_user( self::$admin_id );
		$this->assertTrue( filter_show_post_locked_dialog( true ) );
	}

	/**
	 * @covers \PublicCollaboration\filter_post_lock_meta
	 */
	public function test_a_collaborator_cannot_take_the_post_lock(): void {
		[ , $user_id ] = $this->create_collaborator();

		require_once ABSPATH . 'wp-admin/includes/post.php';

		wp_set_current_user( self::$admin_id );
		wp_set_post_lock( self::$post_id );

		$held_by_the_sharer = get_post_meta( self::$post_id, '_edit_lock', true );

		$this->assertNotEmpty( $held_by_the_sharer );

		wp_set_current_user( $user_id );
		wp_set_post_lock( self::$post_id );

		$this->assertSame(
			$held_by_the_sharer,
			get_post_meta( self::$post_id, '_edit_lock', true ),
			'Whoever shared the post keeps the lock.'
		);
	}

	/**
	 * @covers \PublicCollaboration\filter_post_lock_meta
	 */
	public function test_other_post_meta_is_untouched(): void {
		[ , $user_id ] = $this->create_collaborator();

		wp_set_current_user( $user_id );
		update_post_meta( self::$post_id, 'something_else', 'written' );

		$this->assertSame( 'written', get_post_meta( self::$post_id, 'something_else', true ) );
	}

	/**
	 * @covers \PublicCollaboration\unhook_post_lock_heartbeat
	 */
	public function test_the_heartbeat_does_not_tell_a_collaborator_they_were_taken_over(): void {
		[ , $user_id ] = $this->create_collaborator();

		require_once ABSPATH . 'wp-admin/includes/misc.php';
		add_filter( 'heartbeat_received', 'wp_refresh_post_lock', 10, 3 );

		wp_set_current_user( self::$admin_id );
		unhook_post_lock_heartbeat();
		$this->assertTrue( has_filter( 'heartbeat_received', 'wp_refresh_post_lock' ) !== false );

		wp_set_current_user( $user_id );
		unhook_post_lock_heartbeat();
		$this->assertFalse( has_filter( 'heartbeat_received', 'wp_refresh_post_lock' ) );
	}

	/**
	 * Enqueued scripts leak between tests, and this suite asserts on them.
	 *
	 * @return void
	 */
	private function reset_scripts(): void {
		$GLOBALS['wp_scripts'] = new \WP_Scripts(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		\PublicCollaboration\register_assets();
	}

	/**
	 * The welcome dialog is the only thing telling a collaborator where they
	 * are, and it renders from data printed alongside its own bundle.
	 *
	 * @covers \PublicCollaboration\enqueue_block_editor_assets
	 */
	public function test_a_collaborator_is_given_the_welcome_bundle(): void {
		[ , $user_id ] = $this->create_collaborator( [ 'edit' ] );

		$this->reset_scripts();
		wp_set_current_user( $user_id );

		enqueue_block_editor_assets();

		$this->assertTrue(
			wp_script_is( 'public-collaboration-welcome', 'enqueued' ),
			'The collaborator gets the welcome bundle.'
		);
		$this->assertFalse(
			wp_script_is( 'public-collaboration-editor', 'enqueued' ),
			'And not the sharing UI, which is of no use to them.'
		);

		$before = wp_scripts()->get_data( 'public-collaboration-welcome', 'before' );

		$this->assertIsArray( $before );

		$printed = implode( "\n", array_filter( $before, 'is_string' ) );

		$this->assertStringContainsString(
			'window.publicCollaboration =',
			$printed,
			'The dialog renders from this and nothing else.'
		);
	}

	/**
	 * @covers \PublicCollaboration\enqueue_block_editor_assets
	 */
	public function test_whoever_shared_the_post_is_given_the_sharing_bundle(): void {
		$this->reset_scripts();
		wp_set_current_user( self::$admin_id );

		enqueue_block_editor_assets();

		$this->assertTrue( wp_script_is( 'public-collaboration-editor', 'enqueued' ) );
		$this->assertFalse( wp_script_is( 'public-collaboration-welcome', 'enqueued' ) );
	}

	/**
	 * @covers \PublicCollaboration\enqueue_block_editor_assets
	 */
	public function test_a_subscriber_is_given_nothing(): void {
		$subscriber = self::factory()->user->create( [ 'role' => 'subscriber' ] );

		$this->reset_scripts();
		wp_set_current_user( $subscriber );

		enqueue_block_editor_assets();

		$this->assertFalse( wp_script_is( 'public-collaboration-editor', 'enqueued' ) );
		$this->assertFalse( wp_script_is( 'public-collaboration-welcome', 'enqueued' ) );
	}
}
