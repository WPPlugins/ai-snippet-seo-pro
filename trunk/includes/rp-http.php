<?php
if (!defined('ABSPATH')) exit;

/**
 * RankPilot JSON HTTP helper for AI Snippet SEO Pro
 *
 * @file      rp-http.php
 * @package   RankPilotAI\AI_Snippet_SEO_Pro\Http
 * @since     1.0.0
 * @version   1.0.0
 * @license   GPL-2.0-or-later
 *
 * Features
 * - Uses WordPress HTTP API with JSON headers & UA.
 * - One-time insecure retry (sslverify=false) for cURL error 60 / SSL certificate issues.
 * - Short backoff + single retry for 429/5xx and common transient network errors.
 * - Fully filterable request args & retry behavior (developer friendly).
 */
function aissp_http_json(string $url, array $payload = [], string $method = 'POST', array $extra = []) {
    $base = [
        'headers'         => [
            'Content-Type' => 'application/json',
            'Accept'       => 'application/json',
            'User-Agent'   => 'AI Snippet SEO Pro/' . (defined('AISSP_VERSION') ? AISSP_VERSION : '1.x'),
        ],
        'body'            => wp_json_encode($payload),
        'timeout'         => 25,
        'httpversion'     => '1.1',
        'sslverify'       => true,
        'sslcertificates' => ABSPATH . WPINC . '/certificates/ca-bundle.crt',
        'redirection'     => 2,
    ];

    $args = array_replace_recursive($base, $extra);
    /** Allow customization via filter. */
    $args = apply_filters('aissp_http_args', $args, $url, $payload, $method);

    $do = (strtoupper($method) === 'GET') ? 'wp_remote_get' : 'wp_remote_post';

    /** Retry list & attempts. */
    $retryCodes = apply_filters('aissp_http_retry_codes', [429, 500, 502, 503, 504, 520, 521, 522]);
    $maxRetries = (int) apply_filters('aissp_http_retries', 1); // 1 = one extra attempt

    $triedInsecure = false;
    $attempt = 0;
    $res = null;

    while (true) {
        $res = $do($url, $args);

        if (!is_wp_error($res)) {
            $code = (int) wp_remote_retrieve_response_code($res);
            if (!in_array($code, $retryCodes, true)) {
                // Success or a non-retryable response code.
                break;
            }
            if ($attempt >= $maxRetries) break; // No more retries allowed.
            usleep(200000 * ($attempt + 1));    // Backoff: 0.2s, 0.4s
            $attempt++;
            continue;
        }

        // WP_Error — stop on SSL or network failure (no insecure retry)
$msg = implode('; ', $res->get_error_messages());
if (is_wp_error($res)) {
    break;
}

        // Timeout / DNS / cURL 28 and other transient errors
        $transientErr = (stripos($msg, 'timed out') !== false)
                     || (stripos($msg, 'cURL error 28') !== false)
                     || (stripos($msg, 'Could not resolve') !== false)
                     || (stripos($msg, 'cURL error 6') !== false);

        if ($transientErr && $attempt < $maxRetries) {
            usleep(200000 * ($attempt + 1));
            $attempt++;
            continue;
        }

        // Persistent failure — give up.
        break;
    }

    return apply_filters('aissp_http_result', $res, $url, $payload, $method, $args, $attempt);
}
