/**
 * WordPress dependencies
 */
import { PluginDocumentSettingPanel } from '@wordpress/editor';
import { registerPlugin } from '@wordpress/plugins';
import { __ } from '@wordpress/i18n';
import { people } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import { SharePanel } from './share-panel';

/**
 * Adds a "Public collaboration" section to the post's settings sidebar.
 */
function PublicCollaborationPanel() {
	return (
		<PluginDocumentSettingPanel
			name="public-collaboration-panel"
			icon={ people }
			title={ __( 'Public collaboration', 'public-collaboration' ) }
		>
			<SharePanel />
		</PluginDocumentSettingPanel>
	);
}

registerPlugin( 'public-collaboration', {
	render: PublicCollaborationPanel,
} );
