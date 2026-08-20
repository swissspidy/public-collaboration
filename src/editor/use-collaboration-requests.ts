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

/** How often to ask the server what is still live, in milliseconds. */
const POLL_INTERVAL = 5000;

const DEFAULT_CAPABILITIES: CollaborationCapability[] = [ 'edit', 'upload' ];

/** Which link the dialog is showing, and how it came to be showing it. */
interface OpenLink {
	/** Token of the link on screen. */
	token: string;
	/** Whether it was just minted, and so can still be taken back. */
	isNew: boolean;
}

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
 * Manages the collaboration links to the post being edited.
 *
 * The list is the server's, not the browser's memory of what it happened to
 * mint: links outlive the dialog that shows them, so after a reload — or after
 * the panel has simply been collapsed and opened again — whoever shared them is
 * still the person who can take them back.
 */
export function useCollaborationRequests() {
	const [ requests, setRequests ] = useState< CollaborationRequest[] >( [] );
	const [ open, setOpen ] = useState< OpenLink | null >( null );
	const [ isCreating, setIsCreating ] = useState( false );
	const [ revoking, setRevoking ] = useState< string[] >( [] );

	const { createErrorNotice } = useDispatch( noticesStore );

	const { postId, isNew } = useSelect( ( select ) => {
		const store = select( editorStore );

		return {
			postId: store.getCurrentPostId(),
			isNew: store.isEditedPostNew(),
		};
	}, [] );

	/** The post to share, once it is one the server can be asked about. */
	const sharedPostId =
		! isNew && typeof postId === 'number' && postId > 0 ? postId : null;

	// Kept in refs so that the poll can read the current state without being
	// torn down and set up again every time any of it changes.
	const requestsRef = useRef( requests );
	requestsRef.current = requests;

	const openRef = useRef< OpenLink | null >( null );
	openRef.current = open;

	const revokingRef = useRef< string[] >( [] );

	/*
	 * How many times the list has been changed from here. A poll that was
	 * already on its way when a link was minted or revoked is answering a
	 * question about a list that no longer exists, and putting back the link it
	 * does not yet know about — or the one it does not know is gone — would
	 * undo what somebody has just done.
	 */
	const changes = useRef( 0 );

	/*
	 * What each link's capability updates are up to: whatever is in flight for
	 * it, which update is the latest one, and whether it has one at all.
	 */
	const updates = useRef( {
		queues: new Map< string, Promise< unknown > >(),
		latest: new Map< string, number >(),
		inFlight: new Set< string >(),
	} );

	/** Puts a list on screen, and where the callbacks can see it. */
	const applyRequests = useCallback( ( next: CollaborationRequest[] ) => {
		requestsRef.current = next;
		setRequests( next );
	}, [] );

	// Only worth asking again and again while there is something to watch: who
	// has turned up, and how much of the quarter of an hour each link has left.
	const isWatching = requests.length > 0;

	useEffect( () => {
		if ( null === sharedPostId ) {
			applyRequests( [] );
			setOpen( null );

			return;
		}

		let cancelled = false;

		const read = async () => {
			const asked = changes.current;
			let live: CollaborationRequest[];

			try {
				live = await apiFetch< CollaborationRequest[] >( {
					path: `${ REST_BASE }?post=${ sharedPostId }`,
				} );
			} catch {
				/*
				 * A blip says nothing about which links are live. Emptying the
				 * list over one would take away the only way to revoke links
				 * that are still working for everybody holding them.
				 */
				return;
			}

			if ( cancelled || asked !== changes.current ) {
				return;
			}

			/*
			 * A toggle that has not landed yet keeps what is on screen: the
			 * server's answer was true when it was asked, and putting it back
			 * would flip a switch the person has only just moved.
			 */
			applyRequests(
				live.map( ( item ) => {
					if ( ! updates.current.inFlight.has( item.token ) ) {
						return item;
					}

					const pending = requestsRef.current.find(
						( { token } ) => token === item.token
					);

					return pending
						? { ...item, capabilities: pending.capabilities }
						: item;
				} )
			);

			const showing = openRef.current;

			// A link that has gone while its dialog is open has run out of
			// time — revoking is the one other way for it to go, and that
			// closes the dialog itself.
			if (
				showing &&
				! live.some( ( { token } ) => token === showing.token ) &&
				! revokingRef.current.includes( showing.token )
			) {
				setOpen( null );

				void createErrorNotice(
					__(
						'The collaboration link expired.',
						'public-collaboration'
					),
					{ type: 'snackbar' }
				);
			}
		};

		void read();

		const interval = isWatching
			? setInterval( read, POLL_INTERVAL )
			: undefined;

		return () => {
			cancelled = true;
			clearInterval( interval );
		};
	}, [ sharedPostId, isWatching, applyRequests, createErrorNotice ] );

	const create = useCallback( async () => {
		if ( null === sharedPostId ) {
			return;
		}

		setIsCreating( true );

		try {
			const created = await apiFetch< CollaborationRequest >( {
				path: REST_BASE,
				method: 'POST',
				data: {
					post: sharedPostId,
					capabilities: DEFAULT_CAPABILITIES,
				},
			} );

			changes.current += 1;

			applyRequests( [ ...requestsRef.current, created ] );
			setOpen( { token: created.token, isNew: true } );
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
	}, [ applyRequests, createErrorNotice, sharedPostId ] );

	/*
	 * A link only leaves the list once the server says it is gone. Dropping it
	 * on a failed request would take away the way back to a link that is still
	 * live for everybody who has it.
	 */
	const revoke = useCallback(
		async ( token: string ) => {
			revokingRef.current = [ ...revokingRef.current, token ];
			setRevoking( revokingRef.current );

			let revoked: boolean;

			try {
				await apiFetch( {
					path: `${ REST_BASE }/${ token }`,
					method: 'DELETE',
				} );

				revoked = true;
			} catch ( error ) {
				// A 404 counts as gone: the link had already expired or been
				// cleaned up, which is the outcome being asked for.
				revoked = isNotFound( error );
			}

			revokingRef.current = revokingRef.current.filter(
				( item ) => item !== token
			);
			setRevoking( revokingRef.current );

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

			changes.current += 1;

			updates.current.queues.delete( token );
			updates.current.latest.delete( token );
			updates.current.inFlight.delete( token );

			applyRequests(
				requestsRef.current.filter( ( item ) => item.token !== token )
			);
			setOpen( ( showing ) =>
				showing?.token === token ? null : showing
			);
		},
		[ applyRequests, createErrorNotice ]
	);

	/*
	 * Toggling changes the live link rather than the next one. The server is
	 * the only thing that decides what a collaborator may do, and it decides it
	 * again on every request they make — so a switch turned off here takes
	 * effect on the page they are looking at, without revoking anything.
	 *
	 * Each update to a link waits for the one before it. Two quick clicks each
	 * send the whole list, so letting them race would let the earlier one land
	 * last and put back what the later one took away.
	 */
	const setCapability = useCallback(
		(
			token: string,
			capability: CollaborationCapability,
			enabled: boolean
		) => {
			const current = requestsRef.current.find(
				( item ) => item.token === token
			);

			if ( ! current ) {
				return;
			}

			const previous = current.capabilities;
			const next = enabled
				? [ ...new Set( [ ...previous, capability ] ) ]
				: previous.filter( ( item ) => item !== capability );

			const apply = ( capabilities: CollaborationCapability[] ) =>
				applyRequests(
					requestsRef.current.map( ( item ) =>
						item.token === token ? { ...item, capabilities } : item
					)
				);

			apply( next );

			const update = ( updates.current.latest.get( token ) ?? 0 ) + 1;

			updates.current.latest.set( token, update );
			updates.current.inFlight.add( token );

			const queue = (
				updates.current.queues.get( token ) ?? Promise.resolve()
			)
				.catch( () => {} )
				.then( async () => {
					try {
						const saved = await apiFetch< CollaborationRequest >( {
							path: `${ REST_BASE }/${ token }`,
							method: 'PUT',
							data: { capabilities: next },
						} );

						/*
						 * Unless somebody has flipped something else since:
						 * every update sends the whole list, so the one behind
						 * this in the queue is what the server will end up
						 * with, and what it said about this one is already out
						 * of date on screen.
						 */
						if ( update === updates.current.latest.get( token ) ) {
							apply( saved.capabilities );
						}
					} catch ( error ) {
						// Likewise: putting the switches back to what they were
						// before this update would throw away a choice made
						// after it, and leave what is on screen disagreeing
						// with what the server was told.
						if ( update !== updates.current.latest.get( token ) ) {
							return;
						}

						apply( previous );

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
					} finally {
						if ( update === updates.current.latest.get( token ) ) {
							updates.current.inFlight.delete( token );
						}
					}
				} );

			updates.current.queues.set( token, queue );
		},
		[ applyRequests, createErrorNotice ]
	);

	const show = useCallback( ( token: string ) => {
		setOpen( { token, isNew: false } );
	}, [] );

	const close = useCallback( () => {
		setOpen( null );
	}, [] );

	return {
		/** Every link to this post that is still live, oldest first. */
		requests,
		/** The link the dialog is showing, if it is showing one. */
		openRequest:
			requests.find( ( item ) => item.token === open?.token ) ?? null,
		/** Whether that link was just minted, and can still be taken back. */
		isNewLink: true === open?.isNew,
		isCreating,
		/** Tokens of the links being revoked right now. */
		revoking,
		/** Whether the post has been saved at least once, so it has something to share. */
		canShare: null !== sharedPostId,
		create,
		revoke,
		show,
		close,
		setCapability,
	};
}
