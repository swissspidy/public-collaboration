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
	/** What the collaborator is allowed to do. */
	capabilities: CollaborationCapability[];
	/** Whether somebody has followed the link. */
	joined: boolean;
	/** Display name of the collaborator, once they have joined. */
	collaborator: string | null;
}
