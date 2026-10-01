<?php
/**
 * Plugin Name: WandTech OPcache
 * Description: Adds a live OPcache status node with memory badge and in-place AJAX flush to the WordPress Admin Bar.
 * Version:     1.0.0
 * Author:      Hamxa
 * Text Domain: wandtech-opcache
 */

namespace WandTech\OPcache;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const VERSION             = '1.0.0';

/* --- AJAX & Action Constants --- */
const AJAX_STATUS_ACTION  = 'wandtech_opcache_status';
const AJAX_FLUSH_ACTION   = 'wandtech_opcache_flush';

/* --- Security Nonce Constants --- */
const STATUS_NONCE_ACTION = 'wandtech_opcache_status_nonce';
const FLUSH_NONCE_ACTION  = 'wandtech_opcache_flush_nonce';

/* --- Admin Bar Node IDs --- */
const NODE_ID_PARENT      = 'wandtech-opcache-monitor';
const NODE_ID_FLUSH       = 'wandtech-opcache-flush-action';
const NODE_ID_STATS       = 'wandtech-opcache-combined-stats';

/* --- Memory Badge Thresholds (Percentage) --- */
const WARNING_THRESHOLD   = 80;
const CRITICAL_THRESHOLD  = 95;

/* --- Caching Parameters --- */
const STATUS_CACHE_TTL    = 5;
const CACHE_GROUP         = 'wandtech-opcache';

/**
 * Lazy text-domain loader.
 *
 * @return void
 */
function maybe_load_textdomain(): void {
	static $loaded = false;
	if ( $loaded ) {
		return;
	}
	$loaded = true;
	load_muplugin_textdomain( 'wandtech-opcache' );
}

/**
 * Fetch raw summary from OPcache and cache it in Object Cache.
 *
 * @return array<string, mixed>|null Associative array of OPcache statistics, or null if unavailable.
 */
function get_cached_status_summary(): ?array {
	$cached = wp_cache_get( 'summary', CACHE_GROUP );
	if ( false !== $cached ) {
		return $cached;
	}

	$summary = null;

	if ( function_exists( 'opcache_get_status' ) ) {
		$status = opcache_get_status( false );

		if ( $status && ! empty( $status['opcache_enabled'] ) ) {
			$memory = $status['memory_usage'] ?? [];
			$stats  = $status['opcache_statistics'] ?? [];

			$used_memory  = isset( $memory['used_memory'] ) ? round( $memory['used_memory'] / 1024 / 1024, 1 ) : 0;
			$cached_files = $stats['num_cached_scripts'] ?? 0;

			$total_memory_raw = ini_get( 'opcache.memory_consumption' );
			$total_memory     = $total_memory_raw ? (int) $total_memory_raw : 0;
			$usage_percent    = $total_memory > 0 ? round( ( $used_memory / $total_memory ) * 100, 1 ) : null;

			$summary = [
				'used_memory'   => $used_memory,
				'cached_files'  => (int) $cached_files,
				'total_memory'  => $total_memory,
				'usage_percent' => $usage_percent,
			];
		}
	}

	wp_cache_set( 'summary', $summary, CACHE_GROUP, STATUS_CACHE_TTL );

	return $summary;
}

/**
 * Formats the summary payload for client-side rendering.
 * Uses plain __() because strings are assigned via textContent in JS.
 *
 * @param array<string, mixed> $summary Raw summary array from get_cached_status_summary().
 * @return array<string, mixed> Formatted summary payload including localized strings.
 */
