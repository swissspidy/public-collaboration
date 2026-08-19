# WordPress.org directory assets

`.wordpress-org/` (repo root) holds the images shown on the plugin's directory
listing page — not anything the plugin itself ships. `.github/workflows/deploy.yml`
moves everything in that directory into the top-level `assets` directory in
Subversion (the one next to `trunk`, not inside it) on every published
release.

This doc deliberately lives here, outside `.wordpress-org/`, rather than as a
README inside it: the deploy action copies *everything* in that directory to
SVN as-is, with no notion of "this file isn't a directory asset" — a README
sitting alongside the banner and screenshots would ship to the live directory
page as `assets/README.md` right along with them.

Files go directly in `.wordpress-org/`, not in a subfolder — the deploy
action maps `.wordpress-org/<file>` to SVN's `assets/<file>` as-is.

## What's needed before the first submission

| File | Size | Required? |
| --- | --- | --- |
| `icon-128x128.png` | 128×128 | Yes |
| `icon-256x256.png` | 256×256 (retina) | Recommended |
| `banner-772x250.png` | 772×250 | Yes |
| `banner-1544x500.png` | 1544×500 (retina) | Recommended |
| `screenshot-1.png` | any, 16:9-ish reads best | Matches readme.txt |
| `screenshot-2.png` | | Matches readme.txt |
| `screenshot-3.png` | | Matches readme.txt |

`screenshot-N.png` order and count must match the numbered list under
`== Screenshots ==` in `readme.txt` — that file already describes three:
the "Public collaboration" panel in the settings sidebar, the QR code and
capability choices shown when sharing, and the greeting a collaborator sees
when they follow the link. `.jpg` works too if that's a better fit for a
given image; the extension just needs to match what's actually there.

None of this blocks development or CI — Plugin Check doesn't look at
`.wordpress-org/`, and the deploy workflow only ever runs when a GitHub
release is published. It's only needed before the actual
`wordpress.org/plugins/developers/add/` submission, and again whenever a
screenshot goes stale.
