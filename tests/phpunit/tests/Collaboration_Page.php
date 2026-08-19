<?php
/**
 * Tests for what happens when somebody follows a collaboration link.
 *
 * @package PublicCollaboration
 */

declare(strict_types = 1);

namespace PublicCollaboration\Tests;

use PublicCollaboration\Collaboration_Request;
use WP_UnitTestCase;

use function PublicCollaboration\enqueue_block_editor_assets;
use function PublicCollaboration\filter_template_include;
use function PublicCollaboration\register_assets;

/**
 * Tests for the collaboration page.
 */
class Test_Collaboration_Page extends WP_UnitTestCase {
	use Intercepts_Redirects;

	/**
	 * A template path standing in for whatever the theme would have rendered.
	 */
	private const THEME_TEMPLATE = '/theme/single.php';

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
	 * Creates a collaboration request and visits its link.
	 *
	 * @param int|null $post_id Optional. Post to collaborate on.
	 * @return Collaboration_Request The request.
	 */
	private function go_to_link( ?int $post_id = null ): Collaboration_Request {
		$current = get_current_user_id();

		wp_set_current_user( self::$admin_id );

		$request = Collaboration_Request::create( [ 'post' => $post_id ?? self::$post_id ] );

		$this->assertInstanceOf( Collaboration_Request::class, $request );

		wp_set_current_user( $current );

		$this->go_to( $request->get_url() );

		return $request;
	}

	/**
	 * @covers \PublicCollaboration\filter_template_include
	 */
	public function test_other_pages_are_left_alone(): void {
		$this->go_to( get_permalink( self::$post_id ) );

		$this->assertSame( self::THEME_TEMPLATE, filter_template_include( self::THEME_TEMPLATE ) );
	}

	/**
	 * The token is the only credential, so a request addressed by its post ID —
	 * which anybody could count up to — must not resolve.
	 *
	 * @covers \PublicCollaboration\filter_template_include
	 */
	public function test_a_request_reached_by_id_is_a_404(): void {
		$current = get_current_user_id();

		wp_set_current_user( self::$admin_id );

		$request = Collaboration_Request::create( [ 'post' => self::$post_id ] );

		$this->assertInstanceOf( Collaboration_Request::class, $request );

		wp_set_current_user( $current );

		$this->go_to(
			home_url(
				add_query_arg(
					[
						'post_type' => Collaboration_Request::POST_TYPE,
						'p'         => $request->get_post()->ID,
					],
					''
				)
			)
		);

		$this->assertNotSame( self::THEME_TEMPLATE, filter_template_include( self::THEME_TEMPLATE ) );
		$this->assertTrue( is_404() );
	}

	/**
	 * @covers \PublicCollaboration\filter_template_include
	 */
	public function test_following_a_link_signs_you_in_and_sends_you_to_the_post(): void {
		wp_set_current_user( 0 );

		$request = $this->go_to_link();

		$location = $this->catch_redirect(
			static fn () => filter_template_include( self::THEME_TEMPLATE )
		);

		$this->assertStringContainsString( 'post.php', (string) $location );
		$this->assertStringContainsString( 'post=' . self::$post_id, (string) $location );

		$user_id = $request->get_user_id();

		$this->assertGreaterThan( 0, $user_id );
		$this->assertSame( $user_id, get_current_user_id() );
		$this->assertTrue( current_user_can( 'edit_post', self::$post_id ) );
	}

	/**
	 * @covers \PublicCollaboration\filter_template_include
	 */
	public function test_following_your_own_link_just_opens_the_post(): void {
		wp_set_current_user( self::$admin_id );

		$request = $this->go_to_link();

		$location = $this->catch_redirect(
			static fn () => filter_template_include( self::THEME_TEMPLATE )
		);

		$this->assertStringContainsString( 'post=' . self::$post_id, (string) $location );

		// Nobody needed a temporary account, so none was made.
		$this->assertSame( 0, $request->get_user_id() );
		$this->assertSame( self::$admin_id, get_current_user_id() );
	}

	/**
	 * @covers \PublicCollaboration\filter_template_include
	 */
	public function test_an_expired_link_explains_itself(): void {
		wp_set_current_user( 0 );

		$request = $this->go_to_link();

		update_post_meta( $request->get_post()->ID, Collaboration_Request::META_EXPIRES_AT, time() - 1 );

		$template = filter_template_include( self::THEME_TEMPLATE );

		$this->assertStringEndsWith( 'templates/collaboration-page.php', $template );
		$this->assertSame( 0, $request->get_user_id(), 'An expired link must not create an account.' );
	}

	/**
	 * Swapping somebody's session out from under them would sign them out of
	 * their own account without asking.
	 *
	 * @covers \PublicCollaboration\filter_template_include
	 */
	public function test_somebody_elses_session_is_left_alone(): void {
		$subscriber_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );

		wp_set_current_user( $subscriber_id );

		$request  = $this->go_to_link();
		$template = filter_template_include( self::THEME_TEMPLATE );

		$this->assertStringEndsWith( 'templates/collaboration-page.php', $template );
		$this->assertSame( $subscriber_id, get_current_user_id() );
		$this->assertSame( 0, $request->get_user_id() );
	}

	/**
	 * @covers \PublicCollaboration\filter_template_include
	 */
	public function test_a_link_to_a_deleted_post_explains_itself(): void {
		$doomed_id = self::factory()->post->create( [ 'post_status' => 'publish' ] );

		wp_set_current_user( 0 );

		$request = $this->go_to_link( $doomed_id );

		wp_delete_post( $doomed_id, true );

		$this->assertStringEndsWith(
			'templates/collaboration-page.php',
			filter_template_include( self::THEME_TEMPLATE )
		);
		$this->assertSame( 0, $request->get_user_id() );
	}

	/**
	 * Following a link is two requests, and nothing carries over between them
	 * but the database — so this covers the handover the same way, with the
	 * caches emptied in the middle.
	 *
	 * @covers \PublicCollaboration\filter_template_include
	 * @covers \PublicCollaboration\enqueue_block_editor_assets
	 */
	public function test_the_editor_the_link_leads_to_knows_who_arrived(): void {
		wp_set_current_user( 0 );

		$request = $this->go_to_link();

		$this->assertNotNull(
			$this->catch_redirect(
				static function () {
					filter_template_include( self::THEME_TEMPLATE );
				}
			),
			'Following the link leads to the editor.'
		);

		$user_id = $request->get_user_id();

		$this->assertGreaterThan( 0, $user_id, 'It made an account to get there as.' );

		// Nothing of the first request survives into the second.
		wp_cache_flush();

		$GLOBALS['wp_scripts'] = new \WP_Scripts(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		register_assets();
		wp_set_current_user( $user_id );

		$this->assertTrue(
			current_user_can( 'edit_post', self::$post_id ),
			'The account can edit the post it was invited to.'
		);

		enqueue_block_editor_assets();

		$this->assertTrue(
			wp_script_is( 'public-collaboration-welcome', 'enqueued' ),
			'And is greeted, rather than handed the sharing UI.'
		);
		$this->assertFalse( wp_script_is( 'public-collaboration-editor', 'enqueued' ) );
	}
}
