<?php
/**
 * AI Snippet SEO Pro — Admin Panel
 *
 * @file      settings-page.php
 * @package   RankPilotAI\AI_Snippet_SEO_Pro\Admin
 * @since     1.0.0
 * @version   1.0.0
 * @license   GPL-2.0-or-later
 *
 * Overview
 * - GPT model selection (o3 removed)
 * - Updated token costs → 1 / 3 / 5  (Turbo=1, 4.1=3, 4o=5)
 * - Membership gates (API + PMPro fallback)
 * - “Get Started / Buy Tokens” flow
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
	?>
<!-- AISSPro build: <?php echo esc_html($__aissp_build); ?> -->
<!-- =================================================================
      BEGIN  HTML
================================================================= -->
<div class="aissp-wrapper">
	<aside class="aissp-sidebar">
		<h2>AI Snippet SEO Pro</h2>
		<nav>
			<a href="<?php echo esc_url( add_query_arg('tab','site-key',admin_url('admin.php?page=ai-snippet-seo-pro')) ); ?>"
			   class="<?php echo $tab==='site-key' ? 'active' : ''; ?>">Site Key Management</a>
			<a href="<?php echo esc_url( add_query_arg('tab','seo-settings',admin_url('admin.php?page=ai-snippet-seo-pro')) ); ?>"
			   class="<?php echo $tab==='seo-settings' ? 'active' : ''; ?>">SEO Snippet Settings</a>
		</nav>
	</aside>

	<main class="aissp-content">
	<?php /* ================= TAB: SITE KEY ================= */ ?>
	<?php if ( $tab === 'site-key' ) : ?>
		<h2>RankPilotAI – Site Key Management</h2>

		<?php if ( ! $token ) : ?>
			<p class="intro-txt">Enter your Site Key below to get started.</p>
			<form method="post" class="aissp-form">
				<?php wp_nonce_field('aissp_save_site_key'); ?>
				<input type="hidden" name="aissp_action" value="aissp_save_site_key">
				<input type="text" name="site_token" class="regular-text" placeholder="rp_xxxxxxxxxxxxxxxxx" required>
				<p class="desc linkpad">Generate your Site Key from your account at
					<a href="https://rankpilotai.com" target="_blank" rel="noopener">RankPilotAI.com</a></p>
				<button class="primary-btn">Save Site Key</button>
			</form>

		<?php else : ?>
			<p><strong>Your Active Site Key</strong></p>
			<code class="token-view"><?php echo esc_html($token); ?></code>

			<?php if ( $usage !== null ) : ?>
			<div class="usage-card">
				<h3>Usage Overview</h3>
				<table class="usage-table">
					<thead><tr>
						<th>This&nbsp;Site</th>
						<th>Usage</th>
						<th>Plan&nbsp;Limit</th>
						<th>Remaining</th>
						<th>Progress</th>
						<th></th>
					</tr></thead>
					<tbody><tr>
						<td><?php echo esc_html( parse_url( home_url(), PHP_URL_HOST ) ?: 'Site' ); ?></td>
						<td><?php echo number_format_i18n( (int)$usage ); ?></td> <!-- GLOBAL usage -->
						<td><?php echo number_format_i18n( (int)$limit ); ?></td>
                        <td><?php echo number_format_i18n( (int)$remain ); ?></td>   <!-- GLOBAL remaining -->
						<td>
							<?php
								$pct = $limit ? round( (($limit - (int)$remain) / (int)$limit) * 100 ) : 0;
								$bar = ($remain === 0) ? '#dc3232' : '#3b82f6';
							?>
							<div class="bar-bg">
							  <div class="bar-fill" style="width:<?php echo $pct; ?>%;background:<?php echo $bar; ?>"></div>
							</div>
						</td>
						<td>
							<a href="<?php echo esc_url( $token_btn_url ); ?>" target="_blank" rel="noopener"
							   class="primary-btn"><?php echo esc_html( $token_btn_label ); ?></a>
						</td>
					</tr></tbody>
				</table>
			</div>
			<?php endif; ?>

			<form method="post" class="aissp-form" style="margin-top:26px;">
				<?php wp_nonce_field('aissp_save_site_key'); ?>
				<input type="hidden" name="aissp_action" value="aissp_save_site_key">
				<h3 class="sub-head">Update / Change Site Key</h3>
				<div class="key-row">
					<input type="text" name="site_token" value="<?php echo esc_attr($token); ?>" class="regular-text">
					<button class="primary-btn">Save</button>
				</div>
			</form>
		<?php endif; ?>

	<?php /* ================= TAB: SEO SETTINGS ================= */ ?>
	<?php else :
		$dis = empty($token) ? 'disabled' : ''; ?>
		<h2>SEO Snippet Settings</h2>

		<form method="post" class="aissp-form-wide">
			<?php wp_nonce_field('aissp_save_seo_settings'); ?>
			<input type="hidden" name="aissp_action" value="aissp_save_seo_settings">

			<table class="form-table">

				<tr>
					<th><label for="model_choice">GPT Model</label></th>
					<td>
						<select id="model_choice" name="model_choice" <?php echo $dis;?>>
							<option value="gpt-4-turbo" <?php selected($model,'gpt-4-turbo'); ?>>
								🟢 Economical (GPT-4 Turbo) – 1 token / snippet</option>

							<option value="gpt-4.1" <?php selected($model,'gpt-4.1');?>
								<?php echo in_array('gpt-4.1',$allowed,true)?'':'disabled';?>>
								🔵 Balanced (GPT-4.1) – 3 tokens / snippet</option>

							<option value="gpt-4o" <?php selected($model,'gpt-4o');?>
								<?php echo in_array('gpt-4o',$allowed,true)?'':'disabled';?>>
								🟣 Premium (GPT-4o) – 5 tokens / snippet</option>
						</select>
						<p class="description" style="margin-top:6px;">
							Unavailable options appear greyed-out – upgrade your plan to unlock them.
						</p>
					</td>
				</tr>

				<tr>
					<th><label for="custom_prompt">Custom Prompt</label></th>
					<td>
						<textarea id="custom_prompt" name="custom_prompt" rows="3" class="regular-text"
								  style="width:420px;" <?php echo $dis;?>><?php
							echo esc_textarea($opts['custom_prompt']??'');?></textarea>
						<p class="description">
							We already apply an internal prompt that meets all SEO criteria.
							Add tone or extra instructions here if you want the AI to include them
							<em>on top of</em> ours.
						</p>
					</td>
				</tr>

				<?php
				$labels=['post'=>'Post','page'=>'Page','landing_page'=>'Landing Page',
				         'category'=>'Category','post_tag'=>'Post Tag',
				         'product_cat'=>'Product Category','product_tag'=>'Product Tag'];
				foreach($labels as $k=>$lab):
					$val=$opts["index_$k"]??$def[$k];?>
					<tr>
						<th><?php echo $lab; ?> Index?</th>
						<td>
							<select name="index_<?php echo $k;?>" <?php echo $dis;?>>
								<option value="yes" <?php selected($val,'yes');?>>Yes</option>
								<option value="no"  <?php selected($val,'no' );?>>No (noindex)</option>
							</select>
						</td>
					</tr>
				<?php endforeach; ?>
				<tr>
				  <th><label for="auto_slug">Auto-update URL Slug?</label></th>
				  <td>
					<label>
					  <input type="checkbox" id="auto_slug" name="auto_slug" value="yes"
							 <?php checked($opts['auto_slug'] ?? 'no', 'yes'); ?> <?php echo $dis; ?>>
					  Replace slug with AI suggestion &nbsp;<small>(adds 301 redirect)</small>
					</label>
					<p class="description">
					  Default OFF. When enabled, the AI-generated slug will overwrite the current one.<br>
					  WordPress instantly creates a <code>301</code> from the old URL (<em>_wp_old_slug_</em> meta).
					</p>
				  </td>
				</tr>
			</table>

			<p>
				<button type="submit" class="primary-btn"><?php
					echo empty($token)?'Save (needs key)':'Save Changes';?></button>
				<?php if(empty($token)):?><span class="need-key">← enter a Site Key first</span><?php endif;?>
			</p>
		</form>
	<?php endif; ?>
	</main>
</div>

<?php } /* end aissp_render_settings_page */ ?>
