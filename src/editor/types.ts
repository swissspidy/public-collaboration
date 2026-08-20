/** What a collaboration request may allow somebody to do. */
export type CollaborationCapability = 'edit' | 'upload';

export interface CollaborationRequest {
	/** Unique token identifying the collaboration request. */
	token: string;
	/** URL to share with the collaborator. */
	url: string;
	/** ID of the post being collaborated on. */
	post: number;
	/** Unix timestamp at which the collaboration request expires. */
	expires_at: number;
	/** Unix timestamp of the collaborator's last change. */
	last_active: number;
	/** What the collaborator is allowed to do. */
	capabilities: CollaborationCapability[];
	/** Whether somebody has followed the link. */
	joined: boolean;
	/** Display name of the collaborator, once they have joined. */
	collaborator: string | null;
}

/** What the sharing panel is told about the links it hands out. */
export interface SharingSettings {
	/** How long a collaboration link stays valid, in seconds. */
	ttl: number;
	/** Whether the person sharing may upload media themselves. */
	canUpload: boolean;
}

declare global {
	interface Window {
		/**
		 * Printed with the editor page for whoever may share the post.
		 *
		 * Absent for a collaborator, who gets {@link Window.publicCollaboration}
		 * and the greeting that reads it instead.
		 */
		publicCollaborationSettings?: SharingSettings;
	}
}
