<?php
/**
 * Plugin Name: Bullion Ops Helper
 * Plugin URI: https://github.com/BullionMedia/bullion-ops-helper
 * Description: REST endpoints for programmatic Rank Math redirects, Elementor regenerate, cache purges, a branded restyle of the asx_announcement CPT archive, FAQ JSON-LD schema injection on QMines project pages, shared CSS for In Summary / FAQ blocks, the [qmines_project_faq] shortcode for Elementor placement, and pillar-hero styling (featured-image band + floating title panel) for QMines pillar / cluster pages. Used by Bullion Media ops tooling.
 * Version: 0.9.13
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
define( 'BULLION_OPS_VERSION', '0.9.13' );

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

	register_rest_route( BULLION_OPS_NS, '/procurement-submit', [
		'methods'             => 'POST',
		'callback'            => 'bullion_ops_procurement_submit',
		'permission_callback' => 'bullion_ops_procurement_webhook_permission',
	] );

	register_rest_route( BULLION_OPS_NS, '/wpcode/snippet', [
		'methods'             => 'GET',
		'callback'            => 'bullion_ops_wpcode_list',
		'permission_callback' => 'bullion_ops_permission',
	] );

	register_rest_route( BULLION_OPS_NS, '/wpcode/snippet/(?P<id>\d+)', [
		'methods'             => 'GET',
		'callback'            => 'bullion_ops_wpcode_get',
		'permission_callback' => 'bullion_ops_permission',
	] );

	register_rest_route( BULLION_OPS_NS, '/wpcode/snippet/(?P<id>\d+)/search-replace', [
		'methods'             => 'PATCH',
		'callback'            => 'bullion_ops_wpcode_search_replace',
		'permission_callback' => 'bullion_ops_permission',
	] );
}

// --- WPCode snippet CRUD (v0.9.0) ------------------------------------------
//
// Read-and-search-replace access to WPCode Lite/Pro snippets (post_type=wpcode).
// Needed because WPCode does not expose REST endpoints on its own. Scoped
// tightly: only wpcode CPT, only search-replace (no arbitrary content set),
// admin capability required.

function bullion_ops_wpcode_list( WP_REST_Request $req ) {
	$search = (string) $req->get_param( 'search' );
	$args = [
		'post_type'      => 'wpcode',
		'post_status'    => 'any',
		'posts_per_page' => 200,
		'orderby'        => 'ID',
		'order'          => 'ASC',
	];
	if ( '' !== $search ) {
		$args['s'] = $search;
	}
	$posts = get_posts( $args );
	$out = [];
	foreach ( $posts as $p ) {
		$out[] = [
			'id'      => (int) $p->ID,
			'title'   => $p->post_title,
			'status'  => $p->post_status,
			'excerpt' => substr( $p->post_content, 0, 240 ),
			'length'  => strlen( $p->post_content ),
		];
	}
	return [ 'count' => count( $out ), 'snippets' => $out ];
}

function bullion_ops_wpcode_get( WP_REST_Request $req ) {
	$id = (int) $req['id'];
	$post = get_post( $id );
	if ( ! $post || 'wpcode' !== $post->post_type ) {
		return new WP_Error( 'bullion_ops_not_found', 'wpcode snippet not found', [ 'status' => 404 ] );
	}
	return [
		'id'      => (int) $post->ID,
		'title'   => $post->post_title,
		'status'  => $post->post_status,
		'content' => $post->post_content,
		'length'  => strlen( $post->post_content ),
	];
}

function bullion_ops_wpcode_search_replace( WP_REST_Request $req ) {
	$id      = (int) $req['id'];
	$search  = (string) $req->get_param( 'search' );
	$replace = (string) $req->get_param( 'replace' );

	if ( '' === $search ) {
		return new WP_Error( 'bullion_ops_bad_input', 'search cannot be empty', [ 'status' => 400 ] );
	}

	$post = get_post( $id );
	if ( ! $post ) {
		return new WP_Error( 'bullion_ops_not_found', 'snippet not found', [ 'status' => 404 ] );
	}
	if ( 'wpcode' !== $post->post_type ) {
		return new WP_Error( 'bullion_ops_wrong_type', 'post is not a wpcode snippet (got: ' . $post->post_type . ')', [ 'status' => 400 ] );
	}

	$before = $post->post_content;
	$count  = 0;
	$after  = str_replace( $search, $replace, $before, $count );

	if ( 0 === $count ) {
		return new WP_Error( 'bullion_ops_no_match', 'search string not found in snippet content', [ 'status' => 404 ] );
	}

	$result = wp_update_post( [
		'ID'           => $id,
		'post_content' => $after,
	], true );

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return [
		'ok'            => true,
		'snippet_id'    => $id,
		'replacements'  => $count,
		'before_length' => strlen( $before ),
		'after_length'  => strlen( $after ),
	];
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
  display:grid;grid-template-columns:30% 1fr;grid-column-gap:32px;
  align-items:center;
  background:#fff;border-bottom:1px solid #e8eded;
  padding:14px 20px;transition:background .2s;
}
body.post-type-archive-asx_announcement article.post:first-of-type {
  border-top:1px solid #e8eded;
}
body.post-type-archive-asx_announcement article.post:hover {background:#f8fbf9}
body.post-type-archive-asx_announcement article.post .qm-asx-meta {
  grid-column:1;grid-row:1;
  font-size:11px;color:#4CA565;font-weight:600;text-transform:uppercase;
  letter-spacing:1.4px;margin:0 0 4px;line-height:1.2;
}
body.post-type-archive-asx_announcement article.post .entry-title {
  grid-column:1;grid-row:2;
  font-family:'Muli',sans-serif;font-size:16px;font-weight:600;
  color:#142934;line-height:1.35;margin:0;
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
  margin:0;color:#5a6b73;font-size:14px;line-height:1.5;
  display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;
  overflow:hidden;text-overflow:ellipsis;
}
@media (max-width:768px) {
  body.post-type-archive-asx_announcement article.post {
    display:grid;grid-template-columns:1fr;grid-template-rows:auto auto auto;
    grid-column-gap:0;padding:16px 20px;
  }
  body.post-type-archive-asx_announcement article.post .qm-asx-meta {
    grid-column:1;grid-row:1;margin:0 0 4px;
  }
  body.post-type-archive-asx_announcement article.post .entry-title {
    grid-column:1;grid-row:2;margin:0 0 8px 0;
  }
  body.post-type-archive-asx_announcement article.post > p:not(.qm-asx-meta) {
    grid-column:1;grid-row:3;
    margin:0;-webkit-line-clamp:3;
  }
}
@media (max-width:600px) {
  body.post-type-archive-asx_announcement .page-header {padding:40px 16px 16px}
  body.post-type-archive-asx_announcement .page-header .entry-title {font-size:26px}
  body.post-type-archive-asx_announcement .page-content {padding:16px 16px 60px}
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

// --- Muli WOFF2 override (perf) --------------------------------------------
//
// Elementor's Custom Fonts feature only emits @font-face rules pointing at
// the TTF files the operator uploaded (.ttf, ~92 KB each, 5 weights). On
// mobile this blocks render until the TTFs download (FCP ~9.8s observed).
//
// This hook ships WOFF2 versions bundled in the plugin (assets/fonts/)
// and emits @font-face overrides at wp_head priority 999, after Elementor's
// rules. Same family name + same font-weight values means our rules win
// the cascade. Browsers fetch WOFF2 (~33 KB each, 65% smaller); Elementor's
// TTF rules stay as a fallback for ancient clients that don't support WOFF2.
//
// Weight mapping (matches Elementor's Custom Fonts config on dev 2026-06-03):
//   300 → Muli-Regular     500 → Muli-Bold       700 → Muli-Black
//   400 → Muli-SemiBold    600 → Muli-ExtraBold
//
// Add `font-display: swap` to avoid FOIT on slow networks. font-display is
// already swap on Elementor's rules; we mirror it.

add_action( 'wp_head', 'bullion_ops_inject_muli_woff2', 999 );

function bullion_ops_inject_muli_woff2() {
	$base = plugins_url( 'assets/fonts', __FILE__ );
	$weights = [
		300 => 'Muli-Regular.woff2',
		400 => 'Muli-SemiBold.woff2',
		500 => 'Muli-Bold.woff2',
		600 => 'Muli-ExtraBold.woff2',
		700 => 'Muli-Black.woff2',
	];
	echo "\n<style id=\"bullion-ops-muli-woff2\">\n";
	foreach ( $weights as $weight => $file ) {
		echo "@font-face{font-family:Muli;font-style:normal;font-weight:{$weight};font-display:swap;src:url('" . esc_url( "{$base}/{$file}" ) . "') format('woff2')}\n";
	}
	echo "</style>\n";
}

add_filter( 'upload_mimes', 'bullion_ops_allow_font_mime_types' );

function bullion_ops_allow_font_mime_types( $mimes ) {
	// Allow font uploads via WP media library (REST + admin) for future
	// font-perf work without needing per-file plugin releases.
	$mimes['woff2'] = 'font/woff2';
	$mimes['woff']  = 'font/woff';
	$mimes['ttf']   = 'font/ttf';
	return $mimes;
}

// --- Project page FAQ + In Summary support ---------------------------------
//
// Two responsibilities for the three QMines project pages (Mt Chalmers,
// Develin Creek, Mt Mackenzie):
//
//  1. wp_head — output FAQPage JSON-LD schema so AEO engines + Google rich
//     results work. Source of truth for the Q&A content lives in this
//     plugin so the operator can't accidentally break schema syntax by
//     editing the Elementor HTML widget.
//
//  2. wp_head — output scoped CSS that styles the In Summary + FAQ blocks
//     the operator pastes into Elementor HTML widgets. The HTML lives in
//     Elementor (operator-editable, layout-aware); the CSS lives here
//     (so it stays consistent across dev/live and survives Elementor
//     edits).
//
// Visible HTML for In Summary + FAQ is NOT injected by this plugin. The
// operator pastes it into Elementor HTML widgets on each project page so
// surrounding section backgrounds, paddings, and layouts work correctly.
//
// Page matching is slug-based so the same plugin behaves correctly on
// dev and live.

add_action( 'wp_head', 'bullion_ops_inject_project_faq_jsonld', 100 );

function bullion_ops_inject_project_faq_jsonld() {
	// Scope strictly to WP pages (post_type=page) — never fires on
	// asx_announcement CPT or any other custom post type. is_page() is
	// intentionally stricter than is_singular() so announcement slugs like
	// "10000m-drilling-program-mt-chalmers-initial-results" can never
	// accidentally match the project slug array below. (v0.9.13)
	if ( ! is_page() ) {
		return;
	}
	$post = get_post();
	if ( ! $post ) {
		return;
	}
	$data = bullion_ops_get_project_faq_data();
	if ( ! isset( $data[ $post->post_name ] ) ) {
		return;
	}
	echo bullion_ops_render_project_faq_jsonld( $data[ $post->post_name ] ); // phpcs:ignore WordPress.Security.EscapeOutput
}

add_action( 'wp_head', 'bullion_ops_inject_project_faq_css', 100 );

function bullion_ops_inject_project_faq_css() {
	// Same is_page() gate as bullion_ops_inject_project_faq_jsonld() — project
	// FAQ CSS must not fire on asx_announcement CPT pages. (v0.9.13)
	if ( ! is_page() ) {
		return;
	}
	$post = get_post();
	if ( ! $post ) {
		return;
	}
	$data = bullion_ops_get_project_faq_data();
	if ( ! isset( $data[ $post->post_name ] ) ) {
		return;
	}
	?>
<style id="bullion-ops-project-faq-css">
.bullion-ops-project-summary,
.bullion-ops-project-faq {
  max-width:1100px;margin:32px auto;padding:0 20px;
  font-family:'Muli',sans-serif;color:#142934;
  position:relative;z-index:10;
}
.bullion-ops-project-summary + .bullion-ops-project-faq {
  margin-top:64px;
}
.bullion-ops-project-summary h2,
.bullion-ops-project-faq h2 {
  font-size:24px;font-weight:600;color:#142934;margin:0 0 16px;
}
.bullion-ops-project-summary p {
  font-size:16px;line-height:1.6;color:#3a4a52;margin:0;
}
.bullion-ops-project-faq details {
  border-bottom:1px solid #e8eded;padding:14px 0;
}
.bullion-ops-project-faq details:first-of-type {
  border-top:1px solid #e8eded;
}
.bullion-ops-project-faq summary {
  cursor:pointer;font-size:16px;font-weight:600;color:#142934;
  list-style:none;padding-right:28px;position:relative;
}
.bullion-ops-project-faq summary::-webkit-details-marker {display:none}
.bullion-ops-project-faq summary::after {
  content:'+';position:absolute;right:0;top:0;
  font-size:20px;color:#4CA565;font-weight:400;
  transition:transform .2s;
}
.bullion-ops-project-faq details[open] summary::after {
  content:'\2212';
}
.bullion-ops-project-faq summary:hover {color:#4CA565}
.bullion-ops-project-faq details p {
  margin:12px 0 0;font-size:15px;line-height:1.6;color:#3a4a52;
}
@media (max-width:600px) {
  .bullion-ops-project-summary,
  .bullion-ops-project-faq {padding:0 16px;margin:24px auto}
  .bullion-ops-project-summary h2,
  .bullion-ops-project-faq h2 {font-size:20px}
}
</style>
	<?php
}

// Shortcode for Elementor placement.
//
// Operator adds an Elementor "Shortcode" widget where they want the FAQ +
// In Summary block, types [qmines_project_faq], saves. Plugin renders the
// HTML for the current page (matched by slug) inline.
//
// Returns empty string for pages that don't have project FAQ data, so the
// shortcode is safe to drop in templates / reusable blocks without
// erroring on non-project pages.

add_shortcode( 'qmines_project_faq', 'bullion_ops_project_faq_shortcode' );

function bullion_ops_project_faq_shortcode( $atts = [] ) {
	$post = get_post();
	if ( ! $post ) {
		return '';
	}
	$data = bullion_ops_get_project_faq_data();
	if ( ! isset( $data[ $post->post_name ] ) ) {
		return '';
	}
	return bullion_ops_render_project_faq_html( $data[ $post->post_name ] );
}

function bullion_ops_render_project_faq_html( $page ) {
	$out  = "\n<section class=\"bullion-ops-project-summary\">";
	$out .= '<h2>In Summary</h2>';
	$out .= '<p>' . esc_html( $page['in_summary'] ) . '</p>';
	$out .= "</section>\n";

	$out .= '<section class="bullion-ops-project-faq">';
	$out .= '<h2>Frequently Asked Questions</h2>';
	foreach ( $page['faqs'] as $faq ) {
		$out .= '<details>';
		$out .= '<summary>' . esc_html( $faq['q'] ) . '</summary>';
		$out .= '<p>' . esc_html( $faq['a'] ) . '</p>';
		$out .= '</details>';
	}
	$out .= "</section>\n";
	return $out;
}

function bullion_ops_render_project_faq_jsonld( $page ) {
	$main_entity = [];
	foreach ( $page['faqs'] as $faq ) {
		$main_entity[] = [
			'@type'          => 'Question',
			'name'           => $faq['q'],
			'acceptedAnswer' => [
				'@type' => 'Answer',
				'text'  => $faq['a'],
			],
		];
	}
	$schema = [
		'@context'   => 'https://schema.org',
		'@type'      => 'FAQPage',
		'mainEntity' => $main_entity,
	];
	return "\n<script type=\"application/ld+json\">"
		. wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
		. "</script>\n";
}

function bullion_ops_get_project_faq_data() {
	return [
		'mt-chalmers' => [
			'in_summary' => 'Mt Chalmers is QMines\' flagship development project, a high-grade copper-gold deposit 17km from Rockhampton with a completed Pre-Feasibility Study, five resource upgrades in under three years, and a current JORC resource of 11.86Mt at 1.22% copper equivalent. The project\'s shallow open-pit geometry, proximity to established infrastructure, and 316km² of tenure underpin QMines\' strategy of transitioning Mt Chalmers toward production. With 84% of resources in the Measured and Indicated JORC categories, Mt Chalmers represents the company\'s most advanced asset and its primary value-creation pathway.',
			'faqs' => [
				[ 'q' => 'What is the Mt Chalmers copper project?',             'a' => 'The Mt Chalmers copper-gold project is QMines\' flagship development asset in Central Queensland, located 17km north-east of Rockhampton. It is a past-producing high-grade copper and gold mine that operated between 1898 and 1982, now being redeveloped by QMines with a completed Pre-Feasibility Study, a declared Ore Reserve, and a Definitive Feasibility Study underway. The project holds a current JORC resource of 11.86Mt at 1.22% copper equivalent and forms the processing centre of QMines\' Multi-Project Copper & Gold Production Hub.' ],
				[ 'q' => 'Where is the Mt Chalmers project located?',           'a' => 'Mt Chalmers is located 17km north-east of Rockhampton in Queensland, Australia. QMines holds 316km² of tenure at the project.' ],
				[ 'q' => 'What commodities does Mt Chalmers produce?',          'a' => 'Mt Chalmers is a copper and gold project. The Pre-Feasibility Study defined a contained metal inventory of 65,000 tonnes of copper, 160,000 ounces of gold, 30,600 tonnes of zinc, 1.8 million ounces of silver, and 583,000 tonnes of pyrite.' ],
				[ 'q' => 'What is the current JORC resource estimate for Mt Chalmers?', 'a' => 'The Mt Chalmers Measured, Indicated and Inferred Resource stands at 11.86Mt at 1.22% contained copper equivalent. 84% of these Resources fall in the Measured and Indicated JORC categories, reflecting five resource upgrades completed in under three years.' ],
				[ 'q' => 'What did the Pre-Feasibility Study show for Mt Chalmers?', 'a' => 'The Pre-Feasibility Study returned a post-tax NPV at an 8% discount rate of $100 million, based on a 1Mtpa processing plant. The study also established a maiden Ore Reserve estimate (Proved and Probable categories).' ],
				[ 'q' => 'What stage of development is Mt Chalmers at?',        'a' => 'Mt Chalmers is in the development phase. QMines\' strategy involves transitioning the project toward production, leveraging its shallow high-grade open-pit geometry, coastal proximity, and existing infrastructure access.' ],
				[ 'q' => 'Who owns the Mt Chalmers project?',                   'a' => 'QMines Limited (ASX:QML) holds 100% of the Mt Chalmers project.' ],
			],
		],
		'develin-creek' => [
			'in_summary' => 'Develin Creek is a high-grade copper and zinc project located approximately 90km west of Rockhampton, Queensland, acquired by QMines in September 2024. The project hosts a JORC resource of 4.13Mt at 1.37% copper equivalent for 56,581 tonnes of copper equivalent across several Volcanic Hosted Massive Sulphide deposits, with 70% of that resource in the Indicated category. Its proximity to the Mt Chalmers project positions Develin Creek as a satellite feed source for QMines\' Multi-Project Copper & Gold Production Hub.',
			'faqs' => [
				[ 'q' => 'Who owns the Develin Creek copper project?',          'a' => 'QMines Limited (ASX:QML) owns 100% of the Develin Creek copper-zinc project. QMines completed the acquisition from Zenith Minerals Limited on 30 September 2024. The project is located approximately 90km west of Rockhampton in Queensland.' ],
				[ 'q' => 'What is the Develin Creek copper project?',           'a' => 'Develin Creek is a 100%-owned QMines copper-zinc project located approximately 90km west of Rockhampton, Queensland. It hosts a JORC Mineral Resource of 4.13Mt at 1.37% copper equivalent across several Volcanic Hosted Massive Sulphide (VHMS) deposits, with 70% of the resource in the Indicated category. Its proximity to QMines\' flagship Mt Chalmers project positions Develin Creek as a satellite feed source for the Multi-Project Copper & Gold Production Hub.' ],
				[ 'q' => 'Where is the Develin Creek project located?',         'a' => 'Develin Creek is located approximately 90km west of Rockhampton in Queensland, Australia. The project covers EPM 17604 and EPM 16749, totalling 272km² of tenure.' ],
				[ 'q' => 'What commodities does Develin Creek contain?',        'a' => 'Develin Creek contains copper, zinc, gold and silver mineralisation hosted in several Volcanic Hosted Massive Sulphide (VHMS) deposits, including the Sulphide City, Scorpion and Window deposits.' ],
				[ 'q' => 'What is the JORC resource estimate for Develin Creek?', 'a' => 'The Develin Creek Resource stands at 4.13Mt at 1.37% CuEq for 56,581 tonnes of copper equivalent. 70% of this Resource sits in the Indicated JORC category.' ],
				[ 'q' => 'When did QMines acquire Develin Creek?',              'a' => 'QMines completed the 100% acquisition of the Develin Creek copper and zinc project from Zenith Minerals Limited on 30 September 2024.' ],
				[ 'q' => 'What is the strategic relationship between Develin Creek and Mt Chalmers?', 'a' => 'Develin Creek\'s proximity to QMines\' Mt Chalmers project creates potential for the combined development of both resources. QMines has identified this geographic relationship as a factor in its broader production hub strategy.' ],
			],
		],
		'mt-mackenzie' => [
			'in_summary' => 'Mt Mackenzie is a 100%-owned gold and silver project located approximately 140km northwest of Rockhampton, Queensland, carrying a JORC resource of 3.35Mt at 1.40g/t gold and 8.4g/t silver for 129,000 ounces of gold and 862,000 ounces of silver. The project holds a granted Mining Development Licence (MDL 2008), completed Scoping Study, and freehold land, supporting its near-term development potential. Located 45km from Develin Creek, Mt Mackenzie forms part of QMines\' emerging central Queensland production hub.',
			'faqs' => [
				[ 'q' => 'Where is the Mt Mackenzie project located?',          'a' => 'Mt Mackenzie is located approximately 140km northwest of Rockhampton in Queensland, Australia, and approximately 45km from QMines\' Develin Creek project.' ],
				[ 'q' => 'What commodities does Mt Mackenzie contain?',         'a' => 'Mt Mackenzie is a gold and silver project, with shallow high-grade oxide and primary mineralisation suited to open-pit mining.' ],
				[ 'q' => 'What is the JORC resource estimate for Mt Mackenzie?', 'a' => 'The Mt Mackenzie Mineral Resource Estimate stands at 3.35Mt at 1.40g/t gold and 8.4g/t silver, for 129,000 ounces of gold and 862,000 ounces of silver. Nearly half of the Resource is classified in the Indicated JORC category, and the deposit remains open in multiple directions.' ],
				[ 'q' => 'What approvals and studies has Mt Mackenzie completed?', 'a' => 'Mt Mackenzie holds a granted Mining Development Licence (MDL 2008), a completed Scoping Study, and freehold land. The project is currently undergoing further PFS-level work.' ],
				[ 'q' => 'When did QMines acquire Mt Mackenzie?',               'a' => 'QMines acquired Mt Mackenzie from Resources and Energy Group in mid-2025.' ],
			],
		],
	];
}

// --- Pillar page BreadcrumbList JSON-LD ------------------------------------
//
// Injects BreadcrumbList structured data on QMines pillar / cluster pages.
// Rank Math does not emit a BreadcrumbList for root-level pages with no
// parent category (parent=0 in WP), so this fills the gap.
//
// Each entry in bullion_ops_get_pillar_breadcrumbs() maps a page slug to its
// breadcrumb trail. The array is keyed by WP post_name (slug). The trail is
// an ordered array of [ 'name' => ..., 'url' => ... ] items. Position numbers
// are assigned automatically (1-indexed) during JSON-LD emission.
//
// Shipping on wp_head at priority 100 (same as project FAQ schema) so all
// schema blocks land together in the <head>.

add_action( 'wp_head', 'bullion_ops_inject_pillar_breadcrumbs', 100 );

function bullion_ops_inject_pillar_breadcrumbs() {
	if ( ! is_singular() ) {
		return;
	}
	$post = get_post();
	if ( ! $post ) {
		return;
	}
	$map = bullion_ops_get_pillar_breadcrumbs();
	if ( ! isset( $map[ $post->post_name ] ) ) {
		return;
	}
	$trail = $map[ $post->post_name ];
	$items = [];
	foreach ( $trail as $i => $crumb ) {
		$items[] = [
			'@type'    => 'ListItem',
			'position' => $i + 1,
			'name'     => $crumb['name'],
			'item'     => $crumb['url'],
		];
	}
	$schema = [
		'@context'        => 'https://schema.org',
		'@type'           => 'BreadcrumbList',
		'itemListElement' => $items,
	];
	echo "\n<script type=\"application/ld+json\">"
		. wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
		. "</script>\n";
}

function bullion_ops_get_pillar_breadcrumbs() {
	$home = trailingslashit( home_url() );
	return [
		// Pillar: Is Copper a Good Investment?
		// Root-level page (parent=0) — 2-step trail: Home → page.
		// Added 2026-06-10 to fill the BreadcrumbList gap Rank Math leaves on
		// parentless root-level pages.
		'is-copper-a-good-investment' => [
			[
				'name' => 'Home',
				'url'  => $home,
			],
			[
				'name' => 'Is Copper a Good Investment? An ASX Copper Developer\'s View',
				'url'  => $home . 'is-copper-a-good-investment/',
			],
		],
		// Cluster: ASX Copper Stocks (nested under the pillar via WP parent=18618).
		// 3-step trail: Home → pillar → cluster. Updated 2026-07-22 when the
		// cluster was moved from root-level to nested under the pillar
		// (URL: /is-copper-a-good-investment/asx-copper-stocks/) so the
		// permalink structure reinforces the topical hierarchy.
		'asx-copper-stocks' => [
			[
				'name' => 'Home',
				'url'  => $home,
			],
			[
				'name' => 'Is Copper a Good Investment?',
				'url'  => $home . 'is-copper-a-good-investment/',
			],
			[
				'name' => 'ASX Copper Stocks: A Complete 2026 Investor Guide',
				'url'  => $home . 'is-copper-a-good-investment/asx-copper-stocks/',
			],
		],
	];
}

// --- Pillar hero image + floating title panel ------------------------------
//
// Turns the theme's plain .page-header into a full-viewport-width rounded
// hero band carrying the post's featured image with a 45deg dark-navy
// gradient (mirroring /insights/ hero panel treatment). Hides the theme's
// duplicate title, then promotes the article's .asx-article-header block
// into a dark navy title panel that hovers ~70% up into the hero. The
// featured image URL is pulled dynamically per-post so any pillar / cluster
// page that has a featured image set gets the treatment.
//
// Slug list is centralised in bullion_ops_get_pillar_hero_slugs() so cluster
// posts can be enrolled without changing the injector below.

add_action( 'wp_head', 'bullion_ops_inject_pillar_hero_css', 100 );

function bullion_ops_inject_pillar_hero_css() {
	if ( ! is_singular() ) {
		return;
	}
	$post = get_post();
	if ( ! $post ) {
		return;
	}
	if ( ! in_array( $post->post_name, bullion_ops_get_pillar_hero_slugs(), true ) ) {
		return;
	}
	if ( ! has_post_thumbnail( $post->ID ) ) {
		return;
	}
	$img_url = get_the_post_thumbnail_url( $post->ID, 'full' );
	if ( ! $img_url ) {
		return;
	}
	$img = esc_url( $img_url );
	?>
<style id="bullion-ops-pillar-hero-css">
/* HERO IMAGE BAND — full viewport width, rounded, 45deg dark-navy gradient */
.page-header {
	position: relative !important;
	margin-inline: calc(50% - 50vw) !important;
	width: 100vw !important;
	max-width: 100vw !important;
	padding: 0 2.4vw !important;
	margin-top: 24px !important;
	margin-bottom: 0 !important;
	box-sizing: border-box !important;
	background: transparent !important;
	border: 0 !important;
	min-height: 320px !important;
	overflow: visible !important;
}
.page-header::before {
	content: "" !important;
	position: absolute !important;
	top: 0 !important;
	left: 2.4vw !important;
	right: 2.4vw !important;
	bottom: 0 !important;
	background:
		linear-gradient(45deg, rgba(20,41,52,.75) 0, rgba(20,41,52,.4) 40%, rgba(20,41,52,.05) 75%, rgba(20,41,52,0) 100%),
		url("<?php echo $img; ?>") center right/cover no-repeat,
		#142934 !important;
	border-radius: 20px !important;
	z-index: 0 !important;
	pointer-events: none !important;
}
.page-header .entry-title { display: none !important; }