function format_status_payload( array $summary ): array {
	maybe_load_textdomain();

	$summary['warning_threshold']  = WARNING_THRESHOLD;
	$summary['critical_threshold'] = CRITICAL_THRESHOLD;
	$summary['total_memory_text']  = $summary['total_memory'] > 0
		? sprintf( '%s MB', $summary['total_memory'] )
		: __( 'unknown', 'wandtech-opcache' );
	$summary['usage_percent_text'] = null !== $summary['usage_percent']
		? $summary['usage_percent'] . '%'
		: __( 'n/a', 'wandtech-opcache' );
	$summary['stats_text'] = sprintf(
		/* translators: 1: cached files count, 2: used memory in MB, 3: total memory, 4: usage percentage */
		__( '%1$s Cached Files / %2$s of %3$s (%4$s)', 'wandtech-opcache' ),
		number_format_i18n( $summary['cached_files'] ),
		$summary['used_memory'],
		$summary['total_memory_text'],
		$summary['usage_percent_text']
	);

	return $summary;
}

/**
 * AJAX endpoint: Returns the current OPcache status as JSON.
 *
 * @return void
 */
add_action( 'wp_ajax_' . AJAX_STATUS_ACTION, __NAMESPACE__ . '\\handle_status_request' );

function handle_status_request(): void {
	maybe_load_textdomain();

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( [ 'message' => __( 'Forbidden.', 'wandtech-opcache' ) ], 403 );
	}

	if ( ! check_ajax_referer( STATUS_NONCE_ACTION, 'nonce', false ) ) {
		wp_send_json_error( [ 'message' => __( 'Invalid nonce.', 'wandtech-opcache' ) ], 403 );
	}

	$summary = get_cached_status_summary();

	if ( null === $summary ) {
		wp_send_json_error( [ 'message' => __( 'OPcache is not available or not enabled.', 'wandtech-opcache' ) ] );
	}

	wp_send_json_success( format_status_payload( $summary ) );
}

/**
 * AJAX endpoint: Flushes OPcache memory and returns fresh status metrics.
 *
 * @return void
 */
add_action( 'wp_ajax_' . AJAX_FLUSH_ACTION, __NAMESPACE__ . '\\handle_ajax_flush_request' );

function handle_ajax_flush_request(): void {
	maybe_load_textdomain();

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( [ 'message' => __( 'Forbidden.', 'wandtech-opcache' ) ], 403 );
	}

	if ( ! check_ajax_referer( FLUSH_NONCE_ACTION, 'nonce', false ) ) {
		wp_send_json_error( [ 'message' => __( 'Invalid nonce.', 'wandtech-opcache' ) ], 403 );
	}

	$reset_success = function_exists( 'opcache_reset' ) && opcache_reset();
	wp_cache_delete( 'summary', CACHE_GROUP );

	// Handle Zend Engine race condition: if opcache_reset() returns false, verify if
	// a restart is already scheduled or in progress from a concurrent request.
	if ( ! $reset_success ) {
		$raw_status    = function_exists( 'opcache_get_status' ) ? opcache_get_status( false ) : false;
		$is_restarting = is_array( $raw_status )
			&& ( ! empty( $raw_status['restart_pending'] ) || ! empty( $raw_status['restart_in_progress'] ) );

		if ( ! $is_restarting ) {
			wp_send_json_error( [ 'message' => __( 'Failed to reset OPcache.', 'wandtech-opcache' ) ] );
		}
	}

	$fresh_summary = get_cached_status_summary();
	$payload       = $fresh_summary ? format_status_payload( $fresh_summary ) : null;

	wp_send_json_success( $payload );
}

/**
 * Renders the OPcache monitor nodes in the WordPress Admin Bar.
 *
 * @param \WP_Admin_Bar $wp_admin_bar The WP_Admin_Bar instance.
 * @return void
 */
add_action( 'admin_bar_menu', __NAMESPACE__ . '\\render_admin_bar_node', 99 );

