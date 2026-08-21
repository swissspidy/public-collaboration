=== Public Collaboration ===

Contributors:      swissspidy
Tags:              collaboration, editor, sharing, guest, qr code
Requires at least: 7.1
Tested up to:      7.1
Requires PHP:      8.0
Requires Plugins:  gutenberg
Stable tag:        0.1.0
License:           GPLv2 or later
License URI:       https://www.gnu.org/licenses/old-licenses/gpl-2.0.html

Share a link that lets somebody edit one post with you for a quarter of an hour. No account, no login, no invitation email.

== Description ==

Somebody needs to fix a paragraph, or drop in the photos they took, or check the figures in the post you are writing. Today that means creating them an account, sending an invitation, waiting for them to set a password, and then remembering to remove the account afterwards. Most of the time nobody bothers, and the changes get emailed to you instead.

This plugin removes all of that. While editing a post, open **Public collaboration** in the settings sidebar and click **Share link**. A QR code and a link appear. Send the link to whoever is helping, and they land in the editor for that post — no account, no password, nothing to install.

The panel lists every link you have handed out that is still live, so you can open one again, change what it allows, or revoke it on the spot.

Turn on real-time collaboration in the Gutenberg plugin's experiments and the two of you see each other work, which is what this is for. Without it the link still works, but you are taking turns saving over each other — so the panel says so while it is off.

= What they can do =

You choose when you share, and can change your mind for as long as the link lasts:

* **Edit post content** — write and rearrange the post itself.
* **Upload media files** — add images, video, and audio.

Turn a switch off and it takes effect on the page they are already looking at, whether you are sharing the link or came back to it afterwards.

= What they cannot do =

Everything else. A collaboration link is scoped to one post, and only that post:

* no other post, page, draft, or revision,
* no media library, no user list, no settings, no plugins,
* no publishing, no deleting, no creating anything new,
* and nothing at all once the link expires.

You can never share more than you have yourself — if you cannot upload media, neither can anyone you invite.

= Nothing is left behind =

Each link lasts 15 minutes after the last change made through it, up to twelve hours in all, and has a random, unguessable address. When it expires, or when you revoke it, the link and the temporary account behind it are deleted. What your collaborator wrote stays in the post, credited and intact.

== Frequently Asked Questions ==

= Does the person helping need an account? =

No. They are signed in as a temporary account created for them and deleted afterwards. The link is the only credential, which is why it is random and short-lived.

= Can two people edit at the same time? =

Yes — WordPress's usual "somebody else is editing this post" lock is stood down for a collaboration session, so you are not locked out of your own post while somebody is helping with it.

= How long is a link valid? =

15 minutes after the last change somebody makes through it, so it does not run out from under whoever is using it. Merely having the editor open does not count — only changes do — and no link lives longer than twelve hours whatever happens. Developers can change both with the `public_collaboration_request_ttl` and `public_collaboration_request_max_lifetime` filters.

= Can I take access away sooner? =

Yes. Revoke the link in the **Public collaboration** panel and it stops working immediately, or turn off one of its switches at any time to take just that part away.

= Do we see each other type? =

With real-time collaboration turned on in the Gutenberg plugin's experiments, yes: that is the feature this is built around, and a collaboration link is a way of letting somebody into it without an account. Gutenberg can also leave a single post type out of collaboration while the experiment is on, which comes to the same thing for that post type. With it off, the link still works and they can still edit — but neither of you sees the other's changes until they are saved, and the last save wins.

= Why does this need the Gutenberg plugin? =

The collaboration experience is built on the editor as it exists in the Gutenberg plugin rather than the version bundled with WordPress. It is declared as a plugin dependency, so WordPress offers to install it for you and will not activate this plugin without it.

= What happens to what they wrote if the link expires mid-sentence? =

Whatever was saved stays in the post. The temporary account is deleted, and its revisions are reassigned to whoever shared the link, so the post's history stays intact.

== Screenshots ==

1. The "Public collaboration" panel in the post settings sidebar.
2. The QR code, link, and permissions shown when sharing.
3. The greeting a collaborator sees when they follow the link.

== Changelog ==

= 0.1.0 =

* Initial release.
