<?php
/**
 * Turns the redirects the plugin exits on into something a test can catch.
 *
 * @package PublicCollaboration
 */

declare(strict_types = 1);

namespace PublicCollaboration\Tests;

/**
 * Runs code that may redirect, and reports where it went.
 *
 * Both places the plugin redirects follow the redirect with `exit`, which would
 * take the test runner with it. Throwing from the `wp_redirect` filter stops the
 * request at exactly the same point, and hands the test the URL.
 */
trait Intercepts_Redirects {
	/**
	 * Runs a callback, returning the URL it redirected to.
	 *
	 * @param callable $callback Callback to run.
	 * @return string|null Redirect target, or null if the callback did not redirect.
	 */
	protected function catch_redirect( callable $callback ): ?string {
		$throw = static function ( string $location ): string {
			throw new Redirected( $location );
		};

		add_filter( 'wp_redirect', $throw );

		try {
			$callback();

			return null;
		} catch ( Redirected $redirected ) {
			return $redirected->getMessage();
		} finally {
			remove_filter( 'wp_redirect', $throw );
		}
	}
}
