<?php
/**
 * The collaboration page.
 *
 * Following a working link never gets this far — it signs the visitor in and
 * redirects them to the editor (see filter_template_include() in
 * inc/functions.php). This is the page for when that cannot happen, and its
 * whole job is to say why in a way that does not look like a broken website.
 *
 * Deliberately not a theme template: whoever followed the link may well have
 * never seen this site before, and the page has to read the same way on every
 * theme.
 *
 * @package PublicCollaboration
 */

declare(strict_types = 1);

namespace PublicCollaboration;

use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( __NAMESPACE__ . '\render_collaboration_page' ) ) :
	/**
	 * Renders the collaboration page.
	 *
	 * Called directly below rather than left as file-scope code: this file is
	 * `require`d straight into the global scope by core's template loader, and
	 * namespacing doesn't protect PHP variables the way it does functions and
	 * classes.
	 *
	 * Guarded against redeclaration for the same reason: core's template loader
	 * uses a plain `include`, not `include_once`, so nothing rules out this file
	 * loading twice in one request.
	 *
	 * @return void
	 */
	function render_collaboration_page(): void {
		$post = get_post();

		if ( ! $post instanceof WP_Post ) {
			exit;
		}

		$request = Collaboration_Request::from_post( $post );

		if ( ! $request instanceof Collaboration_Request ) {
			exit;
		}

		$title = __( 'This collaboration link is no longer valid', 'public-collaboration' );
		$hint  = __( 'Collaboration links expire after a short while. Ask whoever shared it with you for a new one.', 'public-collaboration' );
		$link  = '';

		if ( ! $request->is_expired() && $request->get_parent() instanceof WP_Post ) {
			if ( is_user_logged_in() ) {
				$title = __( 'You are already signed in', 'public-collaboration' );
				$hint  = sprintf(
					/* translators: %s: Display name of the currently signed-in user. */
					__( 'This link opens a temporary account, but you are signed in as %s. Sign out first to accept the invitation, or ask for access to the post with the account you already have.', 'public-collaboration' ),
					wp_get_current_user()->display_name
				);
				$link = wp_logout_url( $request->get_url() );
			} else {
				$title = __( 'This invitation could not be opened', 'public-collaboration' );
				$hint  = __( 'Something went wrong setting up your temporary account. Please try the link again in a moment.', 'public-collaboration' );
			}
		}

		?><!DOCTYPE html>
		<html <?php language_attributes(); ?>>
		<head>
			<meta charset="<?php bloginfo( 'charset' ); ?>">
			<meta name="viewport" content="width=device-width, initial-scale=1">
			<meta name="robots" content="noindex, nofollow">
			<title><?php echo esc_html( $title ); ?></title>
			<?php
			/*
			 * Inlined rather than enqueued. This page loads no theme and no other
			 * plugin's assets, it is a handful of rules, and it is the one page
			 * in the plugin that a person only ever sees when something has
			 * already gone wrong — a second request to make it look right is a
			 * poor trade.
			 */
			?>
			<style>
				body {
					margin: 0;
					padding: 2rem 1rem;
					font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
					line-height: 1.6;
					color: #1e1e1e;
					background: #f0f0f1;
				}

				.public-collaboration__wrap {
					max-width: 32rem;
					margin: 0 auto;
				}

				.public-collaboration__site {
					margin: 0 0 1.5rem;
					font-size: 0.875rem;
					text-transform: uppercase;
					letter-spacing: 0.05em;
					color: #646970;
				}

				.public-collaboration__panel {
					padding: 1.5rem;
					background: #fff;
					border-radius: 8px;
					box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
				}

				.public-collaboration__title {
					margin: 0 0 0.5rem;
					font-size: 1.25rem;
				}

				.public-collaboration__hint {
					margin: 0;
					color: #50575e;
				}

				.public-collaboration__footer {
					margin: 1.5rem 0 0;
					font-size: 0.875rem;
				}

				@media (prefers-color-scheme: dark) {
					body {
						color: #f0f0f1;
						background: #1e1e1e;
					}

					.public-collaboration__panel {
						background: #2c3338;
					}

					.public-collaboration__hint {
						color: #c3c4c7;
					}

					a {
						color: #72aee6;
					}
				}
			</style>
		</head>
		<body class="public-collaboration">

		<main class="public-collaboration__wrap">
			<p class="public-collaboration__site"><?php echo esc_html( get_bloginfo( 'name' ) ); ?></p>

			<div class="public-collaboration__panel">
				<h1 class="public-collaboration__title"><?php echo esc_html( $title ); ?></h1>
				<p class="public-collaboration__hint"><?php echo esc_html( $hint ); ?></p>

				<?php if ( '' !== $link ) : ?>
					<p>
						<a href="<?php echo esc_url( $link ); ?>">
							<?php esc_html_e( 'Sign out and open the invitation', 'public-collaboration' ); ?>
						</a>
					</p>
				<?php endif; ?>
			</div>

			<p class="public-collaboration__footer">
				<a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php echo esc_html( get_bloginfo( 'name' ) ); ?></a>
			</p>
		</main>

		</body>
		</html>
		<?php
	}
endif;

render_collaboration_page();
