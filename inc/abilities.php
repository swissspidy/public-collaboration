<?php
/**
 * Abilities API integration.
 *
 * @package PublicCollaboration
 */

declare(strict_types = 1);

namespace PublicCollaboration;

use WP_Error;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Slug of the ability category this plugin's abilities belong to.
 */
const ABILITY_CATEGORY = 'public-collaboration';

/**
 * Registers the category this plugin's abilities are filed under.
 *
 * @return void
 */
function register_ability_category(): void {
	wp_register_ability_category(
		ABILITY_CATEGORY,
		[
			'label'       => __( 'Public collaboration', 'public-collaboration' ),
			'description' => __( 'Share a post through a short-lived link that lets somebody edit it without an account, and manage the links already handed out.', 'public-collaboration' ),
		]
	);
}

/**
 * Registers what this plugin can do as abilities, for code and AI agents alike.
 *
 * Every ability is a thin wrapper around the REST endpoint that does the same
 * thing. Permission is asked of the controller's own checks, and the work is
 * done by dispatching the request internally — so there is one set of rules
 * about who may share what, and an agent is held to exactly the same ones as
 * the editor's panel. Nothing here can be done through an ability that the
 * person it is acting for could not do by clicking.
 *
 * @return void
 */
function register_abilities(): void {
	$token = [
		'type'        => 'string',
		'description' => __( 'Token identifying the collaboration link, as returned when it was created or listed.', 'public-collaboration' ),
		'pattern'     => '^[a-f0-9]{32}$',
	];

	$capabilities = [
		'type'        => 'array',
		'description' => __( 'What the collaborator is allowed to do: "edit" the post, "upload" media, both, or neither.', 'public-collaboration' ),
		'items'       => [
			'type' => 'string',
			'enum' => Collaboration_Request::get_available_capabilities(),
		],
		'uniqueItems' => true,
	];

	$label = [
		'type'        => 'string',
		'description' => __( 'Who, or what, the link is for. Only ever shown to whoever manages the post\'s links.', 'public-collaboration' ),
		'maxLength'   => Collaboration_Request::MAX_LABEL_LENGTH,
	];

	$link = get_link_output_schema();

	wp_register_ability(
		'public-collaboration/list-links',
		[
			'label'               => __( 'List collaboration links', 'public-collaboration' ),
			'description'         => __( 'Lists the collaboration links to a post that are still live: who each is for, whether anybody has joined, what each grants, and when each expires.', 'public-collaboration' ),
			'category'            => ABILITY_CATEGORY,
			'input_schema'        => [
				'type'                 => 'object',
				'properties'           => [
					'post' => [
						'type'        => 'integer',
						'description' => __( 'ID of the post whose links to list.', 'public-collaboration' ),
						'minimum'     => 1,
					],
				],
				'required'             => [ 'post' ],
				'additionalProperties' => false,
			],
			'output_schema'       => [
				'type'  => 'array',
				'items' => $link,
			],
			'permission_callback' => static fn ( $input ): bool => check_ability_permission( 'get_items', 'GET', '', $input ),
			'execute_callback'    => static fn ( $input ) => dispatch_ability( 'GET', '', $input ),
			'meta'                => [
				'annotations'  => [
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				],
				'show_in_rest' => true,
			],
		]
	);

	wp_register_ability(
		'public-collaboration/create-link',
		[
			'label'               => __( 'Create a collaboration link', 'public-collaboration' ),
			'description'         => __( 'Creates a short-lived link that lets whoever opens it edit one post without an account. Returns the URL to hand out. The link ends a quarter of an hour after the last change made through it.', 'public-collaboration' ),
			'category'            => ABILITY_CATEGORY,
			'input_schema'        => [
				'type'                 => 'object',
				'properties'           => [
					'post'         => [
						'type'        => 'integer',
						'description' => __( 'ID of the post to share.', 'public-collaboration' ),
						'minimum'     => 1,
					],
					'capabilities' => $capabilities,
					'label'        => $label,
				],
				'required'             => [ 'post' ],
				'additionalProperties' => false,
			],
			'output_schema'       => $link,
			'permission_callback' => static fn ( $input ): bool => check_ability_permission( 'create_item', 'POST', '', $input ),
			'execute_callback'    => static fn ( $input ) => dispatch_ability( 'POST', '', $input ),
			'meta'                => [
				'annotations'  => [
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => false,
				],
				'show_in_rest' => true,
			],
		]
	);

	wp_register_ability(
		'public-collaboration/update-link',
		[
			'label'               => __( 'Change a collaboration link', 'public-collaboration' ),
			'description'         => __( 'Changes what a live collaboration link grants, what it is labelled, or both. A change to what it grants takes effect on the collaborator\'s very next request.', 'public-collaboration' ),
			'category'            => ABILITY_CATEGORY,
			'input_schema'        => [
				'type'                 => 'object',
				'properties'           => [
					'token'        => $token,
					'capabilities' => $capabilities,
					'label'        => $label,
				],
				'required'             => [ 'token' ],
				'additionalProperties' => false,
			],
			'output_schema'       => $link,
			'permission_callback' => static fn ( $input ): bool => check_ability_permission( 'update_item', 'PUT', '/{token}', $input ),
			'execute_callback'    => static fn ( $input ) => dispatch_ability( 'PUT', '/{token}', $input ),
			'meta'                => [
				'annotations'  => [
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => true,
				],
				'show_in_rest' => true,
			],
		]
	);

	wp_register_ability(
		'public-collaboration/extend-link',
		[
			'label'               => __( 'Extend a collaboration link', 'public-collaboration' ),
			'description'         => __( 'Gives a live collaboration link its full idle time back, never past the ceiling on its whole life.', 'public-collaboration' ),
			'category'            => ABILITY_CATEGORY,
			'input_schema'        => [
				'type'                 => 'object',
				'properties'           => [
					'token' => $token,
				],
				'required'             => [ 'token' ],
				'additionalProperties' => false,
			],
			'output_schema'       => $link,
			'permission_callback' => static fn ( $input ): bool => check_ability_permission( 'extend_item', 'POST', '/{token}/extend', $input ),
			'execute_callback'    => static fn ( $input ) => dispatch_ability( 'POST', '/{token}/extend', $input ),
			'meta'                => [
				'annotations'  => [
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => false,
				],
				'show_in_rest' => true,
			],
		]
	);

	wp_register_ability(
		'public-collaboration/revoke-link',
		[
			'label'               => __( 'Revoke a collaboration link', 'public-collaboration' ),
			'description'         => __( 'Revokes a collaboration link at once, signing out whoever is using it and deleting their temporary account. Anything they wrote stays in the post.', 'public-collaboration' ),
			'category'            => ABILITY_CATEGORY,
			'input_schema'        => [
				'type'                 => 'object',
				'properties'           => [
					'token' => $token,
				],
				'required'             => [ 'token' ],
				'additionalProperties' => false,
			],
			'output_schema'       => [
				'type'       => 'object',
				'properties' => [
					'deleted'  => [ 'type' => 'boolean' ],
					'previous' => $link,
				],
			],
			'permission_callback' => static fn ( $input ): bool => check_ability_permission( 'delete_item', 'DELETE', '/{token}', $input ),
			'execute_callback'    => static fn ( $input ) => dispatch_ability( 'DELETE', '/{token}', $input ),
			'meta'                => [
				'annotations'  => [
					'readonly'    => false,
					'destructive' => true,
					'idempotent'  => true,
				],
				'show_in_rest' => true,
			],
		]
	);
}

