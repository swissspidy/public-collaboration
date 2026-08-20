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
