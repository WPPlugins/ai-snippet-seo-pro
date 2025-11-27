<?php
/*
Plugin Name: AI Snippet SEO Pro
Description: Generate AI SEO titles and meta descriptions in one click, with scoring, live preview and bulk tools for posts and taxonomies.
Version: 1.0.20
Author:      RankPilotAI
Author URI:  https://rankpilotai.com
Text Domain: ai-snippet-seo-pro
Domain Path: /languages
Requires at least: 5.8
Requires PHP:      7.4
License:           GPLv2 or later
License URI:       https://www.gnu.org/licenses/gpl-2.0.html
*/

if ( ! defined('ABSPATH') ) exit;

define('AISSP_FILE', __FILE__);
define('AISSP_RP_API', 'https://rankpilotai.com/wp-json/rankpilotai/v1');

/* Version constant — basit ve PHP5 uyumlu */
if ( ! defined('AISSP_VERSION') ) { if ( ! defined('AISSP_VERSION') ) if ( ! defined('AISSP_VERSION') ) if ( ! defined('AISSP_VERSION') ) define('AISSP_VERSION','1.0.20'); }
function aissp_plugin_activate() {
    $option_key = 'aissp_settings';
    $opts = get_option($option_key,[]);
    if(!is_array($opts)) $opts=[];
    if(empty($opts['site_token'])){
        $opts['site_token'] = 'rp_'.wp_generate_password(20,false,false);
        update_option($option_key,$opts);
    }
}
register_activation_hook(__FILE__,'aissp_plugin_activate');

final class AI_Snippet_SEO_Pro {
    protected static $instance = null;
    private $supported_taxonomies = ['category','post_tag','product_cat','product_tag'];

