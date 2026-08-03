<?php
/**
 * AI Snippet SEO Pro — Helper Functions
 *
 * @file      helper-functions.php
 * @package   RankPilotAI\AI_Snippet_SEO_Pro\Helpers
 * @since     1.0.0
 * @version   1.0.28
 * @license   GPL-2.0-or-later
 *
 * Provides:
 * - Snippet score calculation utilities
 * - RankPilotAI API helpers
 * - Token status / deduction (supports 4 models)
 *
 * Models & Token Costs
 * --------------------
 *   gpt-4o       → 5 tokens / snippet
 *   gpt-4-turbo  → 1 token  / snippet
 *   gpt-4.1      → 3 tokens / snippet
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

/* Add: mb_strlen polyfill (fallback only; defined once is enough) */
if ( ! function_exists( 'mb_strlen' ) ) {
	function mb_strlen( $s, $enc = '' ) { return strlen( $s ); }
}

/* =========================================================
 *  1)  SNIPPET SCORE CALCULATION
 * ======================================================= */
/**
 * Calculate the snippet score and color badge.
 *
 * @param string $fw   Focus keyword(s), comma-separated.
 * @param string $ti   SEO title.
 * @param string $de   Meta description.
 * @param string $slug URL slug.
 * @return array{score:int,color:string} Score (0–100) and hex color.
 */
function aissp_calc_score( $fw, $ti, $de, $slug ) {

	$count = 0;
	$arr   = array_map( 'trim', explode( ',', $fw ) );
	$kw    = strtolower( $arr[0] ?? '' );

	if ( $kw && strpos( strtolower( $ti ), $kw ) !== false ) { $count ++; }
	if ( $kw && strpos( strtolower( $de ), $kw ) !== false ) { $count ++; }

	$sl    = str_replace( ' ', '-', strtolower( $slug ) );
	$kwRep = str_replace( ' ', '-', $kw );
	if ( $kw && strpos( $sl, $kwRep ) !== false ) { $count ++; }

	$tLen = strlen( $ti );
	if ( $tLen >= 30 && $tLen <= 60 ) { $count ++; }
	if ( strlen( $slug ) > 0 && strlen( $slug ) <= 75 ) { $count ++; }
	$dLen = mb_strlen( $de, 'UTF-8' );
	if ( $dLen >= 120 && $dLen <= 160 ) { $count ++; }

	$score = min( 100, $count * 17 );

	$color = '#dc3232';
	if ( $score >= 100 )     { $color = '#0091e0'; }
	elseif ( $score >= 67 )  { $color = '#ffab00'; }

	return [ 'score' => $score, 'color' => $color ];
}

/**
 * Format score text for UI badges.
 *
 * @param int $val Score value.
 * @return string
 */
function aissp_score_text( $val ) { return $val . ' / 100'; }

/**
 * Get per-model token cost.
 *
 * @param string $model Model slug.
 * @return int Tokens required for one snippet.
 */
function aissp_model_cost( string $model ) : int {
	$m = strtolower( trim( $model ) );
	if ( $m === 'gpt-4o' )   return 5;
	if ( $m === 'gpt-4.1' )  return 3;
	if ( $m === 'o3' )       return 12; // optional
	/* gpt-4-turbo (default) */
	return 1;
}

/**
 * Derive a global "remaining" value from token-info response.
 * Tries multiple keys for compatibility with different API variants.
 *
 * @param array $st Token status payload.
 * @return int Global remaining tokens.
 */
function aissp_status_remaining(array $st): int {
    // Prefer 'remaining_total' when provided.
    if (isset($st['remaining_total'])) {
        return (int)$st['remaining_total'];
    }

    // Some APIs expose a single 'remaining' which might already be global.
    if (isset($st['remaining']) && (int)$st['remaining'] > 0) {
        return (int)$st['remaining'];
    }

    // Sum known balance fields we observed in debugging.
    $free      = (int)($st['free'] ?? 0);
    $monthly   = (int)($st['monthly_remaining'] ?? 0);
    $pack      = (int)($st['token_pack'] ?? 0);

    $remain = $free + $monthly + $pack;

    // Fallback: derive from limit - usage if still zero.
    if ($remain <= 0) {
        $limit = (int)($st['token_limit'] ?? 0);
        $usage = (int)($st['all_usage'] ?? $st['token_usage'] ?? 0);
        $remain = max(0, $limit - $usage);
    }

    return $remain;
}

