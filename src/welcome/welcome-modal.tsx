/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import {
	Button,
	Modal,
	TextControl,
	__experimentalText as Text, // eslint-disable-line @wordpress/no-unsafe-wp-apis
} from '@wordpress/components';
import { useDispatch, useSelect } from '@wordpress/data';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { store as preferencesStore } from '@wordpress/preferences';

/**
 * Internal dependencies
 */
import { getTimeLeft } from '../expiry';
import './welcome.css';

const PREFERENCE_SCOPE = 'public-collaboration';
const PREFERENCE_KEY = 'welcomeShown';

/**
 * Greets somebody who has just followed a collaboration link.
 *
 * They arrive in an editor they did not ask for, as an account they did not
 * make, on somebody else's site. This says where they are, what they can do,
 * and offers them a name to be known by — once, the first time they arrive.
 */
export function WelcomeModal() {
	const data = window.publicCollaboration;

	const [ displayName, setDisplayName ] = useState( data?.displayName ?? '' );
	const [ isSaving, setIsSaving ] = useState( false );

	const { set: setPreference } = useDispatch( preferencesStore );

	const wasShown = useSelect(
		( select ) =>
			select( preferencesStore ).get( PREFERENCE_SCOPE, PREFERENCE_KEY ),
		[]
	);

	if ( ! data || wasShown ) {
		return null;
	}

	const dismiss = () => {
		void setPreference( PREFERENCE_SCOPE, PREFERENCE_KEY, true );
	};

	const start = async () => {
		const name = displayName.trim();

		if ( '' !== name && name !== data.displayName ) {
			setIsSaving( true );

			try {
				await apiFetch( {
					path: '/wp/v2/users/me',
					method: 'POST',
					data: { name },
				} );
			} catch {
				// Not worth blocking on: the generated name works fine, and the
				// dialog has already said the name is optional.
			} finally {
				setIsSaving( false );
			}
		}

		dismiss();
	};

	return (
		<Modal
			title={ __(
				'You have been invited to help',
				'public-collaboration'
			) }
			onRequestClose={ dismiss }
			className="public-collaboration-welcome"
		>
			<p>
				<Text>
					{ data.sharedBy
						? sprintf(
								/* translators: 1: Display name of whoever shared the link. 2: Post title. */
								__(
									'%1$s shared “%2$s” with you. Your changes go straight into their post.',
									'public-collaboration'
								),
								data.sharedBy,
								data.postTitle
						  )
						: sprintf(
								/* translators: %s: Post title. */
								__(
									'You have been invited to work on “%s”. Your changes go straight into the post.',
									'public-collaboration'
								),
								data.postTitle
						  ) }
				</Text>
			</p>

			<ul className="public-collaboration-welcome__capabilities">
				{ data.canEdit && (
					<li>
						{ __(
							'You can edit the content of this one post.',
							'public-collaboration'
						) }
					</li>
				) }
				{ data.canUpload && (
					<li>
						{ __(
							'You can upload images, video, and audio.',
							'public-collaboration'
						) }
					</li>
				) }
				<li>
					{ sprintf(
						/* translators: %s: Time left, e.g. "12 minutes". */
						__(
							'Your access ends in %s, and the temporary account it uses is deleted afterwards.',
							'public-collaboration'
						),
						getTimeLeft( data.expiresAt )
					) }
				</li>
			</ul>

			<TextControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ __( 'Your name (optional)', 'public-collaboration' ) }
				help={ __(
					'How you appear to everybody else working on the post.',
					'public-collaboration'
				) }
				value={ displayName }
				onChange={ setDisplayName }
			/>

			<div className="public-collaboration-welcome__actions">
				<Button
					__next40pxDefaultSize
					variant="primary"
					onClick={ start }
					isBusy={ isSaving }
					disabled={ isSaving }
					accessibleWhenDisabled
				>
					{ __( 'Start editing', 'public-collaboration' ) }
				</Button>
			</div>
		</Modal>
	);
}
