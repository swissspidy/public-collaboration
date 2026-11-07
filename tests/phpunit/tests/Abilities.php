<?php
/**
 * Tests for the Abilities API integration.
 *
 * @package PublicCollaboration
 */

declare(strict_types = 1);

namespace PublicCollaboration\Tests;

use PublicCollaboration\Collaboration_Request;
use WP_Ability;
use WP_Error;
use WP_UnitTestCase;

/**
 * @covers ::\PublicCollaboration\register_abilities
 * @covers ::\PublicCollaboration\register_ability_category
 * @covers ::\PublicCollaboration\check_ability_permission
 * @covers ::\PublicCollaboration\dispatch_ability
 * @covers ::\PublicCollaboration\build_ability_request
 */
class Test_Abilities extends WP_UnitTestCase {
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
		self::$admin_id  = $factory->user->create( [ 'role' => 'administrator' ] );
		self::$author_id = $factory->user->create( [ 'role' => 'author' ] );
		self::$post_id   = $factory->post->create( [ 'post_author' => self::$admin_id ] );
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
	 * Returns a registered ability, failing the test if it is not there.
	 *
	 * @param string $name Ability name, without the namespace.
	 * @return WP_Ability The ability.
	 */
	private function get_ability( string $name ): WP_Ability {
		$ability = wp_get_ability( 'public-collaboration/' . $name );

		$this->assertInstanceOf( WP_Ability::class, $ability, "The {$name} ability is not registered." );

		return $ability;
	}

	/**
	 * Creates a link through the ability, as whoever is signed in.
	 *
	 * @param array $input Extra input.
	 * @return array The link.
	 *
	 * @phpstan-param array<string, mixed> $input
	 * @phpstan-return array<string, mixed>
	 */
	private function create_link( array $input = [] ): array {
		$link = $this->get_ability( 'create-link' )->execute( [ 'post' => self::$post_id ] + $input );

		$this->assertIsArray( $link );

		return $link;
	}

	public function test_registers_every_ability_in_its_own_category(): void {
		$this->assertTrue( wp_has_ability_category( 'public-collaboration' ) );

		foreach ( [ 'list-links', 'create-link', 'update-link', 'extend-link', 'revoke-link' ] as $name ) {
			$ability = $this->get_ability( $name );

			$this->assertSame( 'public-collaboration', $ability->get_category() );
			$this->assertTrue( $ability->get_meta_item( 'show_in_rest' ) );
		}
	}

	public function test_only_listing_is_read_only_and_only_revoking_is_destructive(): void {
		$this->assertTrue( $this->get_ability( 'list-links' )->get_meta_item( 'annotations' )['readonly'] );
		$this->assertFalse( $this->get_ability( 'create-link' )->get_meta_item( 'annotations' )['readonly'] );
		$this->assertTrue( $this->get_ability( 'revoke-link' )->get_meta_item( 'annotations' )['destructive'] );
		$this->assertFalse( $this->get_ability( 'update-link' )->get_meta_item( 'annotations' )['destructive'] );
	}

	public function test_create_link(): void {
		$link = $this->create_link(
			[
				'capabilities' => [ 'edit' ],
				'label'        => 'Sam',
			]
		);

		$this->assertMatchesRegularExpression( '/^[a-f0-9]{32}$/', $link['token'] );
		$this->assertSame( [ 'edit' ], $link['capabilities'] );
		$this->assertSame( 'Sam', $link['label'] );
		$this->assertInstanceOf( Collaboration_Request::class, Collaboration_Request::get_by_token( $link['token'] ) );
	}

	public function test_list_links(): void {
		$link = $this->create_link();

		$links = $this->get_ability( 'list-links' )->execute( [ 'post' => self::$post_id ] );

		$this->assertIsArray( $links );
		$this->assertSame( [ $link['token'] ], wp_list_pluck( $links, 'token' ) );
	}

