<?php
/**
 * Plugin Name: Bullion Ops Helper
 * Plugin URI: https://github.com/BullionMedia/bullion-ops-helper
 * Description: REST endpoints for programmatic Rank Math redirects, Elementor regenerate, cache purges, a branded restyle of the asx_announcement CPT archive, FAQ JSON-LD schema injection on QMines project pages, shared CSS for In Summary / FAQ blocks, and the [qmines_project_faq] shortcode for Elementor placement. Used by Bullion Media ops tooling.
 * Version: 0.7.4
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
define( 'BULLION_OPS_VERSION', '0.7.4' );

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
	if ( ! is_singular() ) {
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
	if ( ! is_singular() ) {
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
				[ 'q' => 'Where is the Mt Chalmers project located?',           'a' => 'Mt Chalmers is located 17km north-east of Rockhampton in Queensland, Australia. QMines holds 316km² of tenure at the project.' ],
				[ 'q' => 'What commodities does Mt Chalmers produce?',          'a' => 'Mt Chalmers is a copper and gold project. The Pre-Feasibility Study defined a contained metal inventory of 65,000 tonnes of copper, 160,000 ounces of gold, 30,600 tonnes of zinc, 1.8 million ounces of silver, and 583,000 tonnes of pyrite.' ],
				[ 'q' => 'What is the current JORC resource estimate for Mt Chalmers?', 'a' => 'The Mt Chalmers Measured, Indicated and Inferred Resource stands at 11.86Mt at 1.22% contained copper equivalent. 84% of these Resources fall in the Measured and Indicated JORC categories, reflecting five resource upgrades completed in under three years.' ],
				[ 'q' => 'What did the Pre-Feasibility Study show for Mt Chalmers?', 'a' => 'The Pre-Feasibility Study returned a post-tax NPV at an 8% discount rate of $100 million, based on a 1Mtpa processing plant. The study also established a maiden Ore Reserve estimate (Proved and Probable categories).' ],
				[ 'q' => 'What stage of development is Mt Chalmers at?',        'a' => 'Mt Chalmers is in the development phase. QMines\' strategy involves transitioning the project toward production, leveraging its shallow high-grade open-pit geometry, coastal proximity, and existing infrastructure access.' ],
				[ 'q' => 'Who owns the Mt Chalmers project?',                   'a' => 'QMines Limited (ASX:QML) holds 100% of the Mt Chalmers project.' ],
			],
		],
		'develin-creek' => [
			'in_summary' => 'Develin Creek is a high-grade copper and zinc project located approximately 90km west of Rockhampton, Queensland, acquired by QMines in September 2024. The project hosts a JORC resource of 4.13Mt at 1.37% copper equivalent for 56,581 tonnes of copper equivalent across several Volcanic Hosted Massive Sulphide deposits, with 70% of that resource in the Indicated category. Its proximity to the Mt Chalmers project positions Develin Creek as a potential contributor to QMines\' broader production hub strategy.',
			'faqs' => [
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
				‘name’ => ‘Is Copper a Good Investment? An ASX Copper Developer’s View’,
				‘url’  => $home . ‘is-copper-a-good-investment/’,
			],
		],
	];
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
