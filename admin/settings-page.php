<?php
/*  AI Snippet SEO Pro — Settings Page (Admin)
 *  Version: 1.0.27
 *  Three modes: Trial (25 free), BYOK (own OpenAI key), Pro (RankPilotAI)
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! function_exists( 'aissp_register_settings' ) ) {
	function aissp_register_settings() {}
}

if ( ! function_exists( 'aissp_render_settings_page' ) ) {
function aissp_render_settings_page() {

	$opts  = get_option( AISSP_SETTINGS_SLUG, [] );
	$token = $opts['site_token']   ?? '';
	$model = $opts['model_choice'] ?? 'gpt-4-turbo';
	$mode  = aissp_active_mode();

	/* ───────────── FORM SUBMISSIONS ───────────── */
	if ( isset($_POST['aissp_action']) && check_admin_referer($_POST['aissp_action']) ) {
		if ( ! current_user_can('manage_options') ) wp_die('Insufficient permissions');

		if ( $_POST['aissp_action'] === 'aissp_save_site_key' ) {
			$newKey = sanitize_text_field( wp_unslash( $_POST['site_token'] ?? '' ) );
			$test   = aissp_check_token_status( $newKey );

			if ( ! empty( $test['error'] ) ) {
				echo '<div class="notice notice-error"><p><strong>Site Key Error:</strong> '
				     . esc_html( $test['error'] ) . '</p></div>';
				unset( $opts['site_token'] );
			} else {
				$opts['site_token'] = $newKey;
				echo '<div class="notice notice-success is-dismissible"><p>Site Key saved. Pro mode activated!</p></div>';
			}
			update_option( AISSP_SETTINGS_SLUG, $opts );
			$token = $opts['site_token'] ?? '';
			$mode  = aissp_active_mode();

		} elseif ( $_POST['aissp_action'] === 'aissp_save_seo_settings' ) {
			$mc = sanitize_text_field( wp_unslash( $_POST['model_choice'] ?? 'gpt-4-turbo' ) );
			$opts['model_choice']  = $mc;
			$opts['custom_prompt'] = sanitize_textarea_field( wp_unslash( $_POST['custom_prompt'] ?? '' ) );
			$opts['auto_slug']     = ( isset($_POST['auto_slug']) && $_POST['auto_slug'] === 'yes' ) ? 'yes' : 'no';

			foreach ( [ 'post','page','landing_page','category','post_tag','product_cat','product_tag' ] as $k ) {
				$raw = isset( $_POST["index_$k"] ) ? wp_unslash( $_POST["index_$k"] ) : 'no';
				$opts["index_$k"] = in_array( $raw, [ 'yes','no' ], true ) ? $raw : 'no';
			}

			update_option( AISSP_SETTINGS_SLUG, $opts );
			$model = $mc;
			echo '<div class="notice notice-success is-dismissible"><p>Settings saved.</p></div>';
		}
	}

	/* ───────────── PRO: TOKEN / USAGE ───────────── */
	$usage = $limit = $remain = $site_usage = null;
	$membership     = 'free';
	$allowed_models = ['gpt-4-turbo'];
	$is_pro         = $mode === 'pro';

	if ( $token && $is_pro ) {
		$st = aissp_check_token_status( $token );

		if ( ! isset( $st['remaining_total'] ) ) {
			$fb = (int)($st['free_balance']   ?? 0);
			$pr = (int)($st['plan_remaining'] ?? 0);
			$pb = (int)($st['pack_balance']   ?? 0);
			if ( ($fb + $pr + $pb) > 0 ) { $st['remaining_total'] = $fb + $pr + $pb; }
		}

		if ( empty( $st['error'] ) ) {
			$site_usage = (int)($st['site_usage'] ?? 0);
			$limit      = (int)(
				$st['plan_limit_total']
				?? $st['plan_limit']
				?? $st['token_limit']
				?? 0
			);

			$usage_total  = null;
			$remain_total = null;

			foreach ( ['usage_total','all_usage','total_usage','token_usage','usage'] as $k ) {
				if ( isset($st[$k]) ) { $usage_total = (int)$st[$k]; break; }
			}
			foreach ( ['remaining_total','remaining_all','global_remaining'] as $k ) {
				if ( isset($st[$k]) ) { $remain_total = (int)$st[$k]; break; }
			}
			if ( $remain_total === null && isset($st['remaining']) ) {
				$remain_total = (int)$st['remaining'];
			}
			if ( $usage_total === null && $limit > 0 && $remain_total !== null ) {
				$usage_total = max( 0, $limit - $remain_total );
			}
			if ( $remain_total === null && $limit > 0 && $usage_total !== null ) {
				$remain_total = max( 0, $limit - $usage_total );
			}
			if ( $usage_total === null ) {
				$usage_total = max( 0, (int)$site_usage );
			}

			$usage  = (int)$usage_total;
			$remain = aissp_status_remaining( $st );

			if ( ! empty( $st['allowed_models'] ) && is_array( $st['allowed_models'] ) ) {
				$allowed_models = array_values( array_unique( array_map( 'strval', $st['allowed_models'] ) ) );
			}

			$membership = strtolower( trim( $st['membership'] ?? 'free' ) );
		}

		$gate = [
			'free'    => ['gpt-4-turbo'],
			'starter' => ['gpt-4-turbo','gpt-4.1'],
			'creator' => ['gpt-4-turbo','gpt-4.1','gpt-4o'],
			'expert'  => ['gpt-4-turbo','gpt-4.1','gpt-4o'],
		];
		if ( isset($gate[$membership]) ) {
			$allowed_models = $gate[$membership];
		}

		if ( ! in_array($model, $allowed_models, true) ) {
			$model = 'gpt-4-turbo';
			$opts['model_choice'] = $model;
			update_option( AISSP_SETTINGS_SLUG, $opts );
		}
	}

	$trial_remaining = (int)( $opts['trial_remaining'] ?? 0 );
	$byok_key_masked = '';
	if ( ! empty( $opts['openai_key'] ) ) {
		$k = $opts['openai_key'];
		$byok_key_masked = substr($k, 0, 7) . '...' . substr($k, -4);
	}

	/* ───────────── INDEX DEFAULTS ───────────── */
	$index_defaults = [
		'post'        => 'yes',
		'page'        => 'yes',
		'landing_page'=> 'yes',
		'category'    => 'yes',
		'post_tag'    => 'no',
		'product_cat' => 'yes',
		'product_tag' => 'no',
	];
	$index_labels = [
		'post'        => 'Post',
		'page'        => 'Page',
		'landing_page'=> 'Landing Page',
		'category'    => 'Category',
		'post_tag'    => 'Post Tag',
		'product_cat' => 'Product Category',
		'product_tag' => 'Product Tag',
	];

	/* ───────────── BUY TOKENS BUTTON ───────────── */
	if ( $membership === 'free' ) {
		$token_btn_label = 'GET PREMIUM NOW';
		$token_btn_url   = 'https://rankpilotai.com/ai-snippet-seo-pro-pricing/';
	} else {
		$token_btn_label = 'BUY TOKENS';
		$token_btn_url   = 'https://rankpilotai.com/tokens/?plugin=ai-snippet-seo-pro';
	}
	?>

	<div class="aissp-settings-wrap">

		<!-- HEADER -->
		<div class="aissp-header">
			<div class="aissp-header-inner">
				<div class="aissp-header-left">
					<div class="aissp-logo-icon">
						<img src="<?php echo esc_url( AISSP_URL . 'admin/assets/img/ai-snippet-seo-pro.svg' ); ?>" alt="AI Snippet SEO Pro" width="36" height="36">
					</div>
					<div>
						<h1 class="aissp-header-title">AI Snippet SEO Pro</h1>
						<p class="aissp-header-subtitle">Standalone SEO Snippet Generation</p>
					</div>
				</div>
				<div class="aissp-header-right">
					<?php if ( $mode !== 'none' ) : ?>
					<span class="aissp-mode-pill aissp-mode-pill-<?php echo esc_attr( $mode ); ?>">
						<?php echo esc_html( strtoupper( $mode ) ); ?> MODE
					</span>
					<?php endif; ?>
					<span class="aissp-version-badge">v<?php echo esc_html( AISSP_VERSION ); ?></span>
				</div>
			</div>
		</div>

		<!-- MAIN CONTENT -->
		<div class="aissp-main-content">

		<!-- ==================== SECTION 1: MODE SELECTION ==================== -->
		<div class="aissp-section">
			<div class="aissp-section-header">
				<h2>Getting Started</h2>
				<p>Choose how you want to generate SEO snippets for your posts.</p>
			</div>

			<?php if ( $mode === 'none' ) : ?>
			<div class="aissp-mode-cards">

				<!-- Trial Card -->
				<div class="aissp-mode-card aissp-mode-card-trial">
					<div class="aissp-mode-card-badge">FREE</div>
					<h3>Free Trial</h3>
					<p class="aissp-mode-card-desc">Start instantly with 25 free snippet generations. No API key needed.</p>
					<ul class="aissp-mode-card-features">
						<li>25 lifetime tokens</li>
						<li>GPT-4 Turbo model</li>
						<li>Single post generation</li>
						<li>SEO snippet score</li>
						<li class="aissp-feature-na">No bulk generation</li>
						<li class="aissp-feature-na">No custom prompt</li>
						<li class="aissp-feature-na">No auto-slug</li>
					</ul>
					<button type="button" class="aissp-btn aissp-btn-trial" id="aissp-start-trial">
						Start Free Trial
					</button>
					<p class="aissp-mode-card-note">No credit card required</p>
				</div>

				<!-- BYOK Card -->
				<div class="aissp-mode-card aissp-mode-card-byok">
					<div class="aissp-mode-card-badge">UNLIMITED</div>
					<h3>Your Own API Key</h3>
					<p class="aissp-mode-card-desc">Use your own OpenAI API key. You pay OpenAI directly, we don't touch your key.</p>
					<ul class="aissp-mode-card-features">
						<li>Unlimited generations</li>
						<li>GPT-4.1 Mini model</li>
						<li>Single post generation</li>
						<li>SEO snippet score</li>
						<li class="aissp-feature-na">No bulk generation</li>
						<li class="aissp-feature-na">No custom prompt</li>
						<li class="aissp-feature-na">No auto-slug</li>
					</ul>
					<div class="aissp-byok-inline-form" id="aissp-byok-form" style="display:none;">
						<input type="text" id="aissp-byok-key" placeholder="sk-..." autocomplete="off">
						<button type="button" class="aissp-btn aissp-btn-byok-save" id="aissp-save-byok">Save Key</button>
					</div>
					<button type="button" class="aissp-btn aissp-btn-byok" id="aissp-show-byok-form">
						Enter OpenAI Key
					</button>
					<p class="aissp-mode-card-note">Your key stays on your server</p>
				</div>

				<!-- Pro Card -->
				<div class="aissp-mode-card aissp-mode-card-pro">
					<div class="aissp-mode-card-badge">PRO</div>
					<h3>RankPilotAI Pro</h3>
					<p class="aissp-mode-card-desc">Full power: bulk generate, auto-slug, custom prompts, all models, index/noindex control.</p>
					<ul class="aissp-mode-card-features">
						<li>No API key needed</li>
						<li>Bulk generation</li>
						<li>Auto-Slug replacement</li>
						<li>All models (Turbo/4.1/4o)</li>
						<li>Custom prompt</li>
						<li>Index/Noindex control</li>
						<li>Priority support</li>
					</ul>
					<form method="post" class="aissp-pro-inline-form" id="aissp-pro-form" style="display:none;">
						<?php wp_nonce_field('aissp_save_site_key'); ?>
						<input type="hidden" name="aissp_action" value="aissp_save_site_key">
						<input type="text" name="site_token" placeholder="rp_xxxxxxxxxxxxxxxxx" required>
						<button type="submit" class="aissp-btn aissp-btn-pro-save">Save Key</button>
					</form>
					<button type="button" class="aissp-btn aissp-btn-pro" id="aissp-show-pro-form">
						Enter Site Key
					</button>
					<p class="aissp-mode-card-note">
						<a href="https://rankpilotai.com/ai-snippet-seo-pro-pricing/" target="_blank" rel="noopener">Get a Site Key</a>
					</p>
				</div>
			</div>

			<?php elseif ( $mode === 'trial' ) : ?>
			<div class="aissp-active-mode-panel aissp-panel-trial">
				<div class="aissp-panel-header">
					<div class="aissp-panel-icon">
						<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
					</div>
					<div>
						<h3>Free Trial Active</h3>
						<p><?php echo esc_html( $trial_remaining ); ?> of 25 tokens remaining</p>
					</div>
				</div>
				<div class="aissp-trial-bar-wrap">
					<div class="aissp-trial-bar">
						<div class="aissp-trial-bar-fill" style="width:<?php echo esc_attr( round( ($trial_remaining / 25) * 100 ) ); ?>%"></div>
					</div>
					<span class="aissp-trial-bar-label"><?php echo esc_html( $trial_remaining ); ?> / 25</span>
				</div>

				<?php if ( $trial_remaining <= 0 ) : ?>
				<div class="aissp-trial-exhausted">
					<p>Your trial tokens are used up. Choose one of these options to continue:</p>
					<div class="aissp-trial-cta-row">
						<button type="button" class="aissp-btn aissp-btn-byok" id="aissp-show-byok-form">Use Your OpenAI Key (Free)</button>
						<a href="https://rankpilotai.com/ai-snippet-seo-pro-pricing/" target="_blank" rel="noopener" class="aissp-btn aissp-btn-pro">Upgrade to Pro</a>
					</div>
				</div>
				<?php endif; ?>

				<div class="aissp-byok-inline-form" id="aissp-byok-form" style="display:none;">
					<input type="text" id="aissp-byok-key" placeholder="sk-..." autocomplete="off">
					<button type="button" class="aissp-btn aissp-btn-byok-save" id="aissp-save-byok">Save Key</button>
				</div>

				<div class="aissp-panel-footer">
					<a href="https://rankpilotai.com/ai-snippet-seo-pro-pricing/" target="_blank" rel="noopener" class="aissp-upsell-link">
						Upgrade to Pro for bulk generation, auto-slug, all models &amp; custom prompts
					</a>
				</div>
			</div>

			<?php elseif ( $mode === 'byok' ) : ?>
			<div class="aissp-active-mode-panel aissp-panel-byok">
				<div class="aissp-panel-header">
					<div class="aissp-panel-icon">
						<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4"/></svg>
					</div>
					<div>
						<h3>OpenAI API Key Active</h3>
						<p>Unlimited single post generations</p>
					</div>
				</div>

				<div class="aissp-byok-key-display">
					<code><?php echo esc_html( $byok_key_masked ); ?></code>
					<button type="button" class="aissp-btn aissp-btn-sm aissp-btn-danger" id="aissp-remove-byok">Remove Key</button>
				</div>

				<div class="aissp-panel-footer">
					<a href="https://rankpilotai.com/ai-snippet-seo-pro-pricing/" target="_blank" rel="noopener" class="aissp-upsell-link">
						Upgrade to Pro for bulk generation, auto-slug, all models &amp; custom prompts
					</a>
				</div>
			</div>

			<?php elseif ( $mode === 'pro' ) : ?>
			<div class="aissp-active-mode-panel aissp-panel-pro">
				<div class="aissp-panel-header">
					<div class="aissp-panel-icon aissp-panel-icon-pro">
						<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
					</div>
					<div>
						<h3>Pro Mode Active</h3>
						<p>Full access to all features</p>
					</div>
					<span class="aissp-badge aissp-badge-success">Connected</span>
				</div>

				<div class="aissp-token-display">
					<code><?php echo esc_html( $token ); ?></code>
				</div>

				<?php if ( $usage !== null ) : ?>
				<div class="aissp-usage-grid">
					<div class="aissp-usage-stat">
						<span class="aissp-usage-label">Website</span>
						<span class="aissp-usage-value"><?php echo esc_html( parse_url( home_url(), PHP_URL_HOST ) ); ?></span>
					</div>
					<div class="aissp-usage-stat">
						<span class="aissp-usage-label">Used</span>
						<span class="aissp-usage-value aissp-usage-number"><?php echo esc_html( number_format_i18n( (int) $usage ) ); ?></span>
					</div>
					<div class="aissp-usage-stat">
						<span class="aissp-usage-label">Limit</span>
						<span class="aissp-usage-value aissp-usage-number"><?php echo esc_html( number_format_i18n( (int) $limit ) ); ?></span>
					</div>
					<div class="aissp-usage-stat">
						<span class="aissp-usage-label">Remaining</span>
						<span class="aissp-usage-value aissp-usage-number <?php echo (int)$remain === 0 ? 'aissp-danger' : 'aissp-success'; ?>">
							<?php echo esc_html( number_format_i18n( (int) $remain ) ); ?>
						</span>
					</div>
				</div>

				<?php
				$pct = $limit ? round( ( ( (int) $limit - (int) $remain ) / max( 1, (int) $limit ) ) * 100 ) : 0;
				$pct = max( 0, min( 100, (int) $pct ) );
				$bar_color = ( (int) $remain === 0 ) ? '#ef4444' : '#3b82f6';
				?>
				<div class="aissp-progress-wrap">
					<div class="aissp-progress-bar">
						<div class="aissp-progress-fill" style="width:<?php echo (int) $pct; ?>%;background:<?php echo esc_attr( $bar_color ); ?>;"></div>
					</div>
					<span class="aissp-progress-label"><?php echo (int) $pct; ?>% used</span>
				</div>

				<div class="aissp-usage-cta">
					<a href="<?php echo esc_url( $token_btn_url ); ?>" target="_blank" rel="noopener" class="aissp-btn aissp-btn-primary">
						<?php echo esc_html( $token_btn_label ); ?>
					</a>
				</div>
				<?php endif; ?>

				<div class="aissp-update-key">
					<form method="post" class="aissp-field-row">
						<?php wp_nonce_field('aissp_save_site_key'); ?>
						<input type="hidden" name="aissp_action" value="aissp_save_site_key">
						<input type="text" name="site_token" value="<?php echo esc_attr( $token ); ?>" placeholder="rp_xxxxxxxxxxxxxxxxx">
						<button type="submit" class="aissp-btn aissp-btn-primary">Update</button>
					</form>
				</div>
			</div>
			<?php endif; ?>
		</div>

		<!-- ==================== SECTION 2: SEO SETTINGS ==================== -->
		<?php if ( $mode !== 'none' ) : ?>
		<div class="aissp-section">
			<div class="aissp-section-header">
				<h2>SEO Snippet Settings</h2>
				<p>Configure how AI generates SEO snippets for your posts.</p>
			</div>

			<form method="post">
				<?php wp_nonce_field('aissp_save_seo_settings'); ?>
				<input type="hidden" name="aissp_action" value="aissp_save_seo_settings">

				<!-- Model Selection -->
				<div class="aissp-card">
					<div class="aissp-card-header">
						<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="12 2 2 7 12 12 22 7 12 2"/><polyline points="2 17 12 22 22 17"/><polyline points="2 12 12 17 22 12"/></svg>
						<h3>AI Model</h3>
						<?php if ( ! $is_pro ) : ?>
						<span class="aissp-lock-badge">
							<svg width="12" height="12" viewBox="0 0 24 24" fill="currentColor"><path d="M18 8h-1V6c0-2.76-2.24-5-5-5S7 3.24 7 6v2H6c-1.1 0-2 .9-2 2v10c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V10c0-1.1-.9-2-2-2zM12 3c1.66 0 3 1.34 3 3v2H9V6c0-1.66 1.34-3 3-3zm0 14c-1.1 0-2-.9-2-2s.9-2 2-2 2 .9 2 2-.9 2-2 2z"/></svg>
							<?php echo $mode === 'byok' ? 'Mini only' : 'Turbo only'; ?>
						</span>
						<?php endif; ?>
					</div>

					<div class="aissp-model-grid">
						<label class="aissp-model-card <?php echo ($model === 'gpt-4-turbo') ? 'selected' : ''; ?> <?php echo ($mode === 'byok') ? 'disabled' : ''; ?>">
							<input type="radio" name="model_choice" value="gpt-4-turbo"
								   <?php checked( $model, 'gpt-4-turbo' ); ?>
								   <?php echo ($mode === 'byok') ? 'disabled' : ''; ?>>
							<div class="aissp-model-card-inner">
								<span class="aissp-model-dot" style="background:#22c55e;"></span>
								<div class="aissp-model-info">
									<strong>GPT-4 Turbo</strong>
									<span class="aissp-model-tag">Economical</span>
								</div>
								<span class="aissp-model-cost">1 token</span>
							</div>
						</label>

						<label class="aissp-model-card <?php echo ($model === 'gpt-4.1') ? 'selected' : ''; ?> <?php echo ( ! $is_pro || ! in_array('gpt-4.1',$allowed_models,true) ) ? 'disabled' : ''; ?>">
							<input type="radio" name="model_choice" value="gpt-4.1"
								   <?php checked( $model, 'gpt-4.1' ); ?>
								   <?php echo ( ! $is_pro || ! in_array('gpt-4.1',$allowed_models,true) ) ? 'disabled' : ''; ?>>
							<div class="aissp-model-card-inner">
								<span class="aissp-model-dot" style="background:#3b82f6;"></span>
								<div class="aissp-model-info">
									<strong>GPT-4.1</strong>
									<span class="aissp-model-tag">Balanced</span>
								</div>
								<span class="aissp-model-cost">3 tokens</span>
								<?php if ( ! $is_pro ) : ?><span class="aissp-pro-tag">PRO</span><?php endif; ?>
							</div>
						</label>

						<label class="aissp-model-card <?php echo ($model === 'gpt-4o') ? 'selected' : ''; ?> <?php echo ( ! $is_pro || ! in_array('gpt-4o',$allowed_models,true) ) ? 'disabled' : ''; ?>">
							<input type="radio" name="model_choice" value="gpt-4o"
								   <?php checked( $model, 'gpt-4o' ); ?>
								   <?php echo ( ! $is_pro || ! in_array('gpt-4o',$allowed_models,true) ) ? 'disabled' : ''; ?>>
							<div class="aissp-model-card-inner">
								<span class="aissp-model-dot" style="background:#a855f7;"></span>
								<div class="aissp-model-info">
									<strong>GPT-4o</strong>
									<span class="aissp-model-tag">Premium</span>
								</div>
								<span class="aissp-model-cost">5 tokens</span>
								<?php if ( ! $is_pro ) : ?><span class="aissp-pro-tag">PRO</span><?php endif; ?>
							</div>
						</label>
					</div>
					<?php if ( $mode === 'byok' ) : ?>
					<p class="aissp-card-hint">BYOK mode uses GPT-4.1 Mini for optimal cost. Model selection is hidden as it routes directly to OpenAI.</p>
					<?php endif; ?>
				</div>

				<!-- Custom Prompt (Pro only) -->
				<div class="aissp-card <?php echo ! $is_pro ? 'aissp-card-locked' : ''; ?>">
					<div class="aissp-card-header">
						<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
						<h3>Custom Prompt</h3>
						<?php if ( ! $is_pro ) : ?><span class="aissp-pro-tag">PRO</span><?php endif; ?>
					</div>

					<div class="aissp-field">
						<?php if ( $is_pro ) : ?>
						<textarea id="custom_prompt" name="custom_prompt" rows="4"
								  placeholder="e.g. Write in a friendly, conversational tone..."><?php
							echo esc_textarea( $opts['custom_prompt'] ?? '' ); ?></textarea>
						<p class="aissp-field-hint">
							A base prompt already ensures SEO compliance. Add tone or extra instructions here.
						</p>
						<?php else : ?>
						<div class="aissp-locked-placeholder">
							<p>Add custom tone or style instructions to your snippet generation.</p>
							<span class="aissp-pro-tag">Available with Pro</span>
						</div>
						<?php endif; ?>
					</div>
				</div>

				<!-- Auto Slug (Pro only) -->
				<div class="aissp-card <?php echo ! $is_pro ? 'aissp-card-locked' : ''; ?>">
					<div class="aissp-card-header">
						<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
						<h3>Auto-update URL Slug</h3>
						<?php if ( ! $is_pro ) : ?><span class="aissp-pro-tag">PRO</span><?php endif; ?>
					</div>

					<?php if ( $is_pro ) : ?>
					<label class="aissp-toggle-row">
						<span class="aissp-toggle">
							<input type="checkbox" id="auto_slug" name="auto_slug" value="yes"
								   <?php checked( $opts['auto_slug'] ?? 'no', 'yes' ); ?>>
							<span class="aissp-toggle-slider"></span>
						</span>
						<div class="aissp-toggle-label">
							<strong>Replace URL slug with AI suggestion</strong>
							<p>WordPress keeps the previous slug in <code>_wp_old_slug</code> and serves a 301 redirect.</p>
						</div>
					</label>
					<?php else : ?>
					<div class="aissp-locked-placeholder">
						<p>Automatically replace URL slugs with AI-optimized, keyword-rich alternatives.</p>
						<span class="aissp-pro-tag">Available with Pro</span>
					</div>
					<?php endif; ?>
				</div>

				<!-- Index / Noindex (Pro only) -->
				<div class="aissp-card <?php echo ! $is_pro ? 'aissp-card-locked' : ''; ?>">
					<div class="aissp-card-header">
						<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
						<h3>Index / Noindex Settings</h3>
						<?php if ( ! $is_pro ) : ?><span class="aissp-pro-tag">PRO</span><?php endif; ?>
					</div>

					<?php if ( $is_pro ) : ?>
					<p class="aissp-card-hint" style="margin:0 0 16px;">Control which content types are indexed by search engines.</p>
					<div class="aissp-index-grid">
						<?php foreach ( $index_labels as $k => $lab ) :
							$val = $opts["index_$k"] ?? $index_defaults[$k];
						?>
						<div class="aissp-index-row">
							<span class="aissp-index-label"><?php echo esc_html( $lab ); ?></span>
							<div class="aissp-index-toggle-wrap">
								<label class="aissp-index-option <?php echo $val === 'yes' ? 'aissp-index-yes' : ''; ?>">
									<input type="radio" name="index_<?php echo esc_attr($k); ?>" value="yes"
										   <?php checked( $val, 'yes' ); ?>>
									Index
								</label>
								<label class="aissp-index-option <?php echo $val === 'no' ? 'aissp-index-no' : ''; ?>">
									<input type="radio" name="index_<?php echo esc_attr($k); ?>" value="no"
										   <?php checked( $val, 'no' ); ?>>
									Noindex
								</label>
							</div>
						</div>
						<?php endforeach; ?>
					</div>
					<?php else : ?>
					<div class="aissp-locked-placeholder">
						<p>Control index/noindex settings for posts, pages, taxonomies and custom post types.</p>
						<span class="aissp-pro-tag">Available with Pro</span>
					</div>
					<?php endif; ?>
				</div>

				<!-- Save Button -->
				<?php if ( $is_pro ) : ?>
				<div class="aissp-form-actions">
					<button type="submit" class="aissp-btn aissp-btn-primary aissp-btn-lg">
						<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
						Save Settings
					</button>
				</div>
				<?php endif; ?>
			</form>
		</div>
		<?php endif; ?>

		<!-- ==================== SECTION 3: FEATURE COMPARISON ==================== -->
		<div class="aissp-section">
			<div class="aissp-section-header">
				<h2>Feature Comparison</h2>
				<p>See what's available in each mode.</p>
			</div>

			<div class="aissp-card">
				<table class="aissp-compare-table">
					<thead>
						<tr>
							<th>Feature</th>
							<th>Free Trial</th>
							<th>Own API Key</th>
							<th>Pro</th>
						</tr>
					</thead>
					<tbody>
						<tr>
							<td>No API Key Needed</td>
							<td><span class="aissp-check">&#10003;</span></td>
							<td><span class="aissp-cross">&#10005;</span> <small>Your OpenAI key</small></td>
							<td><span class="aissp-check">&#10003;</span> <small>We handle it</small></td>
						</tr>
						<tr>
							<td>Single Post Generation</td>
							<td><span class="aissp-check">&#10003;</span></td>
							<td><span class="aissp-check">&#10003;</span></td>
							<td><span class="aissp-check">&#10003;</span></td>
						</tr>
						<tr>
							<td>Bulk Generation</td>
							<td><span class="aissp-cross">&#10005;</span></td>
							<td><span class="aissp-cross">&#10005;</span></td>
							<td><span class="aissp-check">&#10003;</span></td>
						</tr>
						<tr>
							<td>Auto-Slug Replacement</td>
							<td><span class="aissp-cross">&#10005;</span></td>
							<td><span class="aissp-cross">&#10005;</span></td>
							<td><span class="aissp-check">&#10003;</span></td>
						</tr>
						<tr>
							<td>Index / Noindex Control</td>
							<td><span class="aissp-cross">&#10005;</span></td>
							<td><span class="aissp-cross">&#10005;</span></td>
							<td><span class="aissp-check">&#10003;</span></td>
						</tr>
						<tr>
							<td>SEO Snippet Score</td>
							<td><span class="aissp-check">&#10003;</span></td>
							<td><span class="aissp-check">&#10003;</span></td>
							<td><span class="aissp-check">&#10003;</span></td>
						</tr>
						<tr>
							<td>AI Models</td>
							<td>Turbo</td>
							<td>Mini</td>
							<td>Turbo / 4.1 / 4o</td>
						</tr>
						<tr>
							<td>Custom Prompt</td>
							<td><span class="aissp-cross">&#10005;</span></td>
							<td><span class="aissp-cross">&#10005;</span></td>
							<td><span class="aissp-check">&#10003;</span></td>
						</tr>
						<tr>
							<td>Generations</td>
							<td>25 lifetime</td>
							<td>Unlimited</td>
							<td>Plan-based</td>
						</tr>
					</tbody>
				</table>

				<?php if ( $mode !== 'pro' ) : ?>
				<div class="aissp-compare-cta">
					<a href="https://rankpilotai.com/ai-snippet-seo-pro-pricing/" target="_blank" rel="noopener" class="aissp-btn aissp-btn-pro aissp-btn-lg">
						<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
						Get Pro
					</a>
				</div>
				<?php endif; ?>
			</div>
		</div>

		</div>
	</div>

	<!-- ========================================================= SETTINGS PAGE CSS ========================================================= -->
	<style>
	.aissp-settings-wrap{max-width:1100px;margin:0 20px 40px 0;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Oxygen,Ubuntu,Cantarell,"Helvetica Neue",Arial,sans-serif;font-size:14px;color:#1e293b;line-height:1.6;}
	.aissp-settings-wrap *{box-sizing:border-box;}
	.aissp-header{background:linear-gradient(135deg,#0f172a 0%,#1e3a5f 50%,#1e40af 100%);border-radius:12px 12px 0 0;margin-top:20px;}
	.aissp-header-inner{display:flex;align-items:center;justify-content:space-between;padding:24px 32px;}
	.aissp-header-left{display:flex;align-items:center;gap:16px;}
	.aissp-logo-icon{width:48px;height:48px;background:rgba(255,255,255,.12);border-radius:12px;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
	.aissp-logo-icon img{border-radius:6px;}
	.aissp-header-title{font-size:22px;font-weight:700;color:#fff;margin:0;line-height:1.2;}
	.aissp-header-subtitle{font-size:13px;color:rgba(255,255,255,.65);margin:2px 0 0;}
	.aissp-header-right{display:flex;align-items:center;gap:10px;}
	.aissp-version-badge{background:rgba(255,255,255,.15);color:rgba(255,255,255,.85);padding:4px 12px;border-radius:20px;font-size:12px;font-weight:600;}
	.aissp-mode-pill{padding:4px 14px;border-radius:20px;font-size:11px;font-weight:700;letter-spacing:.5px;}
	.aissp-mode-pill-trial{background:#dcfce7;color:#16a34a;}
	.aissp-mode-pill-byok{background:#dbeafe;color:#2563eb;}
	.aissp-mode-pill-pro{background:#fef3c7;color:#d97706;}
	.aissp-main-content{background:#f8fafc;border:1px solid #e2e8f0;border-top:none;border-radius:0 0 12px 12px;padding:32px;}
	.aissp-section{margin-bottom:36px;}.aissp-section:last-child{margin-bottom:0;}
	.aissp-section-header{margin-bottom:20px;}
	.aissp-section-header h2{font-size:20px;font-weight:700;color:#0f172a;margin:0 0 4px;}
	.aissp-section-header p{font-size:14px;color:#64748b;margin:0;}
	.aissp-mode-cards{display:grid;grid-template-columns:repeat(3,1fr);gap:20px;}
	.aissp-mode-card{background:#fff;border:2px solid #e2e8f0;border-radius:12px;padding:28px 24px;position:relative;display:flex;flex-direction:column;transition:border-color .2s,box-shadow .2s;}
	.aissp-mode-card:hover{border-color:#93c5fd;box-shadow:0 4px 16px rgba(0,0,0,.06);}
	.aissp-mode-card-badge{position:absolute;top:-1px;right:20px;padding:4px 14px;border-radius:0 0 8px 8px;font-size:10px;font-weight:700;letter-spacing:1px;}
	.aissp-mode-card-trial .aissp-mode-card-badge{background:#dcfce7;color:#16a34a;}
	.aissp-mode-card-byok .aissp-mode-card-badge{background:#dbeafe;color:#2563eb;}
	.aissp-mode-card-pro .aissp-mode-card-badge{background:#fef3c7;color:#d97706;}
	.aissp-mode-card h3{font-size:18px;font-weight:700;color:#0f172a;margin:0 0 8px;}
	.aissp-mode-card-desc{font-size:13px;color:#64748b;margin:0 0 16px;line-height:1.5;}
	.aissp-mode-card-features{list-style:none;padding:0;margin:0 0 20px;flex:1;}
	.aissp-mode-card-features li{font-size:13px;color:#334155;padding:5px 0 5px 22px;position:relative;}
	.aissp-mode-card-features li::before{content:"\2713";position:absolute;left:0;top:5px;color:#22c55e;font-weight:700;font-size:13px;}
	.aissp-mode-card-features li.aissp-feature-na{color:#94a3b8;}
	.aissp-mode-card-features li.aissp-feature-na::before{content:"\2715";color:#ef4444;}
	.aissp-mode-card-note{font-size:11px;color:#94a3b8;text-align:center;margin:10px 0 0;}
	.aissp-mode-card-note a{color:#3b82f6;text-decoration:none;}
	.aissp-mode-card-note a:hover{text-decoration:underline;}
	.aissp-active-mode-panel{background:#fff;border:2px solid #e2e8f0;border-radius:12px;padding:28px;}
	.aissp-panel-trial{border-color:#86efac;}.aissp-panel-byok{border-color:#93c5fd;}.aissp-panel-pro{border-color:#fbbf24;}
	.aissp-panel-header{display:flex;align-items:center;gap:16px;margin-bottom:20px;}
	.aissp-panel-icon{width:44px;height:44px;background:#f0fdf4;color:#16a34a;border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
	.aissp-panel-byok .aissp-panel-icon{background:#eff6ff;color:#2563eb;}
	.aissp-panel-icon-pro{background:#fef3c7 !important;color:#d97706 !important;}
	.aissp-panel-header h3{font-size:16px;font-weight:700;color:#0f172a;margin:0;}
	.aissp-panel-header p{font-size:13px;color:#64748b;margin:4px 0 0;}
	.aissp-panel-footer{margin-top:20px;padding-top:16px;border-top:1px solid #f1f5f9;}
	.aissp-upsell-link{font-size:13px;color:#3b82f6;text-decoration:none;font-weight:500;}
	.aissp-upsell-link:hover{text-decoration:underline;}
	.aissp-trial-bar-wrap{display:flex;align-items:center;gap:12px;margin-bottom:16px;}
	.aissp-trial-bar{flex:1;height:12px;background:#e2e8f0;border-radius:12px;overflow:hidden;}
	.aissp-trial-bar-fill{height:100%;border-radius:12px;background:linear-gradient(90deg,#22c55e,#16a34a);transition:width .6s ease;}
	.aissp-trial-bar-label{font-size:13px;font-weight:600;color:#334155;white-space:nowrap;}
	.aissp-trial-exhausted{background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:16px;margin-bottom:16px;}
	.aissp-trial-exhausted p{margin:0 0 12px;font-size:13px;color:#991b1b;}
	.aissp-trial-cta-row{display:flex;gap:10px;flex-wrap:wrap;}
	.aissp-byok-key-display{display:flex;align-items:center;gap:12px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:12px 16px;}
	.aissp-byok-key-display code{flex:1;font-size:14px;color:#334155;background:none;word-break:break-all;}
	.aissp-byok-inline-form{display:flex;gap:10px;margin-top:12px;}
	.aissp-byok-inline-form input{flex:1;padding:10px 14px;font-size:14px;border:1px solid #cbd5e1;border-radius:8px;background:#fff;color:#1e293b;}
	.aissp-byok-inline-form input:focus{outline:none;border-color:#3b82f6;box-shadow:0 0 0 3px rgba(59,130,246,.12);}
	.aissp-pro-inline-form{display:flex;gap:10px;margin-top:12px;}
	.aissp-pro-inline-form input[type="text"]{flex:1;padding:10px 14px;font-size:14px;border:1px solid #cbd5e1;border-radius:8px;background:#fff;color:#1e293b;}
	.aissp-card{background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:24px;margin-bottom:20px;transition:box-shadow .2s ease;}
	.aissp-card:hover{box-shadow:0 1px 8px rgba(0,0,0,.04);}
	.aissp-card-locked{opacity:.75;}
	.aissp-card-header{display:flex;align-items:center;gap:10px;margin-bottom:20px;padding-bottom:16px;border-bottom:1px solid #f1f5f9;color:#334155;}
	.aissp-card-header h3{font-size:16px;font-weight:600;margin:0;color:#0f172a;flex:1;}
	.aissp-card-hint{font-size:12px;color:#94a3b8;margin:16px 0 0;line-height:1.5;}
	.aissp-badge{padding:3px 10px;border-radius:20px;font-size:11px;font-weight:600;letter-spacing:.3px;text-transform:uppercase;}
	.aissp-badge-success{background:#dcfce7;color:#16a34a;}
	.aissp-lock-badge{display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:600;background:#f1f5f9;color:#64748b;}
	.aissp-pro-tag{display:inline-flex;align-items:center;gap:4px;padding:2px 8px;border-radius:4px;font-size:10px;font-weight:700;background:#fef3c7;color:#d97706;letter-spacing:.3px;}
	.aissp-locked-placeholder{padding:16px;background:#f8fafc;border:1px dashed #e2e8f0;border-radius:8px;text-align:center;}
	.aissp-locked-placeholder p{margin:0 0 8px;font-size:13px;color:#94a3b8;}
	.aissp-field{display:flex;flex-direction:column;gap:6px;}
	.aissp-field textarea{width:100%;padding:10px 14px;font-size:14px;border:1px solid #cbd5e1;border-radius:8px;background:#fff;color:#1e293b;font-family:inherit;resize:vertical;min-height:100px;}
	.aissp-field textarea:focus{outline:none;border-color:#3b82f6;box-shadow:0 0 0 3px rgba(59,130,246,.12);}
	.aissp-field-hint{font-size:12px;color:#94a3b8;margin:0;line-height:1.5;}
	.aissp-field-row{display:flex;gap:10px;align-items:center;}
	.aissp-field-row input[type="text"]{flex:1;padding:10px 14px;font-size:14px;border:1px solid #cbd5e1;border-radius:8px;background:#fff;color:#1e293b;}
	.aissp-field-row input[type="text"]:focus{outline:none;border-color:#3b82f6;box-shadow:0 0 0 3px rgba(59,130,246,.12);}
	.aissp-toggle-row{display:flex;align-items:flex-start;gap:14px;cursor:pointer;}
	.aissp-toggle{position:relative;width:44px;height:24px;flex-shrink:0;margin-top:2px;}
	.aissp-toggle input{opacity:0;width:0;height:0;position:absolute;}
	.aissp-toggle-slider{position:absolute;inset:0;background:#cbd5e1;border-radius:24px;transition:background .2s ease;cursor:pointer;}
	.aissp-toggle-slider::after{content:'';position:absolute;left:3px;top:3px;width:18px;height:18px;background:#fff;border-radius:50%;transition:transform .2s ease;box-shadow:0 1px 3px rgba(0,0,0,.15);}
	.aissp-toggle input:checked + .aissp-toggle-slider{background:#3b82f6;}
	.aissp-toggle input:checked + .aissp-toggle-slider::after{transform:translateX(20px);}
	.aissp-toggle-label strong{display:block;font-size:14px;color:#0f172a;margin-bottom:2px;}
	.aissp-toggle-label p{font-size:13px;color:#64748b;margin:0;}
	.aissp-toggle-label code{background:#f1f5f9;padding:1px 6px;border-radius:3px;font-size:11px;}
	.aissp-index-grid{display:flex;flex-direction:column;gap:10px;}
	.aissp-index-row{display:flex;align-items:center;justify-content:space-between;padding:10px 14px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;}
	.aissp-index-label{font-size:14px;font-weight:500;color:#334155;}
	.aissp-index-toggle-wrap{display:flex;gap:6px;}
	.aissp-index-option{display:inline-flex;align-items:center;gap:6px;padding:5px 14px;border-radius:6px;font-size:12px;font-weight:600;cursor:pointer;border:1px solid #e2e8f0;background:#fff;color:#64748b;transition:all .15s ease;}
	.aissp-index-option input[type="radio"]{position:absolute;opacity:0;width:0;height:0;pointer-events:none;}
	.aissp-index-option.aissp-index-yes{background:#dcfce7;border-color:#86efac;color:#16a34a;}
	.aissp-index-option.aissp-index-no{background:#fef2f2;border-color:#fecaca;color:#dc2626;}
	.aissp-btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:10px 20px;font-size:14px;font-weight:600;border:none;border-radius:8px;cursor:pointer;text-decoration:none;transition:all .15s ease;line-height:1.4;}
	.aissp-btn-primary{background:linear-gradient(135deg,#2563eb,#1d4ed8);color:#fff !important;box-shadow:0 1px 3px rgba(37,99,235,.3);}
	.aissp-btn-primary:hover{background:linear-gradient(135deg,#3b82f6,#2563eb);box-shadow:0 2px 8px rgba(37,99,235,.35);transform:translateY(-1px);color:#fff !important;}
	.aissp-btn-trial{background:linear-gradient(135deg,#22c55e,#16a34a);color:#fff !important;width:100%;box-shadow:0 1px 3px rgba(22,163,74,.3);}
	.aissp-btn-trial:hover{background:linear-gradient(135deg,#4ade80,#22c55e);box-shadow:0 2px 8px rgba(22,163,74,.35);transform:translateY(-1px);}
	.aissp-btn-byok{background:linear-gradient(135deg,#3b82f6,#2563eb);color:#fff !important;width:100%;box-shadow:0 1px 3px rgba(37,99,235,.3);}
	.aissp-btn-byok:hover{background:linear-gradient(135deg,#60a5fa,#3b82f6);transform:translateY(-1px);}
	.aissp-btn-byok-save{background:#2563eb;color:#fff !important;padding:10px 16px;white-space:nowrap;}
	.aissp-btn-pro{background:linear-gradient(135deg,#f59e0b,#d97706);color:#fff !important;width:100%;box-shadow:0 1px 3px rgba(217,119,6,.3);}
	.aissp-btn-pro:hover{background:linear-gradient(135deg,#fbbf24,#f59e0b);transform:translateY(-1px);}
	.aissp-btn-pro-save{background:#d97706;color:#fff !important;padding:10px 16px;white-space:nowrap;}
	.aissp-btn-sm{padding:6px 14px;font-size:12px;}
	.aissp-btn-danger{background:#ef4444;color:#fff !important;}
	.aissp-btn-danger:hover{background:#dc2626;}
	.aissp-btn-lg{padding:12px 28px;font-size:15px;}
	.aissp-btn:disabled,.aissp-btn[disabled]{opacity:.5;cursor:not-allowed;transform:none !important;}
	.aissp-token-display{background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:12px 16px;margin-bottom:20px;}
	.aissp-token-display code{font-size:14px;color:#334155;background:none;word-break:break-all;}
	.aissp-usage-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:20px;}
	.aissp-usage-stat{background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:14px 16px;text-align:center;}
	.aissp-usage-label{display:block;font-size:11px;font-weight:600;color:#94a3b8;text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px;}
	.aissp-usage-value{display:block;font-size:14px;font-weight:700;color:#0f172a;word-break:break-all;}
	.aissp-usage-number{font-size:20px;}
	.aissp-usage-value.aissp-danger{color:#ef4444;}
	.aissp-usage-value.aissp-success{color:#22c55e;}
	.aissp-progress-wrap{display:flex;align-items:center;gap:12px;margin-bottom:20px;}
	.aissp-progress-bar{flex:1;height:10px;background:#e2e8f0;border-radius:10px;overflow:hidden;}
	.aissp-progress-fill{height:100%;border-radius:10px;transition:width .6s ease;}
	.aissp-progress-label{font-size:12px;font-weight:600;color:#64748b;white-space:nowrap;}
	.aissp-usage-cta{padding-top:4px;margin-bottom:16px;}
	.aissp-update-key{border-top:1px solid #f1f5f9;padding-top:16px;}
	.aissp-model-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;}
	.aissp-model-card{position:relative;border:2px solid #e2e8f0;border-radius:10px;padding:16px;cursor:pointer;transition:all .15s ease;background:#fff;}
	.aissp-model-card:hover:not(.disabled){border-color:#93c5fd;background:#f8fafc;}
	.aissp-model-card.selected{border-color:#3b82f6;background:linear-gradient(135deg,#eff6ff,#dbeafe);box-shadow:0 0 0 1px rgba(59,130,246,.2);}
	.aissp-model-card.disabled{opacity:.45;cursor:not-allowed;pointer-events:none;}
	.aissp-model-card input[type="radio"]{position:absolute;opacity:0;width:0;height:0;pointer-events:none;}
	.aissp-model-card-inner{display:flex;align-items:center;gap:10px;}
	.aissp-model-dot{width:10px;height:10px;border-radius:50%;flex-shrink:0;}
	.aissp-model-info{flex:1;min-width:0;}
	.aissp-model-info strong{display:block;font-size:14px;color:#0f172a;}
	.aissp-model-tag{font-size:11px;color:#64748b;}
	.aissp-model-cost{font-size:12px;font-weight:600;color:#3b82f6;white-space:nowrap;background:#eff6ff;padding:3px 8px;border-radius:6px;}
	.aissp-compare-table{width:100%;border-collapse:collapse;}
	.aissp-compare-table th,.aissp-compare-table td{padding:12px 16px;text-align:center;border-bottom:1px solid #f1f5f9;font-size:13px;}
	.aissp-compare-table th{font-weight:600;color:#64748b;text-transform:uppercase;font-size:11px;letter-spacing:.5px;}
	.aissp-compare-table th:first-child,.aissp-compare-table td:first-child{text-align:left;font-weight:500;color:#334155;}
	.aissp-compare-table tbody tr:hover{background:#f8fafc;}
	.aissp-check{color:#22c55e;font-weight:700;font-size:16px;}
	.aissp-cross{color:#ef4444;font-weight:700;font-size:16px;}
	.aissp-compare-cta{text-align:center;padding-top:20px;}
	.aissp-compare-cta .aissp-btn{width:auto;}
	.aissp-form-actions{padding-top:8px;}
	@media (max-width:900px){
		.aissp-settings-wrap{margin:0 10px 30px 0;}.aissp-header-inner{padding:20px 24px;}.aissp-main-content{padding:24px 20px;}
		.aissp-mode-cards{grid-template-columns:1fr;}.aissp-usage-grid{grid-template-columns:repeat(2,1fr);}.aissp-model-grid{grid-template-columns:1fr;}
		.aissp-field-row{flex-direction:column;}.aissp-field-row input[type="text"]{width:100%;}.aissp-btn{width:100%;justify-content:center;}
		.aissp-byok-inline-form{flex-direction:column;}.aissp-pro-inline-form{flex-direction:column;}
		.aissp-index-row{flex-direction:column;align-items:flex-start;gap:8px;}
	}
	@media (max-width:480px){.aissp-header-inner{flex-direction:column;align-items:flex-start;gap:12px;}.aissp-header-right{align-self:flex-end;}.aissp-usage-grid{grid-template-columns:1fr 1fr;}}
	</style>

	<script>
	jQuery(document).ready(function($){
		var cards = document.querySelectorAll('.aissp-model-card');
		cards.forEach(function(card){
			var radio = card.querySelector('input[type="radio"]');
			if(!radio) return;
			card.addEventListener('click', function(){
				if(card.classList.contains('disabled') || radio.disabled) return;
				cards.forEach(function(c){ c.classList.remove('selected'); });
				card.classList.add('selected');
				radio.checked = true;
			});
		});

		/* Index/Noindex radio visual update */
		$(document).on('change', '.aissp-index-option input[type="radio"]', function(){
			var row = $(this).closest('.aissp-index-row');
			row.find('.aissp-index-option').removeClass('aissp-index-yes aissp-index-no');
			var lbl = $(this).closest('.aissp-index-option');
			lbl.addClass( $(this).val() === 'yes' ? 'aissp-index-yes' : 'aissp-index-no' );
		});

		$(document).on('click', '#aissp-show-byok-form', function(){
			$(this).hide();
			$('#aissp-byok-form').show().find('input').focus();
		});

		$('#aissp-show-pro-form').on('click', function(){
			$(this).hide();
			$('#aissp-pro-form').show().find('input[name="site_token"]').focus();
		});

		$('#aissp-start-trial').on('click', function(){
			var btn = $(this);
			if(typeof AISSP_Ajax === 'undefined'){
				alert('Script loading error. Please refresh the page.');
				return;
			}
			btn.prop('disabled', true).text('Activating...');
			$.post(AISSP_Ajax.ajaxurl, {
				action: 'aissp_trial_register',
				nonce:  AISSP_Ajax.nonce
			}, function(res){
				if(res.success) location.reload();
				else {
					alert(res.data && res.data.msg ? res.data.msg : 'Registration failed.');
					btn.prop('disabled', false).text('Start Free Trial');
				}
			}).fail(function(xhr, status, err){
				alert('Network error: ' + status + ' ' + err);
				btn.prop('disabled', false).text('Start Free Trial');
			});
		});

		$('#aissp-save-byok').on('click', function(){
			var key = $('#aissp-byok-key').val().trim();
			if(!key || key.indexOf('sk-') !== 0){
				alert('Please enter a valid OpenAI API key (starts with sk-)');
				return;
			}
			var btn = $(this);
			btn.prop('disabled', true).text('Saving...');
			$.post(AISSP_Ajax.ajaxurl, {
				action: 'aissp_save_byok_key',
				nonce:  AISSP_Ajax.nonce,
				openai_key: key
			}, function(res){
				if(res.success) location.reload();
				else {
					alert(res.data && res.data.msg ? res.data.msg : 'Save failed.');
					btn.prop('disabled', false).text('Save Key');
				}
			}).fail(function(){
				alert('Network error.');
				btn.prop('disabled', false).text('Save Key');
			});
		});

		$('#aissp-remove-byok').on('click', function(){
			if(!confirm('Remove your OpenAI API key?')) return;
			$.post(AISSP_Ajax.ajaxurl, {
				action: 'aissp_remove_byok_key',
				nonce:  AISSP_Ajax.nonce
			}, function(res){
				if(res.success) location.reload();
			});
		});
	});
	</script>
	<?php
} /* end aissp_render_settings_page */
}
