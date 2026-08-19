# Public Collaboration

[![Code Coverage](https://codecov.io/gh/swissspidy/public-collaboration/branch/main/graph/badge.svg)](https://codecov.io/gh/swissspidy/public-collaboration)
[![License](https://img.shields.io/github/license/swissspidy/public-collaboration)](https://github.com/swissspidy/public-collaboration/blob/main/LICENSE)

Share a link that lets somebody edit one post with you for a quarter of an hour. No account, no login, no invitation email.

This started life as a feature of [Media Experiments](https://github.com/swissspidy/media-experiments) and now stands on its own.

## Quick Start

Install and activate the latest nightly build on your WordPress website, open a post, and click **Share link** under **Public collaboration** in the settings sidebar.

[![Download latest nightly build](https://img.shields.io/badge/Download%20latest%20nightly-24282D?style=for-the-badge&logo=Files&logoColor=ffffff)](https://swissspidy.github.io/public-collaboration/nightly.zip)

Note: Requires **WordPress 7.1+**, **PHP 8.0+**, and the [Gutenberg plugin](https://wordpress.org/plugins/gutenberg/). WordPress will refuse to activate this plugin without Gutenberg — it is declared as a dependency in the plugin header, so the plugins screen offers to install it for you.

### Using WordPress Playground

Use [WordPress Playground](https://wordpress.org/playground/) to try this plugin directly in the browser, without installing it on your site:

[![Test on WordPress Playground](https://img.shields.io/badge/Test%20on%20WordPress%20Playground-3F57E1?style=for-the-badge&logo=WordPress&logoColor=ffffff)](https://playground.wordpress.net/?mode=seamless&blueprint-url=https://raw.githubusercontent.com/swissspidy/public-collaboration/main/blueprints/playground.json)

**Note:** A collaboration link in Playground has to be opened by whatever device is running Playground itself — Playground is not reachable from another machine.

## How it works

While editing a post, **Public collaboration** appears in the settings sidebar. Clicking **Share link** creates a short-lived collaboration request and shows its link as a QR code.

A collaboration request is a post of a private, UI-less post type whose slug is a 128-bit random token, with the post being shared as its parent. That token is the only credential involved, so it is treated as one:

| | |
|---|---|
| Address | 32 hex characters from `random_bytes()` — not derived from the clock, not sequential |
| Lifetime | 15 minutes, checked on every use rather than trusted to cron |
| Scope | One post. Not the post list, not the media library, not the rest of wp-admin |
| Powers | Whatever the sharer ticked: edit the post, upload media, or neither |
| Ceiling | Never more than the sharer has themselves |
| Afterwards | The link, and the account behind it, are deleted |

Unknown, expired, and inaccessible tokens all return the same 404, so the endpoint cannot be used to find out which tokens exist.

Following a working link signs the visitor in as a temporary account and drops them straight into the editor for that one post. Closing the dialog revokes the link immediately; otherwise it expires on its own.

## Architecture notes

**WordPress needs an account to hang permissions off, so it gets one.** A collaborator is a real WordPress user, created the first time somebody actually follows the link and deleted along with the request. It carries no role, and nothing is ever written to its capabilities: every capability it ends up with is worked out from the request at the moment the check is made, by a `user_has_cap` filter, and only for the one post. Let the link expire and the account can do nothing at all — which is what makes revoking it a single `wp_delete_post()` rather than an audit.

**One capability is granted site-wide, on purpose.** The block editor will not render until it has read the post type in `edit` context, and that check is the bare `edit_posts` capability with no post attached — there is nothing to scope it to. On its own it grants very little: touching anybody else's post additionally needs `edit_others_posts`, which is only ever granted for the shared post. What it would otherwise allow is creating posts, and both routes to that are closed: `rest_pre_insert_*` refuses a create from a collaborator, and any admin screen other than the shared post's editor redirects back to it.

**Core's post lock is stood down for a collaboration session.** The lock exists to stop two people silently overwriting each other, and it answers that by letting only one of them in. Sharing a post is a decision to have two people in it, so for a collaborator the lock has nothing useful left to say — it would greet them with "somebody else is editing" and, a heartbeat later, tell one of the two that the other had taken over. Collaborators are never shown the dialog and never take the lock, so whoever shared the post keeps it.

**Sharing is not a way to give away more than you have.** A contributor who cannot upload media cannot hand out a link that can.

## Hooks

### Filters

| Filter | Description |
|---|---|
| `public_collaboration_request_ttl` | How long a link stays valid, in seconds. Default 15 minutes, floor of 1 minute. |
| `public_collaboration_rewrite_slug` | URL prefix of the collaboration link. Default `collaborate`. |
| `public_collaboration_template` | Absolute path to the template rendering the collaboration page. |

### Actions

| Action | Description |
|---|---|
| `public_collaboration_request_created` | Fires after a link is created, with the request post. |
| `public_collaboration_user_created` | Fires after somebody follows a link and their temporary account is made, with the user ID and the request post. |
| `public_collaboration_request_deleted` | Fires before a link, and the account behind it, are deleted. |

```php
add_action(
	'public_collaboration_user_created',
	static function ( int $user_id ): void {
		// Somebody just accepted an invitation — notify, log, or greet them.
	}
);
```

## REST API

| Endpoint | Auth | Purpose |
|---|---|---|
| `POST /public-collaboration/v1/collaboration-requests` | `edit_post` on the post, plus `upload_files` to grant uploads | Share a post |
| `GET /public-collaboration/v1/collaboration-requests/<token>` | Owner, or `edit_others_posts` | See whether anybody has joined |
| `PUT /public-collaboration/v1/collaboration-requests/<token>` | Owner, or `edit_others_posts` | Change what the link grants |
| `DELETE /public-collaboration/v1/collaboration-requests/<token>` | Owner, or `edit_others_posts` | Revoke a link |

Changing what a link grants takes effect on the collaborator's very next request — nothing was ever copied onto their account, so there is nothing to revoke separately.

## Development

```bash
npm install
composer install

npm run build       # Build the assets
npm start           # Build and watch
npm run lint:js     # Lint JavaScript
npm run lint:css    # Lint styles
npm run typecheck   # Type check
composer lint       # Lint PHP
composer phpstan    # Static analysis

npx playwright install chromium   # First time only — Playwright manages its own browser binaries
npm run wp-env start               # Start a local WordPress, with Gutenberg
npm run test:e2e                   # Run the end-to-end tests against it

# PHP unit tests run inside the wp-env container, where the WordPress test
# suite lives. `npm run` would swallow --env-cwd as an npm flag, so call
# wp-env directly.
./node_modules/.bin/wp-env run tests-cli \
  --env-cwd=wp-content/plugins/public-collaboration vendor/bin/phpunit
```

`.wp-env.json` pins WordPress to the release this plugin targets and installs the Gutenberg plugin alongside it, in that order — WordPress will not activate this plugin before Gutenberg is active.

## License

GPL-2.0-or-later