	public function test_update_link(): void {
		$link = $this->create_link();

		$updated = $this->get_ability( 'update-link' )->execute(
			[
				'token'        => $link['token'],
				'capabilities' => [],
				'label'        => 'Read only',
			]
		);

		$this->assertIsArray( $updated );
		$this->assertSame( [], $updated['capabilities'] );
		$this->assertSame( 'Read only', $updated['label'] );
	}

	public function test_extend_link(): void {
		$link    = $this->create_link();
		$request = Collaboration_Request::get_by_token( $link['token'] );

		$this->assertInstanceOf( Collaboration_Request::class, $request );

		update_post_meta( $request->get_post()->ID, Collaboration_Request::META_EXPIRES_AT, time() + MINUTE_IN_SECONDS );

		$extended = $this->get_ability( 'extend-link' )->execute( [ 'token' => $link['token'] ] );

		$this->assertIsArray( $extended );
		$this->assertEqualsWithDelta( time() + Collaboration_Request::get_ttl(), $extended['expires_at'], 2 );
	}

	public function test_revoke_link(): void {
		$link = $this->create_link();

		$result = $this->get_ability( 'revoke-link' )->execute( [ 'token' => $link['token'] ] );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['deleted'] );
		$this->assertNull( Collaboration_Request::get_by_token( $link['token'] ) );
	}

	/**
	 * An ability is held to exactly the rules the editor's panel is.
	 */
	public function test_nobody_can_share_a_post_they_cannot_edit(): void {
		wp_set_current_user( self::$author_id );

		$result = $this->get_ability( 'create-link' )->execute( [ 'post' => self::$post_id ] );

		$this->assertWPError( $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
		$this->assertSame( [], Collaboration_Request::get_for_post( self::$post_id ) );
	}

	public function test_nobody_can_revoke_a_link_to_a_post_they_cannot_edit(): void {
		$link = $this->create_link();

		wp_set_current_user( self::$author_id );

		$result = $this->get_ability( 'revoke-link' )->execute( [ 'token' => $link['token'] ] );

		$this->assertWPError( $result );
		$this->assertInstanceOf( Collaboration_Request::class, Collaboration_Request::get_by_token( $link['token'] ) );
	}

	public function test_a_collaborator_cannot_use_the_abilities_to_share_the_post_on(): void {
		$link    = $this->create_link();
		$request = Collaboration_Request::get_by_token( $link['token'] );

		$this->assertInstanceOf( Collaboration_Request::class, $request );

		wp_set_current_user( (int) $request->get_or_create_user() );

		$this->assertWPError( $this->get_ability( 'create-link' )->execute( [ 'post' => self::$post_id ] ) );
		$this->assertWPError( $this->get_ability( 'list-links' )->execute( [ 'post' => self::$post_id ] ) );
		$this->assertWPError( $this->get_ability( 'extend-link' )->execute( [ 'token' => $link['token'] ] ) );
	}

	public function test_nobody_signed_out_can_do_anything(): void {
		wp_set_current_user( 0 );

		$this->assertWPError( $this->get_ability( 'list-links' )->execute( [ 'post' => self::$post_id ] ) );
	}

	public function test_input_is_validated_against_the_schema(): void {
		$result = $this->get_ability( 'revoke-link' )->execute( [ 'token' => 'not-a-token' ] );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_input', $result->get_error_code() );

		$result = $this->get_ability( 'create-link' )->execute(
			[
				'post'         => self::$post_id,
				'capabilities' => [ 'delete' ],
			]
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_input', $result->get_error_code() );
	}

	public function test_an_error_from_the_endpoint_is_passed_on(): void {
		add_filter( 'public_collaboration_max_requests_per_post', static fn (): int => 1 );

		$this->create_link();

		$result = $this->get_ability( 'create-link' )->execute( [ 'post' => self::$post_id ] );

		$this->assertWPError( $result );
	}
}
