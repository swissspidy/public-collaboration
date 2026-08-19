/**
 * External dependencies
 */
import { QRCodeSVG } from 'qrcode.react';

/**
 * WordPress dependencies
 */
import {
	Button,
	CheckboxControl,
	Modal,
	TextControl,
	__experimentalText as Text, // eslint-disable-line @wordpress/no-unsafe-wp-apis
} from '@wordpress/components';
import { useCopyToClipboard } from '@wordpress/compose';
import { useDispatch } from '@wordpress/data';
import { store as noticesStore } from '@wordpress/notices';
import { __, sprintf } from '@wordpress/i18n';
import { copy } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import type { CollaborationCapability, CollaborationRequest } from './types';
import './editor.css';

const CAPABILITY_OPTIONS: Array< {
	value: CollaborationCapability;
	label: string;
} > = [
	{
		value: 'edit',
		label: __( 'Edit post content', 'public-collaboration' ),
	},
	{
		value: 'upload',
		label: __( 'Upload media files', 'public-collaboration' ),
	},
];

interface CollaborationModalProps {
	/** The collaboration request being shown. */
	request: CollaborationRequest;
	/** What the collaborator is currently allowed to do. */
	capabilities: CollaborationCapability[];
	/** Called when a capability is ticked or unticked. */
	onToggleCapability: (
		capability: CollaborationCapability,
		enabled: boolean
	) => void;
	/** Called when the person closes the dialog. */
	onRequestClose: () => void;
}

/**
 * Shows the QR code and link for a collaboration request.
 *
 * @param props                    Component props.
 * @param props.request            The collaboration request being shown.
 * @param props.capabilities       What the collaborator is currently allowed to do.
 * @param props.onToggleCapability Called when a capability is ticked or unticked.
 * @param props.onRequestClose     Called when the person closes the dialog.
 */
export function CollaborationModal( {
	request,
	capabilities,
	onToggleCapability,
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

				{ CAPABILITY_OPTIONS.map( ( option ) => (
					<CheckboxControl
						key={ option.value }
						__nextHasNoMarginBottom
						label={ option.label }
						checked={ capabilities.includes( option.value ) }
						onChange={ ( checked ) =>
							onToggleCapability( option.value, checked )
						}
					/>
				) ) }
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
								'Nobody has opened the link yet. It stops working 15 minutes after it was created.',
								'public-collaboration'
						  ) }
				</Text>
			</p>

			<div className="public-collaboration-modal__actions">
				<Button
					__next40pxDefaultSize
					variant="tertiary"
					onClick={ onRequestClose }
				>
					{ __( 'Revoke link', 'public-collaboration' ) }
				</Button>
			</div>
		</Modal>
	);
}
