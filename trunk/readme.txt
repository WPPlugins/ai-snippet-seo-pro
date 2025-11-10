=== AI Snippet SEO Pro ===
Contributors: rankpilotai
Tags: ai, seo, title, meta, meta description
Requires at least: 5.8
cd ~/ai-snippet-seo-pro-svn
cat > trunk/readme.txt <<'EOF'
=== AI Snippet SEO Pro ===
Contributors: rankpilotai
Tags: ai, seo, title, meta, meta description
Requires at least: 5.8
Tested up to: 6.7.4
Requires PHP: 7.4
Stable tag: 1.0.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Generate SEO titles and meta descriptions with AI. Live preview, 0-100 score, keyword checks, auto-slug, and bulk actions.

== Description ==
Generate SEO-friendly titles and meta descriptions with one click. The plugin scores each snippet (0–100), validates keyword usage, and supports bulk actions for posts and taxonomies.

**Highlights**
- One-click AI generation (post and taxonomy editors)
- Live preview and checklist (title length, meta length, keyword in slug)
- Bulk generate from list tables (posts and terms)
- Optional auto-slug update (adds 301 via `_wp_old_slug`)
- Token-based usage via RankPilotAI (free tier available)

**How it works**
1. Connect your Site Key (Settings → AI Snippet SEO Pro → Site Key Management).
2. Pick a model and optionally enable auto-slug.
3. Open a post or term and click **Generate with AI**.
4. Review snippet, score, and checklist. Save.

**Compatibility**
Works alongside popular SEO plugins like Rank Math or Yoast. Use AI Snippet SEO Pro to generate and score snippets, then keep your existing SEO fields.

**Cloud service**
The plugin calls RankPilotAI endpoints to create snippets and to check token balance.

**Privacy**
Only minimal context is sent to generate suggestions (site key, domain, post/term fields you submit). No telemetry, no ads, no obfuscated code. See our site for Privacy & Terms.

**External services**
Base URL: `https://rankpilotai.com`
Endpoints (examples): `/api/snippet`, `/token-info`

**Uninstall**
- Removes plugin options in settings
- Deletes `_aissp_*` meta on posts/terms:
  `_aissp_focus_keyword`, `_aissp_seo_title`, `_aissp_seo_description`, `_aissp_score`

**Developer Notes**
Meta keys: `_aissp_focus_keyword`, `_aissp_seo_title`, `_aissp_seo_description`, `_aissp_score`  
Filters: `aissp_http_args`, `aissp_http_timeout`, `aissp_api_endpoint`, `aissp_allowed_models`

== Changelog ==
= 1.0.4 =
- Restore full Description section. Minor text polish.

= 1.0.3 =
- Fix readme header (Stable tag, License, short description).

= 1.0.2 =
- Fix tag structure, add license header, polish readme.

= 1.0.1 =
- Readme improvements and minor tweaks.

= 1.0.0 =
- Initial release.