/* TITLE PANEL — hovers ~70% up into the hero */
.asx-article-header {
	position: relative !important;
	z-index: 5 !important;
	display: block !important;
	margin: -140px auto 40px auto !important;
	max-width: 780px !important;
	background: #142934 !important;
	color: #fff !important;
	padding: 40px 50px !important;
	border-radius: 8px !important;
	border: 1px solid #fff !important;
	text-align: left !important;
	box-shadow: 0 14px 34px rgba(0,0,0,.28) !important;
}
.asx-article-header h1 {
	color: #fff !important;
	font-size: clamp(1.5em, 2.6vw, 2em) !important;
	font-weight: 500 !important;
	line-height: 1.25 !important;
	margin: 0 0 16px 0 !important;
	text-align: left !important;
}
.asx-article-header .asx-article-meta {
	color: rgba(255,255,255,.75) !important;
	font-size: 0.9em !important;
	margin: 0 !important;
	padding: 0 !important;
	display: block !important;
	text-align: left !important;
	border: 0 !important;
	border-bottom: 0 !important;
	text-decoration: none !important;
	box-shadow: none !important;
}
.asx-article-header .asx-article-meta::after,
.asx-article-header .asx-article-meta::before {
	display: none !important;
	content: none !important;
}
.asx-article-header .asx-article-meta span {
	color: rgba(255,255,255,.75) !important;
	display: inline !important;
}
.asx-article-header .asx-article-meta span + span::before {
	content: " \00b7 " !important;
	margin: 0 8px !important;
	color: rgba(255,255,255,.4) !important;
}
</style>
	<?php
}

