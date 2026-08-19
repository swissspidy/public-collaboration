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
 * Determines whether a rejection was the server saying the link is not there.
 *
 * The distinction matters: a 404 is the server confirming a link is gone, while
 * a timeout or a 500 says nothing about whether it is still live.
 *
 * @param error The rejection value.
 */
function isNotFound( error: unknown ): boolean {
	return (
		!! error &&
		typeof error === 'object' &&
		'data' in error &&
		!! ( error as { data?: { status?: number } } ).data &&
		404 === ( error as { data: { status?: number } } ).data.status
	);
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
	const [ isRevoking, setIsRevoking ] = useState( false );
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

	// Likewise, so that a capability change can be worked out from the latest
	// value without the callback being rebuilt on every toggle.
	const capabilitiesRef = useRef( capabilities );
	capabilitiesRef.current = capabilities;

	/** Whatever capability update is currently in flight. */
	const pendingRef = useRef< Promise< unknown > >( Promise.resolve() );

	// Which toggle is the current one. A queued update that something newer has
	// already superseded must not put back what that newer one changed.
	const toggleRef = useRef( 0 );

	/**
	 * Asks the server to revoke a link, reporting whether it is now gone.
	 *
	 * A 404 counts as gone: the link had already expired or been cleaned up,
	 * which is the outcome being asked for.
	 */
	const revoke = useCallback(
		async ( revokedToken: string ): Promise< boolean > => {
			try {
				await apiFetch( {
					path: `${ REST_BASE }/${ revokedToken }`,
					method: 'DELETE',
				} );

				return true;
			} catch ( error ) {
				return isNotFound( error );
			}
		},
		[]
	);

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

	/*
	 * The dialog closes only once the server says the link is gone. Closing it
	 * on a failed request would take the URL away from the one person able to
	 * try again, while leaving the link working for everybody who has it.
	 */
	const close = useCallback( async () => {
		const revokedToken = tokenRef.current;

		if ( ! revokedToken ) {
			setRequest( null );

			return;
		}

		setIsRevoking( true );

		const revoked = await revoke( revokedToken );

		setIsRevoking( false );

		if ( ! revoked ) {
			void createErrorNotice(
				__(
					'The collaboration link could not be revoked, and is still live. Please try again.',
					'public-collaboration'
				),
				{ type: 'snackbar' }
			);

			return;
		}

		setRequest( null );
		setCapabilities( DEFAULT_CAPABILITIES );

		void createSuccessNotice(
			__( 'Collaboration link revoked.', 'public-collaboration' ),
			{ type: 'snackbar' }
		);
	}, [ createErrorNotice, createSuccessNotice, revoke ] );

	/*
	 * Toggling a checkbox changes the live link rather than the next one. The
	 * server is the only thing that decides what a collaborator may do, and it
	 * decides it again on every request they make — so a box unticked here takes
	 * effect on the page they are looking at, without revoking anything.
	 *
	 * Each update waits for the one before it. Two quick clicks each send the
	 * whole list, so letting them race would let the earlier one land last and
	 * put back what the later one took away.
	 */
	const toggleCapability = useCallback(
		( capability: CollaborationCapability, enabled: boolean ) => {
			const previous = capabilitiesRef.current;
			const next = enabled
				? [ ...new Set( [ ...previous, capability ] ) ]
				: previous.filter( ( item ) => item !== capability );

			setCapabilities( next );
			capabilitiesRef.current = next;

			const toggle = ++toggleRef.current;

			pendingRef.current = pendingRef.current
				.catch( () => {} )
				.then( async () => {
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
						/*
						 * Somebody has ticked something else since. Every update
						 * sends the whole list, so the one behind this in the
						 * queue will put the server right — undoing the boxes
						 * back to what they were before this update would throw
						 * away a choice made after it, and leave what is on
						 * screen disagreeing with what the server was told.
						 */
						if ( toggle !== toggleRef.current ) {
							return;
						}

						setCapabilities( previous );
						capabilitiesRef.current = previous;

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
				} );
		},
		[ createErrorNotice ]
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
			} catch ( error ) {
				/*
				 * Only a 404 means the link is gone. Anything else — a blip, a
				 * server error — says nothing about whether it is still live,
				 * and closing the dialog over one would take the URL away while
				 * leaving the link working.
				 */
				if ( ! cancelled && isNotFound( error ) ) {
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
			if ( tokenRef.current ) {
				void revoke( tokenRef.current );
			}
		},
		[ revoke ]
	);

	return {
		request,
		capabilities,
		isCreating,
		isRevoking,
		/** Whether the post has been saved at least once, so it has something to share. */
		canShare: ! isNew && typeof postId === 'number' && postId > 0,
		create,
		close,
		toggleCapability,
	};
}
