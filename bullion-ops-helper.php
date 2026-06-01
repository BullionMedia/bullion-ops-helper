<?php
/**
 * Plugin Name: Bullion Ops Helper
 * Plugin URI: https://github.com/BullionMedia/bullion-ops-helper
 * Description: REST endpoints for programmatic Rank Math redirects, Elementor regenerate, cache purges, and a branded restyle of the asx_announcement CPT archive. Used by Bullion Media ops tooling.
 * Version: 0.5.1
 * Author: Bullion Media
 * Author URI: https://bullionmedia.com.au
 * License: MIT
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'BULLION_OPS_NS', 'bullion/v1' );
define( 'BULLION_OPS_VERSION', '0.5.1' );

// --- Auto-update (Plugin Update Checker, GitHub source) --------------------
//
// Watches https://github.com/BullionMedia/bullion-ops-helper for new releases
// (tag name = plugin version, e.g. v0.4.0). On the next twice-daily WP cron,
// new releases show up under Dashboard > Updates and get one-click upgraded.
require_once __DIR__ . '/vendor/plugin-update-checker/plugin-update-checker.php';

\YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
	'https://github.com/BullionMedia/bullion-ops-helper/',
	__FILE__,
	'bullion-ops-helper'
)->getVcsApi()->enableReleaseAssets();

// --- REST routes -----------------------------------------------------------

add_action( 'rest_api_init', 'bullion_ops_register_routes' );

function bullion_ops_register_routes() {
	register_rest_route( BULLION_OPS_NS, '/ping', [
		'methods'             => 'GET',
		'callback'            => 'bullion_ops_ping',
		'permission_callback' => 'bullion_ops_permission',
	] );

	register_rest_route( BULLION_OPS_NS, '/redirect', [
		[
			'methods'             => 'GET',
			'callback'            => 'bullion_ops_list_redirects',
			'permission_callback' => 'bullion_ops_permission',
		],
		[
			'methods'             => 'POST',
			'callback'            => 'bullion_ops_upsert_redirect',
			'permission_callback' => 'bullion_ops_permission',
		],
	] );

	register_rest_route( BULLION_OPS_NS, '/redirect/(?P<id>\d+)', [
		'methods'             => 'DELETE',
		'callback'            => 'bullion_ops_delete_redirect',
		'permission_callback' => 'bullion_ops_permission',
	] );

	register_rest_route( BULLION_OPS_NS, '/cache/purge', [
		'methods'             => 'POST',
		'callback'            => 'bullion_ops_purge_cache',
		'permission_callback' => 'bullion_ops_permission',
	] );

	register_rest_route( BULLION_OPS_NS, '/elementor/regenerate/(?P<id>\d+)', [
		'methods'             => 'POST',
		'callback'            => 'bullion_ops_elementor_regenerate',
		'permission_callback' => 'bullion_ops_permission',
	] );
}

function bullion_ops_permission() {
	return current_user_can( 'manage_options' );
}

// --- ASX Announcement archive — branded horizontal-row layout --------------
//
// Restyles /asx-announcements/ (the asx_announcement CPT archive) into a
// QMines-branded horizontal-row layout: green uppercase date eyebrow, dark
// teal title, body excerpt, hairline divider per row. No feature images.
//
// Active on every WP site that has the asx_announcement CPT registered. If
// that CPT isn't present, the hooks below are no-ops.
//
// Two hooks:
//  1. the_excerpt filter — prepends the post date as <p class="qm-asx-meta">
//     above each archive excerpt.
//  2. wp_head action — injects scoped CSS only when on the archive page.

add_filter( 'the_excerpt', 'bullion_ops_asx_archive_inject_date', 5 );

function bullion_ops_asx_archive_inject_date( $excerpt ) {
	if ( ! is_post_type_archive( 'asx_announcement' ) || ! in_the_loop() ) {
		return $excerpt;
	}
	$date = get_the_date( 'j F Y' );
	return '<p class="qm-asx-meta"><time>' . esc_html( $date ) . '</time></p>' . $excerpt;
}

add_action( 'wp_head', 'bullion_ops_asx_archive_inject_css', 100 );

function bullion_ops_asx_archive_inject_css() {
	if ( ! is_post_type_archive( 'asx_announcement' ) ) {
		return;
	}
	?>
<style id="bullion-ops-asx-archive-css">
body.post-type-archive-asx_announcement .page-header {
  max-width:1100px;margin:0 auto;padding:60px 20px 20px;text-align:left;
}
body.post-type-archive-asx_announcement .page-header .entry-title {
  font-family:'Muli',sans-serif;font-size:32px;font-weight:600;color:#142934;
}
body.post-type-archive-asx_announcement .page-header .entry-title span {
  font-weight:400;display:inline;
}
body.post-type-archive-asx_announcement .page-content {
  max-width:1100px;margin:0 auto;padding:20px 20px 80px;
  font-family:'Muli',sans-serif;
}
body.post-type-archive-asx_announcement article.post {
  display:grid;grid-template-columns:40% 1fr;grid-column-gap:40px;
  align-items:start;
  background:#fff;border-bottom:1px solid #e8eded;
  padding:24px 0;transition:background .2s;
}
body.post-type-archive-asx_announcement article.post:first-of-type {
  border-top:1px solid #e8eded;
}
body.post-type-archive-asx_announcement article.post:hover {background:#f8fbf9}
body.post-type-archive-asx_announcement article.post .qm-asx-meta {
  grid-column:1;grid-row:1;
  font-size:12px;color:#4CA565;font-weight:600;text-transform:uppercase;
  letter-spacing:1.6px;margin:0 0 8px;line-height:1.2;
}
body.post-type-archive-asx_announcement article.post .entry-title {
  grid-column:1;grid-row:2;
  font-family:'Muli',sans-serif;font-size:20px;font-weight:600;
  color:#142934;line-height:1.3;margin:0;
}
body.post-type-archive-asx_announcement article.post .entry-title a {
  color:#142934;text-decoration:none;
}
body.post-type-archive-asx_announcement article.post .entry-title a:hover {
  color:#4CA565;
}
body.post-type-archive-asx_announcement article.post > p:not(.qm-asx-meta) {
  grid-column:2;grid-row:1 / span 2;
  align-self:center;
  margin:0;color:#2a3d44;font-size:15px;line-height:1.6;
}
@media (max-width:768px) {
  body.post-type-archive-asx_announcement article.post {
    display:block;
  }
  body.post-type-archive-asx_announcement article.post .entry-title {margin:0 0 10px 0}
  body.post-type-archive-asx_announcement article.post > p:not(.qm-asx-meta) {margin-top:0}
}
@media (max-width:600px) {
  body.post-type-archive-asx_announcement .page-header {padding:40px 16px 16px}
  body.post-type-archive-asx_announcement .page-header .entry-title {font-size:26px}
  body.post-type-archive-asx_announcement .page-content {padding:16px 16px 60px}
  body.post-type-archive-asx_announcement article.post .entry-title {font-size:18px}
}
</style>
	<?php
}

function bullion_ops_ping() {
	global $wp_version;
	return rest_ensure_response( [
		'ok'           => true,
		'plugin'       => 'bullion-ops-helper',
		'version'      => BULLION_OPS_VERSION,
		'wp_version'   => $wp_version,
		'rank_math'    => defined( 'RANK_MATH_VERSION' ) ? RANK_MATH_VERSION : null,
		'wp_rocket'    => defined( 'WP_ROCKET_VERSION' ) ? WP_ROCKET_VERSION : null,
		'cloudflare'   => class_exists( '\\CF\\WordPress\\Hooks' ),
		'elementor'    => defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : null,
		'home_url'     => home_url(),
	] );
}

// --- Rank Math redirects ---------------------------------------------------

function bullion_ops_redirect_table() {
	global $wpdb;
	return $wpdb->prefix . 'rank_math_redirections';
}

function bullion_ops_list_redirects( WP_REST_Request $req ) {
	global $wpdb;
	$table  = bullion_ops_redirect_table();
	$source = $req->get_param( 'source' );

	if ( $source ) {
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, sources, url_to, header_code, status, hits, created, updated
			 FROM {$table} WHERE sources LIKE %s ORDER BY id DESC",
			'%' . $wpdb->esc_like( $source ) . '%'
		) );
	} else {
		$rows = $wpdb->get_results(
			"SELECT id, sources, url_to, header_code, status, hits, created, updated
			 FROM {$table} ORDER BY id DESC LIMIT 200"
		);
	}

	return rest_ensure_response( array_map( 'bullion_ops_format_row', $rows ?: [] ) );
}

function bullion_ops_format_row( $row ) {
	$sources = @unserialize( $row->sources );
	return [
		'id'          => (int) $row->id,
		'sources'     => is_array( $sources ) ? $sources : [],
		'destination' => $row->url_to,
		'type'        => (int) $row->header_code,
		'status'      => $row->status,
		'hits'        => (int) $row->hits,
		'created'     => $row->created,
		'updated'     => $row->updated,
	];
}

function bullion_ops_upsert_redirect( WP_REST_Request $req ) {
	global $wpdb;

	$source      = trim( (string) $req->get_param( 'source' ) );
	$destination = trim( (string) $req->get_param( 'destination' ) );
	$type        = (int) ( $req->get_param( 'type' ) ?: 301 );
	$comparison  = (string) ( $req->get_param( 'comparison' ) ?: 'exact' );

	if ( $source === '' || $destination === '' ) {
		return new WP_Error( 'bullion_invalid', 'source and destination are required', [ 'status' => 400 ] );
	}

	$allowed_types = [ 301, 302, 307, 410, 451 ];
	if ( ! in_array( $type, $allowed_types, true ) ) {
		return new WP_Error( 'bullion_invalid', 'type must be one of 301, 302, 307, 410, 451', [ 'status' => 400 ] );
	}

	$allowed_comparisons = [ 'exact', 'contains', 'start', 'end', 'regex' ];
	if ( ! in_array( $comparison, $allowed_comparisons, true ) ) {
		return new WP_Error( 'bullion_invalid', 'comparison must be one of exact, contains, start, end, regex', [ 'status' => 400 ] );
	}

	$source_norm = ltrim( $source, '/' );
	$sources_blob = serialize( [ [
		'pattern'    => $source_norm,
		'comparison' => $comparison,
		'ignore'     => '',
	] ] );

	$table = bullion_ops_redirect_table();
	$now   = current_time( 'mysql' );

	$existing = $wpdb->get_row( $wpdb->prepare(
		"SELECT id FROM {$table} WHERE sources LIKE %s LIMIT 1",
		'%' . $wpdb->esc_like( 'pattern";s:' . strlen( $source_norm ) . ':"' . $source_norm . '"' ) . '%'
	) );

	if ( $existing ) {
		$wpdb->update( $table, [
			'sources'     => $sources_blob,
			'url_to'      => $destination,
			'header_code' => $type,
			'status'      => 'active',
			'updated'     => $now,
		], [ 'id' => $existing->id ] );
		$id = (int) $existing->id;
		$action = 'updated';
	} else {
		$result = $wpdb->insert( $table, [
			'sources'       => $sources_blob,
			'url_to'        => $destination,
			'header_code'   => $type,
			'hits'          => 0,
			'status'        => 'active',
			'created'       => $now,
			'updated'       => $now,
			'last_accessed' => '0000-00-00 00:00:00',
		] );
		if ( $result === false ) {
			return new WP_Error( 'bullion_db_error', $wpdb->last_error ?: 'insert failed', [ 'status' => 500 ] );
		}
		$id = (int) $wpdb->insert_id;
		$action = 'created';
	}

	bullion_ops_invalidate_redirect_cache();

	return rest_ensure_response( [
		'action'      => $action,
		'id'          => $id,
		'source'      => $source_norm,
		'destination' => $destination,
		'type'        => $type,
		'comparison'  => $comparison,
	] );
}

function bullion_ops_delete_redirect( WP_REST_Request $req ) {
	global $wpdb;
	$table = bullion_ops_redirect_table();
	$id    = (int) $req->get_param( 'id' );
	$rows  = $wpdb->delete( $table, [ 'id' => $id ], [ '%d' ] );
	bullion_ops_invalidate_redirect_cache();
	return rest_ensure_response( [ 'deleted' => (int) $rows, 'id' => $id ] );
}

function bullion_ops_invalidate_redirect_cache() {
	global $wpdb;
	$cache_table = $wpdb->prefix . 'rank_math_redirections_cache';
	$wpdb->query( "TRUNCATE TABLE {$cache_table}" );
	wp_cache_flush();
}

// --- Elementor regenerate --------------------------------------------------
//
// Forces a single Elementor-managed page to rebuild post_content + per-post
// CSS from the current _elementor_data. Use after REST-API-driven page edits
// (where the editor's save_builder hook never fires) so the page actually
// reflects the new layout without you having to open Elementor + click Update.
//
// POST /wp-json/bullion/v1/elementor/regenerate/{id}

function bullion_ops_elementor_regenerate( WP_REST_Request $req ) {
	if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
		return new WP_Error( 'bullion_no_elementor', 'Elementor plugin is not active', [ 'status' => 412 ] );
	}

	$post_id = (int) $req->get_param( 'id' );
	$post    = get_post( $post_id );
	if ( ! $post ) {
		return new WP_Error( 'bullion_no_post', "Post {$post_id} not found", [ 'status' => 404 ] );
	}

	$results = [ 'post_id' => $post_id, 'post_title' => $post->post_title ];

	// GUARD: only run the Elementor document-save path on pages that were
	// actually built in Elementor. For HTML-in-Classic-Editor pages, calling
	// $document->save() with empty elements data wipes post_content — a
	// 0.3.2 bug that blanked the QMines /is-copper-a-good-investment/
	// pillar before the guard was added. Detected via _elementor_edit_mode
	// post meta: 'builder' means Elementor-managed; empty means not.
	$edit_mode = get_post_meta( $post_id, '_elementor_edit_mode', true );
	$is_elementor_page = ( $edit_mode === 'builder' );

	if ( $is_elementor_page ) {
		try {
			$document = \Elementor\Plugin::$instance->documents->get( $post_id );
			if ( ! $document ) {
				return new WP_Error( 'bullion_not_elementor', "Post {$post_id} is registered as Elementor but no document loaded", [ 'status' => 412 ] );
			}

			// Same code path as the editor's Update button.
			$saved = $document->save( [
				'elements' => $document->get_elements_data(),
				'settings' => $document->get_settings(),
			] );

			$results['document_save'] = is_wp_error( $saved ) ? 'error: ' . $saved->get_error_message() : 'ok';

			if ( class_exists( '\\Elementor\\Core\\Files\\CSS\\Post' ) ) {
				$css_file = \Elementor\Core\Files\CSS\Post::create( $post_id );
				$css_file->update();
				$results['css'] = 'regenerated';
			}
		} catch ( \Throwable $e ) {
			return new WP_Error( 'bullion_elementor_error', $e->getMessage(), [ 'status' => 500 ] );
		}
	} else {
		// Non-Elementor page (HTML in Classic Editor, Gutenberg blocks, etc).
		// Skip document save + CSS regen — they don't apply and the save would
		// wipe post_content. Just invalidate WP caches + purge upstream caches.
		$results['document_save'] = 'skipped: not an Elementor page';
	}

	clean_post_cache( $post_id );
	wp_cache_delete( $post_id, 'posts' );
	wp_cache_delete( $post_id, 'post_meta' );
	$results['post_cache'] = 'cleaned';

	$results['cache'] = bullion_ops_purge_cache_inner();
	$results['regenerated'] = true;

	return rest_ensure_response( $results );
}

// --- Cache purge -----------------------------------------------------------

function bullion_ops_purge_cache_inner() {
	$results = [];

	if ( function_exists( 'rocket_clean_domain' ) ) {
		rocket_clean_domain();
		$results['wp_rocket_domain'] = 'cleared';
	} else {
		$results['wp_rocket_domain'] = 'plugin_inactive';
	}

	if ( function_exists( 'rocket_clean_minify' ) ) {
		rocket_clean_minify();
		$results['wp_rocket_minify'] = 'cleared';
	}

	if ( function_exists( 'rocket_clean_used_css' ) ) {
		rocket_clean_used_css();
		$results['wp_rocket_used_css'] = 'cleared';
	}

	if ( class_exists( '\\CF\\WordPress\\Hooks' ) ) {
		try {
			$hooks = new \CF\WordPress\Hooks();
			if ( method_exists( $hooks, 'purgeCacheEverything' ) ) {
				$hooks->purgeCacheEverything();
				$results['cloudflare'] = 'purged';
			} else {
				$results['cloudflare'] = 'plugin_method_missing';
			}
		} catch ( \Throwable $e ) {
			$results['cloudflare'] = 'error: ' . $e->getMessage();
		}
	} else {
		$results['cloudflare'] = 'plugin_inactive';
	}

	return $results;
}

function bullion_ops_purge_cache( WP_REST_Request $req ) {
	return rest_ensure_response( bullion_ops_purge_cache_inner() );
}
