<?php
/**
 * Class REST_Collaboration_Requests_Controller.
 *
 * @package PublicCollaboration
 */

declare(strict_types = 1);

namespace PublicCollaboration;

use WP_Error;
use WP_Post;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_User;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST API controller for collaboration requests.
 *
 * Lives in this plugin's own namespace rather than extending the core posts
 * controller. A collaboration request is not a post anybody edits — it is a
 * token with an expiry date, and the four things you can do with one (mint it,
 * watch it, change what it grants, revoke it) are not the ones
 * `WP_REST_Posts_Controller` exists to offer.
 */
class REST_Collaboration_Requests_Controller extends WP_REST_Controller {
	/**
	 * Namespace these routes are registered under.
	 */
	private const REST_NAMESPACE = 'public-collaboration/v1';

	/**
	 * Base of the collaboration request routes.
	 */
	private const REST_BASE = 'collaboration-requests';

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->namespace = self::REST_NAMESPACE;
		$this->rest_base = self::REST_BASE;
	}

	/**
	 * Registers the routes for collaboration requests.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/' . self::REST_BASE,
			[
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'create_item' ],
					'permission_callback' => [ $this, 'create_item_permissions_check' ],
					'args'                => [
						'post'         => [
							'description' => __( 'ID of the post to collaborate on.', 'public-collaboration' ),
							'type'        => 'integer',
							'minimum'     => 1,
							'required'    => true,
						],
						'capabilities' => [
							'description' => __( 'What the collaborator is allowed to do.', 'public-collaboration' ),
							'type'        => 'array',
							'items'       => [
								'type' => 'string',
								'enum' => [ Collaboration_Request::CAP_EDIT, Collaboration_Request::CAP_UPLOAD ],
							],
							'default'     => [ Collaboration_Request::CAP_EDIT, Collaboration_Request::CAP_UPLOAD ],
						],
					],
				],
				'schema' => [ $this, 'get_public_item_schema' ],
			]
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/' . self::REST_BASE . '/(?P<token>[a-f0-9]{32})',
			[
				'args'   => [
					'token' => [
						'description' => __( 'Unique token identifying the collaboration request.', 'public-collaboration' ),
						'type'        => 'string',
					],
				],
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_item' ],
					'permission_callback' => [ $this, 'get_item_permissions_check' ],
				],
				[
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => [ $this, 'update_item' ],
					'permission_callback' => [ $this, 'update_item_permissions_check' ],
					'args'                => [
						'capabilities' => [
							'description' => __( 'What the collaborator is allowed to do.', 'public-collaboration' ),
							'type'        => 'array',
							'items'       => [
								'type' => 'string',
								'enum' => [ Collaboration_Request::CAP_EDIT, Collaboration_Request::CAP_UPLOAD ],
							],
							'required'    => true,
						],
					],
				],
				[
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => [ $this, 'delete_item' ],
					'permission_callback' => [ $this, 'delete_item_permissions_check' ],
				],
				'schema' => [ $this, 'get_public_item_schema' ],
			]
		);
	}

	/**
	 * Checks whether the current user may share a given post.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return true|WP_Error True if the request has access, WP_Error object otherwise.
	 */
	public function create_item_permissions_check( $request ) {
		$post_id = $this->get_post_id( $request );
		$post    = get_post( $post_id );

		if ( ! $post instanceof WP_Post ) {
			return new WP_Error(
				'public_collaboration_invalid_post',
				__( 'That post could not be found.', 'public-collaboration' ),
				[ 'status' => 404 ]
			);
		}

		if ( $this->is_collaborator() || ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error(
				'public_collaboration_cannot_share',
				__( 'Sorry, you are not allowed to share this post for collaboration.', 'public-collaboration' ),
				[ 'status' => rest_authorization_required_code() ]
			);
		}

		return $this->check_capabilities( $this->get_capabilities( $request ) );
	}

	/**
	 * Creates a new collaboration request.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error Response object on success, error object on failure.
	 */
	public function create_item( $request ) {
		$collaboration_request = Collaboration_Request::create(
			[
				'post'         => $this->get_post_id( $request ),
				'capabilities' => $this->get_capabilities( $request ),
			]
		);

		if ( is_wp_error( $collaboration_request ) ) {
			return $collaboration_request;
		}

		$response = $this->prepare_item_for_response( $collaboration_request, $request );
		$response->set_status( 201 );

		return $response;
	}

	/**
	 * Checks whether the current user may read a given collaboration request.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return true|WP_Error True if the request has access, WP_Error object otherwise.
	 */
	public function get_item_permissions_check( $request ) {
		return $this->check_owner_permission( $this->get_token( $request ) );
	}

	/**
	 * Returns the state of a collaboration request, including whether anyone has joined.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error Response object on success, error object on failure.
	 */
	public function get_item( $request ) {
		$collaboration_request = Collaboration_Request::get_by_token( $this->get_token( $request ) );

		if ( ! $collaboration_request instanceof Collaboration_Request ) {
			return $this->not_found_error();
		}

		return $this->prepare_item_for_response( $collaboration_request, $request );
	}

	/**
	 * Checks whether the current user may change what a collaboration request grants.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return true|WP_Error True if the request has access, WP_Error object otherwise.
	 */
	public function update_item_permissions_check( $request ) {
		$permission = $this->check_owner_permission( $this->get_token( $request ) );

		if ( is_wp_error( $permission ) ) {
			return $permission;
		}

		return $this->check_capabilities( $this->get_capabilities( $request ) );
	}

	/**
	 * Changes what a collaboration request grants.
	 *
	 * Takes effect on the collaborator's next request: nothing is copied onto
	 * their account, so there is nothing left to revoke separately.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error Response object on success, error object on failure.
	 */
	public function update_item( $request ) {
		$collaboration_request = Collaboration_Request::get_by_token( $this->get_token( $request ) );

		if ( ! $collaboration_request instanceof Collaboration_Request ) {
			return $this->not_found_error();
		}

		$collaboration_request->set_capabilities( $this->get_capabilities( $request ) );

		return $this->prepare_item_for_response( $collaboration_request, $request );
	}

	/**
	 * Checks whether the current user may revoke a given collaboration request.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return true|WP_Error True if the request has access, WP_Error object otherwise.
	 */
	public function delete_item_permissions_check( $request ) {
		return $this->check_owner_permission( $this->get_token( $request ) );
	}

	/**
	 * Revokes a collaboration request, ending the session immediately.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error Response object on success, error object on failure.
	 */
	public function delete_item( $request ) {
		$collaboration_request = Collaboration_Request::get_by_token( $this->get_token( $request ) );

		if ( ! $collaboration_request instanceof Collaboration_Request ) {
			return $this->not_found_error();
		}

		$previous = $this->prepare_item_for_response( $collaboration_request, $request )->get_data();

		$collaboration_request->delete();

		return rest_ensure_response(
			[
				'deleted'  => true,
				'previous' => $previous,
			]
		);
	}

	/**
	 * Reads the post ID off a request.
	 *
	 * Request parameters arrive as whatever was sent; the schema is what narrows
	 * them, and these three readers are where that narrowing is spelled out for
	 * the rest of the class.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return int Post ID, or 0 if the parameter was not a number.
	 */
	private function get_post_id( WP_REST_Request $request ): int {
		$post_id = $request['post'];

		return is_numeric( $post_id ) ? (int) $post_id : 0;
	}

	/**
	 * Reads the collaboration request token off a request.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return string The token, or an empty string if the parameter was not one.
	 */
	private function get_token( WP_REST_Request $request ): string {
		$token = $request['token'];

		return is_string( $token ) ? $token : '';
	}

	/**
	 * Reads the requested capabilities off a request.
	 *
	 * Anything that is not a string is dropped rather than coerced: the names
	 * this plugin knows are all strings, so a value of any other shape can only
	 * be something it was never going to grant.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return string[] Requested capabilities.
	 */
	private function get_capabilities( WP_REST_Request $request ): array {
		$capabilities = $request['capabilities'];

		if ( ! is_array( $capabilities ) ) {
			return [];
		}

		return array_values( array_filter( $capabilities, 'is_string' ) );
	}

	/**
	 * Checks that the current user may hand out the capabilities being asked for.
	 *
	 * Sharing a link is not a way to give away more than you have yourself.
	 *
	 * @param string[] $capabilities Requested capabilities.
	 * @return true|WP_Error True if the capabilities may be granted, WP_Error object otherwise.
	 */
	private function check_capabilities( array $capabilities ) {
		if ( \in_array( Collaboration_Request::CAP_UPLOAD, $capabilities, true ) && ! current_user_can( 'upload_files' ) ) {
			return new WP_Error(
				'public_collaboration_cannot_share_uploads',
				__( 'Sorry, you are not allowed to let others upload media on this site.', 'public-collaboration' ),
				[ 'status' => rest_authorization_required_code() ]
			);
		}

		return true;
	}

	/**
	 * Whether the current user is here on a collaboration link themselves.
	 *
	 * Being able to edit the post is what decides who may manage its links, and
	 * a collaborator can edit the post — that is the whole point of the link
	 * they followed. Without this they could use the invitation they were given
	 * to mint more, and go on doing so long after the fifteen minutes they were
	 * lent were up.
	 *
	 * @return bool True if the current user is a collaborator.
	 */
	private function is_collaborator(): bool {
		return Collaboration_Request::get_for_user( get_current_user_id() ) instanceof Collaboration_Request;
	}

	/**
	 * Checks whether the current user owns, or may administer, a given collaboration request.
	 *
	 * @param string $token The collaboration request token.
	 * @return true|WP_Error True if the request has access, WP_Error object otherwise.
	 */
	private function check_owner_permission( string $token ) {
		$collaboration_request = Collaboration_Request::get_by_token( $token );

		if ( ! $collaboration_request instanceof Collaboration_Request ) {
			return $this->not_found_error();
		}

		$user_id = get_current_user_id();

		if ( $user_id > 0 && $user_id === $collaboration_request->get_author_id() ) {
			return true;
		}

		if ( $this->is_collaborator() ) {
			return $this->not_found_error();
		}

		/*
		 * Scoped to the shared post, not the site-wide `edit_others_posts`:
		 * whoever may edit the post may manage the links to it, which is the
		 * same rule create_item_permissions_check() applies when one is made.
		 */
		$parent_id = $collaboration_request->get_parent_id();

		if ( $parent_id > 0 && current_user_can( 'edit_post', $parent_id ) ) {
			return true;
		}

		return $this->not_found_error();
	}

	/**
	 * Returns the error used for unknown, expired, and inaccessible requests alike.
	 *
	 * Deliberately indistinguishable, so that the endpoint cannot be used to
	 * probe which tokens exist.
	 *
	 * @return WP_Error The error.
	 */
	private function not_found_error(): WP_Error {
		return new WP_Error(
			'public_collaboration_invalid_token',
			__( 'This collaboration link is invalid or has expired.', 'public-collaboration' ),
			[ 'status' => 404 ]
		);
	}

	/**
	 * Prepares a collaboration request for response.
	 *
	 * @param Collaboration_Request $item    Collaboration request.
	 * @param WP_REST_Request       $request Full details about the request.
	 * @return WP_REST_Response Response object.
	 */
	public function prepare_item_for_response( $item, $request ): WP_REST_Response {
		$user = $item->get_user_id() > 0 ? get_userdata( $item->get_user_id() ) : false;

		$data = [
			'token'        => $item->get_token(),
			'url'          => $item->get_url(),
			'post'         => $item->get_parent_id(),
			'expires_at'   => $item->get_expires_at(),
			'capabilities' => $item->get_capabilities(),
			'joined'       => $user instanceof WP_User,
			'collaborator' => $user instanceof WP_User ? $user->display_name : null,
		];

		// Constructed directly rather than through rest_ensure_response(), which
		// is documented as also returning WP_Error and would widen the signature.
		return new WP_REST_Response( $data );
	}

	/**
	 * Retrieves the collaboration request schema, conforming to JSON Schema.
	 *
	 * @return array Item schema data.
	 *
	 * @phpstan-return array<string, mixed>
	 */
	public function get_item_schema(): array {
		if ( ! empty( $this->schema ) ) {
			return $this->add_additional_fields_schema( $this->schema );
		}

		$this->schema = [
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'collaboration-request',
			'type'       => 'object',
			'properties' => [
				'token'        => [
					'description' => __( 'Unique token identifying the collaboration request.', 'public-collaboration' ),
					'type'        => 'string',
					'context'     => [ 'view', 'edit' ],
					'readonly'    => true,
				],
				'url'          => [
					'description' => __( 'URL to share with the collaborator.', 'public-collaboration' ),
					'type'        => 'string',
					'format'      => 'uri',
					'context'     => [ 'view', 'edit' ],
					'readonly'    => true,
				],
				'post'         => [
					'description' => __( 'ID of the post being collaborated on.', 'public-collaboration' ),
					'type'        => 'integer',
					'context'     => [ 'view', 'edit' ],
					'readonly'    => true,
				],
				'expires_at'   => [
					'description' => __( 'Unix timestamp at which the collaboration request expires.', 'public-collaboration' ),
					'type'        => 'integer',
					'context'     => [ 'view', 'edit' ],
					'readonly'    => true,
				],
				'capabilities' => [
					'description' => __( 'What the collaborator is allowed to do.', 'public-collaboration' ),
					'type'        => 'array',
					'items'       => [
						'type' => 'string',
						'enum' => [ Collaboration_Request::CAP_EDIT, Collaboration_Request::CAP_UPLOAD ],
					],
					'context'     => [ 'view', 'edit' ],
				],
				'joined'       => [
					'description' => __( 'Whether somebody has followed the link.', 'public-collaboration' ),
					'type'        => 'boolean',
					'context'     => [ 'view', 'edit' ],
					'readonly'    => true,
				],
				'collaborator' => [
					'description' => __( 'Display name of the collaborator, once they have joined.', 'public-collaboration' ),
					'type'        => [ 'string', 'null' ],
					'context'     => [ 'view', 'edit' ],
					'readonly'    => true,
				],
			],
		];

		return $this->add_additional_fields_schema( $this->schema );
	}
}
