/**
 * WordPress dependencies
 */
import { __, _n, sprintf } from '@wordpress/i18n';

/**
 * Describes a length of time, for a sentence to sit around.
 *
 * Whole minutes: a link that lasts a quarter of an hour is not something to
 * watch a second hand for, and the panel reads it again every few seconds
 * anyway.
 *
 * @param seconds How long, in seconds.
 */
export function getDuration( seconds: number ): string {
	if ( seconds < 60 ) {
		return __( 'less than a minute', 'public-collaboration' );
	}

	const minutes = Math.round( seconds / 60 );

	return sprintf(
		/* translators: %d: Number of minutes. */
		_n( '%d minute', '%d minutes', minutes, 'public-collaboration' ),
		minutes
	);
}

/**
 * Describes how long a collaboration link has left.
 *
 * @param expiresAt Unix timestamp at which the link expires.
 */
export function getTimeLeft( expiresAt: number ): string {
	return getDuration( Math.max( 0, expiresAt * 1000 - Date.now() ) / 1000 );
}

/**
 * How recently somebody has to have changed something to count as still at it.
 *
 * Activity is recorded at most once a minute, so a person typing steadily can
 * still look a minute stale. This is that minute with room to spare, rather
 * than a judgement about how long a pause makes somebody idle.
 */
const ACTIVE_WINDOW = 2 * 60;

/**
 * Determines whether somebody was working on the post a moment ago.
 *
 * @param lastActive Unix timestamp of their last change.
 */
export function isActive( lastActive: number ): boolean {
	return Date.now() / 1000 - lastActive < ACTIVE_WINDOW;
}

/**
 * Determines whether a link is running out whatever anybody does.
 *
 * Expiry slides forward with every change, so what is left is usually a number
 * that keeps being put back. Not once a link has reached the ceiling on its
 * whole life: from there the time left is real, it shrinks while somebody is
 * still typing, and saying anything else would be promising them time the
 * server is not going to give.
 *
 * Which of the two is holding the expiry down is readable from the expiry
 * itself. Every change sets it to the lesser of the idle time and the ceiling,
 * so an expiry that has fallen behind what the last change alone would allow is
 * an expiry the ceiling has taken over.
 *
 * @param expiresAt  Unix timestamp at which the link expires.
 * @param lastActive Unix timestamp of the last change made through it.
 * @param ttl        How long a link outlives a change, in seconds.
 */
export function isEnding(
	expiresAt: number,
	lastActive: number,
	ttl: number
): boolean {
	return expiresAt < lastActive + ttl;
}