    public static function get_instance(){
        if(self::$instance===null){
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct(){
        $this->define_constants();
        $this->includes();
        $this->init_hooks();
    }

    private function define_constants(){
        if ( ! defined('AISSP_VERSION') ) if ( ! defined('AISSP_VERSION') ) if ( ! defined('AISSP_VERSION') ) define('AISSP_VERSION','1.0.9');
        define('AISSP_PATH', plugin_dir_path(__FILE__));
        define('AISSP_URL', plugin_dir_url(__FILE__));
        define('AISSP_SETTINGS_SLUG','aissp_settings');
    }

    private function includes(){
        require_once AISSP_PATH.'admin/settings-page.php';
        require_once AISSP_PATH.'admin/meta-box.php';
        require_once AISSP_PATH.'includes/rp-http.php';       
        require_once AISSP_PATH.'includes/helper-functions.php';
    }

    private function init_hooks(){
        add_filter('plugin_action_links_'.plugin_basename(__FILE__),[$this,'settings_link']);

        add_action('admin_menu',[$this,'register_admin_menu']);
        add_action('admin_init',[$this,'register_settings']);
        add_action('wp_ajax_aissp_bulk_tick',   [$this,'ajax_bulk_tick']);
        add_action('wp_ajax_aissp_bulk_cancel', [$this,'ajax_bulk_cancel']);

        // Bulk snippet for terms
        foreach($this->supported_taxonomies as $tax){
            add_filter("bulk_actions-edit-{$tax}",[$this,'bulk_actions_terms']);
            add_filter("handle_bulk_actions-edit-{$tax}",[$this,'handle_bulk_terms'],10,3);
        }

        // Ajax snippet generation for single post
        add_action('wp_ajax_aissp_generate_snippet',[$this,'ajax_generate_snippet']);

        // Head meta tags
        add_action('wp_head',[$this,'inject_head_meta'],1);

        // Admin CSS/JS
        add_action('admin_enqueue_scripts',[$this,'enqueue_assets']);

        // Bulk + filter for posts
        add_action('admin_init',[$this,'init_posttype_bulk_and_filter']);
        add_action('admin_init',[$this,'listing_columns_setup']);

        // Notices
        add_action('admin_notices',[$this,'show_bulk_notices']);

        // NOTE: Previously inline <style> was printed via admin_head.
        // That violates the "use enqueue" guideline, so it's removed.
        // Icon CSS is now injected via wp_add_inline_style() inside enqueue_assets().
        // add_action('admin_head', [$this,'menu_icon_color_fix']); // removed
    }

    public function settings_link($links){
        $settings_url = admin_url('admin.php?page=ai-snippet-seo-pro');
        array_unshift($links,'<a href="'.esc_url($settings_url).'">Settings</a>');
        return $links;
    }

    public function register_admin_menu(){
        add_menu_page(
            'AI Snippet SEO Pro',                          // Page title
            'AI Snippet SEO Pro',                          // Menu title
            'manage_options',                              // Capability
            'ai-snippet-seo-pro',                          // Menu slug
            [$this,'render_settings_page'],                // Callback
            plugin_dir_url(__FILE__) . 'admin/assets/img/ai-snippet-seo-pro.svg', // icon_url (SVG yolu)
            25                                             // Position
        );
    }

    public function render_settings_page(){
        aissp_render_settings_page();
    }
    public function register_settings(){
        aissp_register_settings();
    }

    public function enqueue_assets($hook){

        /**
         * Always enqueue admin CSS so the menu icon mask CSS can be added via wp_add_inline_style.
         * (Keeps us compliant with "use wp_enqueue" and ensures icon styling across admin.)
         */
        $css_file = AISSP_PATH . 'admin/assets/css/admin.css';
        wp_enqueue_style(
            'aissp-admin-css',
            AISSP_URL . 'admin/assets/css/admin.css',
            [],
            file_exists($css_file) ? filemtime($css_file) : AISSP_VERSION
        );

        // Add the menu icon CSS as inline style instead of echoing a <style> tag.
        $icon_path = AISSP_PATH . 'admin/assets/img/ai-snippet-seo-pro.svg';
        $icon_url  = AISSP_URL  . 'admin/assets/img/ai-snippet-seo-pro.svg';
        $ver       = file_exists($icon_path) ? filemtime($icon_path) : AISSP_VERSION;
        $icon      = esc_url( $icon_url . '?v=' . $ver );

        $icon_css = '
#adminmenu #toplevel_page_ai-snippet-seo-pro .wp-menu-image img{display:none !important;}
#adminmenu #toplevel_page_ai-snippet-seo-pro .wp-menu-image{
    color:#a7aaad !important;
    background-color: currentColor !important;
    -webkit-mask: url("'.$icon.'") center/18px 18px no-repeat;
    mask: url("'.$icon.'") center/18px 18px no-repeat;
}
#adminmenu li#toplevel_page_ai-snippet-seo-pro:hover > a .wp-menu-image,
#adminmenu li#toplevel_page_ai-snippet-seo-pro.wp-has-current-submenu > a .wp-menu-image,
#adminmenu li#toplevel_page_ai-snippet-seo-pro.current > a .wp-menu-image{
    color:inherit !important;
}';
        wp_add_inline_style('aissp-admin-css', $icon_css);

        // Only load JS and localize data where needed.
        if (
            in_array($hook, ['post.php','post-new.php','term.php','edit-tags.php','edit.php'], true)
            || strpos($hook, 'ai-snippet-seo-pro') !== false
        ) {
            wp_enqueue_script(
                'aissp-admin-js',
                AISSP_URL . 'admin/assets/js/admin.js',
                ['jquery'],
                filemtime(AISSP_PATH . 'admin/assets/js/admin.js'),
                true
            );

            $opts = get_option( AISSP_SETTINGS_SLUG, [] );
            wp_localize_script(
                'aissp-admin-js',
                'AISSP_Ajax',
                [
                    'ajaxurl'    => admin_url( 'admin-ajax.php' ),
                    'nonce'      => wp_create_nonce( 'aissp_ajax_nonce' ),
                    'site_token' => $opts['site_token']   ?? '',
                    'rest_url'   => AISSP_RP_API,
                    'site_url'   => home_url(),
                    'model'      => $opts['model_choice'] ?? 'gpt-4-turbo',
                ]
            );
        }
    }

