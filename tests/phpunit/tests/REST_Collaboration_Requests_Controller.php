<?php
/**
 * Tests for the REST API controller.
 *
 * @package PublicCollaboration
 */

declare(strict_types = 1);

namespace PublicCollaboration\Tests;

use PublicCollaboration\Collaboration_Request;
use WP_REST_Request;
use WP_REST_Server;
use WP_Test_REST_TestCase;

/**
 * @coversDefaultClass \PublicCollaboration\REST_Collaboration_Requests_Controller
 */
class Test_REST_Collaboration_Requests_Controller extends WP_Test_REST_TestCase {
	/**
	 * REST route base.
	 */
	private const ROUTE = '/public-collaboration/v1/collaboration-requests';

	/**
	 * Administrator user ID.
	 *
	 * @var int
	 */
	protected static int $admin_id;

	/**
	 * Author user ID.
	 *
	 * @var int
	 */
	protected static int $author_id;

	/**
	 * Subscriber user ID.
	 *
	 * @var int
	 */
	protected static int $subscriber_id;

	/**
	 * ID of the post being collaborated on.
	 *
	 * @var int
	 */
	protected static int $post_id;

	/**
	 * Sets up shared fixtures.
	 *
	 * @param \WP_UnitTest_Factory $factory Factory.
	 * @return void
	 */
	public static function wpSetUpBeforeClass( $factory ): void {
		self::$admin_id      = $factory->user->create( [ 'role' => 'administrator' ] );
		self::$author_id     = $factory->user->create( [ 'role' => 'author' ] );
		self::$subscriber_id = $factory->user->create( [ 'role' => 'subscriber' ] );

		self::$post_id = $factory->post->create(
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

		/*
		 * A fresh server per test, rather than rest_get_server()'s shared one.
		 * That function only fires `rest_api_init` the first time it is called,
		 * and the test case puts the hooks back the way it found them after
		 * every test — so everything that hook sets up, core's own OPTIONS
		 * handling included, would be missing from the second test onwards.
		 */
		global $wp_rest_server;

		$wp_rest_server = new WP_REST_Server();

		do_action( 'rest_api_init', $wp_rest_server ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

		wp_set_current_user( self::$admin_id );
	}

	/**
	 * Tears down each test.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		global $wp_rest_server;

		$wp_rest_server = null;

		parent::tear_down();
	}

	/**
	 * Creates a collaboration request over the API.
	 *
	 * @param string[] $capabilities Capabilities to grant.
	 * @return array Response data.
	 *
	 * @phpstan-return array<string, mixed>
	 */
	private function create_request( array $capabilities = [ 'edit', 'upload' ] ): array {
		$request = new WP_REST_Request( 'POST', self::ROUTE );
		$request->set_param( 'post', self::$post_id );
		$request->set_param( 'capabilities', $capabilities );

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 201, $response->get_status() );

		return (array) $response->get_data();
	}

	/**
	 * @covers ::register_routes
	 */
	public function test_register_routes(): void {
		$routes = rest_get_server()->get_routes();

		$this->assertArrayHasKey( self::ROUTE, $routes );
		$this->assertArrayHasKey( self::ROUTE . '/(?P<token>[a-f0-9]{32})', $routes );
	}

	/**
	 * @covers ::create_item
	 * @covers ::prepare_item_for_response
	 */
	public function test_create_item(): void {
		$data = $this->create_request();

		$this->assertMatchesRegularExpression( '/^[a-f0-9]{32}$/', $data['token'] );
		$this->assertStringContainsString( $data['token'], $data['url'] );
		$this->assertSame( self::$post_id, $data['post'] );
		$this->assertSame( [ 'edit', 'upload' ], $data['capabilities'] );
		$this->assertFalse( $data['joined'] );
		$this->assertNull( $data['collaborator'] );
		$this->assertGreaterThan( time(), $data['expires_at'] );
	}

	/**
	 * @covers ::create_item
	 */
	public function test_create_item_defaults_to_granting_everything(): void {
		$request = new WP_REST_Request( 'POST', self::ROUTE );
		$request->set_param( 'post', self::$post_id );

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( [ 'edit', 'upload' ], $response->get_data()['capabilities'] );
	}

	/**
	 * @covers ::create_item_permissions_check
	 */
	public function test_create_item_requires_a_post(): void {
		$request  = new WP_REST_Request( 'POST', self::ROUTE );
		$response = rest_get_server()->dispatch( $request );

		$this->assertErrorResponse( 'rest_missing_callback_param', $response, 400 );
	}

	/**
	 * @covers ::create_item_permissions_check
	 */
	public function test_create_item_rejects_a_post_that_does_not_exist(): void {
		$request = new WP_REST_Request( 'POST', self::ROUTE );
		$request->set_param( 'post', 999999 );

		$this->assertErrorResponse(
			'public_collaboration_invalid_post',
			rest_get_server()->dispatch( $request ),
			404
		);
	}

	/**
	 * @covers ::create_item_permissions_check
	 */
	public function test_create_item_requires_being_able_to_edit_the_post(): void {
		wp_set_current_user( self::$subscriber_id );

		$request = new WP_REST_Request( 'POST', self::ROUTE );
		$request->set_param( 'post', self::$post_id );

		$this->assertErrorResponse(
			'public_collaboration_cannot_share',
			rest_get_server()->dispatch( $request ),
			403
		);
	}

	/**
	 * @covers ::create_item_permissions_check
	 */
	public function test_create_item_is_closed_to_the_public(): void {
		wp_set_current_user( 0 );

		$request = new WP_REST_Request( 'POST', self::ROUTE );
		$request->set_param( 'post', self::$post_id );

		$this->assertErrorResponse(
			'public_collaboration_cannot_share',
			rest_get_server()->dispatch( $request ),
			401
		);
	}

	/**
	 * Sharing a link is not a way to give away more than you have yourself.
	 *
	 * @covers ::check_capabilities
	 */
	public function test_create_item_refuses_to_hand_out_uploads_you_do_not_have(): void {
		$contributor_id = self::factory()->user->create( [ 'role' => 'contributor' ] );
		$draft_id       = self::factory()->post->create(
			[
				'post_status' => 'draft',
				'post_author' => $contributor_id,
			]
		);

		wp_set_current_user( $contributor_id );

		$this->assertFalse( current_user_can( 'upload_files' ) );

		$request = new WP_REST_Request( 'POST', self::ROUTE );
		$request->set_param( 'post', $draft_id );
		$request->set_param( 'capabilities', [ 'edit', 'upload' ] );

		$this->assertErrorResponse(
			'public_collaboration_cannot_share_uploads',
			rest_get_server()->dispatch( $request ),
			403
		);

		// Editing alone is theirs to give.
		$request = new WP_REST_Request( 'POST', self::ROUTE );
		$request->set_param( 'post', $draft_id );
		$request->set_param( 'capabilities', [ 'edit' ] );

		$this->assertSame( 201, rest_get_server()->dispatch( $request )->get_status() );
	}

	/**
	 * @covers ::create_item
	 */
	public function test_create_item_rejects_capabilities_it_does_not_know(): void {
		$request = new WP_REST_Request( 'POST', self::ROUTE );
		$request->set_param( 'post', self::$post_id );
		$request->set_param( 'capabilities', [ 'manage_options' ] );

		$this->assertErrorResponse(
			'rest_invalid_param',
			rest_get_server()->dispatch( $request ),
			400
		);
	}

	/**
	 * @covers ::get_item
	 */
	public function test_get_item_reports_who_has_turned_up(): void {
		$data = $this->create_request();

		$response = rest_get_server()->dispatch(
			new WP_REST_Request( 'GET', self::ROUTE . '/' . $data['token'] )
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertFalse( $response->get_data()['joined'] );

		$collaboration_request = Collaboration_Request::get_by_token( $data['token'] );

		$this->assertInstanceOf( Collaboration_Request::class, $collaboration_request );
		$this->assertNotWPError( $collaboration_request->get_or_create_user() );

		$response = rest_get_server()->dispatch(
			new WP_REST_Request( 'GET', self::ROUTE . '/' . $data['token'] )
		);

		$this->assertTrue( $response->get_data()['joined'] );
		$this->assertNotEmpty( $response->get_data()['collaborator'] );
	}

	/**
	 * @covers ::get_item_permissions_check
	 * @covers ::check_owner_permission
	 */
	public function test_get_item_is_not_readable_by_anybody_else(): void {
		$data = $this->create_request();

		wp_set_current_user( self::$author_id );

		$this->assertErrorResponse(
			'public_collaboration_invalid_token',
			rest_get_server()->dispatch( new WP_REST_Request( 'GET', self::ROUTE . '/' . $data['token'] ) ),
			404
		);
	}

	/**
	 * @covers ::check_owner_permission
	 */
	public function test_get_item_is_readable_by_an_editor(): void {
		wp_set_current_user( self::$author_id );

		$own_post_id = self::factory()->post->create(
			[
				'post_status' => 'draft',
				'post_author' => self::$author_id,
			]
		);

		$request = new WP_REST_Request( 'POST', self::ROUTE );
		$request->set_param( 'post', $own_post_id );

		$data = (array) rest_get_server()->dispatch( $request )->get_data();

		wp_set_current_user( self::$admin_id );

		$this->assertSame(
			200,
			rest_get_server()->dispatch( new WP_REST_Request( 'GET', self::ROUTE . '/' . $data['token'] ) )->get_status()
		);
	}

	/**
	 * @covers ::update_item
	 */
	public function test_update_item(): void {
		$data = $this->create_request();

		$request = new WP_REST_Request( 'PUT', self::ROUTE . '/' . $data['token'] );
		$request->set_param( 'capabilities', [ 'upload' ] );

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( [ 'upload' ], $response->get_data()['capabilities'] );

		$collaboration_request = Collaboration_Request::get_by_token( $data['token'] );

		$this->assertInstanceOf( Collaboration_Request::class, $collaboration_request );
		$this->assertSame( [ 'upload' ], $collaboration_request->get_capabilities() );
	}

	/**
	 * @covers ::update_item_permissions_check
	 */
	public function test_update_item_is_not_writable_by_anybody_else(): void {
		$data = $this->create_request();

		wp_set_current_user( self::$author_id );

		$request = new WP_REST_Request( 'PUT', self::ROUTE . '/' . $data['token'] );
		$request->set_param( 'capabilities', [ 'edit', 'upload' ] );

		$this->assertErrorResponse(
			'public_collaboration_invalid_token',
			rest_get_server()->dispatch( $request ),
			404
		);
	}

	/**
	 * @covers ::delete_item
	 */
	public function test_delete_item(): void {
		$data = $this->create_request();

		$collaboration_request = Collaboration_Request::get_by_token( $data['token'] );

		$this->assertInstanceOf( Collaboration_Request::class, $collaboration_request );

		$user_id = $collaboration_request->get_or_create_user();

		$this->assertNotWPError( $user_id );

		$response = rest_get_server()->dispatch(
			new WP_REST_Request( 'DELETE', self::ROUTE . '/' . $data['token'] )
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['deleted'] );
		$this->assertSame( $data['token'], $response->get_data()['previous']['token'] );

		// The link, and the account behind it, are gone for good.
		$this->assertNull( Collaboration_Request::get_by_token( $data['token'] ) );
		$this->assertFalse( get_userdata( $user_id ) );

		$this->assertErrorResponse(
			'public_collaboration_invalid_token',
			rest_get_server()->dispatch( new WP_REST_Request( 'GET', self::ROUTE . '/' . $data['token'] ) ),
			404
		);
	}

	/**
	 * Unknown, expired, and somebody else's tokens are all the same 404, so the
	 * endpoint cannot be used to find out which tokens exist.
	 *
	 * @covers ::not_found_error
	 */
	public function test_an_unknown_token_looks_like_every_other_kind_of_no(): void {
		$this->assertErrorResponse(
			'public_collaboration_invalid_token',
			rest_get_server()->dispatch( new WP_REST_Request( 'GET', self::ROUTE . '/' . str_repeat( 'a', 32 ) ) ),
			404
		);
	}

	/**
	 * @covers ::get_item_permissions_check
	 */
	public function test_an_expired_token_is_gone(): void {
		$data = $this->create_request();

		$collaboration_request = Collaboration_Request::get_by_token( $data['token'] );

		$this->assertInstanceOf( Collaboration_Request::class, $collaboration_request );

		update_post_meta(
			$collaboration_request->get_post()->ID,
			Collaboration_Request::META_EXPIRES_AT,
			time() - 1
		);

		$this->assertErrorResponse(
			'public_collaboration_invalid_token',
			rest_get_server()->dispatch( new WP_REST_Request( 'GET', self::ROUTE . '/' . $data['token'] ) ),
			404
		);
	}

	/**
	 * @covers ::get_item_schema
	 */
	public function test_get_item_schema(): void {
		$response = rest_get_server()->dispatch( new WP_REST_Request( 'OPTIONS', self::ROUTE ) );
		$schema   = $response->get_data()['schema'];

		$this->assertSame( 'collaboration-request', $schema['title'] );
		$this->assertSame(
			[ 'token', 'url', 'post', 'expires_at', 'capabilities', 'joined', 'collaborator' ],
			array_keys( $schema['properties'] )
		);
	}

	/**
	 * `edit` context carries `raw` fields — for a password-protected post, the
	 * body behind the password. Granting `edit_posts` is what lets core list
	 * posts in that context at all, so the collection has to be narrowed.
	 *
	 * @covers \PublicCollaboration\filter_rest_query
	 */
	public function test_a_collaborator_cannot_read_other_posts_in_edit_context(): void {
		$secret_id = self::factory()->post->create(
			[
				'post_status'   => 'publish',
				'post_password' => 'hunter2',
				'post_content'  => 'Behind the password.',
				'post_author'   => self::$admin_id,
			]
		);

		$data = $this->create_request();

		$collaboration_request = Collaboration_Request::get_by_token( $data['token'] );

		$this->assertInstanceOf( Collaboration_Request::class, $collaboration_request );

		$user_id = $collaboration_request->get_or_create_user();

		$this->assertNotWPError( $user_id );

		wp_set_current_user( $user_id );

		$request = new WP_REST_Request( 'GET', '/wp/v2/posts' );
		$request->set_param( 'context', 'edit' );

		$response = rest_get_server()->dispatch( $request );
		$ids      = wp_list_pluck( (array) $response->get_data(), 'id' );

		$this->assertSame( 200, $response->get_status() );
		$this->assertNotContains( $secret_id, $ids );
		$this->assertSame( [ self::$post_id ], $ids, 'Only the shared post is theirs to read.' );

		// The single-post route was already closed by core; check it stays shut.
		$request = new WP_REST_Request( 'GET', '/wp/v2/posts/' . $secret_id );
		$request->set_param( 'context', 'edit' );

		$this->assertErrorResponse( 'rest_forbidden_context', rest_get_server()->dispatch( $request ), 403 );
	}

	/**
	 * @covers \PublicCollaboration\filter_rest_query
	 */
	public function test_everybody_else_still_sees_the_whole_list(): void {
		$other_id = self::factory()->post->create(
			[
				'post_status' => 'publish',
				'post_author' => self::$admin_id,
			]
		);

		$request = new WP_REST_Request( 'GET', '/wp/v2/posts' );
		$request->set_param( 'context', 'edit' );

		$ids = wp_list_pluck( (array) rest_get_server()->dispatch( $request )->get_data(), 'id' );

		$this->assertContains( $other_id, $ids );
		$this->assertContains( self::$post_id, $ids );
	}

	/**
	 * Listing media in `edit` context is gated on the same `edit_posts` the
	 * editor cannot start without, so a guest could otherwise page through the
	 * whole library.
	 *
	 * @covers \PublicCollaboration\filter_rest_attachment_query
	 */
	public function test_a_collaborator_only_sees_media_they_uploaded(): void {
		$data = $this->create_request();

		$collaboration_request = Collaboration_Request::get_by_token( $data['token'] );

		$this->assertInstanceOf( Collaboration_Request::class, $collaboration_request );

		$user_id = $collaboration_request->get_or_create_user();

		$this->assertNotWPError( $user_id );

		$somebody_elses = self::factory()->attachment->create_object(
			[
				'file'           => 'somebody-elses.jpg',
				'post_mime_type' => 'image/jpeg',
				'post_author'    => self::$admin_id,
			]
		);

		$theirs = self::factory()->attachment->create_object(
			[
				'file'           => 'theirs.jpg',
				'post_mime_type' => 'image/jpeg',
				'post_author'    => $user_id,
			]
		);

		wp_set_current_user( $user_id );

		$request = new WP_REST_Request( 'GET', '/wp/v2/media' );
		$request->set_param( 'context', 'edit' );

		$ids = wp_list_pluck( (array) rest_get_server()->dispatch( $request )->get_data(), 'id' );

		$this->assertContains( $theirs, $ids );
		$this->assertNotContains( $somebody_elses, $ids );

		// Everybody else still sees the whole library.
		wp_set_current_user( self::$admin_id );

		$request = new WP_REST_Request( 'GET', '/wp/v2/media' );
		$request->set_param( 'context', 'edit' );

		$ids = wp_list_pluck( (array) rest_get_server()->dispatch( $request )->get_data(), 'id' );

		$this->assertContains( $theirs, $ids );
		$this->assertContains( $somebody_elses, $ids );
	}
}
