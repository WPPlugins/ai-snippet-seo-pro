<?php
/**
 * AI Snippet SEO Pro — Meta Box & Term UI
 *
 * @file      meta-box.php
 * @package   RankPilotAI\AI_Snippet_SEO_Pro\Admin
 * @since     1.0.0
 * @version   1.0.0
 * @license   GPL-2.0-or-later
 *
 * Overview
 * - Dual panels on the post edit screen (main + side)
 * - Taxonomy edit-screen UI
 * - Snippet score calculation & checklist
 * - Generate snippets with AI (post & term)
 * - Admin list column with sorting
 * - All action & filter hooks wired
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AISSP_Meta_Box {

	/* ───────────────────────────────────────────────
	 *   Meta-key constants
	 * ───────────────────────────────────────────── */
	private static $mk_focus_kw = '_aissp_focus_keyword';
	private static $mk_title    = '_aissp_seo_title';
	private static $mk_desc     = '_aissp_seo_description';
	private static $mk_score    = '_aissp_score';

	/* =========================================================
	 *  1)  REGISTER META BOXES (main + side)
	 * ======================================================= */
	public static function register_meta_boxes() {
		$pts = get_post_types( [ 'public' => true ], 'names' );
		foreach ( $pts as $pt ) {
			add_meta_box(
				'aissp_meta_box',
				'AI Snippet SEO Pro',
				[ __CLASS__, 'render_meta_box' ],
				$pt,
				'normal',
				'high'
			);
			add_meta_box(
				'aissp_meta_box_side',
				'AI Snippet SEO Pro',
				[ __CLASS__, 'render_side_meta_box' ],
				$pt,
				'side',
				'high'
			);
		}
	}

	/* =========================================================
	 *  2)  SIDE PANEL (compact overview)
	 * ======================================================= */
	public static function render_side_meta_box( $post ) {

		$scVal = get_post_meta( $post->ID, self::$mk_score, true );
		if ( $scVal === '' ) {
			$scVal = 0;
		}
		$ti = get_post_meta( $post->ID, self::$mk_title, true );
		$de = get_post_meta( $post->ID, self::$mk_desc, true );

		$col = '#dc3232';
		if ( $scVal >= 100 ) {
			$scVal = 100;
			$col   = '#0091e0';
		} elseif ( $scVal >= 67 ) {
			$col = '#ffab00';
		}

		echo '<div style="margin-bottom:8px;"><strong>SEO Snippet Score:</strong> 
			<span id="aissp_side_score" style="background:' . esc_attr( $col ) . ';border-radius:2px;padding:6px 9px;color:#fff;font-weight:600;font-size:12px;">'
			. esc_html( $scVal ) . ' / 100</span></div>';

		echo '<div id="aissp_side_title" style="background:#f7f7f7;border:1px solid #ddd;padding:8px;margin-bottom:8px;">
			<strong>Title:</strong> ' . esc_html( $ti ? $ti : '(No Title)' ) . '</div>';

		echo '<div id="aissp_side_desc" style="background:#f7f7f7;border:1px solid #ddd;padding:8px;">
			<strong>Description:</strong> ' . esc_html( $de ? $de : '(No Meta Description)' ) . '</div>';
	}

	/* =========================================================
	 *  3)  MAIN PANEL (full editor)
	 * ======================================================= */
	public static function render_meta_box( $post ) {

		$fw = get_post_meta( $post->ID, self::$mk_focus_kw, true );
		$ti = get_post_meta( $post->ID, self::$mk_title, true );
		$de = get_post_meta( $post->ID, self::$mk_desc, true );

		wp_nonce_field( 'aissp_save_meta_box', 'aissp_meta_nonce' );

		$sc        = aissp_calc_score( $fw, $ti, $de, get_post_field( 'post_name', $post->ID ) );
		$score_txt = aissp_score_text( $sc['score'] );
		$col       = $sc['color'];
		$slugVal   = get_post_field( 'post_name', $post->ID );

		/* ---------- Preview ---------- */
		echo '<h4 style="margin-bottom:4px;">Preview</h4>
		<div class="aissp-preview-box">
			<p class="aissp-preview-title" id="aissp_preview_title">' . ( $ti ? esc_html( $ti ) : '(No Title)' ) . '</p>
			<p class="aissp-preview-url">' . esc_html( home_url( '/' ) ) . '<span id="aissp_preview_slug">' . esc_html( $slugVal ) . '</span></p>
			<p class="aissp-preview-desc" id="aissp_preview_desc">' . ( $de ? esc_html( $de ) : '(No Meta Description)' ) . '</p>
		</div>';

		echo '<p>
  <button type="button" class="button button-primary" id="aissp-generate-ai" data-post-id="' . esc_attr( $post->ID ) . '">
    Generate with AI Snippet SEO Pro
  </button>
  <span id="aissp-ai-status" style="margin-left:10px;color:#0091e0;font-weight:bold;"></span>
</p>';

		/* ---------- Focus Keyword ---------- */
		echo '<div style="background:#f7f7f7;padding:8px;max-width:600px;margin-bottom:1em;">
			<strong>Focus Keyword (comma separated):</strong> 
			<span id="aissp_fkw_count" style="font-size:12px;color:#888;margin-left:4px;"></span><br/>
			<input type="text" id="aissp_focus_keyword" name="aissp_focus_keyword" value="' . esc_attr( $fw ) . '" style="width:100%;"/>
			<p style="font-size:11px;color:#666;">Enter one or more keywords separated by commas.</p>
		</div>';

		/* ---------- Title ---------- */
		echo '<div style="background:#f7f7f7;padding:8px;max-width:600px;margin-bottom:1em;">
			<strong>SEO Title:</strong> 
			<span id="aissp_title_count" style="font-size:12px;color:#888;margin-left:4px;"></span><br/>
			<input type="text" id="aissp_seo_title" name="aissp_seo_title" value="' . esc_attr( $ti ) . '" style="width:100%;"/>
			<p style="font-size:11px;color:#666;">Recommended 30–60 chars. EXACT KW must appear as is (for best score).</p>
		</div>';

		/* ---------- Description ---------- */
		echo '<div style="background:#f7f7f7;padding:8px;max-width:600px;margin-bottom:1em;">
			<strong>Meta Description:</strong> 
			<span id="aissp_desc_count" style="font-size:12px;color:#888;margin-left:4px;"></span><br/>
			<textarea id="aissp_seo_description" name="aissp_seo_description" rows="3" style="width:100%;">'
			. esc_textarea( $de ) . '</textarea>
			<p style="font-size:11px;color:#666;">Recommended 120–160 chars. EXACT KW must appear.</p>
		</div>';

		/* ---------- Score ---------- */
		echo '<div style="margin-bottom:1em;">
			<strong>SEO Snippet Score:</strong> 
			<span id="aissp_score" style="background:' . esc_attr( $col ) . ';border-radius:2px;padding:6px 9px;color:#fff;font-weight:600;font-size:12px;margin-left:6px;">'
			. esc_html( $score_txt ) . '</span>
		</div>';

		/* ---------- Checklist ---------- */
		echo '<div id="aissp_checklist_box" style="border:1px solid #ddd;background:#f7f7f7;padding:8px;max-width:600px;">
			<strong style="display:block;margin-bottom:4px;border-bottom:1px solid #ddd;">Snippet Checklist</strong>';

		$arr       = array_map( 'trim', explode( ',', $fw ) );
		$fk        = strtolower( $arr[0] ?? '' );
		$tLen      = strlen( $ti );
		$descLen   = mb_strlen( $de, 'UTF-8' );
		$slugCheck = str_replace( ' ', '-', strtolower( $slugVal ) );
		$fkCheck   = str_replace( ' ', '-', $fk );

		$c1 = ( ! empty( $fk ) && strpos( strtolower( $ti ), $fk ) !== false );
		$c2 = ( ! empty( $fk ) && strpos( strtolower( $de ), $fk ) !== false );
		$c3 = ( ! empty( $fk ) && strpos( $slugCheck, $fkCheck ) !== false );
		$c4 = ( $tLen >= 30 && $tLen <= 60 );
		$c5 = ( strlen( $slugVal ) > 0 && strlen( $slugVal ) <= 75 );
		$c6 = ( $descLen >= 120 && $descLen <= 160 );

		echo wp_kses_post( self::check_item( $c1, 'Focus Keyword in Title' ) );
		echo wp_kses_post( self::check_item( $c2, 'Focus Keyword in Description' ) );
		echo wp_kses_post( self::check_item( $c3, 'Focus Keyword in URL Slug' ) );
		echo wp_kses_post( self::check_item( $c4, 'Title 30–60 Characters' ) );
		echo wp_kses_post( self::check_item( $c5, 'Slug ≤ 75 Characters' ) );
		echo wp_kses_post( self::check_item( $c6, 'Description 120–160 Characters' ) );
		echo '</div>';
	}

	/* ───────────────────────────────────────────────
	 *   Single checklist row renderer
	 * ───────────────────────────────────────────── */
	private static function check_item( $ok, $txt ) {
		$okI = '<span style="color:#0091e0;font-weight:bold;">✔</span>';
		$noI = '<span style="color:red;font-weight:bold;">✘</span>';
		return '<p style="margin:0;">' . ( $ok ? $okI : $noI ) . ' ' . esc_html( $txt ) . '</p>';
	}

	/* =========================================================
	 *  4)  SAVE (Post)
	 * ======================================================= */
	public static function save_meta_box_data( $post_id ) {

	if (
		! isset( $_POST['aissp_meta_nonce'] ) ||
		! wp_verify_nonce(
			sanitize_text_field( wp_unslash( $_POST['aissp_meta_nonce'] ) ),
			'aissp_save_meta_box'
		)
	) {
		return;
	}

	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}
	if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
    return;
}
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}

	$fwRaw = isset( $_POST['aissp_focus_keyword'] ) ? sanitize_text_field( wp_unslash( $_POST['aissp_focus_keyword'] ) ) : '';