function bullion_ops_get_pillar_hero_slugs() {
	return [
		// Pillar
		'is-copper-a-good-investment',
		// Cluster posts
		'asx-copper-stocks',
	];
}

// --- Pillar / cluster header auto-wrap (v0.9.11) ---------------------------
//
// On enrolled pillar / cluster slugs, ensure the first <h1> and the
// following .asx-article-meta <p> are wrapped inside a
// <div class="asx-article-header"> block. This is what
// bullion_ops_inject_pillar_hero_css targets to float the dark-navy title
// panel over the hero image band; without it, the header renders as a plain
// H1 above the meta bar and the TOC lands above the H1.
//
// Enrolment reuses bullion_ops_get_pillar_hero_slugs() — same set of pages
// that carry the hero-band treatment.
//
// Priority 3 so this runs BEFORE the title-panel meta refresh (5) and TOC
// filters (15, 20). Idempotent: skips if .asx-article-header already
// exists. If no .asx-article-meta follows the H1, synthesizes one with
// placeholder spans (v0.9.9 auto-refresh then populates real values).
//
// Together with v0.9.9 (meta auto-refresh) and v0.9.10 (cluster hero
// enrolment), this means a drafter can ship a pillar / cluster page with
// just `<div class="asx-article-wrapper"><h1>Title</h1><p>Body...</p>...</div>`
// and the plugin fills in the rest: header wrap, meta spans, TOC.