function render_admin_bar_node( \WP_Admin_Bar $wp_admin_bar ): void {
	if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) || ! function_exists( 'opcache_get_status' ) ) {
		return;
	}

	maybe_load_textdomain();

	$wp_admin_bar->add_node( [
		'id'    => NODE_ID_PARENT,
		'title' => 'OPcache <span class="badge wandtech-opcache-badge" id="wandtech-opcache-badge">…</span>',
		'href'  => '#',
		'meta'  => [ 'title' => esc_attr__( 'OPcache Monitor', 'wandtech-opcache' ) ],
	] );

	// Action-specific tooltip on the flush node avoids persistent clutter on the parent node.
	$wp_admin_bar->add_node( [
		'id'     => NODE_ID_FLUSH,
		'parent' => NODE_ID_PARENT,
		'title'  => '<span id="wandtech-opcache-flush-label">' . esc_html__( 'Flush OPcache', 'wandtech-opcache' ) . '</span>',
		'href'   => '#',
		'meta'   => [ 'title' => esc_attr__( 'Resets in-memory OPcache only (does not clear disk file-cache or independent nodes).', 'wandtech-opcache' ) ],
	] );

	$wp_admin_bar->add_node( [
		'id'     => NODE_ID_STATS,
		'parent' => NODE_ID_PARENT,
		'title'  => '<span id="wandtech-opcache-stats">' . esc_html__( 'Loading…', 'wandtech-opcache' ) . '</span>',
		'href'   => '#',
	] );
}

/**
 * Injects inline CSS styles for the Admin Bar badge and status states.
 *
 * @return void
 */
add_action( 'admin_head', __NAMESPACE__ . '\\inject_admin_bar_assets' );
add_action( 'wp_head', __NAMESPACE__ . '\\inject_admin_bar_assets' );

function inject_admin_bar_assets(): void {
	if ( ! is_admin_bar_showing() || ! current_user_can( 'manage_options' ) || ! function_exists( 'opcache_get_status' ) ) {
		return;
	}

	echo '<style id="wandtech-opcache-admin-bar-css">'
		. '#wp-admin-bar-' . esc_attr( NODE_ID_PARENT ) . ' .wandtech-opcache-badge{background:#2271b1;color:#ffffff;border-radius:10px;padding:1px 6px;font-size:10px;margin-left:4px;}'
		. '#wp-admin-bar-' . esc_attr( NODE_ID_PARENT ) . ' .wandtech-opcache-badge--warning{background:#dba617;}'
		. '#wp-admin-bar-' . esc_attr( NODE_ID_PARENT ) . ' .wandtech-opcache-badge--critical{background:#d63638;}'
		. '#wandtech-opcache-flush-label.is-flushing{opacity:0.6;pointer-events:none;}'
		. '#wandtech-opcache-flush-label.is-success{color:#00a32a!important;font-weight:600;}'
		. '</style>';
}

/**
 * Injects inline JavaScript to handle async metrics polling and in-place AJAX flushing.
 *
 * @return void
 */
add_action( 'admin_footer', __NAMESPACE__ . '\\inject_admin_bar_script' );
add_action( 'wp_footer', __NAMESPACE__ . '\\inject_admin_bar_script' );

