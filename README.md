# Pulp Purge Cache

A modernized, dependency-free WordPress plugin that purges Cloudflare cache on post publish, without over-purging on every minor edit.

This is a rewrite of the abandoned [Purge Cache](https://wordpress.org/plugins/c-purge-cache/) ([source](https://github.com/gdidentity/c-purge-cache)) plugin for use on [pulpcovers.com](https://pulpcovers.com).

## Why

The original plugin purged the entire Cloudflare cache on both new posts *and* post updates. That's correct for new posts (so they show up immediately on the front page and tag/archive pages instead of waiting for the cache to expire), but unnecessary for updates to already-published posts — editing a post doesn't change its position in any listing, and most edits are minor enough to wait for the cache to expire naturally.

## Behavior

| Event | Action |
|---|---|
| Post transitions to `publish` from any other status (new post, or a scheduled post going live) | Purge **everything** |
| Already-`publish`ed post is edited and saved again | Purge **only that post's URL** (+ optional homepage/extra URLs) |
| Published post is unpublished/trashed | Purge **everything** (ordering changes site-wide, same as a new post) |

## Features

- No Composer/vendor dependency — single self-contained plugin file.
- Cloudflare Zone ID / API Token settings screen with guidance on the minimum required token scope.
- Configurable extra URLs purged on update, supporting `%slug%`, `%author_nicename%`, `%categories%`, `%tags%` placeholders.
- Optional "Purge All Cache" button in the admin bar.
- Optional REST endpoint (`PUT /wp-json/ppc/v1/purge`) protected by a secret compared with `hash_equals()`, for external/manual purges.
- Sanitized/escaped settings, nonce-protected AJAX actions, capability checks (`manage_options`) on all cache-affecting actions.

## Installation

1. Copy the `pulp-purge-cache` folder into `wp-content/plugins/` (or zip it and upload via **Plugins → Add New → Upload Plugin**).
2. Activate the plugin.
3. Go to **Settings → Pulp Purge Cache**:
   - Enter your Cloudflare **Zone ID**.
   - Create an API Token at [Cloudflare API Tokens](https://developers.cloudflare.com/api/tokens/create) using the **Custom token** template with permission **Zone → Cache Purge → Purge**, scoped to **Specific zone** (your site only), and paste it in.
   - Optionally set the homepage URL, extra URLs to purge on update, and enable the admin bar button.

## Development

Everything lives in [`pulp-purge-cache.php`](pulp-purge-cache.php); `uninstall.php` cleans up the stored option and any pending cron event when the plugin is deleted.

## License

GPL-3.0-or-later. See [LICENSE](https://www.gnu.org/licenses/gpl-3.0.html).
