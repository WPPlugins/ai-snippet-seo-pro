=== AI Snippet SEO Pro ===
Contributors: rankpilotai
Tags: seo, meta-description, title, rank-math, ai
Requires at least: 5.8
Tested up to: 6.8.3
Requires PHP: 7.4
Stable tag: 1.0.8
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

One-click AI SEO titles & meta descriptions with scoring, live preview, and bulk actions for posts & taxonomies.

== Description ==
Generate SEO-friendly titles and meta descriptions with one click. The plugin scores each snippet (0–100), checks keyword usage, shows live preview, and supports bulk actions for posts and taxonomies.

= Highlights =
* One-click AI generation (post and taxonomy editors)
* Live preview and checklist (title length, meta length, keyword in slug)
* Bulk generate from list tables (posts and terms)
* Optional auto-slug update (adds _wp_old_slug for 301)
* Token-based usage via RankPilotAI (free tier available)

= How it works =
1. Go to **AI Snippet SEO Pro → Settings** and add your **Site Key**.
2. Choose a model and whether to enable **auto-slug**.
3. Open any post or term, click **Generate with AI**.
4. Review the score and checklist, then **Save**.

= External services =
This plugin connects to RankPilotAI to generate SEO suggestions.
* Base URL: https://rankpilotai.com
* Endpoints:
  * /wp-json/rankpilotai/v1/snippet
  * /wp-json/rankpilotai/v1/token-info

= Privacy =
No analytics or tracking. Only the fields required to generate snippets are sent to RankPilotAI.

= Uninstall =
Removes plugin options and deletes meta keys starting with `_aissp_`.
== Installation ==
1. Upload and activate the plugin.
2. Go to **AI Snippet SEO Pro → Site Key Management** and paste your Site Key.
3. (Optional) Enable **Auto-update URL Slug** and choose a model.
4. Open a post (or term) and click **Generate with AI**.

== Frequently Asked Questions ==
= Is it free? =
A limited free tier is available. Higher tiers unlock more tokens and models.

= Does it replace my SEO plugin? =
No. You can keep your existing SEO plugin for rendering tags. This plugin focuses on fast, consistent AI generation.

= What data leaves my site? =
Only what’s required to generate a snippet: post/term title, excerpt/description, current slug, optional focus keyword, your domain & site URL, selected model, and the Site Key.

= Will it change my slugs? =
Only if you enable **Auto-update URL Slug** in settings. When enabled, changes are safe by default (WordPress adds a 301 via `_wp_old_slug`).

= What happens if tokens run out? =
Generation will fail with a clear notice. You can either switch to a cheaper model (e.g., GPT-4 Turbo) or purchase more tokens.

= Works with custom post types and WooCommerce? =
Yes—any public post type, plus common taxonomies such as `category`, `post_tag`, `product_cat`, and `product_tag`.

== Privacy ==
The plugin communicates with RankPilotAI endpoints to create snippets and to check/manage token usage. Data sent: title/excerpt (or term name/description), focus keyword (if provided), slug, domain, site URL, selected model, and your Site Key. Refer to rankpilotai.com for full Privacy & Terms.

Endpoint used: `https://rankpilotai.com/wp-json/rankpilotai/v1/*`

== External services ==

This plugin connects to the RankPilotAI API to generate SEO snippets and manage token usage.

**Base URL:** https://rankpilotai.com/wp-json/rankpilotai/v1

**Endpoints used**
- `/snippet` and `/snippet-bulk`: called when you click “Generate with AI” on posts/terms or run a bulk job.
- `/token-info`: called when you save the Site Key or open the settings page to display current usage/limits.
- `/token-reduce`: called after a successful generation to deduct tokens.

**Data sent**
- Site identification: `site_token`, `site_url` (domain), plugin identifier.
- Context for generation:
  - **Posts:** `post_title`, `post_excerpt`, `post_type`, `focus_keyword` (if set), current slug, selected model, `custom_prompt`, `auto_slug` flag.
  - **Terms:** `term_name`, `term_slug`, `term_desc`, `focus_keyword` (if set), selected model, `custom_prompt`, `auto_slug` flag.
- No end-user PII is intentionally sent by the plugin. Content fields are the data already stored in your WordPress site.

**Why it is sent**
- To obtain AI-generated SEO suggestions on demand and to check your token balance.

**Service provider**
- RankPilotAI — Terms of Service: https://rankpilotai.com/terms/  
- Privacy Policy: https://rankpilotai.com/privacy-policy/

== Uninstall ==
Removing the plugin deletes its settings and only the score meta it created:
- Deletes option: `aissp_settings`
- Deletes metas: `_aissp_score` on posts/terms
- Leaves user content metas intact: `_aissp_focus_keyword`, `_aissp_seo_title`, `_aissp_seo_description`

== Screenshots ==
1. Post edit – generator & preview
2. Score & checklist
3. Settings – key & usage
4. Bulk actions

== Developer Notes ==
**Meta Keys**
- `_aissp_focus_keyword`, `_aissp_seo_title`, `_aissp_seo_description`, `_aissp_score`

**Filters**
- `aissp_http_args( $args, $url, $payload, $method )`
- `aissp_http_retry_codes( $codes )`
- `aissp_http_retries( $n )`
- `aissp_http_result( $res, $url, $payload, $method, $args, $attempts )`
- `aissp_bulk_endpoint( $url )`

== Changelog ==
= 1.0.0 =
* First public release.

== Upgrade Notice ==
= 1.0.0 =
Initial release.

= 1.0.6 =
- Fixed plugin header and version constant.
- Updated Description/Privacy.

== Changelog ==

= 1.0.8 =
- Row-meta cleanup and small internal refactor.
