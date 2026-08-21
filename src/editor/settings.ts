/**
 * Internal dependencies
 */
import type { SharingSettings } from './types';

/**
 * What to assume about a page that did not say.
 *
 * Erring towards offering the upload switch rather than hiding it: at worst the
 * server refuses a capability the sharer does not have, which is a message. The
 * other way round would quietly take the switch away from everybody it belongs
 * to.
 */
const FALLBACK: SharingSettings = {
	ttl: 15 * 60,
	canUpload: true,
	maxPerPost: 50,

	// And towards saying nothing about real-time collaboration rather than
	// warning about a thing that may well be on: a page that did not say is not
	// evidence either way, and a notice nobody needs is one nobody reads.
	isSyncing: true,
};

/**
 * What the page said about the links this panel hands out.
 *
 * Printed with the bundle by PHP, where the site's own filters and the sharer's
 * own capabilities have already had their say.
 */
export function getSettings(): SharingSettings {
	return { ...FALLBACK, ...window.publicCollaborationSettings };
}