/* =========================================================
 *  2)  RANKPILOT API → /snippet
 * ======================================================= */
/**
 * Call RankPilotAI /snippet endpoint and return parsed JSON.
 *
 * @param string $site_token Site token.
 * @param array  $payload    Request payload.
 * @throws Exception When request or response is invalid.
 * @return array Decoded JSON response.
 */
function aissp_call_rankpilot_api( $site_token, $payload ) {
    if ( empty( $site_token ) ) {
        throw new Exception( 'No site token configured.' );
    }

    $opts                = get_option( AISSP_SETTINGS_SLUG, [] );
    $payload['domain']   = parse_url( home_url(), PHP_URL_HOST );
    $payload['site_url'] = home_url();
    $payload['plugin']   = 'ai-snippet-seo-pro';
    $payload['site_token'] = $site_token;

    // If the payload didn't set a model, take it from options.
    if ( empty( $payload['model'] ) ) {
        $payload['model'] = $opts['model_choice'] ?? 'gpt-4-turbo';
    }

    $payload['custom_prompt'] = $opts['custom_prompt'] ?? '';

    $res  = aissp_http_json( AISSP_RP_API . '/snippet', $payload, 'POST' );
    if ( is_wp_error( $res ) ) {
        throw new Exception( 'API request error: ' . $res->get_error_message() );
    }
    $code = wp_remote_retrieve_response_code( $res );
    $body = wp_remote_retrieve_body( $res );
    if ( $code !== 200 ) {
        throw new Exception( 'RankPilotAI API Error (HTTP ' . $code . '): ' . $body );
    }
    $json = json_decode( $body, true );
    if ( ! is_array( $json ) ) {
        throw new Exception( 'Invalid JSON response from rankpilotai.com' );
    }
    return $json;
}

/* =========================================================
 *  3)  TOKEN STATUS → /token-info
 * ======================================================= */
/**
 * Query /token-info and return the decoded payload (or minimal error).
 *
 * @param string $site_token Site token.
 * @return array Token status data (ensures 'allowed_models' exists) or ['error'=>...].
 */
function aissp_check_token_status( $site_token ) {
    if ( empty( $site_token ) ) {
        return [ 'error' => 'No token provided' ];
    }

    $body = [
        'site_token' => $site_token,
        'plugin'     => 'ai-snippet-seo-pro',
        'site_url'   => home_url(),

        // Add: cache bypass and (if server supports) balances request.
        'cache_bust' => time(),
        'include'    => 'balances,usage,limits',
    ];

    // Add no-cache header.
    $res = aissp_http_json(
        AISSP_RP_API . '/token-info',
        $body,
        'POST',
        [ 'timeout' => 20, 'headers' => [ 'Cache-Control' => 'no-cache' ] ]
    );

    // 1) WP_Error -> return the real message (far more useful when debugging)
    if ( is_wp_error( $res ) ) {
        $msg = implode( '; ', $res->get_error_messages() );
        if ( ! $msg ) {
            $msg = 'Connection Error';
        }
        return [ 'error' => $msg ];
    }

    $code = (int) wp_remote_retrieve_response_code( $res );
    $body = wp_remote_retrieve_body( $res );
    $data = json_decode( $body, true );

    // 2) Non-200 status -> surface the API's own error message when available
    if ( $code !== 200 ) {
        if ( is_array( $data ) && ! empty( $data['error'] ) ) {
            return [
                'error'     => (string) $data['error'],
                'http_code' => $code,
            ];
        }

        return [
            'error'     => 'HTTP ' . $code,
            'http_code' => $code,
        ];
    }

    // 3) JSON parse edilemediyse
    if ( ! is_array( $data ) ) {
        return [ 'error' => 'Invalid JSON response from rankpilotai.com' ];
    }

    if ( empty( $data['allowed_models'] ) ) {
        $data['allowed_models'] = [ 'gpt-4-turbo' ];
    }

    return $data;
}

/* =========================================================
 *  4)  TOKEN DEDUCTION → /token-reduce
 * ======================================================= */
/**
 * Deduct tokens for a given model.
 *
 * @param string $site_token Site token.
 * @param string $model      Model slug (default: gpt-4-turbo).
 * @throws Exception On connection or API error.
 * @return array API response payload.
 */
