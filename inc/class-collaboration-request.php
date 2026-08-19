<?php
/**
 * Class Collaboration_Request.
 *
 * @package PublicCollaboration
 */

declare(strict_types = 1);

namespace PublicCollaboration;

use WP_Error;
use WP_Post;
use WP_User;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Represents a single collaboration request.
 *
 * A collaboration request is a short-lived, unguessable token that lets whoever
 * holds it edit exactly one post — and nothing else. It is stored as a post of
 * the {@see Collaboration_Request::POST_TYPE} post type, with the token as the
 * post slug and the post being collaborated on as the parent.
 *
 * The person following the link needs an account for WordPress to have anything
 * to hang permissions off, so one is made for them: a roleless user that exists
 * for as long as the request does and is deleted along with it. Every capability
 * that user ends up with is granted by {@see filter_user_has_cap()} at the moment
 * it is checked, and only for this one post.
 */
final class Collaboration_Request {
	/**
	 * Post type name.
	 */
	public const POST_TYPE = 'pubcol_collab';

	/**
	 * Meta key holding the expiration timestamp.
	 */
	public const META_EXPIRES_AT = 'pubcol_expires_at';

	/**
	 * Meta key holding the granted capabilities.
	 */
	public const META_CAPABILITIES = 'pubcol_capabilities';

	/**
	 * Meta key holding the ID of the temporary user.
	 */
	public const META_USER_ID = 'pubcol_user_id';

	/**
	 * User meta key pointing back at the collaboration request.
	 */
	public const USER_META_REQUEST_ID = 'pubcol_request_id';

	/**
	 * Capability to edit the post.
	 */
	public const CAP_EDIT = 'edit';

	/**
	 * Capability to upload media.
	 */
	public const CAP_UPLOAD = 'upload';

	/**
	 * Default lifetime of a collaboration request, in seconds.
	 */
	public const DEFAULT_TTL = 15 * MINUTE_IN_SECONDS;

	/**
	 * The underlying post.
	 *
	 * @var WP_Post
	 */
	private WP_Post $post;

	/**
	 * Constructor.
	 *
	 * @param WP_Post $post The underlying post.
	 */
	private function __construct( WP_Post $post ) {
		$this->post = $post;
	}

	/**
	 * Returns the capabilities a collaboration request may grant.
	 *
	 * Deliberately its own vocabulary rather than WordPress capability names.
	 * What is stored against a request is a list of things the collaborator may
	 * do, not a list of capabilities to hand out — the translation between the
	 * two happens in one place, in {@see filter_user_has_cap()}, where the post
	 * it applies to is known.
	 *
	 * @return string[] Capability names.
	 */
	public static function get_available_capabilities(): array {
		return [ self::CAP_EDIT, self::CAP_UPLOAD ];
	}

