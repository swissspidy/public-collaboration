/**
 * WordPress dependencies
 */
import {
	BaseControl,
	Button,
	Notice,
	useBaseControlProps,
	__experimentalVStack as VStack, // eslint-disable-line @wordpress/no-unsafe-wp-apis
} from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { getDuration } from '../expiry';
import { CollaborationModal } from './modal';
import { LinkList } from './link-list';
import { getSettings } from './settings';
import { useCollaborationRequests } from './use-collaboration-requests';

/**
 * The control that mints collaboration links, and everything they lead to.
 */
export function SharePanel() {
	// Read rather than assumed: how long a link lasts is filterable, and the
	// page is printed with whatever that filter had to say.
	const { ttl, maxPerPost, isSyncing } = getSettings();

	const { baseControlProps, controlProps } = useBaseControlProps( {
		__nextHasNoMarginBottom: true,
		help: sprintf(
			/* translators: %s: How long a link lasts, e.g. "15 minutes". */
			__(
				'Share a link that lets somebody work on this post with you for the next %s. They do not need an account.',
				'public-collaboration'
			),
			getDuration( ttl )
		),
	} );

	const {
		requests,
		openRequest,
		isNewLink,
		isCreating,
		revoking,
		canShare,
		grantable,
		create,
		revoke,
		show,
		close,
		setCapability,
	} = useCollaborationRequests();

	const isFull = requests.length >= maxPerPost;

	let label: string = __(
		'Save the post to share it',
		'public-collaboration'
	);

	if ( canShare ) {
		// Clicking again hands out a second link rather than showing the first
		// one, so it says so.
		label =
			requests.length > 0
				? __( 'Share another link', 'public-collaboration' )
				: __( 'Share link', 'public-collaboration' );
	}

	if ( canShare && isFull ) {
		// The endpoint would refuse this, and a button that turns into an error
		// is a worse way to find that out than a button that says so.
		label = __( 'Revoke a link to share another', 'public-collaboration' );
	}

	return (
		<VStack spacing={ 4 }>
			{ ! isSyncing && (
				<Notice status="warning" isDismissible={ false }>
					{ __(
						'Changes will not appear as they are made. Turn on real-time collaboration in the Gutenberg plugin’s experiments to have everybody see the post change under them; without it, whoever saves last wins.',
						'public-collaboration'
					) }
				</Notice>
			) }

			<BaseControl { ...baseControlProps }>
				<Button
					{ ...controlProps }
					__next40pxDefaultSize
					variant="secondary"
					onClick={ create }
					isBusy={ isCreating }
					disabled={ isCreating || ! canShare || isFull }
					accessibleWhenDisabled
					className="public-collaboration-share-button"
				>
					{ label }
				</Button>
			</BaseControl>

			{ requests.length > 0 && (
				<LinkList
					requests={ requests }
					revoking={ revoking }
					onShow={ show }
					onRevoke={ revoke }
				/>
			) }

			{ openRequest && (
				<CollaborationModal
					request={ openRequest }
					grantable={ grantable }
					isNew={ isNewLink }
					isRevoking={ revoking.includes( openRequest.token ) }
					onToggleCapability={ ( capability, enabled ) =>
						setCapability( openRequest.token, capability, enabled )
					}
					onRevoke={ () => void revoke( openRequest.token ) }
					onRequestClose={ close }
				/>
			) }
		</VStack>
	);
}
