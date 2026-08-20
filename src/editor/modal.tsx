/**
 * External dependencies
 */
import { QRCodeSVG } from 'qrcode.react';

/**
 * WordPress dependencies
 */
import {
	Button,
	Modal,
	TextControl,
	ToggleControl,
	__experimentalHStack as HStack, // eslint-disable-line @wordpress/no-unsafe-wp-apis
	__experimentalText as Text, // eslint-disable-line @wordpress/no-unsafe-wp-apis
	__experimentalVStack as VStack, // eslint-disable-line @wordpress/no-unsafe-wp-apis
} from '@wordpress/components';
import { useCopyToClipboard } from '@wordpress/compose';
import { useDispatch } from '@wordpress/data';
import { store as noticesStore } from '@wordpress/notices';
import { __, sprintf } from '@wordpress/i18n';
import { copy } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import { getDuration, getTimeLeft, isActive } from '../expiry';
import { getSettings } from './settings';
import type { CollaborationCapability, CollaborationRequest } from './types';
import './editor.css';

const CAPABILITY_OPTIONS: Array< {
	value: CollaborationCapability;
	label: string;
	help: string;
} > = [
	{
		value: 'edit',
		label: __( 'Edit post content', 'public-collaboration' ),
		help: __(
			'Change the text and blocks in this post. Nothing else on the site.',
			'public-collaboration'
		),
	},
	{
		value: 'upload',
		label: __( 'Upload media files', 'public-collaboration' ),
		help: __(
			'Add images and other files to the media library.',
			'public-collaboration'
		),
	},
];

/**
 * Says where a link stands, in a sentence under the switches.
 *
 * Three things it can be: nobody has come, somebody is here and working, or
 * somebody is here and has stopped. Only the first and last are counting down —
 * for the one in the middle the clock keeps being put back, so what is worth
 * saying is what it takes to end it rather than when it will.
 *
 * @param request The link being described.
 */
function describeStatus( request: CollaborationRequest ): string {
	if ( ! request.joined || ! request.collaborator ) {
		return sprintf(
			/* translators: %s: Time left, e.g. "12 minutes". */
			__(
				'Nobody has opened the link yet. It stops working in %s.',
				'public-collaboration'
			),
			getTimeLeft( request.expires_at )
		);
	}

	if ( isActive( request.last_active ) ) {
		return sprintf(
			/* translators: 1: Display name of the collaborator. 2: Idle time, e.g. "15 minutes". */
			__(
				'%1$s is working on this post with you. The link ends %2$s after their last change.',
				'public-collaboration'
			),
			request.collaborator,
			getDuration( getSettings().ttl )
		);
	}

	return sprintf(
		/* translators: 1: Display name of the collaborator. 2: Time left, e.g. "12 minutes". */
		__(
			'%1$s has not changed anything for a while. The link stops working in %2$s.',
			'public-collaboration'
		),
		request.collaborator,
		getTimeLeft( request.expires_at )
	);
}

interface CollaborationModalProps {
	/** The collaboration request being shown. */
	request: CollaborationRequest;
	/** What the person sharing may hand out, which is never more than they have. */
	grantable: CollaborationCapability[];
	/** Whether the link was just minted, and so can still be called off. */
	isNew: boolean;
	/** Whether the link is being revoked right now. */
	isRevoking: boolean;
	/** Called when a capability is switched on or off. */
	onToggleCapability: (
		capability: CollaborationCapability,
		enabled: boolean
	) => void;
	/** Called when the person takes the link back. */
	onRevoke: () => void;
	/** Called when the person is done with the dialog. */
	onRequestClose: () => void;
}

/**
 * Shows the QR code, the link, and what it grants.
 *
 * Closing the dialog leaves the link working: it is listed in the panel this
 * was opened from, which is where it can be changed or taken back later.
 *
 * @param props                    Component props.
 * @param props.request            The collaboration request being shown.
 * @param props.grantable          What the person sharing may hand out, which is never more than they have.
 * @param props.isNew              Whether the link was just minted, and so can still be called off.
 * @param props.isRevoking         Whether the link is being revoked right now.
 * @param props.onToggleCapability Called when a capability is switched on or off.
 * @param props.onRevoke           Called when the person takes the link back.
 * @param props.onRequestClose     Called when the person is done with the dialog.
 */