function aissp_reduce_rankpilot_token( $site_token, $model = 'gpt-4-turbo' ) {

	$body = [
		'site_token' => $site_token,
		'plugin'     => 'ai-snippet-seo-pro',        /*  added */
		'model'      => strtolower( $model ),
		'site_url'   => home_url(),
	];

	$res = aissp_http_json( AISSP_RP_API . '/token-reduce', $body, 'POST', ['timeout'=>20] );
if ( is_wp_error( $res ) ) { throw new Exception( 'RankPilotAI connection error.' ); }
$data = json_decode( wp_remote_retrieve_body( $res ), true );
if ( isset( $data['error'] ) ) { throw new Exception( $data['error'] ); }
return $data;
}

/* =========================================================
 *  2B)  BULK SNIPPET HELPERS (single HTTP call)
 * ======================================================= */

/**
 * Prepare minimal item payload for a post (used by bulk and single calls).
 *
 * @param int   $post_id Post ID.
 * @param array $opts    Not used here (reserved for future).
 * @return array Payload item for bulk API.
 */
function aissp_prepare_snippet_item_for_post( int $post_id, array $opts = [] ) : array {
	$title   = get_the_title( $post_id );
	$excerpt = get_the_excerpt( $post_id );
	$ptype   = get_post_type( $post_id );
	$fw      = get_post_meta( $post_id, '_aissp_focus_keyword', true );

	return [
		'id'            => (int) $post_id,
		'context_type'  => 'post',
		'post_title'    => $title,
		'post_excerpt'  => $excerpt,
		'post_type'     => $ptype,
		'focus_keyword' => $fw,
		'slug'          => get_post_field( 'post_name', $post_id ),
	];
}

/**
 * Prepare minimal item payload for a term (used by bulk and single calls).
 *
 * @param int   $term_id Term ID.
 * @param array $opts    Not used here (reserved for future).
 * @return array Payload item for bulk API.
 */
function aissp_prepare_snippet_item_for_term( int $term_id, array $opts = [] ) : array {
	$t = get_term( $term_id );
	if ( ! $t || is_wp_error( $t ) ) {
		return [ 'id' => (int)$term_id, 'context_type' => 'term', 'error' => 'Invalid term' ];
	}
	$fw = get_term_meta( $term_id, '_aissp_focus_keyword', true );

	return [
		'id'            => (int) $term_id,
		'context_type'  => 'term',
		'term_name'     => $t->name,
		'term_slug'     => $t->slug,
		'term_desc'     => $t->description,
		'focus_keyword' => $fw,
		'slug'          => $t->slug,
		'taxonomy'      => $t->taxonomy,
	];
}

/**
 * Attempt a single /snippet-bulk request, else gracefully fall back to per-item /snippet calls.
 *
 * @param string $site_token Site token.
 * @param array  $items      Array of items from prepare helpers.
 * @param string $model_sel  Selected model.
 * @param array  $opts       Options: custom_prompt, auto_slug ('yes'|'no').
 * @throws Exception When critical input is missing.
 * @return array API response in unified shape: ['items'=>array, 'fallback' => 'single' (optional)].
 */