    public function ajax_generate_snippet(){
        check_ajax_referer('aissp_ajax_nonce','nonce');

        // nonce OK; ensure we have a post ID
        $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
        if ( ! $post_id ) {
            wp_send_json_error(['msg' => 'Invalid post ID']);
        }

        // Extra guard: verify capability to edit this post
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            wp_send_json_error(['msg' => 'Unauthorized']);
        }

        try{
            AISSP_Meta_Box::generate_ai_snippet_for_post($post_id,false);

            $fw   = get_post_meta($post_id,'_aissp_focus_keyword',true);
            $ti   = get_post_meta($post_id,'_aissp_seo_title',true);
            $de   = get_post_meta($post_id,'_aissp_seo_description',true);
            $slug = get_post_field('post_name',$post_id);

            $sc   = aissp_calc_score($fw,$ti,$de,$slug);
            wp_send_json_success([
                // Keep legacy keys to avoid breaking other integrations
                'keyword' => $fw,
                'title'   => $ti,
                'desc'    => $de,
                // Short keys expected by the JS
                'kw'      => $fw,
                'ti'      => $ti,
                'de'      => $de,
                'slug'    => $slug,
                'score'   => aissp_score_text( $sc['score'] ),
                'color'   => $sc['color'],
            ]);
        }catch(\Exception $ex){
            wp_send_json_error(['msg'=>$ex->getMessage()]);
        }
    }

    // Bulk actions for terms
    public function bulk_actions_terms($actions){
        $actions['aissp_generate_seo'] = 'Generate with AI Snippet SEO Pro';
        return $actions;
    }
    public function handle_bulk_terms($redirect,$action,$term_ids){
        if($action!=='aissp_generate_seo') return $redirect;

        $opts = get_option(AISSP_SETTINGS_SLUG);
        if(empty($opts['site_token'])){
            return add_query_arg('aissp_berror','notoken',$redirect);
        }
        $site_token = $opts['site_token'];
        $model_sel  = strtolower($opts['model_choice'] ?? 'gpt-4-turbo');

        $ids=[];
        foreach((array)$term_ids as $tid){
            $tid=(int)$tid; if($tid>0 && current_user_can('edit_term',$tid)) $ids[]=$tid;
        }
        if(empty($ids)) return $redirect;

        $st = aissp_check_token_status($site_token);
        if ( isset($st['error']) ) {
            return add_query_arg('aissp_berror','tokencheck',$redirect);
        }
        $remaining = function_exists('aissp_status_remaining') ? aissp_status_remaining($st) : (int)($st['remaining'] ?? 0);
        $need_each = function_exists('aissp_model_cost') ? aissp_model_cost($model_sel) : 1;
        $need_all  = $need_each * count($ids);

        if ($remaining <= 0) {
            return add_query_arg(['aissp_bsuccess'=>0,'aissp_bfailed'=>count($ids),'aissp_bzerotok'=>1], $redirect);
        }
        if ($remaining < $need_all) {
            return add_query_arg(['aissp_bsuccess'=>0,'aissp_bfailed'=>count($ids),'aissp_binsufficient'=>1], $redirect);
        }

        // Create job and return to the page
        $job_id = $this->create_bulk_job('term', $ids, $site_token, $model_sel, $opts);
        return add_query_arg(['aissp_job'=>$job_id], $redirect);
    }

    /* ===== Bulk Job storage ===== */
    private function job_key($id){ return 'aissp_job_'.$id; }
    private function get_job($id){ return get_transient($this->job_key($id)); }
    private function save_job($id,$data){ set_transient($this->job_key($id), $data, HOUR_IN_SECONDS); }
    private function delete_job($id){ delete_transient($this->job_key($id)); }

    private function create_bulk_job(string $ctx, array $ids, string $site_token, string $model, array $opts){
        $job_id = wp_generate_password(12, false, false);
        $this->save_job($job_id, [
            'id'         => $job_id,
            'ctx'        => $ctx,                 // "post" or "term"
            'ids'        => array_values($ids),
            'i'          => 0,                    // current index
            'total'      => count($ids),
            's'          => 0,                    // success count
            'f'          => 0,                    // fail count
            'flags'      => ['zerotok'=>false,'insuff'=>false],
            'site_token' => $site_token,
            'model'      => $model,
            'opts'       => $opts,
        ]);
        return $job_id;
    }

    /* ===== AJAX: process one item per tick ===== */
    public function ajax_bulk_tick(){
        check_ajax_referer('aissp_ajax_nonce','nonce');
        if ( ! current_user_can('edit_posts') ) {
            wp_send_json_error(['msg'=>'Unauthorized']);
        }

        $jid = sanitize_text_field($_POST['job'] ?? '');
        if ( ! $jid ) wp_send_json_error(['msg'=>'Missing job id']);

        $job = $this->get_job($jid);
        if ( ! is_array($job) ) wp_send_json_error(['msg'=>'Job not found']);

        // Finished?
        if ( $job['i'] >= $job['total'] ) {
            $args = [
                'aissp_bsuccess' => (int)$job['s'],
                'aissp_bfailed'  => (int)$job['f'],
            ];
            if ( !empty($job['flags']['zerotok']) ) $args['aissp_bzerotok']=1;
            if ( !empty($job['flags']['insuff']) )  $args['aissp_binsufficient']=1;

            $this->delete_job($jid);

            // If referer exists, remove ?aissp_job and append result params
            $base = wp_get_referer();
            if ( ! $base ) $base = admin_url('edit.php');
            $redir = add_query_arg($args, remove_query_arg('aissp_job', $base));

            wp_send_json_success(['done'=>true,'redirect'=>$redir]);
        }

        // Next ID
        $id   = (int)$job['ids'][ $job['i'] ];
        $opts = $job['opts'];
        $site = $job['site_token'];
        $mdl  = $job['model'];

        // Single-item payload
        $item = ($job['ctx']==='post')
            ? aissp_prepare_snippet_item_for_post($id, $opts)
            : aissp_prepare_snippet_item_for_term($id, $opts);

        $ok=false; $err=''; $zerotok=false; $insuff=false;

        try{
            // Even for one item, call the bulk helper; it will fall back to single if needed
            $res  = aissp_call_rankpilot_api_bulk($site, [$item], $mdl, $opts);
            $list = $res['items'] ?? [];

            // Response can be a list or id=>row map
            if ( array_keys($list) === range(0, count($list)-1) ) {
                $row = $list[0] ?? [];
            } else {
                $row = $list[$id] ?? [];
            }

            if ( ! empty($row['error']) ) {
                $txt = (string)$row['error'];
                if ( stripos($txt,'No tokens left') !== false )  $zerotok = true;
                if ( stripos($txt,'Not enough tokens') !== false ) $insuff = true;
                $err = $txt;
            } else {
                $ok = ($job['ctx']==='post')
                    ? aissp_apply_snippet_result_for_post($id, $row, $opts)
                    : aissp_apply_snippet_result_for_term($id, $row, $opts);
            }
        } catch (\Exception $ex){
            $err = $ex->getMessage();
            if ( stripos($err,'No tokens left') !== false )  $zerotok = true;
            if ( stripos($err,'Not enough tokens') !== false ) $insuff = true;
        }

        // Update counters
        $job['i']++;
        if ($ok) $job['s']++; else $job['f']++;
        if ($zerotok) $job['flags']['zerotok'] = true;
        if ($insuff)  $job['flags']['insuff']  = true;

        $this->save_job($jid,$job);

        wp_send_json_success([
            'done'   => false,
            'i'      => (int)$job['i'],
            'total'  => (int)$job['total'],
            's'      => (int)$job['s'],
            'f'      => (int)$job['f'],
            'flags'  => $job['flags'],
            'lastId' => $id,
            'error'  => $ok ? '' : $err,
        ]);
    }

    /* ===== AJAX: cancel ===== */
    public function ajax_bulk_cancel(){
        check_ajax_referer('aissp_ajax_nonce','nonce');
        if ( ! current_user_can('edit_posts') ) {
            wp_send_json_error(['msg'=>'Unauthorized']);
        }
        $jid = sanitize_text_field($_POST['job'] ?? '');
        if ($jid) $this->delete_job($jid);
        wp_send_json_success();
    }

    // Head meta
    public function inject_head_meta(){
        if(is_singular()){
            global $post;
            if(!$post) return;
            $opts = get_option(AISSP_SETTINGS_SLUG);
            $noindex=false;
            $pt = get_post_type($post->ID);
            if(isset($opts["index_{$pt}"]) && $opts["index_{$pt}"]==='no'){
                $noindex=true;
            }
            if($noindex){
                echo '<meta name="robots" content="noindex, follow"/>'."\n";
            }
            $fw=get_post_meta($post->ID,'_aissp_focus_keyword',true);
            $ti=get_post_meta($post->ID,'_aissp_seo_title',true);
            $de=get_post_meta($post->ID,'_aissp_seo_description',true);

            if ( $ti && ! current_theme_supports('title-tag') ) {
                echo '<title>'.esc_html($ti).'</title>'."\n";
            }
            if($de) echo '<meta name="description" content="'.esc_attr($de).'"/>'."\n";
            if($fw) echo '<meta name="keywords" content="'.esc_attr($fw).'"/>'."\n";

        } elseif(is_category()||is_tag()||is_tax()){
            $term=get_queried_object();
            if($term && isset($term->taxonomy)){
                $opts=get_option(AISSP_SETTINGS_SLUG);
                $tax=$term->taxonomy;
                $noindex=false;
                if(isset($opts["index_{$tax}"]) && $opts["index_{$tax}"]==='no'){
                    $noindex=true;
                }
                if($noindex){
                    echo '<meta name="robots" content="noindex, follow"/>'."\n";
                }
            }
        }
    }

    // Bulk for posts
    public function init_posttype_bulk_and_filter(){
        $pts=get_post_types(['public'=>true],'names');
        foreach($pts as $pt){
            add_filter("bulk_actions-edit-{$pt}",[$this,'bulk_actions_posts']);
            add_filter("handle_bulk_actions-edit-{$pt}",[$this,'handle_bulk_posts'],10,3);
        }
        add_action('restrict_manage_posts',[$this,'render_filter_posts']);
        add_filter('parse_query',[$this,'apply_filter_posts']);
    }
    public function bulk_actions_posts($acts){
        $acts['aissp_generate_seo'] = 'Generate with AI Snippet SEO Pro';
        return $acts;
    }
    public function handle_bulk_posts($redirect, $action, $post_ids){
        if ($action !== 'aissp_generate_seo') return $redirect;

        $opts = get_option(AISSP_SETTINGS_SLUG);
        if (empty($opts['site_token'])){
            return add_query_arg('aissp_berror','notoken',$redirect);
        }
        $site_token = $opts['site_token'];
        $model_sel  = strtolower($opts['model_choice'] ?? 'gpt-4-turbo');

        // Filter only posts the user can edit
        $ids = [];
        foreach ((array)$post_ids as $pid) {
            $pid = (int)$pid;
            if ($pid > 0 && current_user_can('edit_post', $pid)) {
                $ids[] = $pid;
            }
        }
        if (empty($ids)) return $redirect;

        // Token status + model cost * item count
        $st = aissp_check_token_status($site_token);
        if ( isset($st['error']) ) {
            return add_query_arg('aissp_berror','tokencheck',$redirect);
        }
        $remaining = function_exists('aissp_status_remaining') ? aissp_status_remaining($st) : (int)($st['remaining'] ?? 0);
        $need_each = function_exists('aissp_model_cost') ? aissp_model_cost($model_sel) : 1;
        $need_all  = $need_each * count($ids);

        if ($remaining <= 0) {
            return add_query_arg(['aissp_bsuccess'=>0,'aissp_bfailed'=>count($ids),'aissp_bzerotok'=>1], $redirect);
        }
        if ($remaining < $need_all) {
            // Single call will be made; if insufficient, abort with notice
            return add_query_arg(['aissp_bsuccess'=>0,'aissp_bfailed'=>count($ids),'aissp_binsufficient'=>1], $redirect);
        }

        // Create job and return to the page
        $job_id = $this->create_bulk_job('post', $ids, $site_token, $model_sel, $opts);
        return add_query_arg(['aissp_job'=>$job_id], $redirect);
    }

    // Filter UI
    public function render_filter_posts(){
        global $pagenow;
        if($pagenow!=='edit.php') return;
        $v=isset($_GET['aissp_filter'])?sanitize_text_field($_GET['aissp_filter']):'';
        echo '<select name="aissp_filter" style="margin-right:8px;">';
        echo '<option value="">AI Snippet SEO Pro</option>';
        echo '<option value="haskw" '.selected($v,'haskw',false).'>Has Focus Keyword</option>';
        echo '<option value="nokw" '.selected($v,'nokw',false).'>Missing Focus Keyword</option>';
        echo '<option value="poor" '.selected($v,'poor',false).'>Score: Poor</option>';
        echo '<option value="fair" '.selected($v,'fair',false).'>Score: Fair</option>';
        echo '<option value="excel" '.selected($v,'excel',false).'>Score: Excellent</option>';
        echo '</select>';
    }
    public function apply_filter_posts($query){
        global $pagenow;
        if($pagenow!=='edit.php' || !is_admin() || !$query->is_main_query()) return;

        if(isset($_GET['aissp_filter'])){
            $val=sanitize_text_field($_GET['aissp_filter']);
            $mq=[];
            if($val==='haskw'){
                $mq[]=['key'=>'_aissp_focus_keyword','compare'=>'EXISTS'];
            }elseif($val==='nokw'){
                $mq[]=['key'=>'_aissp_focus_keyword','compare'=>'NOT EXISTS'];
            }elseif($val==='poor'){
                $mq[]=['key'=>'_aissp_score','value'=>[0,66],'compare'=>'BETWEEN','type'=>'numeric'];
            }elseif($val==='fair'){
                $mq[]=['key'=>'_aissp_score','value'=>[67,99],'compare'=>'BETWEEN','type'=>'numeric'];
            }elseif($val==='excel'){
                $mq[]=['key'=>'_aissp_score','value'=>100,'compare'=>'=','type'=>'numeric'];
            }
            if(!empty($mq)){
                $query->set('meta_query',$mq);
            }
        }
    }

    // Listing columns: Score
    public function listing_columns_setup(){
        $pts=get_post_types(['public'=>true],'names');
        foreach($pts as $pt){
            add_filter("manage_{$pt}_posts_columns",[$this,'add_snippet_score_column']);
            add_action("manage_{$pt}_posts_custom_column",[$this,'render_snippet_score_column'],10,2);
        }
    }
    public function add_snippet_score_column($cols){
        if(isset($cols['name'])){
            $new=[];
            foreach($cols as $k=>$v){
                $new[$k]=$v;
                if($k==='name'){
                    $new['aissp_scorecol']='SEO Snippet Score';
                }
            }
            return $new;
        } else {
            $new=[];
            foreach($cols as $k=>$v){
                $new[$k]=$v;
                if($k==='title'){
                    $new['aissp_scorecol']='SEO Snippet Score';
                }
            }
            return $new;
        }
    }
    public function render_snippet_score_column($col,$post_id){
        if($col==='aissp_scorecol'){
            $val=get_post_meta($post_id,'_aissp_score',true);
            if($val==='') $val=0;
            $color='#dc3232';
            if($val>=100){
                $val=100;$color='#0091e0';
            }elseif($val>=67){
                $color='#ffab00';
            }
            echo '<span style="background:'.esc_attr($color).';border-radius:3px;padding:6px 9px;color:#fff;font-weight:600;font-size:12px;">'
                .(int)$val.' / 100</span>';
        }
    }

    // Notices (bulk success/fail)
    public function show_bulk_notices(){

        // If a job is running, show the live progress box
        if ( ! empty($_GET['aissp_job']) ) {
            $jid = sanitize_text_field($_GET['aissp_job']);
            echo '<div id="aissp-bulk-box" class="notice notice-info is-dismissible" style="position:relative;">'
                . '<p><strong>AI Snippet SEO Pro:</strong> Running bulk job… '
                . '<span id="aissp-bulk-text">starting</span></p>'
                . '<p style="margin-top:6px;"><button type="button" class="button" id="aissp-bulk-cancel" data-job="'.esc_attr($jid).'">Cancel</button></p>'
                . '</div>';
        }
        
        if (isset($_GET['aissp_berror']) && $_GET['aissp_berror'] === 'notoken') {
            echo '<div class="notice notice-error is-dismissible"><p><strong>AI Snippet SEO Pro:</strong> No Site Key configured.</p></div>';
        }

        /* Special CTA — global zero tokens */
        if (!empty($_GET['aissp_bzerotok'])) {
            echo '<div class="notice notice-error is-dismissible"><p><strong>AI Snippet SEO Pro:</strong> Failed – no tokens left. '
                . '<a href="https://rankpilotai.com/tokens/?plugin=ai-snippet-seo-pro" target="_blank" rel="noopener">Buy Tokens</a> to continue.</p></div>';
        }

        /* Insufficient balance for the selected model */
        if (!empty($_GET['aissp_binsufficient'])) {
            echo '<div class="notice notice-warning is-dismissible"><p><strong>AI Snippet SEO Pro:</strong> Not enough tokens for the selected model. '
                . 'Please choose a cheaper model (e.g. GPT-4 Turbo) or buy more tokens.</p></div>';
        }

        if (isset($_GET['aissp_bsuccess']) || isset($_GET['aissp_bfailed'])) {
            $s = (int)($_GET['aissp_bsuccess'] ?? 0);
            $f = (int)($_GET['aissp_bfailed'] ?? 0);

            if ($s > 0) {
                echo '<div class="notice notice-success is-dismissible"><p><strong>AI Snippet SEO Pro:</strong> Generated snippets for ' . intval($s) . ' item(s).</p></div>';
            }

            /* When zero-token flag is set, suppress the general "Failed" notice */
            if ($f > 0 && empty($_GET['aissp_bzerotok'])) {
                echo '<div class="notice notice-error is-dismissible"><p><strong>AI Snippet SEO Pro:</strong> Failed for ' . intval($f) . ' item(s).</p></div>';
            }
        }
    }

    /**
     * (Kept for backward reference; no longer hooked)
     * Paint the PRO menu icon via CSS mask; now done via wp_add_inline_style in enqueue_assets().
     */
    public function menu_icon_color_fix(){
        $path = AISSP_PATH . 'admin/assets/img/ai-snippet-seo-pro.svg';
        $url  = AISSP_URL  . 'admin/assets/img/ai-snippet-seo-pro.svg';
        $ver  = file_exists($path) ? filemtime($path) : AISSP_VERSION;
        $icon = esc_url( $url . '?v=' . $ver );
        // No echo here; kept only as a reference to original implementation.
    }

}

// Singleton
function aissp(){
    return AI_Snippet_SEO_Pro::get_instance();
}
aissp();
