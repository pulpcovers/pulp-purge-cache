=== Pulp Purge Cache ===
Contributors: pulpcovers
Tags: cloudflare, cache, purge, cdn
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Purges Cloudflare cache automatically: everything on a new published post, just that post's URL on later edits.

== Description ==

Pulp Purge Cache keeps a Cloudflare-cached WordPress site consistent without over-purging:

* **New post published** — the entire zone cache is purged, so the post immediately appears on the front page, tag pages, and archives (no risk of it "falling off" a stale page 2 before anyone can find it).
* **Existing published post edited** — only that post's own URL (and, optionally, the homepage and any extra configured URLs) is purged. Minor edits don't force a full site-wide cache rebuild; visitors simply see the update once the cache naturally expires or when they hit the purged URL.
* **Post unpublished/trashed** — treated like a new post (purge everything), since removing a post changes site-wide ordering the same way adding one does.

This is a modernized, dependency-free rewrite of the abandoned [Purge Cache](https://wordpress.org/plugins/c-purge-cache/) plugin, with no Composer/vendor requirement, sanitized/escaped settings, nonce-protected admin actions, and a `hash_equals()`-based REST endpoint.

= Features =

* Cloudflare Zone ID / API Token settings screen.
* Configurable extra URLs to purge on update, with `%slug%`, `%author_nicename%`, `%categories%`, `%tags%` placeholders.
* Optional "Purge All Cache" admin bar button.
* Optional secret-protected REST endpoint (`PUT /wp-json/ppc/v1/purge`) for external/manual triggers.

== Installation ==

1. Upload the `pulp-purge-cache` folder to `/wp-content/plugins/`, or install the zip via **Plugins → Add New → Upload Plugin**.
2. Activate the plugin.
3. Go to **Settings → Pulp Purge Cache** and enter your Cloudflare Zone ID and a scoped API Token (Zone → Cache Purge → Purge, restricted to your zone).

== Frequently Asked Questions ==

= Why doesn't editing a post purge the whole cache? =

Most edits are minor and don't change post ordering across the site, so they can wait for the cache to expire naturally. Only newly published (or unpublished) posts purge everything, since those changes affect front-page/archive ordering immediately.

= What Cloudflare API token permissions do I need? =

A Custom Token with the **Zone → Cache Purge → Purge** permission, scoped to **Specific zone** for your site only. See the description text under the API Token field in the settings screen.

== Changelog ==

= 1.0.0 =
* Initial release: rewritten, dependency-free replacement for c-purge-cache with new-post-vs-update purge logic.