add_filter( 'the_content', 'bullion_ops_ensure_pillar_header', 3 );

function bullion_ops_ensure_pillar_header( $content ) {
	if ( is_admin() || ! is_singular() || ! in_the_loop() || ! is_main_query() ) {
		return $content;
	}
	$post = get_post();
	if ( ! $post || ! in_array( $post->post_name, bullion_ops_get_pillar_hero_slugs(), true ) ) {
		return $content;
	}
	if ( false !== strpos( $content, 'class="asx-article-header"' ) ) {
		return $content;
	}
	if ( false === strpos( $content, '<h1' ) ) {
		return $content;
	}
	$dom = bullion_ops_toc_load_dom( $content );
	if ( ! $dom ) {
		return $content;
	}
	$h1s = $dom->getElementsByTagName( 'h1' );
	if ( 0 === $h1s->length ) {
		return $content;
	}
	$h1     = $h1s->item( 0 );
	$parent = $h1->parentNode;
	if ( ! $parent ) {
		return $content;
	}

	$meta = null;
	$next = $h1->nextSibling;
	while ( $next && XML_ELEMENT_NODE !== $next->nodeType ) {
		$next = $next->nextSibling;
	}
	if ( $next && $next->hasAttribute( 'class' ) && false !== strpos( $next->getAttribute( 'class' ), 'asx-article-meta' ) ) {
		$meta = $next;
	}

	$header = $dom->createElement( 'div' );
	$header->setAttribute( 'class', 'asx-article-header' );

	$insert_before = $h1->nextSibling;
	$parent->removeChild( $h1 );
	$header->appendChild( $h1 );

	if ( $meta ) {
		if ( $insert_before === $meta ) {
			$insert_before = $meta->nextSibling;
		}
		$parent->removeChild( $meta );
		$header->appendChild( $meta );
	} else {
		$meta_p = $dom->createElement( 'p' );
		$meta_p->setAttribute( 'class', 'asx-article-meta' );
		$published_str = date_i18n( 'j M Y', strtotime( $post->post_date ) );
		$updated_str   = date_i18n( 'j M Y', strtotime( $post->post_modified ) );
		$meta_p->appendChild( $dom->createElement( 'span', '1 min read' ) );
		$meta_p->appendChild( $dom->createElement( 'span', 'Published ' . $published_str ) );
		$meta_p->appendChild( $dom->createElement( 'span', 'Updated ' . $updated_str ) );
		$header->appendChild( $meta_p );
	}

	if ( $insert_before ) {
		$parent->insertBefore( $header, $insert_before );
	} else {
		$parent->appendChild( $header );
	}

	$out = bullion_ops_toc_dom_to_html( $dom );
	return ( null === $out ) ? $content : $out;
}

