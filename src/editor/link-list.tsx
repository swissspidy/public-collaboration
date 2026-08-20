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
import { getTimeLeft } from '../expiry';
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
									<Text truncate>
										{ request.joined && request.collaborator
											? request.collaborator
											: __(
													'Not opened yet',
													'public-collaboration'
											  ) }
									</Text>

									<Text variant="muted" size="12">
										{ sprintf(
											/* translators: %s: Time left, e.g. "12 minutes". */
											__(
												'Expires in %s',
												'public-collaboration'
											),
											getTimeLeft( request.expires_at )
										) }
									</Text>
								</VStack>
							</Button>

							<Button
								className="public-collaboration-links__revoke"
								size="small"
								icon={ closeSmall }
								label={
									request.joined && request.collaborator
										? sprintf(
												/* translators: %s: Display name of the collaborator. */
												__(
													'Revoke the link %s is using',
													'public-collaboration'
												),
												request.collaborator
										  )
										: __(
												'Revoke link',
												'public-collaboration'
										  )
								}
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