function inject_admin_bar_script(): void {
	if ( ! is_admin_bar_showing() || ! current_user_can( 'manage_options' ) || ! function_exists( 'opcache_get_status' ) ) {
		return;
	}

	maybe_load_textdomain();

	$config = [
		'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
		'statusAction' => AJAX_STATUS_ACTION,
		'flushAction'  => AJAX_FLUSH_ACTION,
		'statusNonce'  => wp_create_nonce( STATUS_NONCE_ACTION ),
		'flushNonce'   => wp_create_nonce( FLUSH_NONCE_ACTION ),
		'cacheTtl'     => STATUS_CACHE_TTL,
		'flushNodeId'  => 'wp-admin-bar-' . NODE_ID_FLUSH,
		'i18n'         => [
			// Unescaped: assigned via el.textContent in JS, never innerHTML.
			'defaultLabel'  => __( 'Flush OPcache', 'wandtech-opcache' ),
			'flushingLabel' => __( 'Flushing…', 'wandtech-opcache' ),
			'successLabel'  => __( 'OPcache Flushed!', 'wandtech-opcache' ),
			'errorLabel'    => __( 'Failed to flush!', 'wandtech-opcache' ),
		],
	];

	printf(
		'<script id="wandtech-opcache-admin-bar-js">(function(cfg){'
			. 'var STORAGE_KEY="wandtechOpcacheStatus";'
			. 'var TTL_MS=cfg.cacheTtl*1000;'
			. 'var badge=document.getElementById("wandtech-opcache-badge");'
			. 'var stats=document.getElementById("wandtech-opcache-stats");'
			. 'var flushLabel=document.getElementById("wandtech-opcache-flush-label");'
			. 'var flushNode=document.getElementById(cfg.flushNodeId);'
			. 'var isFlushing=false;'
			. 'function apply(data){'
			. '  if(!badge||!stats||!data||typeof data.used_memory==="undefined"){return;}'
			. '  badge.textContent=data.used_memory+" MB";'
			. '  badge.className="badge wandtech-opcache-badge";'
			. '  if(data.usage_percent!==null){'
			. '    if(data.usage_percent>=data.critical_threshold){badge.className+=" wandtech-opcache-badge--critical";}'
			. '    else if(data.usage_percent>=data.warning_threshold){badge.className+=" wandtech-opcache-badge--warning";}'
			. '  }'
			. '  stats.textContent=data.stats_text;'
			. '}'
			. 'function fail(){'
			. '  if(stats){stats.textContent="—";}'
			. '}'
			// 1. In-place Flush Handler.
			. 'if(flushNode&&flushLabel){'
			. '  flushNode.addEventListener("click",function(e){'
			. '    e.preventDefault();'
			. '    if(isFlushing)return;'
			. '    isFlushing=true;'
			. '    flushLabel.className="is-flushing";'
			. '    flushLabel.textContent=cfg.i18n.flushingLabel;'
			. '    var body=new URLSearchParams();'
			. '    body.set("action",cfg.flushAction);'
			. '    body.set("nonce",cfg.flushNonce);'
			. '    fetch(cfg.ajaxUrl,{method:"POST",credentials:"same-origin",body:body})'
			. '    .then(function(r){return r.json();})'
			. '    .then(function(res){'
			. '      if(res&&res.success){'
			. '        flushLabel.className="is-success";'
			. '        flushLabel.textContent=cfg.i18n.successLabel;'
			. '        if(res.data){apply(res.data);try{sessionStorage.setItem(STORAGE_KEY,JSON.stringify({data:res.data,ts:Date.now()}));}catch(e){}}'
			. '      }else{'
			. '        flushLabel.className="";'
			. '        flushLabel.textContent=cfg.i18n.errorLabel;'
			. '      }'
			. '      setTimeout(function(){flushLabel.className="";flushLabel.textContent=cfg.i18n.defaultLabel;isFlushing=false;},2500);'
			. '    })'
			. '    .catch(function(){'
			. '      flushLabel.className="";flushLabel.textContent=cfg.i18n.errorLabel;'
			. '      setTimeout(function(){flushLabel.textContent=cfg.i18n.defaultLabel;isFlushing=false;},2500);'
			. '    });'
			. '  });'
			. '}'
			// 2. Initial Fetch with Client Cache.
			. 'try{'
			. '  var cachedRaw=sessionStorage.getItem(STORAGE_KEY);'
			. '  if(cachedRaw){'
			. '    var cached=JSON.parse(cachedRaw);'
			. '    if(cached&&cached.ts&&(Date.now()-cached.ts)<TTL_MS){apply(cached.data);return;}'
			. '  }'
			. '}catch(e){}'
			. 'var body=new URLSearchParams();'
			. 'body.set("action",cfg.statusAction);'
			. 'body.set("nonce",cfg.statusNonce);'
			. 'fetch(cfg.ajaxUrl,{method:"POST",credentials:"same-origin",body:body})'
			. '.then(function(r){return r.json();})'
			. '.then(function(json){'
			. '  if(json&&json.success){apply(json.data);try{sessionStorage.setItem(STORAGE_KEY,JSON.stringify({data:json.data,ts:Date.now()}));}catch(e){}}'
			. '  else{fail();}'
			. '})'
			. '.catch(fail);'
			. '})(%s);</script>',
		wp_json_encode( $config )
	);
}