$arr   = array_map( 'trim', explode( ',', $fwRaw ) );
$fw    = implode( ', ', $arr );
update_post_meta( $post_id, self::$mk_focus_kw, $fw );

$ti = isset( $_POST['aissp_seo_title'] ) ? sanitize_text_field( wp_unslash( $_POST['aissp_seo_title'] ) ) : '';
update_post_meta( $post_id, self::$mk_title, $ti );

$de = isset( $_POST['aissp_seo_description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['aissp_seo_description'] ) ) : '';
update_post_meta( $post_id, self::$mk_desc, $de );

	/* Slug: first focus keyword (if auto-slug is ON) */
	$opts = get_option( 'aissp_settings', [] );
	if ( ( $opts['auto_slug'] ?? 'no' ) !== 'yes' ) {
		// Auto-Slug disabled → skip slug change block
	} else {
		if ( ! empty( $arr[0] ) ) {
			$mk      = sanitize_title( $arr[0] );
			$curSlug = get_post_field( 'post_name', $post_id );
			if ( $mk && $mk !== $curSlug ) {
				wp_update_post( [ 'ID' => $post_id, 'post_name' => $mk ] );
			}
		}
	} // ← closes the "else" block

	$slug = get_post_field( 'post_name', $post_id );
	$sc   = aissp_calc_score( $fw, $ti, $de, $slug );
	update_post_meta( $post_id, self::$mk_score, $sc['score'] );
}
	/* =========================================================
	 *  5)  TERM UI — Edit screen
	 * ======================================================= */
	public static function render_term_edit_ui( $term, $taxonomy ) {

		echo '<tr class="form-field"><th colspan="2"><h3>AI Snippet SEO Pro</h3></th></tr>';
		wp_nonce_field( 'aissp_save_term_meta', 'aissp_term_nonce' );


		$fw = get_term_meta( $term->term_id, self::$mk_focus_kw, true );
		$ti = get_term_meta( $term->term_id, self::$mk_title, true );
		$de = get_term_meta( $term->term_id, self::$mk_desc, true );

		$sc        = aissp_calc_score( $fw, $ti, $de, $term->slug );
		$score_txt = aissp_score_text( $sc['score'] );
		$col       = $sc['color'];

		/* Preview */
		echo '<tr class="form-field"><th><label>Snippet Preview</label></th><td>
			<div class="aissp-preview-box">
				<p class="aissp-preview-title">' . ( $ti ? esc_html( $ti ) : '(No Title)' ) . '</p>
				<p class="aissp-preview-url">' . esc_html( home_url( '/' ) ) . '<span>' . esc_html( $term->slug ) . '</span></p>
				<p class="aissp-preview-desc">' . ( $de ? esc_html( $de ) : '(No Meta Description)' ) . '</p>
			</div>
		</td></tr>';

		/* Focus KW */
		echo '<tr class="form-field"><th><label>Focus Keyword</label></th>
			<td style="background:#f7f7f7;padding:8px;">
				<input type="text" name="aissp_focus_keyword" value="' . esc_attr( $fw ) . '" style="width:100%;"/>
			</td></tr>';

		/* Title */
		echo '<tr class="form-field"><th><label>SEO Title</label></th>
			<td style="background:#f7f7f7;padding:8px;">
				<input type="text" name="aissp_seo_title" value="' . esc_attr( $ti ) . '" style="width:100%;"/>
			</td></tr>';

		/* Description */
		echo '<tr class="form-field"><th><label>Meta Description</label></th>
			<td style="background:#f7f7f7;padding:8px;">
				<textarea name="aissp_seo_description" rows="2" style="width:100%;">' . esc_textarea( $de ) . '</textarea>
			</td></tr>';

		/* Score */
		echo '<tr class="form-field"><th><label>SEO Snippet Score</label></th>
			<td>
				<span style="background:' . esc_attr( $col ) . ';border-radius:2px;padding:6px 9px;color:#fff;font-weight:600;font-size:12px;">'
				. esc_html( $score_txt ) . '</span>
			</td></tr>';

		/* Checklist */
		$arr       = array_map( 'trim', explode( ',', $fw ) );
		$fk        = strtolower( $arr[0] ?? '' );
		$tLen      = strlen( $ti );
		$descLen   = mb_strlen( $de, 'UTF-8' );
		$slugCheck = str_replace( ' ', '-', strtolower( $term->slug ) );
		$fkCheck   = str_replace( ' ', '-', $fk );

		$c1 = ( ! empty( $fk ) && strpos( strtolower( $ti ), $fk ) !== false );
		$c2 = ( ! empty( $fk ) && strpos( strtolower( $de ), $fk ) !== false );
		$c3 = ( ! empty( $fk ) && strpos( $slugCheck, $fkCheck ) !== false );
		$c4 = ( $tLen >= 30 && $tLen <= 60 );
		$c5 = ( strlen( $term->slug ) > 0 && strlen( $term->slug ) <= 75 );
		$c6 = ( $descLen >= 120 && $descLen <= 160 );

		echo '<tr class="form-field"><th><label>Snippet Checklist</label></th>
			<td style="border:1px solid #ddd;background:#f7f7f7;padding:8px;max-width:600px;">
			<strong style="display:block;margin-bottom:4px;border-bottom:1px solid #ddd;">Snippet Checklist</strong>';
		echo wp_kses_post( self::check_item( $c1, 'Focus Keyword in Title' ) );
		echo wp_kses_post( self::check_item( $c2, 'Focus Keyword in Description' ) );
		echo wp_kses_post( self::check_item( $c3, 'Focus Keyword in URL Slug' ) );
		echo wp_kses_post( self::check_item( $c4, 'Title 30–60 Characters' ) );
		echo wp_kses_post( self::check_item( $c5, 'Slug ≤ 75 Characters' ) );
		echo wp_kses_post( self::check_item( $c6, 'Description 120–160 Characters' ) );
		echo '</td></tr>';
	}
	/* ---------------------------------------------------------
	 *  TERM SAVE
	 * ------------------------------------------------------- */
	public static function save_term_edit_ui( $term_id, $tax ) {

    // 1) Verify nonce for security
    if (
        ! isset( $_POST['aissp_term_nonce'] ) ||
        ! wp_verify_nonce(
            sanitize_text_field( wp_unslash( $_POST['aissp_term_nonce'] ) ),
            'aissp_save_term_meta'
        )
    ) {
        return;
    }

    // 2) Check user capability
    if ( ! current_user_can( 'edit_term', $term_id ) ) {
        return;
    }

    // 3) Safely read POST values
    $raw = isset( $_POST['aissp_focus_keyword'] ) ? sanitize_text_field( wp_unslash( $_POST['aissp_focus_keyword'] ) ) : '';

		if ( $raw === '' ) {
			delete_term_meta( $term_id, self::$mk_focus_kw );
			delete_term_meta( $term_id, self::$mk_title );
			delete_term_meta( $term_id, self::$mk_desc );
			delete_term_meta( $term_id, self::$mk_score );
			return;
		}

		$arr = array_map( 'trim', explode( ',', $raw ) );
		$fw  = implode( ', ', $arr );
		update_term_meta( $term_id, self::$mk_focus_kw, $fw );

		$ti = isset( $_POST['aissp_seo_title'] )
    ? sanitize_text_field( wp_unslash( $_POST['aissp_seo_title'] ) )
    : '';
update_term_meta( $term_id, self::$mk_title, $ti );

$de = isset( $_POST['aissp_seo_description'] )
    ? sanitize_textarea_field( wp_unslash( $_POST['aissp_seo_description'] ) )
    : '';
update_term_meta( $term_id, self::$mk_desc, $de );

		// Update slug only when auto_slug is enabled
		$opts = get_option( 'aissp_settings', [] );
		if ( ( $opts['auto_slug'] ?? 'no' ) === 'yes' && ! empty( $arr[0] ) ) {
			$mk       = sanitize_title( $arr[0] );
			$term_obj = get_term( $term_id, $tax );
			if ( $term_obj && $term_obj->slug !== $mk ) {
				wp_update_term( $term_id, $tax, [ 'slug' => $mk ] );
			}
		}

$term_obj = get_term( $term_id, $tax );
if ( $term_obj && ! is_wp_error( $term_obj ) ) {
    $sc = aissp_calc_score( $fw, $ti, $de, $term_obj->slug );
    update_term_meta( $term_id, self::$mk_score, $sc['score'] );
}
}
	/* =========================================================
	 *  6)  TERM LIST COLUMN & SORTING
	 * ======================================================= */
	public static function term_snippet_cols( $cols ) {
		$new = [];
		foreach ( $cols as $k => $v ) {
			$new[ $k ] = $v;
			if ( $k === 'slug' ) {
				$new['seo_snippet_col'] = 'SEO Snippet';
			}
		}
		return $new;
	}

	public static function term_snippet_cols_render( $out, $col, $term_id ) {
		if ( $col === 'seo_snippet_col' ) {
			$val = get_term_meta( $term_id, self::$mk_score, true );
			if ( $val === '' ) {
				$val = 0;
			}
			$color = '#dc3232';
			if ( $val >= 100 ) {
				$val   = 100;
				$color = '#0091e0';
			} elseif ( $val >= 67 ) {
				$color = '#ffab00';
			}
			return '<span style="background:' . esc_attr( $color ) . ';border-radius:3px;padding:6px 9px;color:#fff;font-weight:600;font-size:12px;">'
			       . esc_html( $val ) . ' / 100</span>';
		}
		return $out;
	}

	public static function term_snippet_cols_sortable( $cols ) {
		$cols['seo_snippet_col'] = 'seo_snippet_col';
		return $cols;
	}

	public static function term_snippet_cols_sort_clauses( $clauses, $tax, $args ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display parameter, no state change.
		if ( isset( $_GET['orderby'] ) && $_GET['orderby'] === 'seo_snippet_col' ) {
			global $wpdb;
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- read-only display parameter; compared against a string literal only, and $ord itself is a hardcoded literal before it reaches the query.
			$ord                 = ( isset( $_GET['order'] ) && strtoupper( (string) wp_unslash( $_GET['order'] ) ) === 'DESC' ) ? 'DESC' : 'ASC';
			$clauses['fields']  .= ", (CASE WHEN tm_score.meta_value<>'' THEN CAST(tm_score.meta_value AS UNSIGNED) ELSE 0 END) as snippet_score_val";
			$clauses['join']    .= " LEFT JOIN {$wpdb->termmeta} tm_score ON (t.term_id=tm_score.term_id AND tm_score.meta_key='" . self::$mk_score . "')";
			$clauses['orderby']  = " snippet_score_val $ord";
			$clauses['groupby']  = " t.term_id";
		}
		return $clauses;
	}

/* =========================================================
 *  7)  GENERATE AI SNIPPET — POST
 * ======================================================= */
public static function generate_ai_snippet_for_post( $post_id, $return_data = false ) {

	$opts       = get_option( 'aissp_settings' );
	$site_token = isset( $opts['site_token'] )   ? $opts['site_token']   : '';
	$model_sel  = isset( $opts['model_choice'] ) ? strtolower( $opts['model_choice'] ) : 'gpt-4-turbo';

	if ( ! $site_token ) {
		throw new \Exception( 'No site token' );
	}

	/* ---- usage/balance check ---- */
	$st = aissp_check_token_status( $site_token );
// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- delivered as JSON to admin.js and shown with alert(); never echoed as HTML.
if ( isset( $st['error'] ) ) { throw new \Exception( 'Token check error: ' . $st['error'] ); }

$__remaining_global = function_exists('aissp_status_remaining')
    ? aissp_status_remaining( $st )
    : (int) ( $st['remaining'] ?? 0 );

if ( $__remaining_global <= 0 ) {
    throw new \Exception( 'No tokens left. Please upgrade.' );
}

	/* === TOKEN GUARD (is there enough balance for the model cost?) === */
	$__need      = function_exists('aissp_model_cost') ? aissp_model_cost( $model_sel ) : ( $model_sel === 'gpt-4o' ? 5 : ( $model_sel === 'gpt-4.1' ? 3 : ( $model_sel === 'o3' ? 12 : 1 ) ) );
	$__remaining = function_exists('aissp_status_remaining') ? aissp_status_remaining( $st ) : ( isset($st['remaining']) ? (int)$st['remaining'] : 0 );

	if ( $__remaining < $__need ) {
		throw new \Exception(
			esc_html(
				'Not enough tokens for the selected model (' . $model_sel . '). '
				. 'Needs ' . $__need . ', you have ' . $__remaining . '. '
				. 'Choose a cheaper model (e.g. GPT-4 Turbo) or buy more tokens.'
			)
		);
	}

	/* ---- Payload ---- */
	$title   = get_the_title( $post_id );
	$excerpt = get_the_excerpt( $post_id );
	$ptype   = get_post_type( $post_id );
	$fw      = get_post_meta( $post_id, self::$mk_focus_kw, true );

	$payload = [
		'context_type'  => 'post',
		'post_title'    => $title,
		'post_excerpt'  => $excerpt,
		'post_type'     => $ptype,
		'focus_keyword' => $fw,
		'model'         => $model_sel,
	];
	$payload['slug']      = get_post_field( 'post_name', $post_id );
	$payload['auto_slug'] = ( $opts['auto_slug'] ?? 'no' ) === 'yes';

	/* ---- API ---- */
	$res = aissp_call_rankpilot_api( $site_token, $payload );

	/* ---- Persist results ---- */
	$newKW   = $res['keyword']     ?? '';
	$newTit  = $res['title']       ?? '';
	$newDes  = $res['description'] ?? '';
	$newSlug = $res['slug']        ?? '';

	update_post_meta( $post_id, self::$mk_focus_kw, $newKW );
	update_post_meta( $post_id, self::$mk_title,    $newTit );
	update_post_meta( $post_id, self::$mk_desc,     $newDes );

	/* ---------------- Slug update ---------------- */
	$auto = $opts['auto_slug'] ?? 'no';

	if ( $auto === 'yes' && $newSlug ) {
		$san          = sanitize_title( $newSlug );
		$current_slug = get_post_field( 'post_name', $post_id );

		if ( $san && $san !== $current_slug ) {
			// WordPress' built-in old-slug → 301 redirect mechanism
			add_post_meta( $post_id, '_wp_old_slug', $current_slug );
			wp_update_post( [ 'ID' => $post_id, 'post_name' => $san ] );
		}
	}

	$final_slug = get_post_field( 'post_name', $post_id );
	$sc         = aissp_calc_score( $newKW, $newTit, $newDes, $final_slug );
	update_post_meta( $post_id, self::$mk_score, $sc['score'] );

	if ( $return_data ) {
		return [
			'kw'   => $newKW,
			'ti'   => $newTit,
			'de'   => $newDes,
			'slug' => $final_slug,
		];
	}
}

/* =========================================================
 *  8)  GENERATE AI SNIPPET — TERM
 * ======================================================= */
public static function generate_ai_snippet_for_term( $term_id ) {

	$opts       = get_option( 'aissp_settings' );
	$site_token = isset( $opts['site_token'] ) ? $opts['site_token'] : '';
	$model_sel  = isset( $opts['model_choice'] ) ? strtolower( $opts['model_choice'] ) : 'gpt-4-turbo';

	if ( ! $site_token ) {
		throw new \Exception( 'No site token' );
	}

	$st = aissp_check_token_status( $site_token );
// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- delivered as JSON to admin.js and shown with alert(); never echoed as HTML.
if ( isset( $st['error'] ) ) { throw new \Exception( 'Token check error: ' . $st['error'] ); }

$__remaining_global = function_exists('aissp_status_remaining')
    ? aissp_status_remaining( $st )
    : (int) ( $st['remaining'] ?? 0 );

if ( $__remaining_global <= 0 ) {
    throw new \Exception( 'No tokens left. Please upgrade.' );
}

	/* === TOKEN GUARD (enough balance for the model?) === */
	$__need      = function_exists('aissp_model_cost') ? aissp_model_cost( $model_sel ) : ( $model_sel === 'gpt-4o' ? 5 : ( $model_sel === 'gpt-4.1' ? 3 : ( $model_sel === 'o3' ? 12 : 1 ) ) );
	$__remaining = function_exists('aissp_status_remaining') ? aissp_status_remaining( $st ) : ( isset($st['remaining']) ? (int)$st['remaining'] : 0 );

	if ( $__remaining < $__need ) {
		throw new \Exception(
			esc_html(
				'Not enough tokens for the selected model (' . $model_sel . '). '
				. 'Needs ' . $__need . ', you have ' . $__remaining . '. '
				. 'Choose a cheaper model (e.g. GPT-4 Turbo) or buy more tokens.'
			)
		);
	}

	$term_obj = get_term( $term_id );
	if ( ! $term_obj || is_wp_error( $term_obj ) ) {
		throw new \Exception( 'Invalid term' );
	}
	$fw = get_term_meta( $term_id, self::$mk_focus_kw, true );

	$payload = [
		'context_type'  => 'term',
		'term_name'     => $term_obj->name,
		'term_slug'     => $term_obj->slug,
		'term_desc'     => $term_obj->description,
		'focus_keyword' => $fw,
		'model'         => $model_sel,
	];
	$payload['slug']      = $term_obj->slug;
	$payload['auto_slug'] = ( $opts['auto_slug'] ?? 'no' ) === 'yes';

	$res = aissp_call_rankpilot_api( $site_token, $payload );

	$newKW = $res['keyword']     ?? '';
	$newTit= $res['title']       ?? '';
	$newDes= $res['description'] ?? '';
	$newSlu= $res['slug']        ?? '';

	update_term_meta( $term_id, self::$mk_focus_kw, $newKW );
	update_term_meta( $term_id, self::$mk_title,    $newTit );
	update_term_meta( $term_id, self::$mk_desc,     $newDes );

	$auto = $opts['auto_slug'] ?? 'no';
	if ( $auto === 'yes' && $newSlu ) {
		$san = sanitize_title( $newSlu );
		$term_obj_after = get_term( $term_id );
		if ( $term_obj_after && $term_obj_after->slug !== $san ) {
			wp_update_term( $term_id, $term_obj->taxonomy, [ 'slug' => $san ] );
		}
	}

	$updated = get_term( $term_id );
	if ( $updated && ! is_wp_error( $updated ) ) {
		$sc = aissp_calc_score( $newKW, $newTit, $newDes, $updated->slug );
		update_term_meta( $term_id, self::$mk_score, $sc['score'] );
	}
}

	/* =========================================================
	 *  9)  FRONTEND: HOOK REGISTRATION
	 * ======================================================= */
	public static function init_hooks() {

		/* Post meta-box */
		add_action( 'add_meta_boxes',      [ __CLASS__, 'register_meta_boxes' ] );
		add_action( 'save_post',           [ __CLASS__, 'save_meta_box_data' ] );

		/* Term edit UI */
		add_action( 'product_cat_edit_form_fields', [ __CLASS__, 'render_term_edit_ui' ], 10, 2 );
		add_action( 'product_tag_edit_form_fields', [ __CLASS__, 'render_term_edit_ui' ], 10, 2 );
		add_action( 'category_edit_form_fields',    [ __CLASS__, 'render_term_edit_ui' ], 10, 2 );
		add_action( 'post_tag_edit_form_fields',    [ __CLASS__, 'render_term_edit_ui' ], 10, 2 );

		add_action( 'edited_term',         [ __CLASS__, 'save_term_edit_ui' ], 10, 2 );

		/* Admin list column */
		add_filter( 'manage_edit-category_columns',          [ __CLASS__, 'term_snippet_cols' ] );
		add_filter( 'manage_edit-post_tag_columns',          [ __CLASS__, 'term_snippet_cols' ] );
		add_filter( 'manage_edit-product_cat_columns',       [ __CLASS__, 'term_snippet_cols' ] );
		add_filter( 'manage_edit-product_tag_columns',       [ __CLASS__, 'term_snippet_cols' ] );

		add_filter( 'manage_category_custom_column',         [ __CLASS__, 'term_snippet_cols_render' ], 10, 3 );
		add_filter( 'manage_post_tag_custom_column',         [ __CLASS__, 'term_snippet_cols_render' ], 10, 3 );
		add_filter( 'manage_product_cat_custom_column',      [ __CLASS__, 'term_snippet_cols_render' ], 10, 3 );
		add_filter( 'manage_product_tag_custom_column',      [ __CLASS__, 'term_snippet_cols_render' ], 10, 3 );

		add_filter( 'manage_edit-category_sortable_columns', [ __CLASS__, 'term_snippet_cols_sortable' ] );
		add_filter( 'manage_edit-post_tag_sortable_columns', [ __CLASS__, 'term_snippet_cols_sortable' ] );
		add_filter( 'manage_edit-product_cat_sortable_columns', [ __CLASS__, 'term_snippet_cols_sortable' ] );
		add_filter( 'manage_edit-product_tag_sortable_columns', [ __CLASS__, 'term_snippet_cols_sortable' ] );

		add_filter( 'terms_clauses', [ __CLASS__, 'term_snippet_cols_sort_clauses' ], 10, 3 );
	}
}

/* =========================================================
 *  11)  BOOTSTRAP
 * ======================================================= */
add_action( 'plugins_loaded', [ 'AISSP_Meta_Box', 'init_hooks' ] );
