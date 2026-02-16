<?php
if (!defined('ABSPATH')) exit;

/**
 * RankPilot JSON HTTP helper for AI Snippet SEO Pro
 *
 * Güvenli ama gerektiğinde toleranslı HTTP katmanı:
 * - WP HTTP API kullanır
 * - 429/5xx için kısa backoff + tek retry
 * - SSL/cert hatalarında (cURL 60 vs.) tek seferlik sslverify=false fallback (filtre ile kapatılabilir)
 * - Timeout / DNS gibi transient network hatalarına tek retry
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
    $maxRetries = (int) apply_filters('aissp_http_retries', 1); // 1 = bir ekstra deneme

    $triedInsecure = false;
    $attempt       = 0;
    $res           = null;

    while (true) {
        $res = $do($url, $args);

        /* ---------------- Normal HTTP cevabı ---------------- */
        if ( ! is_wp_error($res) ) {
            $code = (int) wp_remote_retrieve_response_code($res);

            // 2xx / 3xx / 4xx ama retry listesinde değil → döngü biter
            if ( ! in_array($code, $retryCodes, true) ) {
                break;
            }

            // 429 / 5xx için sınırlı retry
            if ( $attempt >= $maxRetries ) {
                break;
            }

            $attempt++;
            usleep(200000 * $attempt); // 0.2s, 0.4s
            continue;
        }

        /* ---------------- WP_Error cevabı ---------------- */
        $msg = implode('; ', $res->get_error_messages());

        // 1) SSL / sertifika problemi → tek seferlik insecure retry (opsiyonel)
        $isCertErr = ( stripos($msg, 'certificate') !== false )
                  || ( stripos($msg, 'SSL') !== false )
                  || ( stripos($msg, 'cURL error 60') !== false );

        if (
            $isCertErr
            && ! $triedInsecure
            && apply_filters('aissp_http_allow_insecure_retry', true, $res, $url, $args)
        ) {
            $triedInsecure           = true;
            $args['sslverify']       = false;
            unset($args['sslcertificates']);

            $attempt++;
            usleep(200000 * $attempt);
            continue;
        }

        // 2) Timeout / DNS / cURL 28/6 gibi transient hatalar
        $transientErr = ( stripos($msg, 'timed out') !== false )
                     || ( stripos($msg, 'cURL error 28') !== false )
                     || ( stripos($msg, 'Could not resolve') !== false )
                     || ( stripos($msg, 'cURL error 6') !== false );

        if ( $transientErr && $attempt < $maxRetries ) {
            $attempt++;
            usleep(200000 * $attempt);
            continue;
        }

        // 3) Kalıcı WP_Error → vazgeç
        break;
    }

    return apply_filters('aissp_http_result', $res, $url, $payload, $method, $args, $attempt);
}
