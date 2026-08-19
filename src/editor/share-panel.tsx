/**
 * WordPress dependencies
 */
import {
	BaseControl,
	Button,
	useBaseControlProps,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { CollaborationModal } from './modal';
import { useCollaborationRequest } from './use-collaboration-request';

/**
 * The control that mints a collaboration link, and the dialog that shows it.
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
		request,
		capabilities,
		isCreating,
		canShare,
		create,
		close,
		toggleCapability,
	} = useCollaborationRequest();

	return (
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
				{ canShare
					? __( 'Share link', 'public-collaboration' )
					: __(
							'Save the post to share it',
							'public-collaboration'
					  ) }
			</Button>

			{ request && (
				<CollaborationModal
					request={ request }
					capabilities={ capabilities }
					onToggleCapability={ toggleCapability }
					onRequestClose={ close }
				/>
			) }
		</BaseControl>
	);
}
