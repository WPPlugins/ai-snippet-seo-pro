<?php
/**
 * AI Snippet SEO Pro — Admin Panel
 *
 * @file      settings-page.php
 * @package   RankPilotAI\AI_Snippet_SEO_Pro\Admin
 * @since     1.0.0
 * @version   1.0.25
 * @license   GPL-2.0-or-later
 *
 * Overview
 * - GPT model selection (o3 removed)
 * - Updated token costs → 1 / 3 / 5  (Turbo=1, 4.1=3, 4o=5)
 * - Membership gates (API + PMPro fallback)
 * - "Get Started / Buy Tokens" flow
 * - Show Usage Overview immediately after the first Site Key save
 * - Reject saving keys that belong to another domain
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Empty stub to keep the plugin hook present. */
function aissp_register_settings() {}

/**
 * PMPro fallback — level id → allowed models matrix.
 *
 * @param int $lvl PMPro level id (1=Free, 2=Starter, 3=Creator, 4=Expert).
 * @return array List of allowed model slugs for the level.
 */
function aissp_default_allowed_matrix( $lvl = 1 ){
	return [
		1 => [ 'gpt-4-turbo' ],
		2 => [ 'gpt-4-turbo', 'gpt-4.1' ],
		3 => [ 'gpt-4-turbo', 'gpt-4.1', 'gpt-4o' ],
		4 => [ 'gpt-4-turbo', 'gpt-4.1', 'gpt-4o' ], // Ultra-Premium (o3) removed
	][$lvl] ?? [ 'gpt-4-turbo' ];
}

/**
 * Render the settings page (two tabs: Site Key & SEO).
 *
 * @return void
 */
