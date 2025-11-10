=== AI Snippet SEO Pro ===
Contributors: rankpilotai
Tags: ai, seo, title, meta, meta description
Requires at least: 5.8
Tested up to: 6.7.4
Requires PHP: 7.4
Stable tag: 1.0.5
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Generate SEO titles and meta descriptions with AI. Live preview, 0-100 score, keyword checks, auto-slug, and bulk actions.

== Description ==
Generate SEO-friendly titles and meta descriptions with one click. The plugin scores each snippet (0–100), validates keyword usage, and supports bulk actions for posts and taxonomies.

Highlights
- One-click AI generation on post and taxonomy editors
- Live preview and checklist for title length, meta length, keyword in slug
- Bulk generate from list tables (posts and terms)
- Optional auto-slug update (adds 301 via _wp_old_slug)
- Token-based usage via RankPilotAI (free tier available)

How it works
1. Go to AI Snippet SEO Pro → Site Key Management and paste your Site Key.
2. Optionally enable Auto-slug. Pick a model.
3. Open a post or term editor and click Generate with AI.
4. Review the score and checklist. Save.

Privacy
The plugin connects to RankPilotAI only when you click to generate snippets or when checking token usage. Suggestions are returned to your site and are not used for ad targeting.

What data we send
- Site identifier: site URL and domain
- Content for generation (when you click Generate): post_id or term_id, titles, excerpts, focus keyword, current slug, editor screen type, selected model
- Token checks: your Site Key only

We do not store your content unless you save the suggestion inside WordPress. See the Privacy Policy on rankpilotai.com.

External services
Service: RankPilotAI  
Base URL: https://rankpilotai.com

Endpoints
- POST /api/snippet   → generate a title/description
- GET  /token-info    → return token balance and limits

Uninstall
Removes plugin options and _aissp_* meta. Saved titles and descriptions remain untouched.

1. AI Snippet panel on the post editor
2. Bulk generate from list tables
3. Live preview and scoring
4. Taxonomy edit screen integration
5. Settings page overview

== Changelog ==
= 1.0.5 =
- Fix stable tag parsing and license. Restore full Description.

= 1.0.4 =
- Readme text restore.

= 1.0.3 =
- Fix readme header.

= 1.0.2 =
- Tag structure fix.

= 1.0.1 =
- Readme tweaks.

= 1.0.0 =
- Initial release.
