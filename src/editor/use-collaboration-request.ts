/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { useDispatch, useSelect } from '@wordpress/data';
import { store as editorStore } from '@wordpress/editor';
import { store as noticesStore } from '@wordpress/notices';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { CollaborationCapability, CollaborationRequest } from './types';

const REST_BASE = '/public-collaboration/v1/collaboration-requests';

/** How often to ask the server whether anybody has turned up, in milliseconds. */
const POLL_INTERVAL = 5000;

const DEFAULT_CAPABILITIES: CollaborationCapability[] = [ 'edit', 'upload' ];

/**
 * Turns an unknown rejection into something worth showing a person.
 *
 * A rejected `apiFetch` is not always an Error: a REST error arrives as a plain
 * object, and a custom fetch handler can reject with anything at all.
 *
 * @param error    The rejection value.
 * @param fallback Message to use when the rejection carries none.
 */
function getErrorMessage( error: unknown, fallback: string ): string {
	if ( error instanceof Error && error.message ) {
		return error.message;
	}

	if (
		error &&
		typeof error === 'object' &&
		'message' in error &&
		typeof ( error as { message: unknown } ).message === 'string' &&
		( error as { message: string } ).message
	) {
		return ( error as { message: string } ).message;
	}

	return fallback;
}

/**
 * Manages the lifecycle of a single collaboration request.
 *
 * Mints the link, watches for somebody to turn up, keeps what it grants in step
 * with the checkboxes, and makes sure it is revoked afterwards — including when
 * the editor is closed with the dialog still open.
 */
export function useCollaborationRequest() {
	const [ request, setRequest ] = useState< CollaborationRequest | null >(
		null
	);
	const [ isCreating, setIsCreating ] = useState( false );
	const [ capabilities, setCapabilities ] =
		useState< CollaborationCapability[] >( DEFAULT_CAPABILITIES );

	const { createErrorNotice, createSuccessNotice } =
		useDispatch( noticesStore );

	const { postId, isNew } = useSelect( ( select ) => {
		const store = select( editorStore );

		return {
			postId: store.getCurrentPostId(),
			isNew: store.isEditedPostNew(),
		};
	}, [] );

	const token = request?.token ?? null;

	// Kept in a ref so that the unmount cleanup can revoke the link without
	// re-running every time the request object changes.
	const tokenRef = useRef< string | null >( null );
	tokenRef.current = token;

	const revoke = useCallback( async ( revokedToken: string | null ) => {
		if ( ! revokedToken ) {
			return;
		}

		try {
			await apiFetch( {
				path: `${ REST_BASE }/${ revokedToken }`,
				method: 'DELETE',
			} );
		} catch {
			// The link expires on its own soon enough; nothing useful to say here.
		}
	}, [] );

	const create = useCallback( async () => {
		setIsCreating( true );

		try {
			setRequest(
				await apiFetch< CollaborationRequest >( {
					path: REST_BASE,
					method: 'POST',
					data: { post: postId, capabilities },
				} )
			);
		} catch ( error ) {
			void createErrorNotice(
				getErrorMessage(
					error,
					__(
						'The collaboration link could not be created. Please try again.',
						'public-collaboration'
					)
				),
				{ type: 'snackbar' }
			);
		} finally {
			setIsCreating( false );
		}
	}, [ capabilities, createErrorNotice, postId ] );

	const close = useCallback( () => {
		void revoke( tokenRef.current );
		setRequest( null );
		setCapabilities( DEFAULT_CAPABILITIES );

		void createSuccessNotice(
			__( 'Collaboration link revoked.', 'public-collaboration' ),
			{ type: 'snackbar' }
		);
	}, [ createSuccessNotice, revoke ] );

	/*
	 * Toggling a checkbox changes the live link rather than the next one. The
	 * server is the only thing that decides what a collaborator may do, and it
	 * decides it again on every request they make — so a box unticked here takes
	 * effect on the page they are looking at, without revoking anything.
	 */
	const toggleCapability = useCallback(
		async ( capability: CollaborationCapability, enabled: boolean ) => {
			const next = enabled
				? [ ...new Set( [ ...capabilities, capability ] ) ]
				: capabilities.filter( ( item ) => item !== capability );

			setCapabilities( next );

			if ( ! tokenRef.current ) {
				return;
			}

			try {
				setRequest(
					await apiFetch< CollaborationRequest >( {
						path: `${ REST_BASE }/${ tokenRef.current }`,
						method: 'PUT',
						data: { capabilities: next },
					} )
				);
			} catch ( error ) {
				setCapabilities( capabilities );

				void createErrorNotice(
					getErrorMessage(
						error,
						__(
							'What the collaborator is allowed to do could not be changed.',
							'public-collaboration'
						)
					),
					{ type: 'snackbar' }
				);
			}
		},
		[ capabilities, createErrorNotice ]
	);

	// Watch for somebody turning up, and for the link going stale.
	useEffect( () => {
		if ( ! token ) {
			return;
		}

		let cancelled = false;

		const interval = setInterval( async () => {
			let current: CollaborationRequest;

			try {
				current = await apiFetch< CollaborationRequest >( {
					path: `${ REST_BASE }/${ token }`,
				} );
			} catch {
				// A 404 means the link expired or was cleaned up server-side.
				if ( ! cancelled ) {
					setRequest( null );
					void createErrorNotice(
						__(
							'The collaboration link expired.',
							'public-collaboration'
						),
						{ type: 'snackbar' }
					);
				}
				return;
			}

			if ( ! cancelled ) {
				setRequest( current );
			}
		}, POLL_INTERVAL );

		return () => {
			cancelled = true;
			clearInterval( interval );
		};
	}, [ token, createErrorNotice ] );

	// Expire the link in the UI at the moment the server would refuse it.
	useEffect( () => {
		if ( ! request ) {
			return;
		}

		const remaining = request.expires_at * 1000 - Date.now();

		const timeout = setTimeout(
			() => {
				setRequest( null );
				void createErrorNotice(
					__(
						'The collaboration link expired.',
						'public-collaboration'
					),
					{ type: 'snackbar' }
				);
			},
			Math.max( 0, remaining )
		);

		return () => clearTimeout( timeout );
	}, [ request, createErrorNotice ] );

	// Never leave a working link behind when the editor moves on.
	useEffect(
		() => () => {
			void revoke( tokenRef.current );
		},
		[ revoke ]
	);

	return {
		request,
		capabilities,
		isCreating,
		/** Whether the post has been saved at least once, so it has something to share. */
		canShare: ! isNew && typeof postId === 'number' && postId > 0,
		create,
		close,
		toggleCapability,
	};
}
