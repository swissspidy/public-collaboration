/**
 * WordPress dependencies
 */
import { registerPlugin } from '@wordpress/plugins';

/**
 * Internal dependencies
 */
import { WelcomeModal } from './welcome-modal';

registerPlugin( 'public-collaboration-welcome', {
	render: WelcomeModal,
} );