function aissp_call_rankpilot_api_bulk( string $site_token, array $items, string $model_sel, array $opts = [] ) : array {
	if ( empty( $site_token ) ) { throw new Exception('No site token configured.'); }
	if ( empty( $items ) )      { return [ 'items' => [] ]; }

	$endpoint = AISSP_RP_API . '/snippet-bulk';
	$endpoint = apply_filters( 'aissp_bulk_endpoint', $endpoint );

	$payload = [
		'site_token'    => $site_token,
		'plugin'        => 'ai-snippet-seo-pro',
		'site_url'      => home_url(),
		'domain'        => parse_url( home_url(), PHP_URL_HOST ),
		'model'         => strtolower( $model_sel ?: 'gpt-4-turbo' ),
		'custom_prompt' => $opts['custom_prompt'] ?? '',
		'auto_slug'     => ( $opts['auto_slug'] ?? 'yes' ) === 'yes',
		'items'         => array_values( $items ),
	];

	/* ---------- 1) Try BULK ---------- */
	$res = aissp_http_json( $endpoint, $payload, 'POST', [ 'timeout' => 120 ] );
	if ( ! is_wp_error( $res ) ) {
		$code = (int) wp_remote_retrieve_response_code( $res );
		$body = wp_remote_retrieve_body( $res );

		if ( $code === 200 ) {
			$json = json_decode( $body, true );
			if ( is_array( $json ) ) {
				// Some implementations may return id=>data directly.
				if ( empty( $json['items'] ) ) {
					$json['items'] = $json;
				}
				return $json;
			}
		}
		/* Not 200 (404/5xx/etc.) → silently fall back. */
	}

	/* ---------- 2) FALLBACK: per-item /snippet loop ---------- */
	$out = [];
	foreach ( $items as $it ) {
		$single = array_merge( $it, [
			'model'         => strtolower( $model_sel ?: 'gpt-4-turbo' ),
			'auto_slug'     => ( $opts['auto_slug'] ?? 'yes' ) === 'yes',
			'custom_prompt' => $opts['custom_prompt'] ?? '',
		] );

		try {
			$resp = aissp_call_rankpilot_api( $site_token, $single );
			$resp['id'] = (int) ($it['id'] ?? 0);
			$out[ (int)$it['id'] ] = $resp;
		} catch ( \Exception $ex ) {
			$out[ (int)$it['id'] ] = [
				'id'    => (int)$it['id'],
				'error' => $ex->getMessage(),
			];
		}

		// Small pause — helps avoid CF/LB rate-limit bursts.
		usleep( 50000 ); // 50ms
	}

	return [ 'items' => $out, 'fallback' => 'single' ];
}

/**
 * Apply a snippet API result to a post.
 *
 * @param int   $post_id Post ID.
 * @param array $data    Result item: keyword/title/description/slug.
 * @param array $opts    ['auto_slug' => 'yes'|'no'].
 * @return bool True on write success (or when only meta updated).
 */
function aissp_apply_snippet_result_for_post( int $post_id, array $data, array $opts = [] ) : bool {
	if ( ! empty( $data['error'] ) ) { return false; }

	$kw   = $data['keyword']     ?? '';
	$ti   = $data['title']       ?? '';
	$de   = $data['description'] ?? '';
	$slug = $data['slug']        ?? '';

	update_post_meta( $post_id, '_aissp_focus_keyword', $kw );
	update_post_meta( $post_id, '_aissp_seo_title',     $ti );
	update_post_meta( $post_id, '_aissp_seo_description', $de );

	// Auto-slug
	if ( ( $opts['auto_slug'] ?? 'no' ) === 'yes' && $slug ) {
		$san = sanitize_title( $slug );
		$cur = get_post_field( 'post_name', $post_id );
		if ( $san && $san !== $cur ) {
			add_post_meta( $post_id, '_wp_old_slug', $cur );
			wp_update_post( [ 'ID' => $post_id, 'post_name' => $san ] );
		}
	}

	$final_slug = get_post_field( 'post_name', $post_id );
	$sc         = aissp_calc_score( $kw, $ti, $de, $final_slug );
	update_post_meta( $post_id, '_aissp_score', $sc['score'] );
	return true;
}

/**
 * Apply a snippet API result to a term.
 *
 * @param int   $term_id Term ID.
 * @param array $data    Result item: keyword/title/description/slug.
 * @param array $opts    ['auto_slug' => 'yes'|'no'].
 * @return bool True on write success (or when only meta updated).
 */
function aissp_apply_snippet_result_for_term( int $term_id, array $data, array $opts = [] ) : bool {
	if ( ! empty( $data['error'] ) ) { return false; }

	$kw   = $data['keyword']     ?? '';
	$ti   = $data['title']       ?? '';
	$de   = $data['description'] ?? '';
	$slug = $data['slug']        ?? '';

	update_term_meta( $term_id, '_aissp_focus_keyword', $kw );
	update_term_meta( $term_id, '_aissp_seo_title',     $ti );
	update_term_meta( $term_id, '_aissp_seo_description', $de );

	if ( ( $opts['auto_slug'] ?? 'no' ) === 'yes' && $slug ) {
		$san = sanitize_title( $slug );
		$tm  = get_term( $term_id );
		if ( $tm && ! is_wp_error( $tm ) && $tm->slug !== $san ) {
			wp_update_term( $term_id, $tm->taxonomy, [ 'slug' => $san ] );
		}
	}

	$tm2 = get_term( $term_id );
	if ( $tm2 && ! is_wp_error( $tm2 ) ) {
		$sc = aissp_calc_score( $kw, $ti, $de, $tm2->slug );
		update_term_meta( $term_id, '_aissp_score', $sc['score'] );
	}
	return true;
}