// --- Title-panel meta auto-refresh (v0.9.9) --------------------------------
//
// Every render, walk the .asx-article-meta block on enrolled pillar / cluster
// slugs and rewrite:
//   * "N min read"           -> word count / 250 wpm (rounded, min 1)
//   * "Updated DD Mmm YYYY"  -> WP post_modified formatted "j M Y"
// Also refreshes any manual `"dateModified":"..."` inside inline JSON-LD
// scripts so schema stays in sync with the visible date.
//
// Priority 5 so this runs BEFORE the H2-anchor / TOC filters (15, 20).
// Enrolment reuses bullion_ops_get_toc_enrolled_slugs() — same set of pages
// that carry the .asx-article-meta title-panel pattern.
//
// Trade-off (recorded in v0.9.11 release notes): any WP edit bumps
// post_modified, so meta-only tweaks will refresh the visible Updated
// date. Matches Google's post-modified convention.

add_filter( 'the_content', 'bullion_ops_refresh_title_panel_meta', 5 );

function bullion_ops_refresh_title_panel_meta( $content ) {
	if ( ! bullion_ops_toc_should_run() ) {
		return $content;
	}
	if ( false === strpos( $content, 'asx-article-meta' ) ) {
		return $content;
	}
	$post = get_post();
	if ( ! $post || empty( $post->post_modified ) ) {
		return $content;
	}

	$modified_ts = strtotime( $post->post_modified );
	if ( ! $modified_ts ) {
		return $content;
	}
	$updated_str  = date_i18n( 'j M Y', $modified_ts );
	$iso_modified = date( 'c', $modified_ts );

	$plain = wp_strip_all_tags( $content, true );
	$words = preg_match_all( '/[A-Za-z0-9\'\-]+/u', $plain, $m );
	$mins  = max( 1, (int) round( ( $words > 0 ? $words : 1 ) / 250 ) );

	// Refresh any manual `"dateModified":"..."` in inline JSON-LD blocks.
	$content = preg_replace(
		'/"dateModified":"[^"]*"/',
		'"dateModified":"' . $iso_modified . '"',
		$content
	);

	// Rewrite .asx-article-meta spans via DOMDocument.
	$dom = bullion_ops_toc_load_dom( $content );
	if ( ! $dom ) {
		return $content;
	}
	$xpath = new DOMXPath( $dom );
	$q     = '//*[contains(concat(" ", normalize-space(@class), " "), " asx-article-meta ")]';
	$hits  = $xpath->query( $q );
	if ( ! $hits || 0 === $hits->length ) {
		return $content;
	}
	$meta_el = $hits->item( 0 );

	$touched = false;
	foreach ( $meta_el->childNodes as $child ) {
		if ( XML_ELEMENT_NODE !== $child->nodeType ) {
			continue;
		}
		$text = trim( $child->textContent );
		if ( preg_match( '/^\d+\s+min read$/i', $text ) ) {
			while ( $child->firstChild ) {
				$child->removeChild( $child->firstChild );
			}
			$child->appendChild( $dom->createTextNode( $mins . ' min read' ) );
			$touched = true;
		} elseif ( preg_match( '/^Updated\s+\d/i', $text ) ) {
			while ( $child->firstChild ) {
				$child->removeChild( $child->firstChild );
			}
			$child->appendChild( $dom->createTextNode( 'Updated ' . $updated_str ) );
			$touched = true;
		}
	}
	if ( ! $touched ) {
		return $content;
	}
	$out = bullion_ops_toc_dom_to_html( $dom );
	return ( null === $out ) ? $content : $out;
}

// --- Auto Table of Contents (v0.9.4) --------------------------------------
//
// For enrolled slugs (pillar + cluster long-form articles), walks the H2
// elements in post_content, adds anchor ids where missing, and injects a
// collapsible <details> TOC block near the top of the article. Also emits
// a SpeakableSpecification schema pointing at the TOC + first paragraph so
// AI engines pick these as canonical speakable regions (AEO lift).
//
// Enrolment centralised in bullion_ops_get_toc_enrolled_slugs() below.
// Skip conditions: not enrolled / <4 H2s / >20 H2s / manual TOC already
// present (class="bullion-ops-toc").

function bullion_ops_get_toc_enrolled_slugs() {
	return [
		'is-copper-a-good-investment',   // Pillar
		'asx-copper-stocks',             // Cluster
	];
}

function bullion_ops_slugify_heading( $text ) {
	$text = wp_strip_all_tags( (string) $text );
	$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	if ( function_exists( 'iconv' ) ) {
		$folded = @iconv( 'UTF-8', 'ASCII//TRANSLIT//IGNORE', $text );
		if ( $folded !== false ) {
			$text = $folded;
		}
	}
	$text = strtolower( $text );
	$text = preg_replace( '/[^a-z0-9]+/', '-', $text );
	$text = trim( $text, '-' );
	if ( '' === $text ) {
		return 'section';
	}
	if ( preg_match( '/^\d/', $text ) ) {
		$text = 'section-' . $text;
	}
	if ( strlen( $text ) > 60 ) {
		$text = substr( $text, 0, 60 );
		$text = rtrim( $text, '-' );
	}
	return $text;
}

function bullion_ops_toc_should_run() {
	if ( is_admin() || ! is_singular() || ! in_the_loop() || ! is_main_query() ) {
		return false;
	}
	$post = get_post();
	if ( ! $post ) {
		return false;
	}
	return in_array( $post->post_name, bullion_ops_get_toc_enrolled_slugs(), true );
}

