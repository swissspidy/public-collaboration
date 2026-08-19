export interface CollaboratorData {
	/** ID of the post they were invited to. */
	postId: number;
	/** Title of that post. */
	postTitle: string;
	/** Display name of whoever shared the link. */
	sharedBy: string;
	/** The collaborator's own display name, as generated for them. */
	displayName: string;
	/** Unix timestamp at which their access ends. */
	expiresAt: number;
	/** Whether they may edit the post. */
	canEdit: boolean;
	/** Whether they may upload media. */
	canUpload: boolean;
}

declare global {
	interface Window {
		/** Printed with the editor page for a collaborator. Absent for everybody else. */
		publicCollaboration?: CollaboratorData;
	}
}
