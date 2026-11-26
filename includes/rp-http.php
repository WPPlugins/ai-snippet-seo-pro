<?php
if (!defined('ABSPATH')) exit;

/**
 * RankPilot JSON HTTP helper for AI Snippet SEO Pro
 *
 * Secure-by-default HTTP layer:
 * - Uses the WordPress HTTP API with its default CA bundle.
 * - Short backoff plus a single retry for 429/5xx responses.
 * - A single retry for transient network errors (timeout / DNS).
 * - TLS certificate verification is never disabled automatically. Site owners with a
 *   broken local CA bundle can opt in explicitly via the aissp_http_allow_insecure_retry
 *   filter, but that downgrades the connection and is not recommended.
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
        // No hardcoded sslcertificates path - use WP defaults so custom/host CA bundles keep working.
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
    $attempt       = 0;
    $res           = null;

    while (true) {
        $res = $do($url, $args);

        /* ---------------- Normal HTTP response ---------------- */
        if ( ! is_wp_error($res) ) {
            $code = (int) wp_remote_retrieve_response_code($res);

            // 2xx / 3xx / 4xx that is not in the retry list -> stop looping
            if ( ! in_array($code, $retryCodes, true) ) {
                break;
            }

            // Limited retry for 429 / 5xx
            if ( $attempt >= $maxRetries ) {
                break;
            }

            $attempt++;
            usleep(200000 * $attempt); // 0.2s, 0.4s
            continue;
        }

        /* ---------------- WP_Error response ---------------- */
        $msg = implode('; ', $res->get_error_messages());

        // 1) TLS / certificate problem. Verification stays ON unless the site owner
        //    explicitly opts in, because a failed certificate check is exactly the
        //    signal that the connection may not be trustworthy.
        $isCertErr = ( stripos($msg, 'certificate') !== false )
                  || ( stripos($msg, 'SSL') !== false )
                  || ( stripos($msg, 'cURL error 60') !== false );

        if (
            $isCertErr
            && ! $triedInsecure
            && apply_filters('aissp_http_allow_insecure_retry', false, $res, $url, $args)
        ) {
            $triedInsecure           = true;
            $args['sslverify']       = false;
            unset($args['sslcertificates']);

            $attempt++;
            usleep(200000 * $attempt);
            continue;
        }

        // 2) Transient errors such as timeout / DNS / cURL 28 / cURL 6
        $transientErr = ( stripos($msg, 'timed out') !== false )
                     || ( stripos($msg, 'cURL error 28') !== false )
                     || ( stripos($msg, 'Could not resolve') !== false )
                     || ( stripos($msg, 'cURL error 6') !== false );

        if ( $transientErr && $attempt < $maxRetries ) {
            $attempt++;
            usleep(200000 * $attempt);
            continue;
        }

        // 3) Permanent WP_Error -> give up
        break;
    }

    return apply_filters('aissp_http_result', $res, $url, $payload, $method, $args, $attempt);
}
