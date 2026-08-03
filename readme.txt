=== AI Snippet SEO Pro ===
Contributors: rankpilotai
Tags: seo, meta-description, title, snippets, ai
Requires at least: 5.8
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.0.32
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

AI-powered SEO snippet generation for posts and taxonomies. Create optimized titles, meta descriptions, focus keywords and slugs in one click.

== Description ==

AI Snippet SEO Pro generates SEO-optimized titles, meta descriptions, focus keywords and slugs for posts, pages, custom post types and taxonomies.

**Three ways to use:**

1. **Free Trial** - Start instantly with 25 free tokens, no registration or API key needed.
2. **Bring Your Own Key (BYOK)** - Use your own OpenAI API key for unlimited single generations at no cost.
3. **Pro** - Upgrade for bulk generation, auto-slug, all AI models, custom prompts, index/noindex control, and priority support.

**Highlights**

- One-click AI generation in the post and term editor
- Live preview, score badge (0-100), and snippet checklist
- Standalone SEO meta output (title, description, keywords tags in head)
- Index / Noindex control per content type (Pro)
- Bulk generate from list tables for posts and terms (Pro)
- Optional **Auto-Slug** update (WordPress keeps a 301 via `_wp_old_slug`)
- Model selection based on plan (GPT-4 Turbo / GPT-4.1 / GPT-4o)
- Works with custom post types and WooCommerce taxonomies

**Ratings**

If you enjoy using AI Snippet SEO Pro, please consider leaving a review on WordPress.org.

== Installation ==

1. Upload and activate **AI Snippet SEO Pro**.
2. Go to **AI Snippet SEO Pro** in the admin menu.
3. Choose your mode:
   - **Free Trial** - Click "Start Free Trial" for 25 free tokens (no registration needed)
   - **Own API Key** - Enter your OpenAI API key for unlimited single generations
   - **Pro** - Enter your RankPilotAI Site Key for full features (bulk, auto-slug, all models)
4. Open any post, page, or taxonomy term and click **Generate with AI Snippet SEO Pro**.

== Frequently Asked Questions ==

= Do I need to register or create an account? =
No. The free trial starts instantly with one click. For BYOK mode, just paste your OpenAI API key.

= Is there a free option? =
Yes. 25 free trial tokens are included with no registration. After that, use your own OpenAI API key for unlimited free generations, or upgrade to Pro for advanced features.

= Which models are supported? =
GPT-4.1 Mini (Trial & BYOK), plus GPT-4 Turbo, GPT-4.1, and GPT-4o in Pro mode.

= What data leaves my site? =
Only minimal context: post/term title, excerpt/description, current slug, optional focus keyword, your domain and site URL, selected model, and your Site Key. In BYOK mode, data goes directly to OpenAI and nothing is sent to RankPilotAI.

= Will Auto-Slug break existing URLs? =
When enabled, the new slug replaces the old one and WordPress stores the previous slug in `_wp_old_slug`, serving an automatic **301**.

= What is the difference between Trial, BYOK and Pro? =
- **Trial:** 25 lifetime tokens, GPT-4.1 Mini only, single generation only.
- **BYOK:** Unlimited single generations with your own OpenAI API key, GPT-4.1 Mini.
- **Pro:** Bulk generation, auto-slug, custom prompts, all models (GPT-4 Turbo, GPT-4.1, GPT-4o), index/noindex control, priority support, multi-site licenses.

= Does it replace my SEO plugin? =
No. You can keep your existing SEO plugin. This plugin focuses on fast, consistent AI generation and outputs its own meta tags.

= Works with custom post types and WooCommerce? =
Yes - any public post type, plus common taxonomies such as category, post_tag, product_cat, and product_tag.

== External services ==

This plugin connects to RankPilotAI's API to generate SEO snippets and to fetch token status.

=== What is sent and when ===

- **Trial mode:** Site URL is sent to RankPilotAI for registration. Post/term title, excerpt/description, slug are sent to RankPilotAI for processing.
- **BYOK mode:** Post/term title, excerpt/description, slug are sent directly to OpenAI's API using your own API key. Nothing is sent to RankPilotAI.
- **Pro mode:** Post/term title, excerpt/description, slug, selected model, custom prompt, site URL, domain, plugin slug, and Site Token are sent to RankPilotAI.
- On token-status checks in the settings page (Pro mode only): Site Token, site URL and domain.

=== Why it is sent ===

- To generate the requested SEO snippet and return it to your editor
- To authorize usage and show your remaining balance (Pro mode)
- To register and track trial token usage (Trial mode)

=== Endpoints ===