	/**
	 * Creates a new collaboration request.
	 *
	 * @param array $args {
	 *     Arguments.
	 *
	 *     @type int      $post         ID of the post to collaborate on.
	 *     @type string[] $capabilities Optional. Capabilities to grant. Defaults to all of them.
	 * }
	 * @return Collaboration_Request|WP_Error New collaboration request on success, error object on failure.
	 *
	 * @phpstan-param array{post: int, capabilities?: string[]} $args
	 */
	public static function create( array $args ) {
		$parent = get_post( $args['post'] );

		if ( ! $parent instanceof WP_Post ) {
			return new WP_Error(
				'public_collaboration_invalid_post',
				__( 'That post could not be found.', 'public-collaboration' ),
				[ 'status' => 404 ]
			);
		}

		$post_id = wp_insert_post(
			[
				'post_type'   => self::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => __( 'Collaboration request', 'public-collaboration' ),
				'post_name'   => self::generate_token(),
				'post_parent' => $parent->ID,
				'post_author' => get_current_user_id(),
			],
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$post = get_post( $post_id );

		if ( ! $post instanceof WP_Post ) {
			return new WP_Error(
				'public_collaboration_cannot_create',
				__( 'The collaboration link could not be created. Please try again.', 'public-collaboration' ),
				[ 'status' => 500 ]
			);
		}

		$request = new self( $post );

		update_post_meta( $post_id, self::META_EXPIRES_AT, time() + self::get_ttl() );
		$request->set_capabilities( $args['capabilities'] ?? self::get_available_capabilities() );

		/**
		 * Fires right after a collaboration request has been created.
		 *
		 * @param WP_Post $post The collaboration request post.
		 */
		do_action( 'public_collaboration_request_created', $post );

		return $request;
	}

	/**
	 * Returns the collaboration request for a given token, if it is still usable.
	 *
	 * Expired requests are treated as if they did not exist. This is deliberate:
	 * cleanup runs on cron, which is unreliable on low-traffic sites, so expiry
	 * must also be enforced at the time of use.
	 *
	 * @param string $token The collaboration request token.
	 * @return Collaboration_Request|null Collaboration request if found and still valid, null otherwise.
	 */
	public static function get_by_token( string $token ): ?self {
		if ( '' === $token || ! self::is_valid_token_format( $token ) ) {
			return null;
		}

		$posts = get_posts(
			[
				'name'                   => $token,
				'post_type'              => self::POST_TYPE,
				'post_status'            => 'publish',
				'numberposts'            => 1,
				'suppress_filters'       => false,
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
			]
		);

		if ( empty( $posts ) ) {
			return null;
		}

		$request = new self( $posts[0] );

		if ( $request->is_expired() ) {
			return null;
		}

		return $request;
	}

	/**
	 * Returns the collaboration request a temporary user belongs to, if it is still usable.
	 *
	 * @param int $user_id User ID.
	 * @return Collaboration_Request|null Collaboration request, or null if the user is not a collaborator.
	 */
	public static function get_for_user( int $user_id ): ?self {
		if ( $user_id <= 0 ) {
			return null;
		}

		$request_id = get_user_meta( $user_id, self::USER_META_REQUEST_ID, true );

		if ( ! is_numeric( $request_id ) ) {
			return null;
		}

		$post = get_post( (int) $request_id );

		if ( ! $post instanceof WP_Post || self::POST_TYPE !== $post->post_type ) {
			return null;
		}

		$request = new self( $post );

		/*
		 * A request whose user meta no longer matches is not this user's to use.
		 * Belt and suspenders against a stale pointer outliving the request that
		 * set it — the capability filter is the one caller that must never be
		 * generous by accident.
		 */
		if ( $request->get_user_id() !== $user_id || $request->is_expired() ) {
			return null;
		}

		return $request;
	}

	/**
	 * Returns the collaboration request for a given post, regardless of whether it has expired.
	 *
	 * @param WP_Post $post The collaboration request post.
	 * @return Collaboration_Request|null Collaboration request, or null if the post is not one.
	 */
	public static function from_post( WP_Post $post ): ?self {
		if ( self::POST_TYPE !== $post->post_type ) {
			return null;
		}

		return new self( $post );
	}

	/**
	 * Generates a new, unguessable collaboration request token.
	 *
	 * 32 hexadecimal characters, i.e. 128 bits of entropy. Lower case throughout
	 * so that it survives {@see sanitize_title()} unchanged when stored as a slug.
	 *
	 * @return string The token.
	 */
	private static function generate_token(): string {
		return bin2hex( random_bytes( 16 ) );
	}

	/**
	 * Determines whether a string has the shape of a collaboration request token.
	 *
	 * @param string $token Token to check.
	 * @return bool Whether the token is well-formed.
	 */
	public static function is_valid_token_format( string $token ): bool {
		return 1 === preg_match( '/^[a-f0-9]{32}$/', $token );
	}

	/**
	 * Returns the lifetime of a collaboration request, in seconds.
	 *
	 * @return int Lifetime in seconds.
	 */
	public static function get_ttl(): int {
		/**
		 * Filters how long a collaboration request stays valid.
		 *
		 * Keep this short. The token is the only thing standing between the
		 * public and write access to a post on your site.
		 *
		 * @param int $ttl Lifetime in seconds. Default 15 minutes.
		 */
		$ttl = (int) apply_filters( 'public_collaboration_request_ttl', self::DEFAULT_TTL );

		return max( MINUTE_IN_SECONDS, $ttl );
	}

	/**
	 * Returns the underlying post.
	 *
	 * @return WP_Post The post.
	 */
	public function get_post(): WP_Post {
		return $this->post;
	}

	/**
	 * Returns the collaboration request token.
	 *
	 * @return string The token.
	 */
	public function get_token(): string {
		return $this->post->post_name;
	}

	/**
	 * Returns the public URL collaborators follow.
	 *
	 * @return string The URL.
	 */
	public function get_url(): string {
		return (string) get_permalink( $this->post );
	}

	/**
	 * Returns the ID of the user who created this collaboration request.
	 *
	 * @return int User ID.
	 */
	public function get_author_id(): int {
		return (int) $this->post->post_author;
	}

	/**
	 * Returns the ID of the post being collaborated on.
	 *
	 * @return int Post ID.
	 */
	public function get_parent_id(): int {
		return (int) $this->post->post_parent;
	}

	/**
	 * Returns the post being collaborated on.
	 *
	 * @return WP_Post|null The post, or null if it has since been deleted.
	 */
	public function get_parent(): ?WP_Post {
		$parent = $this->get_parent_id() > 0 ? get_post( $this->get_parent_id() ) : null;

		return $parent instanceof WP_Post ? $parent : null;
	}

	/**
	 * Returns the timestamp at which this collaboration request expires.
	 *
	 * @return int Unix timestamp.
	 */
	public function get_expires_at(): int {
		return (int) get_post_meta( $this->post->ID, self::META_EXPIRES_AT, true );
	}

	/**
	 * Determines whether this collaboration request has expired.
	 *
	 * @return bool Whether the request has expired.
	 */
	public function is_expired(): bool {
		return $this->get_expires_at() <= time();
	}

	/**
	 * Returns the capabilities this collaboration request grants.
	 *
	 * @return string[] Capability names.
	 */
	public function get_capabilities(): array {
		$capabilities = get_post_meta( $this->post->ID, self::META_CAPABILITIES, true );

		if ( ! is_array( $capabilities ) ) {
			return [];
		}

		return array_values(
			array_intersect( array_map( 'strval', $capabilities ), self::get_available_capabilities() )
		);
	}

	/**
	 * Sets the capabilities this collaboration request grants.
	 *
	 * Anything not on the list of available capabilities is dropped, so that the
	 * stored value can never grow beyond what this plugin knows how to grant.
	 *
	 * @param string[] $capabilities Capability names.
	 * @return void
	 */
	public function set_capabilities( array $capabilities ): void {
		update_post_meta(
			$this->post->ID,
			self::META_CAPABILITIES,
			array_values( array_intersect( array_map( 'strval', $capabilities ), self::get_available_capabilities() ) )
		);
	}

	/**
	 * Determines whether this collaboration request grants a given capability.
	 *
	 * @param string $capability Capability name.
	 * @return bool Whether the capability is granted.
	 */
	public function allows( string $capability ): bool {
		return \in_array( $capability, $this->get_capabilities(), true );
	}

	/**
	 * Returns the ID of the temporary user belonging to this request.
	 *
	 * @return int User ID, or 0 if no user has been created yet.
	 */
	public function get_user_id(): int {
		return (int) get_post_meta( $this->post->ID, self::META_USER_ID, true );
	}

	/**
	 * Returns the temporary user belonging to this request, creating it if needed.
	 *
	 * The account is made on first use rather than up front: a link that nobody
	 * follows should not leave a user row behind.
	 *
	 * @global \wpdb $wpdb WordPress database abstraction object.
	 *
	 * @return int|WP_Error User ID on success, error object on failure.
	 */
	public function get_or_create_user() {
		$user_id = $this->get_user_id();

		if ( $user_id > 0 && get_userdata( $user_id ) instanceof WP_User ) {
			return $user_id;
		}

		$login        = 'pubcol_' . bin2hex( random_bytes( 8 ) );
		$display_name = self::generate_display_name();

		$user_id = wp_insert_user(
			[
				'user_login'   => $login,
				'user_pass'    => wp_generate_password( 32, true, true ),

				/*
				 * `.invalid` is reserved by RFC 2606 precisely so that an address
				 * can be syntactically valid and provably undeliverable. An empty
				 * address would be neither: wp_insert_user() rejects the second
				 * account that tries to use one, because email_exists() matches
				 * the first.
				 */
				'user_email'   => $login . '@public-collaboration.invalid',
				'display_name' => $display_name,
				'nickname'     => $display_name,
				// No role. Everything this account can do is granted per request.
				'role'         => '',
			]
		);

		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		if ( is_multisite() ) {
			add_user_to_blog( get_current_blog_id(), $user_id, '' );
		}

		/*
		 * Two people can open the same link in the same moment, and both get
		 * this far. add_post_meta()'s unique flag is what settles it: whichever
		 * account claims the request keeps it, and the other is deleted rather
		 * than left in the users table with nothing pointing at it.
		 */
		if ( ! add_post_meta( $this->post->ID, self::META_USER_ID, $user_id, true ) ) {
			$claimed_by = $this->get_user_id();

			if ( $claimed_by > 0 && $claimed_by !== $user_id && get_userdata( $claimed_by ) instanceof WP_User ) {
				// Nothing to reassign: this account lost the race before it was
				// ever signed in to, so it cannot have written anything.
				self::delete_user( $user_id );

				return $claimed_by;
			}

			// The stored ID points at an account that no longer exists. Take over.
			update_post_meta( $this->post->ID, self::META_USER_ID, $user_id );
		}

		update_user_meta( $user_id, self::USER_META_REQUEST_ID, $this->post->ID );

		/*
		 * Somebody who came to help with one post for a quarter of an hour does
		 * not need WordPress's tour of the editor, and it would arrive on top of
		 * the greeting that explains why they are here at all — two dialogs at
		 * once, each hiding the other from screen readers. This is the same
		 * store the editor writes preferences back to, seeded before they
		 * arrive. The key carries the blog prefix so that each site in a
		 * network keeps its own preferences; see
		 * wp_register_persisted_preferences_meta().
		 */
		global $wpdb;

		update_user_meta(
			$user_id,
			$wpdb->get_blog_prefix() . 'persisted_preferences',
			[
				'core/edit-post' => [
					'welcomeGuide' => false,
				],
				'_modified'      => gmdate( 'c' ),
			]
		);

		/**
		 * Fires after the temporary user for a collaboration request has been created.
		 *
		 * @param int     $user_id ID of the temporary user.
		 * @param WP_Post $post    The collaboration request post.
		 */
		do_action( 'public_collaboration_user_created', $user_id, $this->post );

		return $user_id;
	}

	/**
	 * Generates a display name for a collaborator.
	 *
	 * Something to address them by, and to tell two collaborators apart in the
	 * editor. They get to change it the first time they open the post.
	 *
	 * @return string The display name.
	 */
	private static function generate_display_name(): string {
		$adjectives = [
			__( 'Happy', 'public-collaboration' ),
			__( 'Clever', 'public-collaboration' ),
			__( 'Swift', 'public-collaboration' ),
			__( 'Bright', 'public-collaboration' ),
			__( 'Noble', 'public-collaboration' ),
			__( 'Wise', 'public-collaboration' ),
			__( 'Bold', 'public-collaboration' ),
			__( 'Keen', 'public-collaboration' ),
		];

		$animals = [
			__( 'Panda', 'public-collaboration' ),
			__( 'Fox', 'public-collaboration' ),
			__( 'Owl', 'public-collaboration' ),
			__( 'Dolphin', 'public-collaboration' ),
			__( 'Eagle', 'public-collaboration' ),
			__( 'Tiger', 'public-collaboration' ),
			__( 'Wolf', 'public-collaboration' ),
			__( 'Bear', 'public-collaboration' ),
		];

		return sprintf(
			/* translators: 1: Adjective, e.g. "Happy". 2: Animal, e.g. "Panda". */
			__( '%1$s %2$s', 'public-collaboration' ),
			$adjectives[ random_int( 0, \count( $adjectives ) - 1 ) ],
			$animals[ random_int( 0, \count( $animals ) - 1 ) ]
		);
	}

	/**
	 * Deletes one of the temporary accounts this plugin makes.
	 *
	 * Both calls are needed on a network, in this order. wp_delete_user() is the
	 * only one of the two that reassigns, and on multisite it does not delete
	 * the account at all — it detaches it from this site and leaves the network
	 * user behind. wpmu_delete_user() is what finally removes it, and reaching
	 * for it alone would delete every post the account authored, which includes
	 * the collaborator's revisions of the shared post: the very history the
	 * reassignment is trying to keep.
	 *
	 * @param int      $user_id  Account to delete.
	 * @param int|null $reassign Optional. User to give anything it wrote to.
	 * @return void
	 */
	private static function delete_user( int $user_id, ?int $reassign = null ): void {
		require_once ABSPATH . 'wp-admin/includes/user.php';

		wp_delete_user( $user_id, $reassign );

		if ( is_multisite() ) {
			require_once ABSPATH . 'wp-admin/includes/ms.php';

			wpmu_delete_user( $user_id );
		}
	}

	/**
	 * Permanently deletes this collaboration request, and the account it created.
	 *
	 * Anything the collaborator wrote stays where it is — by this point it
	 * belongs to the post, not to the request. Their revisions are reassigned to
	 * whoever shared the link, so that deleting the account does not take the
	 * post's own history with it.
	 *
	 * @return void
	 */
	public function delete(): void {
		/**
		 * Fires right before a collaboration request is deleted.
		 *
		 * @param WP_Post $post The collaboration request post.
		 */
		do_action( 'public_collaboration_request_deleted', $this->post );

		$user_id = $this->get_user_id();

		if ( $user_id > 0 ) {
			// Null, not 0: wp_delete_user() reads 0 as "reassign to user 0" and
			// only treats null as "there is nobody to reassign to".
			$author_id = $this->get_author_id();

			self::delete_user( $user_id, $author_id > 0 ? $author_id : null );
		}

		wp_delete_post( $this->post->ID, true );
	}
}
