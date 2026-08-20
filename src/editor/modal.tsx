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
import { getTimeLeft } from './expiry';
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

interface CollaborationModalProps {
	/** The collaboration request being shown. */
	request: CollaborationRequest;
	/** Whether the link was just minted, and so can still be taken back. */
	isNew: boolean;
	/** Whether the link is being revoked right now. */
	isRevoking: boolean;
	/** Called when a capability is switched on or off. */
	onToggleCapability: (
		capability: CollaborationCapability,
		enabled: boolean
	) => void;
	/** Called when the person takes back a link they have just minted. */
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
 * @param props.isNew              Whether the link was just minted, and so can still be taken back.
 * @param props.isRevoking         Whether the link is being revoked right now.
 * @param props.onToggleCapability Called when a capability is switched on or off.
 * @param props.onRevoke           Called when the person takes back a link they have just minted.
 * @param props.onRequestClose     Called when the person is done with the dialog.
 */
export function CollaborationModal( {
	request,
	isNew,
	isRevoking,
	onToggleCapability,
	onRevoke,
	onRequestClose,
}: CollaborationModalProps ) {
	const { createNotice } = useDispatch( noticesStore );

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
					{ CAPABILITY_OPTIONS.map( ( option ) => (
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
				<Text variant="muted">
					{ request.joined && request.collaborator
						? sprintf(
								/* translators: %s: Display name of the collaborator. */
								__(
									'%s is working on this post with you.',
									'public-collaboration'
								),
								request.collaborator
						  )
						: __(
								'Nobody has opened the link yet.',
								'public-collaboration'
						  ) }{ ' ' }
					{ sprintf(
						/* translators: %s: Time left, e.g. "12 minutes". */
						__( 'It stops working in %s.', 'public-collaboration' ),
						getTimeLeft( request.expires_at )
					) }
				</Text>
			</p>

			<HStack justify="flex-end">
				{ isNew && (
					<Button
						__next40pxDefaultSize
						variant="tertiary"
						onClick={ onRevoke }
						isBusy={ isRevoking }
						disabled={ isRevoking }
						accessibleWhenDisabled
					>
						{ __( 'Cancel', 'public-collaboration' ) }
					</Button>
				) }

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
