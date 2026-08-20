<?php
/**
 * Tests for the Collaboration_Request model.
 *
 * @package PublicCollaboration
 */

declare(strict_types = 1);

namespace PublicCollaboration\Tests;

use PublicCollaboration\Collaboration_Request;
use WP_UnitTestCase;

/**
 * @coversDefaultClass \PublicCollaboration\Collaboration_Request
 */
class Test_Collaboration_Request extends WP_UnitTestCase {
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
	 * Sets up shared fixtures.
	 *
	 * @param \WP_UnitTest_Factory $factory Factory.
	 * @return void
	 */
	public static function wpSetUpBeforeClass( $factory ): void {
		self::$admin_id = $factory->user->create( [ 'role' => 'administrator' ] );
		self::$post_id  = $factory->post->create(
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
	 * Creates a collaboration request for the shared post.
	 *
	 * @param string[] $capabilities Capabilities to grant.
	 * @return Collaboration_Request The request.
	 */
	private function create_request( array $capabilities = [ 'edit', 'upload' ] ): Collaboration_Request {
		$request = Collaboration_Request::create(
			[
				'post'         => self::$post_id,
				'capabilities' => $capabilities,
			]
		);

		$this->assertInstanceOf( Collaboration_Request::class, $request );

		return $request;
	}

	/**
	 * @covers ::create
	 * @covers ::get_token
	 * @covers ::get_parent_id
	 * @covers ::get_author_id
	 * @covers ::get_capabilities
	 */
	public function test_create(): void {
		$request = $this->create_request();

		$this->assertMatchesRegularExpression( '/^[a-f0-9]{32}$/', $request->get_token() );
		$this->assertSame( self::$post_id, $request->get_parent_id() );
		$this->assertSame( self::$admin_id, $request->get_author_id() );
		$this->assertSame( [ 'edit', 'upload' ], $request->get_capabilities() );
		$this->assertSame( Collaboration_Request::POST_TYPE, $request->get_post()->post_type );
	}

	/**
	 * @covers ::create
	 */
	public function test_create_rejects_a_post_that_does_not_exist(): void {
		$request = Collaboration_Request::create( [ 'post' => 999999 ] );

		$this->assertWPError( $request );
		$this->assertSame( 'public_collaboration_invalid_post', $request->get_error_code() );
	}

	/**
	 * @covers ::create
	 */
	public function test_create_gives_every_request_its_own_token(): void {
		$this->assertNotSame(
			$this->create_request()->get_token(),
			$this->create_request()->get_token()
		);
	}

	/**
	 * The REST controller refuses this as well, and neither refusal is there to
	 * lean on the other: everything a collaborator can do is lent to them for a
	 * quarter of an hour, and minting more links is not on the list.
	 *
	 * @covers ::create
	 */
	public function test_create_refuses_a_collaborator(): void {
		$request = $this->create_request();

		wp_set_current_user( $request->get_or_create_user() );

		$attempt = Collaboration_Request::create( [ 'post' => self::$post_id ] );

		$this->assertWPError( $attempt );
		$this->assertSame( 'public_collaboration_cannot_share', $attempt->get_error_code() );
	}

	/**
	 * @covers ::get_expires_at
	 * @covers ::is_expired
	 */
	public function test_create_sets_an_expiry_a_quarter_of_an_hour_out(): void {
		$request = $this->create_request();

		$this->assertFalse( $request->is_expired() );
		$this->assertEqualsWithDelta( time() + 15 * MINUTE_IN_SECONDS, $request->get_expires_at(), 5 );
	}

	/**
	 * @covers ::get_ttl
	 */
	public function test_ttl_is_filterable_but_has_a_floor(): void {
		add_filter( 'public_collaboration_request_ttl', static fn (): int => 60 * 60 );
		$this->assertSame( 60 * 60, Collaboration_Request::get_ttl() );

		remove_all_filters( 'public_collaboration_request_ttl' );

		// A link that has expired before it is read is not a shorter link, it is a broken one.
		add_filter( 'public_collaboration_request_ttl', static fn (): int => 0 );
		$this->assertSame( MINUTE_IN_SECONDS, Collaboration_Request::get_ttl() );
	}

	/**
	 * @covers ::set_capabilities
	 * @covers ::get_capabilities
	 * @covers ::allows
	 */
	public function test_capabilities_are_limited_to_the_ones_the_plugin_knows(): void {
		$request = $this->create_request( [ 'edit', 'manage_options', 'upload' ] );

		$this->assertSame( [ 'edit', 'upload' ], $request->get_capabilities() );
		$this->assertTrue( $request->allows( 'edit' ) );
		$this->assertTrue( $request->allows( 'upload' ) );
		$this->assertFalse( $request->allows( 'manage_options' ) );

		$request->set_capabilities( [ 'upload' ] );

		$this->assertSame( [ 'upload' ], $request->get_capabilities() );
		$this->assertFalse( $request->allows( 'edit' ) );
	}

	/**
	 * @covers ::get_by_token
	 */
	public function test_get_by_token(): void {
		$request = $this->create_request();
		$found   = Collaboration_Request::get_by_token( $request->get_token() );

		$this->assertInstanceOf( Collaboration_Request::class, $found );
		$this->assertSame( $request->get_post()->ID, $found->get_post()->ID );
	}

	/**
	 * @covers ::get_by_token
	 * @covers ::is_valid_token_format
	 */
	public function test_get_by_token_rejects_tokens_that_cannot_exist(): void {
		$this->assertNull( Collaboration_Request::get_by_token( '' ) );
		$this->assertNull( Collaboration_Request::get_by_token( 'not-a-token' ) );
		$this->assertNull( Collaboration_Request::get_by_token( str_repeat( 'a', 32 ) ) );
		$this->assertNull( Collaboration_Request::get_by_token( str_repeat( 'A', 32 ) ) );
	}

	/**
	 * @covers ::get_by_token
	 */
	public function test_get_by_token_treats_an_expired_request_as_gone(): void {
		$request = $this->create_request();

		update_post_meta( $request->get_post()->ID, Collaboration_Request::META_EXPIRES_AT, time() - 1 );

		$this->assertTrue( $request->is_expired() );
		$this->assertNull( Collaboration_Request::get_by_token( $request->get_token() ) );
	}

	/**
	 * @covers ::get_for_post
	 */
	public function test_get_for_post_finds_every_live_request_for_a_post(): void {
		$first  = $this->create_request();
		$second = $this->create_request();

		$this->assertSame(
			[ $first->get_token(), $second->get_token() ],
			array_map(
				static fn ( Collaboration_Request $request ): string => $request->get_token(),
				Collaboration_Request::get_for_post( self::$post_id )
			),
			'Oldest first, so the panel does not reshuffle as links are handed out.'
		);
	}

	/**
	 * @covers ::get_for_post
	 */
	public function test_get_for_post_treats_an_expired_request_as_gone(): void {
		$request = $this->create_request();

		update_post_meta( $request->get_post()->ID, Collaboration_Request::META_EXPIRES_AT, time() - 1 );

		$this->assertSame( [], Collaboration_Request::get_for_post( self::$post_id ) );
	}

	/**
	 * Cleanup runs on cron, which is unreliable on a low-traffic site, so a post
	 * that has been shared all week can hold any number of spent requests. The
	 * live ones must not end up behind them.
	 *
	 * @covers ::get_for_post
	 */
	public function test_get_for_post_is_not_crowded_out_by_spent_requests(): void {
		for ( $i = 0; $i < 3; $i++ ) {
			$spent = $this->create_request();

			update_post_meta( $spent->get_post()->ID, Collaboration_Request::META_EXPIRES_AT, time() - 1 );
		}

		$live = $this->create_request();

		$this->assertSame(
			[ $live->get_token() ],
			array_map(
				static fn ( Collaboration_Request $request ): string => $request->get_token(),
				Collaboration_Request::get_for_post( self::$post_id )
			)
		);
	}

	/**
	 * @covers ::get_for_post
	 */
	public function test_get_for_post_has_nothing_for_a_post_that_cannot_exist(): void {
		$this->assertSame( [], Collaboration_Request::get_for_post( 0 ) );
		$this->assertSame( [], Collaboration_Request::get_for_post( -1 ) );
	}

	/**
	 * @covers ::from_post
	 */
	public function test_from_post_only_accepts_collaboration_requests(): void {
		$post = get_post( self::$post_id );

		$this->assertNotNull( $post );
		$this->assertNull( Collaboration_Request::from_post( $post ) );
	}

	/**
	 * @covers ::get_or_create_user
	 * @covers ::get_user_id
	 */
	public function test_get_or_create_user(): void {
		$request = $this->create_request();

		$this->assertSame( 0, $request->get_user_id(), 'A link nobody has followed should have no account behind it.' );

		$user_id = $request->get_or_create_user();

		$this->assertNotWPError( $user_id );
		$this->assertSame( $user_id, $request->get_user_id() );

		$user = get_userdata( $user_id );

		$this->assertNotFalse( $user );
		$this->assertStringStartsWith( 'pubcol_', $user->user_login );
		$this->assertNotEmpty( $user->display_name );
		$this->assertSame( [], $user->roles, 'The temporary account must not carry a role.' );
		$this->assertSame(
			(string) $request->get_post()->ID,
			(string) get_user_meta( $user_id, Collaboration_Request::USER_META_REQUEST_ID, true )
		);
	}

	/**
	 * @covers ::get_or_create_user
	 */
	public function test_get_or_create_user_skips_the_editor_tour(): void {
		global $wpdb;

		$request = $this->create_request();
		$user_id = $request->get_or_create_user();

		$this->assertNotWPError( $user_id );

		$meta_key = $wpdb->get_blog_prefix() . 'persisted_preferences';

		/*
		 * Seeding a key of our own invention would be silently useless: the
		 * editor only ever reads the one core registers for it. The test case
		 * unregisters every meta key between tests, so register it back before
		 * asking what it is called.
		 */
		wp_register_persisted_preferences_meta();

		$this->assertArrayHasKey(
			$meta_key,
			get_registered_meta_keys( 'user' ),
			'The editor reads its preferences from this key and no other.'
		);

		$preferences = get_user_meta( $user_id, $meta_key, true );

		$this->assertIsArray( $preferences );
		$this->assertFalse( $preferences['core/edit-post']['welcomeGuide'] );
	}

	/**
	 * @covers ::get_or_create_user
	 */
	public function test_get_or_create_user_reuses_the_account_it_made(): void {
		$request = $this->create_request();

		$this->assertSame( $request->get_or_create_user(), $request->get_or_create_user() );
	}

	/**
	 * @covers ::get_or_create_user
	 */
	public function test_every_request_gets_its_own_account(): void {
		$this->assertNotSame(
			$this->create_request()->get_or_create_user(),
			$this->create_request()->get_or_create_user()
		);
	}

	/**
	 * @covers ::get_for_user
	 */
	public function test_get_for_user(): void {
		$request = $this->create_request();
		$user_id = $request->get_or_create_user();

		$found = Collaboration_Request::get_for_user( $user_id );

		$this->assertInstanceOf( Collaboration_Request::class, $found );
		$this->assertSame( $request->get_post()->ID, $found->get_post()->ID );
	}

	/**
	 * @covers ::get_for_user
	 */
	public function test_get_for_user_ignores_everybody_else(): void {
		$this->assertNull( Collaboration_Request::get_for_user( 0 ) );
		$this->assertNull( Collaboration_Request::get_for_user( self::$admin_id ) );
	}

	/**
	 * @covers ::get_for_user
	 */
	public function test_get_for_user_ignores_an_expired_request(): void {
		$request = $this->create_request();
		$user_id = $request->get_or_create_user();

		update_post_meta( $request->get_post()->ID, Collaboration_Request::META_EXPIRES_AT, time() - 1 );

		$this->assertNull( Collaboration_Request::get_for_user( $user_id ) );
	}

	/**
	 * @covers ::get_for_user
	 */
	public function test_get_for_user_ignores_a_pointer_the_request_does_not_return(): void {
		$request = $this->create_request();
		$user_id = $request->get_or_create_user();

		// A second account claiming the same request. Only the one the request
		// itself points back at counts.
		$other_id = self::factory()->user->create();
		update_user_meta( $other_id, Collaboration_Request::USER_META_REQUEST_ID, $request->get_post()->ID );

		$this->assertInstanceOf( Collaboration_Request::class, Collaboration_Request::get_for_user( $user_id ) );
		$this->assertNull( Collaboration_Request::get_for_user( $other_id ) );
	}

	/**
	 * @covers ::delete
	 */
	public function test_delete_takes_the_account_with_it(): void {
		$request = $this->create_request();
		$post_id = $request->get_post()->ID;
		$user_id = $request->get_or_create_user();

		$this->assertNotFalse( get_userdata( $user_id ) );

		$request->delete();

		$this->assertNull( get_post( $post_id ) );
		$this->assertFalse( get_userdata( $user_id ) );
	}

	/**
	 * @covers ::delete
	 */
	public function test_delete_leaves_the_post_alone(): void {
		$request = $this->create_request();
		$request->get_or_create_user();
		$request->delete();

		$this->assertNotNull( get_post( self::$post_id ) );
	}

	/**
	 * @covers ::get_url
	 */
	public function test_get_url_addresses_the_request_by_token(): void {
		$request = $this->create_request();

		$this->assertStringContainsString( $request->get_token(), $request->get_url() );
		$this->assertStringNotContainsString( 'p=' . $request->get_post()->ID, $request->get_url() );
	}
}