function bullion_ops_toc_load_dom( $content ) {
	if ( ! class_exists( 'DOMDocument' ) ) {
		return null;
	}
	$wrapped = '<?xml encoding="UTF-8"?><div id="bullion-ops-toc-root">' . $content . '</div>';
	$dom     = new DOMDocument();
	libxml_use_internal_errors( true );
	$loaded = $dom->loadHTML( $wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
	libxml_clear_errors();
	if ( ! $loaded ) {
		return null;
	}
	return $dom;
}

function bullion_ops_toc_dom_to_html( $dom ) {
	$root = $dom->getElementById( 'bullion-ops-toc-root' );
	if ( ! $root ) {
		return null;
	}
	$html = '';
	foreach ( $root->childNodes as $child ) {
		$html .= $dom->saveHTML( $child );
	}
	return $html;
}

function bullion_ops_add_h2_anchor_ids( $content ) {
	if ( ! bullion_ops_toc_should_run() ) {
		return $content;
	}
	if ( '' === trim( (string) $content ) ) {
		return $content;
	}
	$dom = bullion_ops_toc_load_dom( $content );
	if ( ! $dom ) {
		return $content;
	}
	$h2s = $dom->getElementsByTagName( 'h2' );
	if ( 0 === $h2s->length ) {
		return $content;
	}
	$seen = [];
	foreach ( iterator_to_array( $h2s ) as $h2 ) {
		if ( $h2->hasAttribute( 'id' ) && '' !== trim( $h2->getAttribute( 'id' ) ) ) {
			$seen[ $h2->getAttribute( 'id' ) ] = true;
			continue;
		}
		$base = bullion_ops_slugify_heading( $h2->textContent );
		$slug = $base;
		$n    = 2;
		while ( isset( $seen[ $slug ] ) ) {
			$slug = $base . '-' . $n;
			$n++;
		}
		$seen[ $slug ] = true;
		$h2->setAttribute( 'id', $slug );
	}
	$out = bullion_ops_toc_dom_to_html( $dom );
	return ( null === $out ) ? $content : $out;
}
add_filter( 'the_content', 'bullion_ops_add_h2_anchor_ids', 15 );

function bullion_ops_toc_find_insertion_target( $dom, $root ) {
	// Returns [ 'mode' => 'after' | 'prepend-in', 'node' => DOMElement ]
	// or null (prepend at root).
	//
	// Priority ladder:
	//   1. Sibling AFTER .asx-article-header (pillar has this — lands
	//      the TOC directly under the dark navy title panel).
	//   2. First child OF .asx-article-wrapper (cluster case — no
	//      header block, but the wrapper still exists; putting TOC at
	//      the top of the wrapper matches the pillar's visual position).
	//   3. Sibling AFTER first <p> at the root (generic long-form
	//      article without either pillar-specific class).
	//   4. Prepend at root (last resort).
	$xpath = new DOMXPath( $dom );

	$q_header = '//*[contains(concat(" ", normalize-space(@class), " "), " asx-article-header ")]';
	$hits     = $xpath->query( $q_header );
	if ( $hits && $hits->length > 0 ) {
		return [ 'mode' => 'after', 'node' => $hits->item( 0 ) ];
	}

	$q_wrap = '//*[contains(concat(" ", normalize-space(@class), " "), " asx-article-wrapper ")]';
	$hits   = $xpath->query( $q_wrap );
	if ( $hits && $hits->length > 0 ) {
		return [ 'mode' => 'prepend-in', 'node' => $hits->item( 0 ) ];
	}

	foreach ( $root->childNodes as $child ) {
		if ( XML_ELEMENT_NODE === $child->nodeType && 'p' === strtolower( $child->nodeName ) ) {
			return [ 'mode' => 'after', 'node' => $child ];
		}
	}
	return null;
}

function bullion_ops_toc_render_html( $entries ) {
	// Inline styles are the belt-and-braces layer: WP Rocket / LiteSpeed
	// UsedCSS strips inline <style> blocks that reference selectors not
	// found in the pre-render scan, which was killing the v0.9.4 TOC
	// appearance. Inline element styles survive every cache layer.
	// The <style> block from bullion_ops_inject_toc_css() adds hover /
	// focus / ::marker / @media rules that inline styles can't express.
	// Palette matches existing plugin tones: #142934 (dark navy text),
	// #4CA565 (QMines green accent), #e8eded (border used across FAQ +
	// ASX archive). Background is the harmonising neutral lightest grey.
	//
	// The explicit "border:0" on the anchor is load-bearing: without it,
	// the theme's default underline / bordered-link style paints a full
	// four-sided border box around each TOC entry text.
	//
	// v0.9.11 semantics: outer <nav aria-label="Table of contents"> creates
	// a labelled navigation landmark (accessibility + doc SEO). The inner
	// <details role="doc-toc"> adds DPUB-ARIA role recognised by
	// screen readers + Google as a Table of Contents section. Defensive
	// inline reset on the <nav> prevents any theme rule targeting bare
	// <nav a { ... }> from bleeding in and altering the visual.
	$s_nav     = 'display:block;margin:0;padding:0;border:0;background:transparent;';
	$s_toc     = 'background:#f5f6f7;border:1px solid #e8eded;border-radius:12px;padding:16px 24px 18px;margin:0 0 32px;font-size:0.98em;line-height:1.15;color:#142934;font-family:inherit;';
	$s_summary = 'cursor:pointer;font-weight:600;color:#142934;font-size:1.02em;list-style:none;padding:0;margin:0;display:flex;align-items:center;gap:10px;';
	$s_ol      = 'margin:10px 0 0;padding:0 0 0 26px;list-style:decimal;color:#142934;';
	$s_li      = 'margin:3px 0;padding-left:4px;font-weight:400;';
	$s_a       = 'color:#142934;text-decoration:none;border:0;background:transparent;padding:0;box-shadow:none;';

	$li = '';
	foreach ( $entries as $e ) {
		$li .= '<li style="' . esc_attr( $s_li ) . '">'
			. '<a href="#' . esc_attr( $e['id'] ) . '" style="' . esc_attr( $s_a ) . '">'
			. esc_html( $e['text'] )
			. '</a></li>';
	}
	return '<nav aria-label="Table of contents" style="' . esc_attr( $s_nav ) . '">'
		. '<details class="bullion-ops-toc" open role="doc-toc" style="' . esc_attr( $s_toc ) . '">'
		. '<summary class="bullion-ops-toc__summary" style="' . esc_attr( $s_summary ) . '">On this page</summary>'
		. '<ol class="bullion-ops-toc__list" style="' . esc_attr( $s_ol ) . '">' . $li . '</ol>'
		. '</details>'
		. '</nav>';
}

// Deterministic H2 extractor — mirrors the id-assignment logic in
// bullion_ops_add_h2_anchor_ids so the wp_head schema emitter can compute
// the same {id, text} entries before the_content filter has fired.
function bullion_ops_toc_extract_entries_from_content( $content ) {
	if ( '' === trim( (string) $content ) ) {
		return [];
	}
	$dom = bullion_ops_toc_load_dom( $content );
	if ( ! $dom ) {
		return [];
	}
	$h2s = $dom->getElementsByTagName( 'h2' );
	if ( 0 === $h2s->length ) {
		return [];
	}
	$entries = [];
	$seen    = [];
	foreach ( iterator_to_array( $h2s ) as $h2 ) {
		$text = trim( $h2->textContent );
		if ( '' === $text ) {
			continue;
		}
		$existing = $h2->hasAttribute( 'id' ) ? trim( $h2->getAttribute( 'id' ) ) : '';
		$base     = ( '' !== $existing ) ? $existing : bullion_ops_slugify_heading( $text );
		$id       = $base;
		$n        = 2;
		while ( isset( $seen[ $id ] ) ) {
			$id = $base . '-' . $n;
			$n++;
		}
		$seen[ $id ] = true;
		$entries[]   = [ 'id' => $id, 'text' => $text ];
	}
	return $entries;
}

function bullion_ops_inject_toc_block( $content ) {
	if ( ! bullion_ops_toc_should_run() ) {
		return $content;
	}
	if ( false !== strpos( $content, 'class="bullion-ops-toc"' ) ) {
		return $content; // Manual TOC already present — do not double-render.
	}
	$dom = bullion_ops_toc_load_dom( $content );
	if ( ! $dom ) {
		return $content;
	}
	$h2s   = $dom->getElementsByTagName( 'h2' );
	$count = $h2s->length;
	if ( $count < 4 || $count > 20 ) {
		return $content;
	}
	$entries = [];
	foreach ( iterator_to_array( $h2s ) as $h2 ) {
		$id = $h2->getAttribute( 'id' );
		if ( '' === $id ) {
			continue;
		}
		$text = trim( $h2->textContent );
		if ( '' === $text ) {
			continue;
		}
		$entries[] = [ 'id' => $id, 'text' => $text ];
	}
	if ( count( $entries ) < 4 ) {
		return $content;
	}

	$toc = bullion_ops_toc_render_html( $entries );

	$root = $dom->getElementById( 'bullion-ops-toc-root' );
	if ( ! $root ) {
		return $content;
	}

	$tmp = new DOMDocument();
	libxml_use_internal_errors( true );
	$tmp->loadHTML( '<?xml encoding="UTF-8"?><div id="bullion-ops-toc-wrap">' . $toc . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
	libxml_clear_errors();
	$wrap = $tmp->getElementById( 'bullion-ops-toc-wrap' );
	if ( ! $wrap || ! $wrap->firstChild ) {
		return $content;
	}
	$imported = $dom->importNode( $wrap->firstChild, true );

	$target = bullion_ops_toc_find_insertion_target( $dom, $root );
	if ( $target && 'after' === $target['mode'] ) {
		$node   = $target['node'];
		$parent = $node->parentNode;
		if ( $node->nextSibling ) {
			$parent->insertBefore( $imported, $node->nextSibling );
		} else {
			$parent->appendChild( $imported );
		}
	} elseif ( $target && 'prepend-in' === $target['mode'] ) {
		$node = $target['node'];
		if ( $node->firstChild ) {
			$node->insertBefore( $imported, $node->firstChild );
		} else {
			$node->appendChild( $imported );
		}
	} else {
		$root->insertBefore( $imported, $root->firstChild );
	}

	$out = bullion_ops_toc_dom_to_html( $dom );
	return ( null === $out ) ? $content : $out;
}
add_filter( 'the_content', 'bullion_ops_inject_toc_block', 20 );

add_action( 'wp_head', 'bullion_ops_inject_toc_css', 100 );

function bullion_ops_inject_toc_css() {
	if ( ! is_singular() ) {
		return;
	}
	$post = get_post();
	if ( ! $post || ! in_array( $post->post_name, bullion_ops_get_toc_enrolled_slugs(), true ) ) {
		return;
	}
	?>
<style id="bullion-ops-toc-css">
.bullion-ops-toc {
	background: #f5f6f7 !important;
	border: 1px solid #e8eded !important;
	border-radius: 12px !important;
	padding: 16px 24px 18px !important;
	margin: 0 0 32px 0 !important;
	font-size: 0.98em !important;
	line-height: 1.15 !important;
}
.bullion-ops-toc > summary {
	cursor: pointer !important;
	font-weight: 600 !important;
	color: #142934 !important;
	font-size: 1.02em !important;
	list-style: none !important;
	padding: 0 !important;
	margin: 0 !important;
	display: flex !important;
	align-items: center !important;
	gap: 10px !important;
}
.bullion-ops-toc > summary::-webkit-details-marker { display: none !important; }
.bullion-ops-toc > summary::before {
	content: "" !important;
	display: inline-block !important;
	width: 10px !important;
	height: 10px !important;
	border-right: 2px solid #4CA565 !important;
	border-bottom: 2px solid #4CA565 !important;
	transform: rotate(45deg) !important;
	transition: transform 0.15s ease !important;
	margin-right: 4px !important;
}
.bullion-ops-toc[open] > summary::before {
	transform: rotate(-135deg) !important;
	margin-top: 4px !important;
}
.bullion-ops-toc ol {
	margin: 10px 0 0 0 !important;
	padding: 0 0 0 26px !important;
	list-style: decimal !important;
}
.bullion-ops-toc ol li {
	margin: 3px 0 !important;
	padding-left: 4px !important;
	font-weight: 400 !important;
}
.bullion-ops-toc ol li::marker {
	color: #4CA565 !important;
	font-weight: 400 !important;
}
.bullion-ops-toc ol li a {
	color: #142934 !important;
	text-decoration: none !important;
	border: 0 !important;
	background: transparent !important;
	padding: 0 !important;
	box-shadow: none !important;
}
.bullion-ops-toc ol li a:hover,
.bullion-ops-toc ol li a:focus {
	color: #4CA565 !important;
	text-decoration: underline !important;
	text-underline-offset: 3px !important;
}
@media (max-width: 640px) {
	.bullion-ops-toc {
		padding: 14px 18px 16px !important;
		margin: 0 0 24px 0 !important;
	}
	.bullion-ops-toc[open] > summary {
		margin-bottom: 4px !important;
	}
}
</style>
	<?php
}

add_action( 'wp_head', 'bullion_ops_inject_toc_itemlist_jsonld', 104 );

function bullion_ops_inject_toc_itemlist_jsonld() {
	if ( ! is_singular() ) {
		return;
	}
	$post = get_post();
	if ( ! $post || ! in_array( $post->post_name, bullion_ops_get_toc_enrolled_slugs(), true ) ) {
		return;
	}
	$entries = bullion_ops_toc_extract_entries_from_content( $post->post_content );
	$count   = count( $entries );
	if ( $count < 4 || $count > 20 ) {
		return; // same gate as the visible TOC block
	}
	$permalink = get_permalink( $post );
	if ( ! $permalink ) {
		return;
	}
	$items = [];
	foreach ( $entries as $idx => $e ) {
		$items[] = [
			'@type'    => 'ListItem',
			'position' => $idx + 1,
			'name'     => $e['text'],
			'url'      => $permalink . '#' . $e['id'],
		];
	}
	$schema = [
		'@context'        => 'https://schema.org',
		'@type'           => 'ItemList',
		'name'            => 'Table of contents',
		'itemListElement' => $items,
	];
	echo "\n<script type=\"application/ld+json\">"
		. wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
		. "</script>\n";
}

add_action( 'wp_head', 'bullion_ops_inject_toc_speakable_jsonld', 105 );

function bullion_ops_inject_toc_speakable_jsonld() {
	if ( ! is_singular() ) {
		return;
	}
	$post = get_post();
	if ( ! $post || ! in_array( $post->post_name, bullion_ops_get_toc_enrolled_slugs(), true ) ) {
		return;
	}
	$schema = [
		'@context'  => 'https://schema.org',
		'@type'     => 'WebPage',
		'speakable' => [
			'@type'       => 'SpeakableSpecification',
			'cssSelector' => [ '.bullion-ops-toc', '.entry-content > p:first-of-type' ],
		],
	];
	echo "\n<script type=\"application/ld+json\">"
		. wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
		. "</script>\n";
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

// --- Procurement webhook -> Notion DB --------------------------------------
//
// Receives form submissions from the Elementor Pro Webhook action on the
// /suppliers/ page and creates rows in the QMines Procurement Signups
// Notion database.
//
// POST /wp-json/bullion/v1/procurement-submit
//
// Auth: shared-secret header X-Bullion-Ops-Webhook-Secret matched against
// the BULLION_OPS_PROCUREMENT_WEBHOOK_SECRET constant in wp-config.php.
// Bypasses the standard manage_options check because Elementor sends the
// webhook from the frontend with no WP user session.
//
// Required wp-config constants:
//   BULLION_OPS_NOTION_TOKEN                  Notion integration secret
//   BULLION_OPS_PROCUREMENT_WEBHOOK_SECRET    Shared secret matching the header
//
// Notion database ID — set ONE of (constant takes precedence):
//   define( 'BULLION_OPS_PROCUREMENT_DB_ID', '...' );     in wp-config.php, OR
//   bullion_ops_procurement_db_id                          WP option
//
// Notion field mapping (Elementor field Custom ID -> Notion property):
//   company_name        -> Company Name (title)
//   abn                 -> ABN (rich_text)
//   contact_name        -> Contact Name (rich_text)
//   contact_email       -> Contact Email (email)
//   contact_phone       -> Contact Phone (phone_number)
//   service_category    -> Service Category (multi_select, comma-split)
//   location            -> Location (rich_text)
//   capability_summary  -> Capability Summary (rich_text)
//   website             -> Website (url, auto-prepends https:// if missing)
//
// Auto-stamped on every row:
//   Submitted Date  -> today
//   Status          -> "New"
//   Submitted Via   -> "Procurement Form"

function bullion_ops_procurement_webhook_permission( WP_REST_Request $req ) {
	if ( ! defined( 'BULLION_OPS_PROCUREMENT_WEBHOOK_SECRET' ) ) {
		error_log( 'bullion-ops procurement webhook: BULLION_OPS_PROCUREMENT_WEBHOOK_SECRET not defined' );
		return new WP_Error( 'bullion_misconfigured', 'webhook secret not configured', [ 'status' => 500 ] );
	}
	// Accept secret via either X-Bullion-Ops-Webhook-Secret header OR ?secret= query
	// string. Header is preferred (doesn't appear in server access logs); query string
	// is the fallback for form plugins like Elementor Pro's stock Webhook action that
	// don't expose a custom-headers UI.
	$supplied = $req->get_header( 'x_bullion_ops_webhook_secret' );
	if ( ! $supplied ) {
		$query = $req->get_query_params();
		if ( is_array( $query ) && ! empty( $query['secret'] ) ) {
			$supplied = (string) $query['secret'];
		}
	}
	if ( ! $supplied ) {
		return new WP_Error( 'bullion_no_secret', 'missing webhook secret (header X-Bullion-Ops-Webhook-Secret or query ?secret=)', [ 'status' => 401 ] );
	}
	if ( ! hash_equals( BULLION_OPS_PROCUREMENT_WEBHOOK_SECRET, $supplied ) ) {
		return new WP_Error( 'bullion_bad_secret', 'invalid webhook secret', [ 'status' => 401 ] );
	}
	return true;
}

function bullion_ops_procurement_submit( WP_REST_Request $req ) {
	if ( ! defined( 'BULLION_OPS_NOTION_TOKEN' ) ) {
		error_log( 'bullion-ops procurement submit: BULLION_OPS_NOTION_TOKEN not defined' );
		return new WP_Error( 'bullion_no_token', 'Notion token not configured', [ 'status' => 500 ] );
	}
	// DB ID resolution: constant > option > filter. Constant in wp-config.php is
	// the operator-friendly path (consistent with the other two webhook constants).
	$db_id = defined( 'BULLION_OPS_PROCUREMENT_DB_ID' ) ? BULLION_OPS_PROCUREMENT_DB_ID : '';
	if ( ! $db_id ) {
		$db_id = get_option( 'bullion_ops_procurement_db_id', '' );
	}
	$db_id = apply_filters( 'bullion_ops_procurement_notion_db_id', $db_id );
	if ( ! $db_id ) {
		error_log( 'bullion-ops procurement submit: procurement DB ID not configured (set BULLION_OPS_PROCUREMENT_DB_ID constant in wp-config.php or bullion_ops_procurement_db_id option)' );
		return new WP_Error( 'bullion_no_db', 'procurement DB ID not configured', [ 'status' => 500 ] );
	}

	$fields = $req->get_params();
	if ( empty( $fields ) || ! is_array( $fields ) ) {
		return new WP_Error( 'bullion_no_fields', 'no form fields received', [ 'status' => 400 ] );
	}

	$fields     = bullion_ops_normalize_field_keys( $fields );
	$properties = bullion_ops_map_elementor_to_notion( $fields );
	if ( empty( $properties ) ) {
		return new WP_Error( 'bullion_empty_map', 'no mapped fields produced', [ 'status' => 400 ] );
	}

	$result = bullion_ops_post_to_notion( $db_id, $properties );
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return rest_ensure_response( [
		'ok'              => true,
		'notion_page_id'  => $result['id'] ?? null,
		'notion_page_url' => $result['url'] ?? null,
	] );
}

// Normalises whatever key style Elementor sends (Custom IDs, field labels, or
// auto-generated IDs) into the canonical lowercase_underscore names the mapper
// expects. Elementor defaults to field labels as keys if Custom IDs aren't set
// on each field, so the canonical form is "Company Name" -> "company_name",
// "City/Region" -> "city_region", etc. Aliases below collapse common label
// variations onto the canonical Notion property keys.
function bullion_ops_normalize_field_keys( array $fields ) {
	$aliases = [
		// company_name
		'company_name'       => 'company_name',
		'company'            => 'company_name',
		'business_name'      => 'company_name',
		// abn
		'abn'                => 'abn',
		// contact_name
		'contact_name'       => 'contact_name',
		'name'               => 'contact_name',
		'your_name'          => 'contact_name',
		'full_name'          => 'contact_name',
		// contact_email
		'contact_email'      => 'contact_email',
		'email'              => 'contact_email',
		'email_address'      => 'contact_email',
		'your_email'         => 'contact_email',
		// contact_phone
		'contact_phone'      => 'contact_phone',
		'phone'              => 'contact_phone',
		'phone_number'       => 'contact_phone',
		'your_phone'         => 'contact_phone',
		// location
		'location'           => 'location',
		'city'               => 'location',
		'region'             => 'location',
		'town'               => 'location',
		'city_region'        => 'location',
		'cityregion'         => 'location',
		'town_or_region'     => 'location',
		'town_region'        => 'location',
		// capability_summary
		'capability_summary' => 'capability_summary',
		'capability'         => 'capability_summary',
		'summary'            => 'capability_summary',
		'about'              => 'capability_summary',
		'description'        => 'capability_summary',
		'message'            => 'capability_summary',
		// service_category
		'service_category'   => 'service_category',
		'service'            => 'service_category',
		'services'           => 'service_category',
		'category'           => 'service_category',
		'categories'         => 'service_category',
		// website
		'website'            => 'website',
		'web'                => 'website',
		'url'                => 'website',
		'site'               => 'website',
	];
	$normalized = [];
	foreach ( $fields as $key => $value ) {
		// Lowercase + collapse any non-alphanumeric run to a single underscore.
		$clean = strtolower( (string) $key );
		$clean = preg_replace( '#[^a-z0-9]+#', '_', $clean );
		$clean = trim( (string) $clean, '_' );
		if ( $clean === '' ) {
			continue;
		}
		if ( isset( $aliases[ $clean ] ) ) {
			$normalized[ $aliases[ $clean ] ] = $value;
		}
		// Unknown keys (Elementor meta like form_name, page_url, user_agent,
		// remote_ip, powered_by, date, time, etc.) are dropped silently.
	}
	return $normalized;
}

function bullion_ops_map_elementor_to_notion( array $fields ) {
	$props = [];

	// Title (required for Notion page creation; fall back to "(no company name)" if missing).
	$company_name = isset( $fields['company_name'] ) ? trim( (string) $fields['company_name'] ) : '';
	if ( $company_name === '' ) {
		$company_name = '(no company name)';
	}
	$props['Company Name'] = [
		'title' => [ [ 'text' => [ 'content' => $company_name ] ] ],
	];

	// Rich text fields.
	foreach ( [
		'abn'                => 'ABN',
		'contact_name'       => 'Contact Name',
		'location'           => 'Location',
		'capability_summary' => 'Capability Summary',
	] as $key => $prop ) {
		if ( ! empty( $fields[ $key ] ) ) {
			$props[ $prop ] = [
				'rich_text' => [ [ 'text' => [ 'content' => trim( (string) $fields[ $key ] ) ] ] ],
			];
		}
	}

	// Email.
	if ( ! empty( $fields['contact_email'] ) ) {
		$props['Contact Email'] = [
			'email' => trim( (string) $fields['contact_email'] ),
		];
	}

	// Phone.
	if ( ! empty( $fields['contact_phone'] ) ) {
		$props['Contact Phone'] = [
			'phone_number' => trim( (string) $fields['contact_phone'] ),
		];
	}

	// URL (auto-prepend https:// if user typed "example.com" without scheme).
	if ( ! empty( $fields['website'] ) ) {
		$url = trim( (string) $fields['website'] );
		if ( ! preg_match( '#^https?://#i', $url ) ) {
			$url = 'https://' . $url;
		}
		$props['Website'] = [ 'url' => $url ];
	}

	// Service Category — multi-select, splits on comma so single-select dropdown
	// values and multi-checkbox values both work.
	if ( ! empty( $fields['service_category'] ) ) {
		$raw = (string) $fields['service_category'];
		$options = array_filter( array_map( 'trim', explode( ',', $raw ) ) );
		if ( ! empty( $options ) ) {
			$multi = [];
			foreach ( $options as $name ) {
				$multi[] = [ 'name' => $name ];
			}
			$props['Service Category'] = [ 'multi_select' => $multi ];
		}
	}

	// Auto-stamped fields.
	$props['Submitted Date'] = [
		'date' => [ 'start' => current_time( 'Y-m-d' ) ],
	];
	$props['Status'] = [
		'select' => [ 'name' => 'New' ],
	];
	$props['Submitted Via'] = [
		'select' => [ 'name' => 'Procurement Form' ],
	];

	return $props;
}

function bullion_ops_post_to_notion( $db_id, array $properties ) {
	$body = [
		'parent'     => [ 'database_id' => $db_id ],
		'properties' => $properties,
	];

	$response = wp_remote_post( 'https://api.notion.com/v1/pages', [
		'timeout' => 15,
		'headers' => [
			'Authorization'  => 'Bearer ' . BULLION_OPS_NOTION_TOKEN,
			'Notion-Version' => '2022-06-28',
			'Content-Type'   => 'application/json',
		],
		'body'    => wp_json_encode( $body ),
	] );

	if ( is_wp_error( $response ) ) {
		error_log( 'bullion-ops procurement webhook: Notion API request failed - ' . $response->get_error_message() );
		return new WP_Error(
			'bullion_notion_timeout',
			'Notion API request failed: ' . $response->get_error_message(),
			[ 'status' => 504 ]
		);
	}

	$code   = wp_remote_retrieve_response_code( $response );
	$raw    = wp_remote_retrieve_body( $response );
	$parsed = json_decode( $raw, true );

	if ( $code < 200 || $code >= 300 ) {
		$detail = is_array( $parsed ) && isset( $parsed['message'] ) ? $parsed['message'] : $raw;
		error_log( 'bullion-ops procurement webhook: Notion API ' . $code . ' - ' . $detail );
		return new WP_Error(
			'bullion_notion_error',
			'Notion API error (' . $code . '): ' . $detail,
			[ 'status' => 502 ]
		);
	}

	return is_array( $parsed ) ? $parsed : [];
}