/* =========================================================
 *  MODE SYSTEM (Trial / BYOK / Pro)
 * ======================================================= */

function aissp_active_mode(): string {
	$opt = get_option( AISSP_SETTINGS_SLUG, [] );
	if ( ! empty( $opt['site_token'] ) )  return 'pro';
	if ( ! empty( $opt['openai_key'] ) )  return 'byok';
	if ( ! empty( $opt['trial_token'] ) ) return 'trial';
	return 'none';
}

function aissp_call_openai_direct( string $api_key, array $context ): array {
	$prompt = "You are an expert SEO consultant. Generate an SEO-optimized snippet for the following content.\n\n"
		. "Content title: " . ( $context['post_title'] ?? $context['term_name'] ?? '' ) . "\n"
		. "Content excerpt: " . ( $context['post_excerpt'] ?? $context['term_desc'] ?? '' ) . "\n"
		. "Current slug: " . ( $context['slug'] ?? '' ) . "\n\n"
		. "Return a JSON object with exactly these keys:\n"
		. "- keyword: primary focus keyword (2-4 words)\n"
		. "- title: SEO title (30-60 chars, must contain the keyword)\n"
		. "- description: meta description (120-160 chars, must contain the keyword)\n"
		. "- slug: URL-friendly slug (must contain the keyword)\n\n"
		. "Return ONLY the JSON object, no markdown, no explanation.";

	$body = [
		'model'       => 'gpt-4.1-mini',
		'messages'    => [ [ 'role' => 'user', 'content' => $prompt ] ],
		'max_tokens'  => 300,
		'temperature' => 0,
	];

	$res = wp_remote_post( 'https://api.openai.com/v1/chat/completions', [
		'headers' => [
			'Authorization' => 'Bearer ' . $api_key,
			'Content-Type'  => 'application/json',
		],
		'body'    => wp_json_encode( $body ),
		'timeout' => 30,
	] );

	if ( is_wp_error( $res ) ) return [ 'error' => $res->get_error_message() ];
	$code = wp_remote_retrieve_response_code( $res );
	if ( $code === 401 ) return [ 'error' => 'Invalid OpenAI API key' ];
	if ( $code !== 200 ) return [ 'error' => 'OpenAI API error (HTTP ' . $code . ')' ];

	$json = json_decode( wp_remote_retrieve_body( $res ), true );
	$text = trim( $json['choices'][0]['message']['content'] ?? '' );
	if ( strpos( $text, '```' ) !== false ) {
		$text = trim( preg_replace( '/```(?:json)?\s*|```/', '', $text ) );
	}
	$parsed = json_decode( $text, true );
	return is_array( $parsed ) ? $parsed : [ 'error' => 'Failed to parse OpenAI response' ];
}

function aissp_call_trial_api( array $context ): array {
	$opts  = get_option( AISSP_SETTINGS_SLUG, [] );
	$token = $opts['trial_token'] ?? '';
	if ( ! $token ) return [ 'error' => 'No trial token' ];

	$payload = array_merge( $context, [
		'trial_token' => $token,
		'domain'      => wp_parse_url( home_url(), PHP_URL_HOST ),
		'site_url'    => home_url(),
		'plugin'      => 'ai-snippet-seo-pro',
	] );

	$res = wp_remote_post( AISSP_RP_API . '/trial-snippet', [
		'headers' => [ 'Content-Type' => 'application/json' ],
		'body'    => wp_json_encode( $payload ),
		'timeout' => 30,
	] );

	if ( is_wp_error( $res ) ) return [ 'error' => $res->get_error_message() ];
	$code = wp_remote_retrieve_response_code( $res );
	$body = json_decode( wp_remote_retrieve_body( $res ), true );
	if ( $code !== 200 || ! is_array( $body ) ) {
		return [ 'error' => $body['error'] ?? 'Trial API error (HTTP ' . $code . ')' ];
	}
	return $body;
}
