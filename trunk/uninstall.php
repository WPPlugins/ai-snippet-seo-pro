<?php
/**
 * Uninstall script for AI Snippet SEO Pro
 *
 * What this removes:
 * - The main plugin option.
 * - ONLY the plugin’s own score meta key (`_aissp_score`) from posts and terms.
 *
 * What this keeps (intentionally):
 * - User content metas:
 *     - `_aissp_focus_keyword`
 *     - `_aissp_seo_title`
 *     - `_aissp_seo_description`
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// 1) Remove main option
delete_option( 'aissp_settings' );

global $wpdb;

/** 2) Post meta: remove only the score */
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->postmeta} WHERE meta_key = %s",
		'_aissp_score'
	)
);

/** 3) Term meta: remove only the score */
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->termmeta} WHERE meta_key = %s",
		'_aissp_score'
	)
);

/** 4) (Optional) User-level token traces (if present) */
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->usermeta} WHERE meta_key = %s",
		'ai-snippet-seo-pro_membership_tokens'
	)
);

/* Note: User-facing content metas (focus keyword, title, description) are intentionally preserved. */