function aissp_render_settings_page() {

	/* Admin cache guard */
	if ( function_exists('nocache_headers') ) { nocache_headers(); }
	$__aissp_build = 'aissp-settings-2025-09-02-GLOBAL-REMAINING-v3';

	/* ---------------- Load current options ---------------- */
	$opts  = get_option( 'aissp_settings', [] );
	$token = $opts['site_token']   ?? '';
	$model = $opts['model_choice'] ?? 'gpt-4-turbo';
	$tab   = ( isset($_GET['tab']) && $_GET['tab']==='seo-settings' ) ? 'seo-settings' : 'site-key';

	/* =====================================================
	   1)  FORM POSTS
	===================================================== */
	if ( isset( $_POST['aissp_action'] ) ) {
	        if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    $action = sanitize_key( wp_unslash( $_POST['aissp_action'] ) );

    if ( $action === 'aissp_save_site_key' ) {
        check_admin_referer( 'aissp_save_site_key' );

        $newKey = sanitize_text_field( wp_unslash( $_POST['site_token'] ?? '' ) );
        $test   = aissp_check_token_status( $newKey );

        if ( ! empty( $test['error'] ) ) {
            echo '<div class="notice notice-error"><p><strong>Site Key Error:</strong> '
                 . esc_html( $test['error'] ) . '</p></div>';
            unset( $opts['site_token'] );
        } else {
            $opts['site_token'] = $newKey;
            echo '<div class="notice notice-success is-dismissible"><p>Site Key saved.</p></div>';
        }
        update_option( 'aissp_settings', $opts );
        $token = $opts['site_token'] ?? '';
        $tab   = 'site-key';

    } elseif ( $action === 'aissp_save_seo_settings' ) {
        check_admin_referer( 'aissp_save_seo_settings' );

        $mc = sanitize_text_field( wp_unslash( $_POST['model_choice'] ?? 'gpt-4-turbo' ) );
        $opts['model_choice']   = $mc;
        $opts['custom_prompt']  = sanitize_textarea_field( wp_unslash( $_POST['custom_prompt'] ?? '' ) );
        $opts['auto_slug']      = ( isset( $_POST['auto_slug'] ) && 'yes' === wp_unslash( $_POST['auto_slug'] ) ) ? 'yes' : 'no';

        foreach ( [ 'post','page','landing_page','category','post_tag','product_cat','product_tag' ] as $k ) {
            $raw = isset( $_POST["index_$k"] ) ? wp_unslash( $_POST["index_$k"] ) : 'no';
            $opts["index_$k"] = in_array( $raw, [ 'yes','no' ], true ) ? $raw : 'no';
        }

        update_option( 'aissp_settings', $opts );
        $model = $mc;
        $tab   = 'seo-settings';
        echo '<div class="notice notice-success is-dismissible"><p>Settings saved.</p></div>';
    }
}

	/* =====================================================
	   2)  TOKEN / MEMBERSHIP / MODEL GATES (GLOBAL NORMALIZE)
	===================================================== */
	$usage = $limit = $remain = $site_usage = null;   // $usage = GLOBAL usage (all sites)
	$membership='free';
	$allowed   =[ 'gpt-4-turbo' ];

	if ( $token ){
		$st = aissp_check_token_status( $token );

		// If the API didn't provide 'remaining_total', derive it from balances.
		if ( ! isset( $st['remaining_total'] ) ) {
			$fb = (int)($st['free_balance']   ?? 0);
			$pr = (int)($st['plan_remaining'] ?? 0);
			$pb = (int)($st['pack_balance']   ?? 0);
			if ( ($fb + $pr + $pb) > 0 ) { $st['remaining_total'] = $fb + $pr + $pb; }
		}

		if ( empty( $st['error'] ) ){
			/* ---- Read raw fields (tolerate different API versions) ---- */
			$site_usage = (int) ( $st['site_usage'] ?? 0 );

			$limit = (int) (
				$st['plan_limit_total']
				?? $st['plan_limit']
				?? $st['token_limit']
				?? 0
			);

			/* Global usage (all sites) – try common keys */
			$usage_total = null;
			foreach ( ['usage_total','all_usage','total_usage','token_usage','usage'] as $k ){
				if ( isset($st[$k]) ) { $usage_total = (int)$st[$k]; break; }
			}

			/* Global remaining (all sites) – try common keys */
			$remain_total = null;
			foreach ( ['remaining_total','remaining_all','global_remaining'] as $k ){
				if ( isset($st[$k]) ) { $remain_total = (int)$st[$k]; break; }
			}
			/* Some APIs only provide 'remaining' which may already be global */
			if ( $remain_total === null && isset($st['remaining']) ) {
				$remain_total = (int)$st['remaining'];
			}

			/* — Derivations — */
			// 1) If usage_total is missing but limit & remain_total exist → usage_total = limit - remain_total
			if ( $usage_total === null && $limit > 0 && $remain_total !== null ) {
				$usage_total = max( 0, $limit - $remain_total );
			}
			// 2) If remain_total is missing but limit & usage_total exist → remain_total = limit - usage_total
			if ( $remain_total === null && $limit > 0 && $usage_total !== null ) {
				$remain_total = max( 0, $limit - $usage_total );
			}
			// 3) Still missing usage_total → fall back to site_usage (API/UI can differ)
			if ( $usage_total === null ) {
				$usage_total = max( 0, (int)$site_usage );
			}

			$usage  = (int) $usage_total;               // GLOBAL usage
			$remain = aissp_status_remaining( $st );    // GLOBAL remaining (helper)
			$membership = strtolower( trim( $st['membership'] ?? 'free' ) );
			$allowed    = $st['allowed_models'] ?? [ 'gpt-4-turbo' ];
		}
	}

	/* ➊ Plan by token balance — 0–25 ⇒ Free, 26+ ⇒ Starter */
	$user_id       = get_current_user_id();
	$plan_tokens   = (int) get_user_meta(
		$user_id,
		'ai-snippet-seo-pro_membership_tokens',
		true
	);

	if ( $plan_tokens > 25 && $membership === 'free' ) {
		$membership = 'starter';
		$allowed    = aissp_default_allowed_matrix( 2 ); // Starter (lvl 2) → Turbo + 4.1
	}

	/* ---------- PMPro fallback (when API data is missing) ---------- */
	if ( function_exists('pmpro_getMembershipLevelForUser') ){
		$lvlObj = pmpro_getMembershipLevelForUser();
		$lvlId  = $lvlObj ? (int)$lvlObj->id : 1;
		if ( $membership === 'free' ){
			$allowed = aissp_default_allowed_matrix( $lvlId );
		}
		if ( $lvlId === 4 )     $membership = 'expert';
		elseif ( $lvlId === 3 ) $membership = 'creator';
		elseif ( $lvlId === 2 ) $membership = 'starter';
	}

	/* ---------- Ensure the currently selected model is allowed ---------- */
	if ( ! in_array( $model, $allowed, true ) ){
		$model = 'gpt-4-turbo';
		$opts['model_choice'] = $model;
		update_option( 'aissp_settings', $opts );
	}

	/* ❶ Limit-based correction — apply only when $limit is known */
if ( $limit !== null ) {
    if ( $limit <= 25 ) {
        $membership = 'free';
        $allowed    = aissp_default_allowed_matrix( 1 );   // Turbo
    } else {
        if ( $membership === 'free' ) {
            $membership = 'starter';
            $allowed    = aissp_default_allowed_matrix( 2 ); // Turbo + 4.1
        }
    }
}

	/* ---------- Get Started / Buy Tokens button ---------- */
	if ( $membership === 'free' ){
		$token_btn_label = 'GET STARTED';
		$token_btn_url   = 'https://rankpilotai.com/ai-snippet-seo-pro-pricing/';
	}else{
		$token_btn_label = 'BUY TOKENS';
		$token_btn_url   = 'https://rankpilotai.com/tokens/?plugin=ai-snippet-seo-pro';
	}

	/* ---------- Default indexing matrix ---------- */
	$def = [
		'post'=>'yes','page'=>'yes','landing_page'=>'yes',
		'category'=>'yes','post_tag'=>'no','product_cat'=>'yes','product_tag'=>'no'
	];

	/* ---------- Header logo ---------- */
	$header_logo = esc_url( AISSP_URL . 'admin/assets/img/header-logo.png' );
	?>
<!-- AISSPro build: <?php echo esc_html($__aissp_build); ?> -->
<style>
/* ── AI Snippet SEO Pro — Modern Settings ── */
:root{--aissp-dark:#1a2332;--aissp-blue:#0984e3;--aissp-blue-light:#0b9dfb;--aissp-green:#00b894;--aissp-purple:#6c5ce7;--aissp-border:#e2e8f0;--aissp-bg:#f8fafc;--aissp-card:#fff;--aissp-radius:12px;--aissp-shadow:0 1px 3px rgba(0,0,0,.08)}
*,*::before,*::after{box-sizing:border-box}

/* Header */
.aissp-header{background:linear-gradient(135deg,#1a2332 0%,#2d3f56 50%,#0984e3 100%);border-radius:var(--aissp-radius) var(--aissp-radius) 0 0;padding:28px 34px;display:flex;align-items:center;gap:16px}
.aissp-header img{width:48px;height:48px;border-radius:10px}
.aissp-header-text h1{margin:0;font-size:22px;font-weight:700;color:#fff;line-height:1.2}
.aissp-header-text p{margin:4px 0 0;font-size:13px;color:rgba(255,255,255,.7)}

/* Nav tabs */
.aissp-nav{display:flex;gap:0;background:#f1f5f9;border-left:1px solid var(--aissp-border);border-right:1px solid var(--aissp-border)}
.aissp-nav a{flex:1;text-align:center;padding:14px 20px;font-size:15px;font-weight:600;color:#64748b;text-decoration:none;border-bottom:3px solid transparent;transition:all .2s}
.aissp-nav a:hover{color:var(--aissp-blue);background:#fff}
.aissp-nav a.active{color:var(--aissp-blue);border-bottom-color:var(--aissp-blue);background:#fff}
.aissp-nav a svg{width:16px;height:16px;vertical-align:-2px;margin-right:6px}

/* Container */
.aissp-container{max-width:900px;margin:24px auto 40px;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif}
.aissp-body{background:var(--aissp-card);border:1px solid var(--aissp-border);border-top:none;border-radius:0 0 var(--aissp-radius) var(--aissp-radius);padding:32px 34px}

/* Section titles */
.aissp-section-title{font-size:18px;font-weight:700;color:var(--aissp-dark);margin:0 0 6px;display:flex;align-items:center;gap:10px}
.aissp-section-title svg{width:22px;height:22px;color:var(--aissp-blue);flex-shrink:0}
.aissp-section-desc{font-size:14px;color:#64748b;margin:0 0 20px}

/* Cards */
.aissp-card{background:var(--aissp-bg);border:1px solid var(--aissp-border);border-radius:10px;padding:22px 24px;margin-bottom:24px}

/* Token view */
.aissp-token-display{display:inline-block;background:#e2e8f0;padding:8px 16px;border-radius:8px;font-family:monospace;font-size:15px;color:var(--aissp-dark);letter-spacing:.3px}

/* Key input row */
.aissp-key-row{display:flex;align-items:center;gap:12px;margin-top:14px;max-width:560px}
.aissp-key-row input{flex:1;padding:11px 14px;font-size:15px;border:1px solid #cbd5e1;border-radius:8px;background:#fff;transition:border-color .2s}
.aissp-key-row input:focus{outline:none;border-color:var(--aissp-blue);box-shadow:0 0 0 3px rgba(9,132,227,.12)}

/* Buttons */
.aissp-btn{display:inline-block;padding:11px 28px;font-size:15px;font-weight:600;border:none;border-radius:8px;cursor:pointer;text-decoration:none;transition:all .2s;line-height:1.4}
.aissp-btn-primary{background:var(--aissp-blue);color:#fff!important}
.aissp-btn-primary:hover{background:var(--aissp-blue-light);transform:translateY(-1px);color:#fff!important}
.aissp-btn-green{background:var(--aissp-green);color:#fff!important}
.aissp-btn-green:hover{background:#00a884;transform:translateY(-1px);color:#fff!important}

/* Usage grid */
.aissp-usage-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-top:20px}
.aissp-usage-stat{background:#fff;border:1px solid var(--aissp-border);border-radius:10px;padding:18px;text-align:center}
.aissp-usage-stat .label{font-size:12px;color:#94a3b8;text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px}
.aissp-usage-stat .value{font-size:24px;font-weight:700;color:var(--aissp-dark)}
.aissp-usage-stat .value.blue{color:var(--aissp-blue)}
.aissp-usage-stat .value.green{color:var(--aissp-green)}
.aissp-usage-stat .value.red{color:#e74c3c}

/* Progress bar */
.aissp-progress{background:#e2e8f0;height:10px;border-radius:5px;overflow:hidden;margin:16px 0 8px}
.aissp-progress-fill{height:100%;border-radius:5px;transition:width .4s ease}
.aissp-progress-text{font-size:13px;color:#64748b;text-align:right}

/* Model radio cards */
.aissp-model-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-bottom:20px}
.aissp-model-card{position:relative;background:#fff;border:2px solid var(--aissp-border);border-radius:10px;padding:20px 16px;text-align:center;cursor:pointer;transition:all .2s}
.aissp-model-card:hover{border-color:var(--aissp-blue);box-shadow:var(--aissp-shadow)}
.aissp-model-card.selected{border-color:var(--aissp-blue);background:#eff6ff;box-shadow:0 0 0 3px rgba(9,132,227,.15)}
.aissp-model-card.disabled{opacity:.45;cursor:not-allowed;pointer-events:none}
.aissp-model-card input[type="radio"]{position:absolute;opacity:0;pointer-events:none}
.aissp-model-card .icon{font-size:28px;margin-bottom:8px}
.aissp-model-card .name{font-size:14px;font-weight:700;color:var(--aissp-dark);margin-bottom:2px}
.aissp-model-card .tier{font-size:12px;color:#94a3b8;margin-bottom:6px}
.aissp-model-card .cost{display:inline-block;background:#f1f5f9;padding:3px 10px;border-radius:20px;font-size:12px;font-weight:600;color:var(--aissp-blue)}

/* Form elements */
.aissp-label{display:block;font-size:14px;font-weight:600;color:var(--aissp-dark);margin-bottom:6px}
.aissp-input{width:100%;padding:11px 14px;font-size:15px;border:1px solid #cbd5e1;border-radius:8px;background:#fff;font-family:inherit;transition:border-color .2s}
.aissp-input:focus{outline:none;border-color:var(--aissp-blue);box-shadow:0 0 0 3px rgba(9,132,227,.12)}
.aissp-hint{font-size:13px;color:#94a3b8;margin-top:6px}

/* Index settings grid */
.aissp-index-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:12px}
.aissp-index-item{display:flex;align-items:center;justify-content:space-between;background:#fff;border:1px solid var(--aissp-border);border-radius:8px;padding:12px 16px}
.aissp-index-item .type-label{font-size:14px;font-weight:600;color:var(--aissp-dark)}
.aissp-index-item select{padding:6px 32px 6px 10px;font-size:13px;border:1px solid #cbd5e1;border-radius:6px;background:#fff url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='%231a2332'%3E%3Cpath d='M7 10l5 5 5-5'/%3E%3C/svg%3E") no-repeat right 8px center;background-size:12px;appearance:none;-webkit-appearance:none;cursor:pointer}

/* Auto-slug toggle */
.aissp-toggle-row{display:flex;align-items:center;gap:12px;padding:16px 20px;background:var(--aissp-bg);border:1px solid var(--aissp-border);border-radius:10px;margin-top:20px}
.aissp-toggle-row input[type="checkbox"]{width:18px;height:18px;accent-color:var(--aissp-blue);cursor:pointer}
.aissp-toggle-label{font-size:14px;color:var(--aissp-dark)}
.aissp-toggle-label strong{font-weight:600}
.aissp-toggle-label small{display:block;font-size:12px;color:#94a3b8;margin-top:2px}

/* Divider */
.aissp-divider{border:none;border-top:1px solid var(--aissp-border);margin:28px 0}

/* Intro text */
.aissp-intro{font-size:15px;color:#64748b;margin:0 0 20px}
.aissp-intro a{color:var(--aissp-blue);text-decoration:none;font-weight:600}
.aissp-intro a:hover{text-decoration:underline}

/* Responsive */
@media(max-width:768px){
	.aissp-header{padding:20px;flex-wrap:wrap}
	.aissp-body{padding:20px}
	.aissp-nav a{padding:12px 10px;font-size:13px}
	.aissp-usage-grid{grid-template-columns:repeat(2,1fr);gap:10px}
	.aissp-model-grid{grid-template-columns:1fr}
	.aissp-index-grid{grid-template-columns:1fr}
	.aissp-key-row{flex-direction:column;max-width:100%}
	.aissp-key-row input{width:100%}
	.aissp-btn{width:100%;text-align:center}
}
@media(max-width:480px){
	.aissp-usage-grid{grid-template-columns:1fr 1fr;gap:8px}
	.aissp-usage-stat{padding:14px 10px}
	.aissp-usage-stat .value{font-size:20px}
}
</style>

<div class="aissp-container">

	<!-- ── HEADER ── -->
	<div class="aissp-header">
		<img src="<?php echo $header_logo; ?>" alt="AI Snippet SEO Pro" width="48" height="48">
		<div class="aissp-header-text">
			<h1>AI Snippet SEO Pro</h1>
			<p>Generate AI SEO titles &amp; meta descriptions in one click</p>
		</div>
	</div>

	<!-- ── NAV ── -->
	<div class="aissp-nav">
		<a href="<?php echo esc_url( add_query_arg('tab','site-key',admin_url('admin.php?page=ai-snippet-seo-pro')) ); ?>"
		   class="<?php echo $tab==='site-key' ? 'active' : ''; ?>">
			<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4"/></svg>
			Site Key Management
		</a>
		<a href="<?php echo esc_url( add_query_arg('tab','seo-settings',admin_url('admin.php?page=ai-snippet-seo-pro')) ); ?>"
		   class="<?php echo $tab==='seo-settings' ? 'active' : ''; ?>">
			<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
			SEO Snippet Settings
		</a>
	</div>

	<!-- ── BODY ── -->
	<div class="aissp-body">

	<?php /* ================= TAB: SITE KEY ================= */ ?>
	<?php if ( $tab === 'site-key' ) : ?>

		<div class="aissp-section-title">
			<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4"/></svg>
			Site Key Management
		</div>

		<?php if ( ! $token ) : ?>
			<p class="aissp-intro">Enter your Site Key below to connect your site with
				<a href="https://rankpilotai.com" target="_blank" rel="noopener">RankPilotAI</a>.</p>

			<div class="aissp-card">
				<form method="post">
					<?php wp_nonce_field('aissp_save_site_key'); ?>
					<input type="hidden" name="aissp_action" value="aissp_save_site_key">
					<label class="aissp-label">Site Key</label>
					<div class="aissp-key-row">
						<input type="text" name="site_token" class="aissp-input" placeholder="rp_xxxxxxxxxxxxxxxxx" required style="flex:1">
						<button type="submit" class="aissp-btn aissp-btn-primary">Save Site Key</button>
					</div>
					<p class="aissp-hint">Generate your Site Key from your account at
						<a href="https://rankpilotai.com" target="_blank" rel="noopener" style="color:var(--aissp-blue)">RankPilotAI.com</a></p>
				</form>
			</div>

		<?php else : ?>
			<p class="aissp-section-desc">Your site is connected to RankPilotAI.</p>

			<div class="aissp-card">
				<label class="aissp-label">Active Site Key</label>
				<div class="aissp-token-display"><?php echo esc_html($token); ?></div>
			</div>

			<?php if ( $usage !== null ) : ?>
			<!-- Usage Overview -->
			<div class="aissp-section-title" style="margin-top:28px">
				<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20V10"/><path d="M18 20V4"/><path d="M6 20v-4"/></svg>
				Usage Overview
			</div>
			<p class="aissp-section-desc">Your current token usage across all sites.</p>

			<div class="aissp-card" style="padding-bottom:16px">
				<div class="aissp-usage-grid">
					<div class="aissp-usage-stat">
						<div class="label">Website</div>
						<div class="value" style="font-size:15px"><?php echo esc_html( parse_url( home_url(), PHP_URL_HOST ) ?: 'Site' ); ?></div>
					</div>
					<div class="aissp-usage-stat">
						<div class="label">Usage</div>
						<div class="value blue"><?php echo number_format_i18n( (int)$usage ); ?></div>
					</div>
					<div class="aissp-usage-stat">
						<div class="label">Plan Limit</div>
						<div class="value"><?php echo number_format_i18n( (int)$limit ); ?></div>
					</div>
					<div class="aissp-usage-stat">
						<div class="label">Remaining</div>
						<div class="value <?php echo (int)$remain > 0 ? 'green' : 'red'; ?>"><?php echo number_format_i18n( (int)$remain ); ?></div>
					</div>
				</div>

				<?php
					$pct = $limit ? round( (($limit - (int)$remain) / (int)$limit) * 100 ) : 0;
					$bar_color = ($remain === 0) ? '#e74c3c' : 'var(--aissp-blue)';
				?>
				<div class="aissp-progress">
					<div class="aissp-progress-fill" style="width:<?php echo (int)$pct; ?>%;background:<?php echo $bar_color; ?>"></div>
				</div>
				<div class="aissp-progress-text"><?php echo (int)$pct; ?>% used</div>

				<div style="text-align:center;margin-top:16px">
					<a href="<?php echo esc_url( $token_btn_url ); ?>" target="_blank" rel="noopener"
					   class="aissp-btn <?php echo $membership === 'free' ? 'aissp-btn-primary' : 'aissp-btn-green'; ?>">
						<?php echo esc_html( $token_btn_label ); ?>
					</a>
				</div>
			</div>
			<?php endif; ?>

			<!-- Update Key -->
			<hr class="aissp-divider">
			<div class="aissp-section-title">
				<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
				Update / Change Site Key
			</div>
			<div class="aissp-card">
				<form method="post">
					<?php wp_nonce_field('aissp_save_site_key'); ?>
					<input type="hidden" name="aissp_action" value="aissp_save_site_key">
					<div class="aissp-key-row">
						<input type="text" name="site_token" value="<?php echo esc_attr($token); ?>">
						<button type="submit" class="aissp-btn aissp-btn-primary">Save</button>
					</div>
				</form>
			</div>
		<?php endif; ?>

	<?php /* ================= TAB: SEO SETTINGS ================= */ ?>
	<?php else :
		$dis = empty($token) ? 'disabled' : ''; ?>

		<div class="aissp-section-title">
			<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
			SEO Snippet Settings
		</div>
		<p class="aissp-section-desc">Configure your AI model, prompts, indexing rules and slug behavior.</p>

		<form method="post">
			<?php wp_nonce_field('aissp_save_seo_settings'); ?>
			<input type="hidden" name="aissp_action" value="aissp_save_seo_settings">

			<!-- Model Selection -->
			<label class="aissp-label" style="margin-bottom:12px">
				<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:16px;height:16px;vertical-align:-2px;margin-right:4px"><path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/></svg>
				GPT Model
			</label>
			<div class="aissp-model-grid">
				<?php
				$models = [
					'gpt-4-turbo' => ['name'=>'GPT-4 Turbo','tier'=>'Economical','cost'=>'1 token / snippet','icon'=>'&#x1F7E2;','color'=>'#00b894'],
					'gpt-4.1'     => ['name'=>'GPT-4.1','tier'=>'Balanced','cost'=>'3 tokens / snippet','icon'=>'&#x1F535;','color'=>'#0984e3'],
					'gpt-4o'      => ['name'=>'GPT-4o','tier'=>'Premium','cost'=>'5 tokens / snippet','icon'=>'&#x1F7E3;','color'=>'#6c5ce7'],
				];
				foreach ( $models as $val => $m ) :
					$is_allowed  = in_array( $val, $allowed, true );
					$is_selected = ( $model === $val );
					$cls  = 'aissp-model-card';
					$cls .= $is_selected ? ' selected' : '';
					$cls .= ( !$is_allowed || $dis ) ? ' disabled' : '';
				?>
				<label class="<?php echo esc_attr($cls); ?>">
					<input type="radio" name="model_choice" value="<?php echo esc_attr($val); ?>"
						   <?php checked($is_selected); ?>
						   <?php echo (!$is_allowed || $dis) ? 'disabled' : ''; ?>>
					<div class="icon"><?php echo $m['icon']; ?></div>
					<div class="name"><?php echo esc_html($m['name']); ?></div>
					<div class="tier"><?php echo esc_html($m['tier']); ?></div>
					<div class="cost"><?php echo esc_html($m['cost']); ?></div>
				</label>
				<?php endforeach; ?>
			</div>
			<p class="aissp-hint">Greyed-out models require a higher plan.
				<a href="https://rankpilotai.com/ai-snippet-seo-pro-pricing/" target="_blank" rel="noopener" style="color:var(--aissp-blue)">Upgrade</a></p>

			<hr class="aissp-divider">

			<!-- Custom Prompt -->
			<div class="aissp-card">
				<label class="aissp-label" for="custom_prompt">Custom Prompt</label>
				<textarea id="custom_prompt" name="custom_prompt" rows="3" class="aissp-input"
						  <?php echo $dis; ?>><?php echo esc_textarea($opts['custom_prompt']??''); ?></textarea>
				<p class="aissp-hint">We already apply an internal prompt that meets all SEO criteria.
					Add tone or extra instructions here if you want the AI to include them <em>on top of</em> ours.</p>
			</div>

			<hr class="aissp-divider">

			<!-- Index Settings -->
			<div class="aissp-section-title">
				<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
				Index / Noindex Settings
			</div>
			<p class="aissp-section-desc">Choose which content types should include an index or noindex meta tag.</p>

			<div class="aissp-index-grid">
				<?php
				$labels=['post'=>'Post','page'=>'Page','landing_page'=>'Landing Page',
				         'category'=>'Category','post_tag'=>'Post Tag',
				         'product_cat'=>'Product Category','product_tag'=>'Product Tag'];
				foreach($labels as $k=>$lab):
					$val=$opts["index_$k"]??$def[$k];?>
					<div class="aissp-index-item">
						<span class="type-label"><?php echo esc_html($lab); ?></span>
						<select name="index_<?php echo esc_attr($k); ?>" <?php echo $dis; ?>>
							<option value="yes" <?php selected($val,'yes'); ?>>Index</option>
							<option value="no"  <?php selected($val,'no' ); ?>>Noindex</option>
						</select>
					</div>
				<?php endforeach; ?>
			</div>

			<!-- Auto Slug -->
			<div class="aissp-toggle-row">
				<input type="checkbox" id="auto_slug" name="auto_slug" value="yes"
					   <?php checked($opts['auto_slug'] ?? 'no', 'yes'); ?> <?php echo $dis; ?>>
				<label class="aissp-toggle-label" for="auto_slug">
					<strong>Auto-update URL Slug</strong>
					<small>Replace slug with AI suggestion. WordPress adds a 301 redirect via <code>_wp_old_slug</code>.</small>
				</label>
			</div>

			<hr class="aissp-divider">

			<button type="submit" class="aissp-btn aissp-btn-primary" <?php echo $dis; ?>>
				<?php echo empty($token) ? 'Save (needs key)' : 'Save Changes'; ?>
			</button>
			<?php if(empty($token)):?><span style="margin-left:10px;color:#94a3b8;font-size:14px">Enter a Site Key first</span><?php endif; ?>
		</form>
	<?php endif; ?>

	</div><!-- .aissp-body -->
</div><!-- .aissp-container -->

<script>
(function(){
	var cards = document.querySelectorAll('.aissp-model-card:not(.disabled)');
	cards.forEach(function(card){
		card.addEventListener('click', function(){
			document.querySelectorAll('.aissp-model-card').forEach(function(c){ c.classList.remove('selected'); });
			card.classList.add('selected');
			var radio = card.querySelector('input[type="radio"]');
			if(radio) radio.checked = true;
		});
	});
})();
</script>

<?php } /* end aissp_render_settings_page */ ?>
