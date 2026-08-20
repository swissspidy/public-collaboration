/**
 * WordPress dependencies
 */
import {
	BaseControl,
	Button,
	useBaseControlProps,
	__experimentalVStack as VStack, // eslint-disable-line @wordpress/no-unsafe-wp-apis
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { CollaborationModal } from './modal';
import { LinkList } from './link-list';
import { useCollaborationRequests } from './use-collaboration-requests';

/**
 * The control that mints collaboration links, and everything they lead to.
 */
export function SharePanel() {
	const { baseControlProps, controlProps } = useBaseControlProps( {
		__nextHasNoMarginBottom: true,
		help: __(
			'Share a link that lets somebody work on this post with you for the next 15 minutes. They do not need an account.',
			'public-collaboration'
		),
	} );

	const {
		requests,
		openRequest,
		isNewLink,
		isCreating,
		revoking,
		canShare,
		create,
		revoke,
		show,
		close,
		setCapability,
	} = useCollaborationRequests();

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

	return (
		<VStack spacing={ 4 }>
			<BaseControl { ...baseControlProps }>
				<Button
					{ ...controlProps }
					__next40pxDefaultSize
					variant="secondary"
					onClick={ create }
					isBusy={ isCreating }
					disabled={ isCreating || ! canShare }
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