export function CollaborationModal( {
	request,
	grantable,
	isNew,
	isRevoking,
	onToggleCapability,
	onRevoke,
	onRequestClose,
}: CollaborationModalProps ) {
	const { createNotice } = useDispatch( noticesStore );

	/*
	 * A capability the sharer cannot hand out is not offered, because the server
	 * would refuse it — but one this link already grants stays on screen even
	 * so, since taking something away is always theirs to do.
	 */
	const options = CAPABILITY_OPTIONS.filter(
		( option ) =>
			grantable.includes( option.value ) ||
			request.capabilities.includes( option.value )
	);

	const copyRef = useCopyToClipboard( request.url, () => {
		void createNotice(
			'info',
			__( 'Copied link to clipboard.', 'public-collaboration' ),
			{ isDismissible: true, type: 'snackbar' }
		);
	} );

	return (
		<Modal
			title={ __( 'Share for collaboration', 'public-collaboration' ) }
			onRequestClose={ onRequestClose }
			className="public-collaboration-modal"
		>
			<p>
				<Text>
					{ __(
						'Anyone who opens this link can work on this post with you, without signing in.',
						'public-collaboration'
					) }
				</Text>
			</p>

			<div className="public-collaboration-modal__qrcode">
				<QRCodeSVG
					value={ request.url }
					title={ __(
						'QR code for the collaboration link',
						'public-collaboration'
					) }
					marginSize={ 2 }
				/>
			</div>

			<div className="public-collaboration-modal__link">
				<TextControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ __( 'Collaboration link', 'public-collaboration' ) }
					hideLabelFromVision
					value={ request.url }
					readOnly
					onChange={ () => {} }
					onFocus={ ( event ) => event.target.select() }
				/>

				<Button
					__next40pxDefaultSize
					variant="secondary"
					ref={ copyRef }
					icon={ copy }
					showTooltip={ false }
					label={ __( 'Copy link', 'public-collaboration' ) }
				/>
			</div>

			<fieldset className="public-collaboration-modal__capabilities">
				<legend>
					<Text>
						{ __(
							'Choose what they can do:',
							'public-collaboration'
						) }
					</Text>
				</legend>

				<VStack spacing={ 4 }>
					{ options.map( ( option ) => (
						<ToggleControl
							key={ option.value }
							__nextHasNoMarginBottom
							label={ option.label }
							help={ option.help }
							checked={ request.capabilities.includes(
								option.value
							) }
							onChange={ ( checked ) =>
								onToggleCapability( option.value, checked )
							}
						/>
					) ) }
				</VStack>
			</fieldset>

			<p className="public-collaboration-modal__status">
				<Text variant="muted">{ describeStatus( request ) }</Text>
			</p>

			<HStack justify="flex-end">
				{ /*
				 * The same thing either way, under the name that fits what has
				 * happened to the link so far. Calling off one that has only
				 * just been minted is undoing a click; revoking one that has
				 * been handed out takes it away from somebody, which is worth
				 * both the longer word and the colour that goes with it.
				 */ }
				<Button
					__next40pxDefaultSize
					variant="tertiary"
					isDestructive={ ! isNew }
					onClick={ onRevoke }
					isBusy={ isRevoking }
					disabled={ isRevoking }
					accessibleWhenDisabled
				>
					{ isNew
						? __( 'Cancel', 'public-collaboration' )
						: __( 'Revoke link', 'public-collaboration' ) }
				</Button>

				<Button
					__next40pxDefaultSize
					variant="primary"
					onClick={ onRequestClose }
					disabled={ isRevoking }
					accessibleWhenDisabled
				>
					{ __( 'Done', 'public-collaboration' ) }
				</Button>
			</HStack>
		</Modal>
	);
}