- https://rankpilotai.com/wp-json/rankpilotai/v1/snippet (Pro)
- https://rankpilotai.com/wp-json/rankpilotai/v1/snippet-bulk (Pro bulk)
- https://rankpilotai.com/wp-json/rankpilotai/v1/token-info (Pro)
- https://rankpilotai.com/wp-json/rankpilotai/v1/token-reduce (Pro)
- https://rankpilotai.com/wp-json/rankpilotai/v1/trial-register (Trial)
- https://rankpilotai.com/wp-json/rankpilotai/v1/trial-snippet (Trial)
- https://api.openai.com/v1/chat/completions (BYOK - direct to OpenAI)

=== Providers ===

- RankPilotAI (Terms: https://rankpilotai.com/terms-and-conditions, Privacy: https://rankpilotai.com/privacy-policy)
- OpenAI (BYOK mode only - Terms: https://openai.com/policies/terms-of-use, Privacy: https://openai.com/policies/privacy-policy)

== Uninstall ==
Removing the plugin deletes its settings and only the score meta it created:
- Deletes option: `aissp_settings` (includes trial_token, openai_key)
- Deletes metas: `_aissp_score` on posts/terms
- Leaves user content metas intact: `_aissp_focus_keyword`, `_aissp_seo_title`, `_aissp_seo_description`

== Screenshots ==
1. Post editor - AI generation panel with preview and checklist
2. Settings - mode selection cards (Trial, BYOK, Pro)
3. Settings - SEO settings and feature comparison table
4. Bulk actions on list tables

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

= 1.0.32 =
* SECURITY: TLS certificate verification is no longer disabled automatically. Previously, a failed certificate check triggered a silent retry with verification turned off, which could expose the request to a man-in-the-middle. Verification now stays on; site owners with a broken local CA bundle can opt in explicitly via the `aissp_http_allow_insecure_retry` filter.
* Improvement: removed the hardcoded CA bundle path so custom and host-provided certificate stores are respected.
* Maintenance: remaining inline code comments translated to English.
* Compatibility: re-verified with WordPress 7.0.

= 1.0.31 =
* Maintenance: routine housekeeping, minor code cleanup and performance tuning. No functional changes.
* Compatibility: re-verified with WordPress 7.0.

= 1.0.30 =
* Maintenance: minor polish, code cleanup and stability improvements. No functional changes.
* Compatibility: re-verified with WordPress 7.0.

= 1.0.29 =
* Maintenance: internal code cleanup and stability improvements. No functional changes.
* Compatibility: re-verified with WordPress 7.0.

= 1.0.28 =
* Compatibility: tested up to WordPress 7.0.
* Maintenance: minor housekeeping and copy cleanup. No functional changes.

= 1.0.27 =
* NEW: Three-mode system - Trial (25 free tokens), BYOK (your own OpenAI key), Pro (RankPilotAI).
* NEW: Plugin works immediately after install with zero-friction free trial.
* NEW: BYOK mode - unlimited single generations using your own OpenAI API key.
* NEW: Redesigned settings page with mode selection, feature comparison table, and Pro feature indicators.
* Bulk generation, auto-slug, custom prompt, model selection, and index/noindex are now Pro-only features.
* Maintenance: tested with WordPress 6.9.1, minor compatibility improvements.

= 1.0.26 =
* Maintenance: tested with WordPress 6.9.1, minor compatibility improvements.

= 1.0.25 =
* UI: Modernized settings page with gradient header, card-based layout, model radio cards, usage grid, and responsive design.

= 1.0.24 =
* Maintenance: minor version consistency updates and small documentation/copy cleanup. No functional changes.

= 1.0.23 =
* Maintenance: minor version consistency updates and small documentation/copy cleanup. No functional changes.

= 1.0.22 =
* Maintenance: version consistency updates and minor documentation/copy cleanup. No functional changes.

= 1.0.21 =
* New installs start with an empty Site Key field instead of a random generated key.

= 1.0.20 =
* Tweak: Updated short description and plugin listing copy. No functional changes.

= 1.0.10 =
* Marked as tested up to 6.8.3. FAQ clarified.

= 1.0.9 =
* Cleanup: removed custom "View details" row-meta link.

= 1.0.8 =
* Row-meta cleanup and small internal refactor.

= 1.0.0 =
* First public release.

== Upgrade Notice ==

= 1.0.32 =
Security update. The plugin no longer falls back to an unverified TLS connection when a certificate check fails. Updating is recommended.
= 1.0.29 =
Maintenance release. Safe to update; no functional changes.

= 1.0.28 =
Compatibility update: tested up to WordPress 7.0. Minor maintenance, no functional changes.

= 1.0.27 =
Major update: Free trial (25 tokens), BYOK mode (unlimited with your own OpenAI key), redesigned settings page. Existing Pro users are not affected.

= 1.0.0 =
First public release.