/**
 * Describes one collaboration link, as the abilities return it.
 *
 * The REST schema's own properties, so that the two cannot drift apart.
 *
 * @return array JSON Schema.
 *
 * @phpstan-return array<string, mixed>
 */
function get_link_output_schema(): array {
	$schema = ( new REST_Collaboration_Requests_Controller() )->get_item_schema();

	return [
		'type'       => 'object',
		'properties' => $schema['properties'] ?? [],
	];
}

/**
 * Builds the REST request an ability stands in for.
 *
 * @param string $method HTTP method.
 * @param string $route  Route below the collection, with `{token}` standing in for the token.
 * @param mixed  $input  The ability's input.
 * @return WP_REST_Request The request.
 */
function build_ability_request( string $method, string $route, $input ): WP_REST_Request {
	$params = \is_array( $input ) ? $input : [];
	$token  = isset( $params['token'] ) && \is_string( $params['token'] ) ? $params['token'] : '';

	unset( $params['token'] );

	$request = new WP_REST_Request(
		$method,
		'/public-collaboration/v1/collaboration-requests' . str_replace( '{token}', $token, $route )
	);

	if ( '' !== $token ) {
		$request->set_url_params( [ 'token' => $token ] );
	}

	if ( 'GET' === $method ) {
		$request->set_query_params( $params );
	} else {
		$request->set_body_params( $params );
	}

	return $request;
}

/**
 * Asks the REST controller whether the current user may do what an ability does.
 *
 * Only a yes or a no: the Abilities API treats an error here as somebody
 * calling it wrong, and the reason a request was refused is the endpoint's to
 * give when it is actually made.
 *
 * @param string $action Controller action, e.g. `create_item`.
 * @param string $method HTTP method.
 * @param string $route  Route below the collection.
 * @param mixed  $input  The ability's input.
 * @return bool Whether the current user may go ahead.
 */
function check_ability_permission( string $action, string $method, string $route, $input ): bool {
	$controller = new REST_Collaboration_Requests_Controller();
	$check      = [ $controller, "{$action}_permissions_check" ];

	if ( ! \is_callable( $check ) ) {
		return false;
	}

	return true === \call_user_func( $check, build_ability_request( $method, $route, $input ) );
}

/**
 * Does what an ability does, by making the REST request it stands in for.
 *
 * @param string $method HTTP method.
 * @param string $route  Route below the collection.
 * @param mixed  $input  The ability's input.
 * @return mixed|WP_Error The response data, or the error the endpoint gave.
 */
function dispatch_ability( string $method, string $route, $input ) {
	$response = rest_do_request( build_ability_request( $method, $route, $input ) );

	if ( $response->is_error() ) {
		return $response->as_error();
	}

	return $response->get_data();
}
