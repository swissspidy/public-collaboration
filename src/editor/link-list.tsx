/**
 * WordPress dependencies
 */
import {
	Button,
	__experimentalHStack as HStack, // eslint-disable-line @wordpress/no-unsafe-wp-apis
	__experimentalText as Text, // eslint-disable-line @wordpress/no-unsafe-wp-apis
	__experimentalVStack as VStack, // eslint-disable-line @wordpress/no-unsafe-wp-apis
} from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { closeSmall } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import { getTimeLeft, isActive, isEnding } from '../expiry';
import { getSettings } from './settings';
import type { CollaborationRequest } from './types';
import './editor.css';

interface LinkListProps {
	/** The links to this post that are still live. */
	requests: CollaborationRequest[];
	/** Tokens of the links being revoked right now. */
	revoking: string[];
	/** Called to show a link again. */
	onShow: ( token: string ) => void;
	/** Called to revoke a link. */
	onRevoke: ( token: string ) => void;
}

/**
 * Says what a link is doing, for the line under its name.
 *
 * A countdown is the useful thing to say about a link nobody is using, and the
 * wrong thing to say about one somebody is working on: it slides forward with
 * every change they make, so a number ticking down would be describing a moment
 * that keeps not arriving.
 *
 * @param request The link being described.
 */
function describeState( request: CollaborationRequest ): string {
	const ending = isEnding(
		request.expires_at,
		request.last_active,
		getSettings().ttl
	);

	if ( request.joined && isActive( request.last_active ) && ! ending ) {
		return __( 'Working on it now', 'public-collaboration' );
	}

	return sprintf(
		/* translators: %s: Time left, e.g. "12 minutes". */
		__( 'Expires in %s', 'public-collaboration' ),
		getTimeLeft( request.expires_at )
	);
}

/**
 * Names a link, for the first line of its row.
 *
 * The sharer's own label wins: it is what they chose to tell the links apart
 * by. Without one, whoever has turned up is the next best thing, and a link
 * nobody has opened yet is just that.
 *
 * @param request The link being named.
 */
function getName( request: CollaborationRequest ): string {
	if ( request.label ) {
		return request.label;
	}

	if ( request.joined && request.collaborator ) {
		return request.collaborator;
	}

	return __( 'Not opened yet', 'public-collaboration' );
}

/**
 * Says what a link is doing, and who is on it if the name does not.
 *
 * @param request The link being described.
 */
function describe( request: CollaborationRequest ): string {
	const state = describeState( request );

	if ( ! request.label ) {
		return state;
	}

	if ( request.joined && request.collaborator ) {
		return sprintf(
			/* translators: 1: Display name of the collaborator. 2: What the link is doing, e.g. "Working on it now". */
			__( '%1$s · %2$s', 'public-collaboration' ),
			request.collaborator,
			state
		);
	}

	return sprintf(
		/* translators: %s: What the link is doing, e.g. "Expires in 12 minutes". */
		__( 'Not opened yet · %s', 'public-collaboration' ),
		state
	);
}

/**
 * Describes the revoke button, which has only an icon to go on.
 *
 * @param request The link the button revokes.
 */
function getRevokeLabel( request: CollaborationRequest ): string {
	if ( request.label ) {
		return sprintf(
			/* translators: %s: Label the sharer gave the link. */
			__( 'Revoke the link for %s', 'public-collaboration' ),
			request.label
		);
	}

	if ( request.joined && request.collaborator ) {
		return sprintf(
			/* translators: %s: Display name of the collaborator. */
			__( 'Revoke the link %s is using', 'public-collaboration' ),
			request.collaborator
		);
	}

	return __( 'Revoke link', 'public-collaboration' );
}

/**
 * Lists the links that have been handed out, and offers them back.
 *
 * A link outlives the dialog it was minted in, so this is where it can be
 * looked at again, changed, or revoked for as long as it lasts.
 *
 * @param props          Component props.
 * @param props.requests The links to this post that are still live.
 * @param props.revoking Tokens of the links being revoked right now.
 * @param props.onShow   Called to show a link again.
 * @param props.onRevoke Called to revoke a link.
 */
export function LinkList( {
	requests,
	revoking,
	onShow,
	onRevoke,
}: LinkListProps ) {
	return (
		<VStack spacing={ 2 }>
			<Text
				as="h3"
				weight={ 500 }
				className="public-collaboration-links__title"
			>
				{ __( 'Shared links', 'public-collaboration' ) }
			</Text>

			<VStack
				as="ul"
				spacing={ 1 }
				className="public-collaboration-links"
			>
				{ requests.map( ( request ) => {
					const isRevoking = revoking.includes( request.token );

					return (
						<HStack
							as="li"
							key={ request.token }
							spacing={ 1 }
							className="public-collaboration-links__item"
						>
							<Button
								className="public-collaboration-links__link"
								onClick={ () => onShow( request.token ) }
								disabled={ isRevoking }
								accessibleWhenDisabled
							>
								<VStack
									as="span"
									spacing={ 0 }
									alignment="left"
									className="public-collaboration-links__label"
								>
									<Text truncate>{ getName( request ) }</Text>

									<Text variant="muted" size="12">
										{ describe( request ) }
									</Text>
								</VStack>
							</Button>

							<Button
								className="public-collaboration-links__revoke"
								size="small"
								icon={ closeSmall }
								label={ getRevokeLabel( request ) }
								onClick={ () => onRevoke( request.token ) }
								isBusy={ isRevoking }
								disabled={ isRevoking }
								accessibleWhenDisabled
							/>
						</HStack>
					);
				} ) }
			</VStack>
		</VStack>
	);
}
