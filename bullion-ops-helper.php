<?php
/**
 * Plugin Name: Bullion Ops Helper
 * Plugin URI: https://github.com/BullionMedia/bullion-ops-helper
 * Description: REST endpoints for programmatic Rank Math redirects, Elementor regenerate, cache purges, a branded restyle of the asx_announcement CPT archive, FAQ JSON-LD schema injection on QMines project pages, shared CSS for In Summary / FAQ blocks, the [qmines_project_faq] shortcode for Elementor placement, pillar-hero styling (featured-image band + floating title panel) for QMines pillar / cluster pages, and asx_announcement CPT sitemap force-inclusion. Used by Bullion Media ops tooling.
 * Version: 0.9.44
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
define( 'BULLION_OPS_VERSION', '0.9.44' );

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

	register_rest_route( BULLION_OPS_NS, '/grep', [
		'methods'             => 'GET',
		'callback'            => 'bullion_ops_grep',
		'permission_callback' => 'bullion_ops_permission',
	] );

	register_rest_route( BULLION_OPS_NS, '/dbgrep', [
		'methods'             => 'GET',
		'callback'            => 'bullion_ops_dbgrep',
		'permission_callback' => 'bullion_ops_permission',
	] );

	register_rest_route( BULLION_OPS_NS, '/head-hooks', [
		'methods'             => 'GET',
		'callback'            => 'bullion_ops_head_hooks',
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

	register_rest_route( BULLION_OPS_NS, '/sitemap/inspect/(?P<id>\d+)', [
		'methods'             => 'GET',
		'callback'            => 'bullion_ops_sitemap_inspect',
		'permission_callback' => 'bullion_ops_permission',
	] );

	register_rest_route( BULLION_OPS_NS, '/rank-math-scores', [
		'methods'             => 'GET',
		'callback'            => 'bullion_ops_rank_math_scores',
		'permission_callback' => 'bullion_ops_permission',
	] );
}

// --- Rank Math score capture (v0.9.33) -------------------------------------
//
// Master plan Priority 0.2 originally called for the operator to screenshot
// the SEO Score column in WP Admin for every published page. At 32 URLs that
// is a miserable job that has to be redone after every remediation pass, so
// the plan offered a plugin endpoint as the alternative. This is it.
//
// Rank Math stores the score in postmeta as `rank_math_seo_score` and is not
// exposed over the REST API, which is why it could not simply be read with
// the WP core endpoints.
//
// Returns focus keyword and title/description alongside the score, because
// the score alone is close to useless without knowing what it was scored
// against. Note the standing finding this endpoint exists to confirm rather
// than act on: Rank Math scores are a poor signal for this site. Mt Chalmers
// scored RM-23 against rubric-72 because the plugin's schema work is
// invisible to Rank Math. Treat sub-40 as a misconfiguration alarm and
// ignore the number above that.

function bullion_ops_rank_math_scores( WP_REST_Request $req ) {
	$types = $req->get_param( 'post_type' );
	$types = $types ? array_map( 'sanitize_key', explode( ',', $types ) )
	                : [ 'page', 'post', 'asx_announcement' ];

	$q = new WP_Query( [
		'post_type'      => $types,
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'no_found_rows'  => true,
	] );

	$rows = [];
	foreach ( $q->posts as $id ) {
		$score = get_post_meta( $id, 'rank_math_seo_score', true );
		$rows[] = [
			'id'            => $id,
			'post_type'     => get_post_type( $id ),
			'title'         => get_the_title( $id ),
			'url'           => get_permalink( $id ),
			// Distinguish "Rank Math scored this 0" from "Rank Math has never
			// scored this page". Both render as an empty column in WP Admin,
			// which is exactly the ambiguity screenshots could not resolve.
			'score'         => ( $score === '' || $score === null ) ? null : (int) $score,
			'focus_keyword' => get_post_meta( $id, 'rank_math_focus_keyword', true ) ?: null,
			'seo_title'     => get_post_meta( $id, 'rank_math_title', true ) ?: null,
			'seo_desc'      => get_post_meta( $id, 'rank_math_description', true ) ?: null,
		];
	}

	usort( $rows, function ( $a, $b ) {
		// Unscored first: those are the ones that need attention.
		if ( $a['score'] === null && $b['score'] !== null ) return -1;
		if ( $b['score'] === null && $a['score'] !== null ) return 1;
		return $a['score'] <=> $b['score'];
	} );

	$scored = array_filter( $rows, function ( $r ) { return $r['score'] !== null; } );
	$vals   = array_column( $scored, 'score' );

	return [
		'generated'   => current_time( 'c' ),
		'rank_math'   => defined( 'RANK_MATH_VERSION' ) ? RANK_MATH_VERSION : null,
		'post_types'  => $types,
		'total'       => count( $rows ),
		'scored'      => count( $scored ),
		'unscored'    => count( $rows ) - count( $scored ),
		'average'     => $vals ? round( array_sum( $vals ) / count( $vals ), 1 ) : null,
		'below_40'    => count( array_filter( $vals, function ( $v ) { return $v < 40; } ) ),
		'items'       => $rows,
	];
}

// --- Sitemap diagnostic + child-page inclusion filter (v0.9.25) ------------
//
// Rank Math's Pages sitemap silently excludes hierarchical child pages on
// some installs. Diagnostic endpoint returns the raw postmeta + RM's
// exclusion decision. Output filter guarantees every published `page`
// with parent != 0 (and Robots Meta == index) appears in the sitemap URL
// set, overriding any upstream exclusion.

function bullion_ops_sitemap_inspect( WP_REST_Request $req ) {
	$id   = (int) $req['id'];
	$post = get_post( $id );
	if ( ! $post ) {
		return new WP_Error( 'bullion_ops_not_found', 'post not found', [ 'status' => 404 ] );
	}
	$all_meta = get_post_meta( $id );
	$rm_meta  = [];
	foreach ( $all_meta as $k => $v ) {
		if ( strpos( $k, 'rank_math' ) === 0 ) {
			$rm_meta[ $k ] = count( $v ) === 1 ? $v[0] : $v;
		}
	}
	$excluded_by = null;
	if ( class_exists( '\\RankMath\\Sitemap\\Sitemap' ) ) {
		$is_indexable = \RankMath\Helper::is_post_indexable( $id );
		$excluded_by  = $is_indexable ? 'indexable' : 'RankMath\\Helper::is_post_indexable returned false';
	}
	return [
		'id'                   => $id,
		'post_type'            => $post->post_type,
		'post_status'          => $post->post_status,
		'parent'               => (int) $post->post_parent,
		'permalink'            => get_permalink( $id ),
		'rank_math_postmeta'   => $rm_meta,
		'rm_indexable_verdict' => $excluded_by,
	];
}

// Force-include published hierarchical `page` posts in the RM sitemap.
// Runs on the exclude decision — returning `false` tells RM NOT to exclude.
add_filter( 'rank_math/sitemap/exclude_post', function( $exclude, $post_id ) {
	$post = get_post( $post_id );
	if ( ! $post || 'page' !== $post->post_type ) {
		return $exclude;
	}
	if ( 'publish' !== $post->post_status || (int) $post->post_parent === 0 ) {
		return $exclude;
	}
	// Respect explicit noindex — only override the mysterious hierarchical exclusion.
	$robots = get_post_meta( $post_id, 'rank_math_robots', true );
	if ( is_array( $robots ) && in_array( 'noindex', $robots, true ) ) {
		return $exclude;
	}
	return false;
}, 10, 2 );

// Belt-and-braces: if the URL still doesn't make it into the final set,
// inject it directly.
add_filter( 'rank_math/sitemap/urls', function( $urls ) {
	$pages = get_posts( [
		'post_type'      => 'page',
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'post_parent__not_in' => [ 0 ],
		'fields'         => 'ids',
	] );
	if ( empty( $pages ) ) {
		return $urls;
	}
	$existing = [];
	foreach ( $urls as $u ) {
		if ( isset( $u['loc'] ) ) {
			$existing[ $u['loc'] ] = true;
		}
	}
	foreach ( $pages as $pid ) {
		$robots = get_post_meta( $pid, 'rank_math_robots', true );
		if ( is_array( $robots ) && in_array( 'noindex', $robots, true ) ) {
			continue;
		}
		$link = get_permalink( $pid );
		if ( isset( $existing[ $link ] ) ) {
			continue;
		}
		$urls[] = [
			'loc' => $link,
			'mod' => get_the_modified_date( DATE_W3C, $pid ),
		];
	}
	return $urls;
}, 20 );

// --- Defer below-the-fold WebLink widgets on the front page (v0.9.29) ------
//
// Measured 2026-07-28: the homepage is 4,235 KB across 143 requests, of which
// the WebLink investor widgets are 99 requests and 3,373 KB - about 80% of the
// page. Lab mobile score 55/100, first paint 10.6s. Total Blocking Time is 0ms
// and the server answers in 20ms, so this is not a CSS/JS execution problem
// and not a hosting problem: it is purely the weight arriving over a throttled
// mobile connection. (Real-user CrUX is far better at 1.8s first paint, so
// this is a moderate win, not an emergency.)
//
// There are six WebLink iframes and each is a self-contained page that loads
// its own copy of jQuery 1.8.2, moment.js and Raphael. We cannot stop WebLink
// duplicating those - that is their product. What we can control is WHEN each
// widget loads.
//
// Four of the six sit below the fold and nobody sees them during the first
// paint, so their src is swapped to data-src at render time and restored by
// IntersectionObserver as the reader approaches them:
//
//   DEFER  priceframe2.aspx                 QMines price, below fold
//   DEFER  priceframe2.aspx?symbol=copper   copper price, below fold
//   DEFER  chartformresponsive.aspx         QMines chart, below fold
//   DEFER  copper/chartformresponsive.aspx  copper chart, below fold
//   KEEP   priceframe.aspx                  share price above the header
//   KEEP   headlineContentsNoTab.aspx       latest announcements in the hero
//
// Done as a server-side rewrite of the rendered HTML rather than by editing
// the Elementor JSON, because _elementor_data is one long JSON string where a
// single bad byte breaks the page. Reversible by deactivating the plugin, and
// it touches nothing the operator edits by hand.

function bullion_ops_weblink_deferred_needles() {
	// Substrings identifying the below-fold widgets. Deliberately specific:
	// "priceframe2.aspx" does NOT match the header's "priceframe.aspx".
	return [ 'priceframe2.aspx', 'chartformresponsive.aspx' ];
}

add_action( 'template_redirect', 'bullion_ops_weblink_lazy_start' );

function bullion_ops_weblink_lazy_start() {
	if ( is_admin() || ! is_front_page() ) {
		return;
	}
	// Never rewrite for logged-in editors - Elementor preview and the editor
	// itself should always see the real, un-deferred markup.
	if ( is_user_logged_in() ) {
		return;
	}
	ob_start( 'bullion_ops_weblink_lazy_filter' );
}

function bullion_ops_weblink_lazy_filter( $html ) {
	if ( stripos( $html, 'weblink' ) === false ) {
		return $html;
	}
	$needles = bullion_ops_weblink_deferred_needles();
	$count   = 0;

	$html = preg_replace_callback(
		'#<iframe\b[^>]*>#i',
		function ( $m ) use ( $needles, &$count ) {
			$tag = $m[0];
			if ( stripos( $tag, 'weblink' ) === false ) {
				return $tag;
			}
			$match = false;
			foreach ( $needles as $n ) {
				if ( stripos( $tag, $n ) !== false ) {
					$match = true;
					break;
				}
			}
			if ( ! $match || stripos( $tag, 'data-bullion-lazy' ) !== false ) {
				return $tag;
			}
			// src -> data-bullion-src so the browser does not fetch it yet.
			//
			// The lookbehind rather than a leading \s is deliberate. The real
			// markup on the QMines homepage is malformed, hand-typed into an
			// Elementor HTML widget:
			//     <iframe style=""src ="https://...priceframe2.aspx" ...>
			// There is NO space before src, and there IS one between src and
			// the equals sign. Browsers tolerate it; a '\ssrc=' pattern does
			// not match it at all. (?<![\w-]) still refuses to match srcset
			// or an already-rewritten data-bullion-src.
			$new = preg_replace( '#(?<![\w-])src\s*=#i', 'data-bullion-src=', $tag, 1 );
			if ( $new === null || $new === $tag ) {
				return $tag; // no src attribute found; leave untouched
			}
			$count++;
			return str_replace( '<iframe', '<iframe data-bullion-lazy="1" loading="lazy"', $new );
		},
		$html
	);

	if ( ! $count ) {
		return $html;
	}

	// Restore src shortly before each widget scrolls into view. 600px of
	// rootMargin means it is normally already loaded by the time it is seen.
	$js = <<<'JS'
<script id="bullion-ops-weblink-lazy">
(function(){
  var f = document.querySelectorAll('iframe[data-bullion-lazy]');
  if (!f.length) return;
  var load = function(el){
    var s = el.getAttribute('data-bullion-src');
    if (!s) return;
    el.removeAttribute('data-bullion-src');
    el.removeAttribute('data-bullion-lazy');
    el.setAttribute('src', s);
  };
  if (!('IntersectionObserver' in window)) {
    // Old browser: load everything now rather than leave blank frames.
    Array.prototype.forEach.call(f, load);
    return;
  }
  var io = new IntersectionObserver(function(entries){
    entries.forEach(function(e){
      if (e.isIntersecting) { load(e.target); io.unobserve(e.target); }
    });
  }, { rootMargin: '600px 0px' });
  Array.prototype.forEach.call(f, function(el){ io.observe(el); });
})();
</script>
JS;

	$pos = strripos( $html, '</body>' );
	if ( $pos === false ) {
		return $html . $js;
	}
	return substr( $html, 0, $pos ) . $js . substr( $html, $pos );
}

// --- asx_announcement CPT sitemap inclusion (v0.9.28) ----------------------
//
// Rank Math silently excludes CPTs that are not toggled ON in its Sitemap
// Settings UI. The asx_announcement CPT (registered by the ASX Webpage
// Pipeline) is public + has_archive but never appears in sitemap_index.xml
// because its per-CPT toggle defaults to off.
//
// v0.9.26/0.9.27 attempted this via a `rank_math/sitemap/post_types` filter
// but that filter name doesn't exist in Rank Math. RM enables CPT sitemaps
// via the `rank-math-options-sitemap` option (each CPT has a per-key toggle
// like `pt_asx_announcement_sitemap = 'on'`), NOT via a runtime filter.
//
// v0.9.28 approach: on init, ensure the option carries the correct toggle
// for asx_announcement. Only writes if the value isn't already 'on', so
// there's no per-request DB write cost.
//
// Belt-and-braces: keep the `rank_math/sitemap/urls` filter to inject any
// published announcement URL that still doesn't make it into the URL set.

add_action( 'init', function() {
	if ( ! post_type_exists( 'asx_announcement' ) ) {
		return;
	}
	$opts = get_option( 'rank-math-options-sitemap', [] );
	if ( ! is_array( $opts ) ) {
		$opts = [];
	}
	if ( ( $opts['pt_asx_announcement_sitemap'] ?? '' ) !== 'on' ) {
		$opts['pt_asx_announcement_sitemap'] = 'on';
		update_option( 'rank-math-options-sitemap', $opts );
	}
}, 20 );

add_filter( 'rank_math/sitemap/urls', function( $urls ) {
	$posts = get_posts( [
		'post_type'      => 'asx_announcement',
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'fields'         => 'ids',
	] );
	if ( empty( $posts ) ) {
		return $urls;
	}
	$existing = [];
	foreach ( $urls as $u ) {
		if ( isset( $u['loc'] ) ) {
			$existing[ $u['loc'] ] = true;
		}
	}
	foreach ( $posts as $pid ) {
		$robots = get_post_meta( $pid, 'rank_math_robots', true );
		if ( is_array( $robots ) && in_array( 'noindex', $robots, true ) ) {
			continue;
		}
		$link = get_permalink( $pid );
		if ( isset( $existing[ $link ] ) ) {
			continue;
		}
		$urls[] = [
			'loc' => $link,
			'mod' => get_the_modified_date( DATE_W3C, $pid ),
		];
	}
	return $urls;
}, 20 );

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

	$flushed = function_exists( 'bullion_ops_flush_wpcode_cache' )
		? bullion_ops_flush_wpcode_cache()
		: [];

	return [
		'ok'            => true,
		'snippet_id'    => $id,
		'replacements'  => $count,
		'before_length' => strlen( $before ),
		'after_length'  => strlen( $after ),
		'cache_flushed' => $flushed,
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
	// accidentally match the project slug array below. (v0.9.23)
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
	// FAQ CSS must not fire on asx_announcement CPT pages. (v0.9.23)
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
				[ 'q' => 'What is the Mt Chalmers copper-gold project?',        'a' => 'The Mt Chalmers copper-gold project is QMines\' flagship development asset in Central Queensland, located 17km north-east of Rockhampton. It is a past-producing high-grade copper and gold mine that operated between 1898 and 1982, now being redeveloped by QMines with a completed Pre-Feasibility Study, a declared Ore Reserve, and a Definitive Feasibility Study underway. The project holds a current JORC resource of 11.86Mt at 1.22% copper equivalent and forms the processing centre of QMines\' Multi-Project Copper & Gold Production Hub.' ],
				[ 'q' => 'Where is Mt Chalmers located?',                       'a' => 'Mt Chalmers is located 17km north-east of Rockhampton in Queensland, Australia. QMines holds 316km² of tenure at the project.' ],
				[ 'q' => 'What copper and gold does Mt Chalmers contain?',      'a' => 'Mt Chalmers contains copper, gold, zinc, silver, and pyrite mineralisation. The Pre-Feasibility Study defined a contained metal inventory of 65,000 tonnes of copper, 160,000 ounces of gold, 30,600 tonnes of zinc, 1.8 million ounces of silver, and 583,000 tonnes of pyrite.' ],
				[ 'q' => 'What is the JORC resource at Mt Chalmers?',           'a' => 'The Mt Chalmers Measured, Indicated and Inferred Resource stands at 11.86Mt at 1.22% contained copper equivalent. 84% of these Resources fall in the Measured and Indicated JORC categories, reflecting five resource upgrades completed in under three years.' ],
				[ 'q' => 'What did the Mt Chalmers Pre-Feasibility Study find?', 'a' => 'The Pre-Feasibility Study returned a pre-tax Net Present Value (NPV8) of $373 million and a 54% Internal Rate of Return, based on a stand-alone 1Mtpa processing plant with a 10.4 year initial mine life and a capital cost estimate of A$191 million. The study also declared a maiden Ore Reserve of 9.6Mt (Proved and Probable).' ],
				[ 'q' => 'When is the Mt Chalmers DFS due?',                    'a' => 'The Definitive Feasibility Study (DFS) for Mt Chalmers is currently underway. QMines announced a fully-funded DFS delivery program in 2026 and has committed to delivering the DFS results later that year. Updates are released via the ASX as the program progresses.' ],
				[ 'q' => 'What stage of development is Mt Chalmers at?',        'a' => 'Mt Chalmers is in the development phase with a completed Pre-Feasibility Study, declared Ore Reserve, and a Definitive Feasibility Study underway. QMines\' strategy involves transitioning the project toward production, leveraging its shallow high-grade open-pit geometry, coastal proximity, and existing infrastructure access.' ],
				[ 'q' => 'Who owns Mt Chalmers?',                               'a' => 'QMines Limited (ASX:QML) holds 100% of the Mt Chalmers project.' ],
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
			'in_summary' => 'Mt Mackenzie is a 100%-owned gold and silver project located approximately 140km northwest of Rockhampton, Queensland, carrying a JORC Mineral Resource of 5.2Mt at 1.01g/t gold and 6.7g/t silver for 170,000 ounces of gold and 1.12 million ounces of silver. The project holds a granted Mining Development Licence (MDL 2008), a completed Scoping Study, and freehold land, supporting its near-term development potential. Located 45km from Develin Creek, Mt Mackenzie forms part of QMines\' Multi-Project Copper &amp; Gold Production Hub in Central Queensland.',
			'faqs' => [
				[ 'q' => 'Where is the Mt Mackenzie project located?',          'a' => 'Mt Mackenzie is located approximately 140km northwest of Rockhampton in Queensland, Australia, and approximately 45km from QMines\' Develin Creek project.' ],
				[ 'q' => 'What commodities does Mt Mackenzie contain?',         'a' => 'Mt Mackenzie is a gold and silver project, with shallow high-grade oxide and primary mineralisation suited to open-pit mining.' ],
				[ 'q' => 'What is the JORC resource estimate for Mt Mackenzie?', 'a' => 'The Mt Mackenzie Mineral Resource Estimate stands at 5.2Mt at 1.01g/t gold and 6.7g/t silver, for 170,000 ounces of gold and approximately 1.12 million ounces of silver. Approximately 70% of the contained gold is classified in the Indicated JORC category, and the deposit remains open along strike and at depth.' ],
				[ 'q' => 'What approvals and studies has Mt Mackenzie completed?', 'a' => 'Mt Mackenzie holds a granted Mining Development Licence (MDL 2008), a completed Scoping Study, and freehold land. The project is currently undergoing further PFS-level work.' ],
				[ 'q' => 'When did QMines acquire Mt Mackenzie?',               'a' => 'QMines acquired Mt Mackenzie from Resources and Energy Group in mid-2025.' ],
				[ 'q' => 'How has the Mt Mackenzie resource grown since QMines acquired the project?', 'a' => 'Since acquiring the project, QMines has increased the Resource by 32% in contained gold and 30% in contained silver. The Resource now stands at 5.2Mt at 1.01g/t gold and 6.7g/t silver for 170,000 ounces of gold and 1.12 million ounces of silver, with approximately 70% of contained gold classified as Indicated.' ],
			],
		],
		// Google Ads landing page — dual-purpose investor/discovery page.
		// FAQPage schema injected here matches the 7 visible Q/A pairs in the
		// page body. No shortcode needed (FAQs are already in Elementor content).
		// Added v0.9.27 — 2026-07-27.
		'the-copper-gold-opportunity-hiding-in-plain-sight' => [
			'in_summary' => '',
			'faqs' => [
				[ 'q' => 'Why is QMines undervalued compared to its peers?',            'a' => 'QMines trades at an EV/Resource multiple of approximately 0.08x, roughly 75% below the average of comparable copper developer peers, despite delivering a completed Pre-Feasibility Study, resource upgrades, a maiden Ore Reserve, and a scalable development plan.' ],
				[ 'q' => 'What is driving the copper and gold opportunity right now?',  'a' => 'A structural copper deficit exists due to ageing global supply and limited new discoveries, while demand accelerates from electrification, electric vehicles, and renewables. Gold has reached record highs as a safe-haven asset. QMines provides exposure to both metals through its Queensland project portfolio.' ],
				[ 'q' => 'How advanced is the Mt Chalmers project?',                   'a' => 'Mt Chalmers is a historic high-grade copper-gold producer with a declared Ore Reserve of 9.6Mt, a completed Pre-Feasibility Study showing a $373 million pre-tax NPV (NPV8) and 54% IRR, and a Mining Lease Application in progress.' ],
				[ 'q' => 'What is the role of Develin Creek in the company\'s future?', 'a' => 'Develin Creek serves as a key growth engine with a 4.2Mt at 1.07% Cu resource and high-impact drill results showing near-surface mineralisation that could extend mine life and improve overall project economics when integrated into an updated study.' ],
				[ 'q' => 'Why is Mount Mackenzie so important?',                       'a' => 'Mount Mackenzie adds 129,000 ounces of gold and 862,000 ounces of silver to the portfolio, brings freehold land, provides operational synergies with the other Queensland projects, diversifies revenue streams, and strengthens regional scale.' ],
				[ 'q' => 'What is the exploration upside beyond current deposits?',    'a' => 'QMines controls brownfield and greenfield targets including Artillery Road, Woods Shaft, and Mt Warminster within haulage distance of the proposed central processing hub, with multiple untested anomalies representing a robust exploration pipeline.' ],
				[ 'q' => 'How high could QMines\' share price go?',                    'a' => 'East Coast Research\'s September 2024 report assigned a valuation target of $0.157 per share, representing 138% upside from the price at that time. This is a third-party analyst view, not a company forecast.' ],
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

// RETIRED v0.9.38. Rank Math now emits the correct trail on both the pillar
// (2 levels) and the cluster (3 levels: Home > pillar > cluster), verified on
// live 2026-08-04, and its version carries an @id where this one did not. Two
// BreadcrumbLists with the identical trail is redundant markup, so this hook
// is no longer registered. The builder below is kept, not deleted: if Rank
// Math's CPT breadcrumb handling regresses, re-adding the add_action line
// restores it. That regression is what this was written for (task #25).
//
// add_action( 'wp_head', 'bullion_ops_inject_pillar_breadcrumbs', 100 );

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
		'is-copper-a-good-investment',                    // Pillar
		'asx-copper-stocks',                              // Cluster
		'qmines-rapidly-advances-fully-funded-dfs-delivery', // ASX announcement — 28 H2s, TOC-worthy per seo-auditor 2026-07-22
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
	// Auto-enrol every ASX announcement CPT singular. Downstream guards
	// (4-H2 minimum, 40-H2 maximum in inject_toc_block) still apply, so
	// short announcements skip the TOC automatically. Added 2026-07-22
	// after per-slug enrolment proved too tedious across the announcement
	// backlog.
	if ( 'asx_announcement' === $post->post_type ) {
		return true;
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

	// ASX announcement pages: sit under the pill-tag meta block
	// (.asx-meta contains date / ticker / project / type pills). Landing
	// the TOC here keeps it INSIDE .asx-announcement-wrapper (correct
	// width + padding) and directly under the tag pills (correct
	// vertical order).
	$q_meta = '//*[contains(concat(" ", normalize-space(@class), " "), " asx-meta ")]';
	$hits   = $xpath->query( $q_meta );
	if ( $hits && $hits->length > 0 ) {
		return [ 'mode' => 'after', 'node' => $hits->item( 0 ) ];
	}

	// Generic announcement fallback: prepend inside .asx-announcement-wrapper
	// if a meta block isn't present.
	$q_ann  = '//*[contains(concat(" ", normalize-space(@class), " "), " asx-announcement-wrapper ")]';
	$hits   = $xpath->query( $q_ann );
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
	// Lower bound 4 (TOC pointless below that). Upper bound 40 —
	// substantive ASX announcements can legitimately have 25-30 H2s
	// (DFS update, quarterly, etc.). Was 20 originally; bumped 2026-07-22
	// after the DFS-delivery announcement (28 H2s) was rejected.
	if ( $count < 4 || $count > 40 ) {
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

// Enrolment check shared by the CSS + ItemList + Speakable emitters.
// Slug enrolment covers pillar / cluster; post_type covers every ASX
// announcement automatically (matches bullion_ops_toc_should_run).
function bullion_ops_toc_is_enrolled_context() {
	if ( ! is_singular() ) {
		return false;
	}
	$post = get_post();
	if ( ! $post ) {
		return false;
	}
	if ( 'asx_announcement' === $post->post_type ) {
		return true;
	}
	return in_array( $post->post_name, bullion_ops_get_toc_enrolled_slugs(), true );
}

// Compact-table styling for ASX announcement pages. Fires on wp_head for any
// singular asx_announcement so ANY table tagged with class="asx-table-compact"
// renders with compressed font-size + padding. Rule uses !important so it wins
// over the theme's default table styling regardless of specificity.
add_action( 'wp_head', 'bullion_ops_inject_asx_compact_table_css', 100 );

function bullion_ops_inject_asx_compact_table_css() {
	if ( ! is_singular( 'asx_announcement' ) ) {
		return;
	}
	?>
<style id="bullion-ops-asx-compact-table-css">
.asx-official-announcement table.asx-table-compact td {
	font-size: 11px !important;
	padding: 4px 8px !important;
	line-height: 1.3 !important;
}
.asx-official-announcement table.asx-table-compact thead th {
	font-size: 12px !important;
	padding: 5px 8px !important;
	line-height: 1.25 !important;
}
@media (max-width: 640px) {
	.asx-official-announcement table.asx-table-compact td {
		font-size: 10px !important;
		padding: 3px 6px !important;
	}
}
</style>
	<?php
}

function bullion_ops_inject_toc_css() {
	if ( ! bullion_ops_toc_is_enrolled_context() ) {
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
	if ( ! bullion_ops_toc_is_enrolled_context() ) {
		return;
	}
	$post    = get_post();
	$entries = bullion_ops_toc_extract_entries_from_content( $post->post_content );
	$count   = count( $entries );
	if ( $count < 4 || $count > 40 ) {
		return; // same gate as the visible TOC block (v0.9.19 raised cap 20 -> 40)
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
	if ( ! bullion_ops_toc_is_enrolled_context() ) {
		return;
	}
	// Carry Rank Math's WebPage @id so this MERGES into the existing node
	// instead of standing up a second, anonymous WebPage for the same URL.
	// Rank Math uses "<permalink>#webpage"; matching it means the speakable
	// property attaches to the page entity Google already knows about.
	// Without this the page served two WebPage entities (v0.9.38).
	$schema = [
		'@context'  => 'https://schema.org',
		'@type'     => 'WebPage',
		'@id'       => trailingslashit( get_permalink() ) . '#webpage',
		'speakable' => [
			'@type'       => 'SpeakableSpecification',
			'cssSelector' => [ '.bullion-ops-toc', '.entry-content > p:first-of-type' ],
		],
	];
	echo "\n<script type=\"application/ld+json\">"
		. wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
		. "</script>\n";
}

// --- Diagnostics: grep + wp_head hook dump (v0.9.36) -----------------------
//
// Built while hunting a stale Organization JSON-LD block that was rendering
// on every page. It was not in any WPCode snippet, not in the theme, not in
// this plugin, and not in the WPCode Header box — every candidate ruled out
// by inspection, with no way left to find it short of reading the whole
// install by hand. These two endpoints replace that guesswork.
//
//   GET /bullion/v1/grep?needle=Foo[&dir=wp-content][&ext=php,html]
//     Recursive literal search under WP_CONTENT_DIR. Returns file, line
//     number and a trimmed excerpt. Read-only.
//
//   GET /bullion/v1/head-hooks
//     Every callback registered on wp_head, in priority order, resolved to
//     the file and line it is defined in. Names the culprit when output
//     appears in the head and nothing obvious accounts for it.
//
// Both are admin-only via bullion_ops_permission.

function bullion_ops_grep( WP_REST_Request $req ) {
	$needle = (string) $req->get_param( 'needle' );
	if ( strlen( $needle ) < 3 ) {
		return new WP_Error( 'bullion_ops_bad_needle', 'needle must be at least 3 characters', [ 'status' => 400 ] );
	}

	// Confine the search to wp-content. realpath() then prefix-check defeats
	// any ../ traversal in the dir param.
	$base = realpath( WP_CONTENT_DIR );
	$sub  = (string) $req->get_param( 'dir' );
	$root = $sub ? realpath( WP_CONTENT_DIR . '/' . ltrim( str_replace( 'wp-content', '', $sub ), '/' ) ) : $base;
	if ( ! $root || strpos( $root, $base ) !== 0 ) {
		$root = $base;
	}

	$ext_param = (string) $req->get_param( 'ext' );
	$exts      = $ext_param ? array_filter( array_map( 'trim', explode( ',', $ext_param ) ) )
	                        : [ 'php', 'html', 'htm', 'js', 'json', 'txt' ];

	$hits    = [];
	$scanned = 0;
	$it      = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::LEAVES_ONLY
	);

	foreach ( $it as $file ) {
		if ( count( $hits ) >= 200 ) {
			break;
		}
		if ( ! $file->isFile() || $file->getSize() > 3145728 ) {
			continue;
		}
		if ( ! in_array( strtolower( $file->getExtension() ), $exts, true ) ) {
			continue;
		}
		$scanned++;
		$body = @file_get_contents( $file->getPathname() );
		if ( $body === false || strpos( $body, $needle ) === false ) {
			continue;
		}
		foreach ( explode( "\n", $body ) as $i => $line ) {
			if ( strpos( $line, $needle ) !== false ) {
				$hits[] = [
					'file'    => str_replace( WP_CONTENT_DIR, 'wp-content', $file->getPathname() ),
					'line'    => $i + 1,
					'excerpt' => trim( substr( $line, max( 0, strpos( $line, $needle ) - 60 ), 200 ) ),
				];
				if ( count( $hits ) >= 200 ) {
					break 2;
				}
			}
		}
	}

	return [
		'needle'        => $needle,
		'root'          => str_replace( WP_CONTENT_DIR, 'wp-content', $root ),
		'files_scanned' => $scanned,
		'hit_count'     => count( $hits ),
		'hits'          => $hits,
	];
}

// GET /bullion/v1/dbgrep?needle=Foo
//
// Companion to /grep. That one searches files; this searches the four
// tables that can hold executable or renderable markup: options, posts,
// postmeta and termmeta. Between them they cover every "code box" a plugin
// exposes in the admin — WPCode Header & Footer, Elementor custom code,
// theme mods, widget content, per-post schema overrides.
//
// Needed because the stale Organization block was in none of the 17,219
// files under wp-content, which meant it could only be in the database.
function bullion_ops_dbgrep( WP_REST_Request $req ) {
	global $wpdb;

	$needle = (string) $req->get_param( 'needle' );
	if ( strlen( $needle ) < 3 ) {
		return new WP_Error( 'bullion_ops_bad_needle', 'needle must be at least 3 characters', [ 'status' => 400 ] );
	}

	$like = '%' . $wpdb->esc_like( $needle ) . '%';
	$out  = [];

	$rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT option_id AS id, option_name AS label, option_value AS body
		 FROM {$wpdb->options} WHERE option_value LIKE %s LIMIT 40", $like
	) );
	foreach ( $rows as $r ) {
		$out[] = [ 'table' => 'options', 'id' => $r->id, 'label' => $r->label, 'size' => strlen( $r->body ) ];
	}

	$rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT ID AS id, post_title AS label, post_type, post_status, post_content AS body
		 FROM {$wpdb->posts} WHERE post_content LIKE %s LIMIT 40", $like
	) );
	foreach ( $rows as $r ) {
		$out[] = [
			'table' => 'posts', 'id' => $r->id, 'label' => $r->label,
			'post_type' => $r->post_type, 'post_status' => $r->post_status, 'size' => strlen( $r->body ),
		];
	}

	$rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT meta_id AS id, post_id, meta_key AS label, meta_value AS body
		 FROM {$wpdb->postmeta} WHERE meta_value LIKE %s LIMIT 40", $like
	) );
	foreach ( $rows as $r ) {
		$out[] = [ 'table' => 'postmeta', 'id' => $r->id, 'post_id' => $r->post_id, 'label' => $r->label, 'size' => strlen( $r->body ) ];
	}

	$rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT meta_id AS id, term_id, meta_key AS label, meta_value AS body
		 FROM {$wpdb->termmeta} WHERE meta_value LIKE %s LIMIT 20", $like
	) );
	foreach ( $rows as $r ) {
		$out[] = [ 'table' => 'termmeta', 'id' => $r->id, 'term_id' => $r->term_id, 'label' => $r->label, 'size' => strlen( $r->body ) ];
	}

	return [ 'needle' => $needle, 'hit_count' => count( $out ), 'hits' => $out ];
}

function bullion_ops_head_hooks() {
	global $wp_filter;

	if ( empty( $wp_filter['wp_head'] ) ) {
		return [ 'error' => 'no wp_head callbacks registered in this request context' ];
	}

	$out = [];
	foreach ( $wp_filter['wp_head']->callbacks as $priority => $callbacks ) {
		foreach ( $callbacks as $cb ) {
			$fn   = $cb['function'];
			$name = '(closure)';
			$ref  = null;

			try {
				if ( is_string( $fn ) ) {
					$name = $fn;
					$ref  = new ReflectionFunction( $fn );
				} elseif ( is_array( $fn ) ) {
					$cls  = is_object( $fn[0] ) ? get_class( $fn[0] ) : (string) $fn[0];
					$name = $cls . '::' . $fn[1];
					$ref  = new ReflectionMethod( $cls, $fn[1] );
				} elseif ( $fn instanceof Closure ) {
					$ref = new ReflectionFunction( $fn );
				}
			} catch ( Throwable $e ) {
				$ref = null;
			}

			$out[] = [
				'priority' => $priority,
				'callback' => $name,
				'file'     => $ref ? str_replace( ABSPATH, '', (string) $ref->getFileName() ) : null,
				'line'     => $ref ? $ref->getStartLine() : null,
			];
		}
	}

	return [ 'count' => count( $out ), 'callbacks' => $out ];
}

// --- Current year shortcode (v0.9.35) --------------------------------------
//
// [current_year] outputs the current four-digit year in the site's timezone,
// so footer copyright lines stop needing a manual edit every January.
//
// Usage in the Elementor footer text widget:
//   <p>&copy;[current_year] QMines Limited | All Rights Reserved</p>
//
// Site timezone matters here: a server on UTC would roll the year over at
// 10am Brisbane time on 1 January and show the wrong year for ten hours.
// date_i18n() respects the WordPress timezone setting; date() would not.
function bullion_ops_current_year_shortcode() {
	return date_i18n( 'Y' );
}
add_shortcode( 'current_year', 'bullion_ops_current_year_shortcode' );

// --- Insights grid shortcode (v0.9.23) -------------------------------------
//
// [insights_grid] renders a card grid of every pillar / cluster page enrolled
// in bullion_ops_get_pillar_hero_slugs() using the hand-coded
// .qm-insights-card / .qm-insights-card-visual / .qm-insights-card-body
// markup already used on the /insights/ hub page. Drop the shortcode wherever
// the manual `<div class="qm-insights-grid">` block sits and every future
// pillar / cluster added to the enrolment list appears automatically. The
// existing page's inline <style> block carries the visual design — no
// additional CSS is emitted from the plugin.
//
// Shortcode attributes (optional):
//   orderby="pillar-first|title|date|menu_order"  (default: pillar-first)
//   order="asc|desc"                              (default: asc)
//   exclude="slug1,slug2"                         (default: none)
//                                                 — omit pages from the grid,
//                                                 useful when the pillar is
//                                                 rendered separately as the
//                                                 Featured hero card.
//   wrap="1|0"                                    (default: 1)
//                                                 — 1 emits the outer
//                                                 <div class="qm-insights-grid">
//                                                 wrapper; 0 emits raw
//                                                 <article> cards for use
//                                                 inside a wrapper that
//                                                 already exists.
//
// Per-card content: featured image (srcset preserved), overlay text (from
// bullion_ops_get_insights_visual_text() slug map), tag label ("Pillar" for
// parent=0, "Cluster" for parent!=0), title, 20-word excerpt, meta line
// (read time + published date, both auto-computed).

add_shortcode( 'insights_grid', 'bullion_ops_render_insights_grid' );

function bullion_ops_render_insights_grid( $atts ) {
	$atts = shortcode_atts(
		[
			'orderby' => 'pillar-first',
			'order'   => 'asc',
			'exclude' => '',
			'wrap'    => '1',
		],
		$atts,
		'insights_grid'
	);

	$slugs = bullion_ops_get_pillar_hero_slugs();
	if ( empty( $slugs ) ) {
		return '';
	}
	$exclude_slugs = array_filter( array_map( 'trim', explode( ',', (string) $atts['exclude'] ) ) );
	if ( ! empty( $exclude_slugs ) ) {
		$slugs = array_values( array_diff( $slugs, $exclude_slugs ) );
	}
	if ( empty( $slugs ) ) {
		return '';
	}

	$query_args = [
		'post_type'      => 'page',
		'post_status'    => 'publish',
		'post_name__in'  => array_values( $slugs ),
		'posts_per_page' => -1,
		'no_found_rows'  => true,
	];
	if ( 'pillar-first' === $atts['orderby'] ) {
		$query_args['orderby'] = 'parent';
		$query_args['order']   = 'ASC';
	} elseif ( in_array( $atts['orderby'], [ 'title', 'date', 'menu_order' ], true ) ) {
		$query_args['orderby'] = $atts['orderby'];
		$query_args['order']   = strtoupper( $atts['order'] );
	}

	$q = new WP_Query( $query_args );
	if ( ! $q->have_posts() ) {
		return '';
	}

	$cards = '';
	while ( $q->have_posts() ) {
		$q->the_post();
		$id       = get_the_ID();
		$post_obj = get_post( $id );
		$link     = get_permalink( $id );
		$title    = get_the_title( $id );
		// Prefer the page's custom Excerpt field (post_excerpt) if the
		// operator has set one — that's the bespoke preview text control.
		// Fall back to auto-generated 20-word summary otherwise.
		$raw_excerpt = trim( (string) $post_obj->post_excerpt );
		if ( '' !== $raw_excerpt ) {
			$excerpt = strip_shortcodes( wp_strip_all_tags( $raw_excerpt ) );
		} else {
			$excerpt = wp_trim_words( strip_shortcodes( wp_strip_all_tags( get_the_excerpt( $id ) ) ), 20, '&hellip;' );
		}
		// Reader-facing content-type labels — "Pillar" / "Cluster" are SEO
		// jargon and mean nothing to a public reader. "Deep Dive" signals
		// long-form definitive coverage; "Guide" signals a focused, more
		// scoped piece. Reasoning captured 2026-07-22.
		$tag      = ( 0 === (int) $post_obj->post_parent ) ? 'Deep Dive' : 'Guide';
		$visual   = bullion_ops_get_insights_visual_text( $post_obj->post_name );

		$thumb_html = has_post_thumbnail( $id )
			? get_the_post_thumbnail( $id, 'medium_large', [ 'loading' => 'lazy', 'decoding' => 'async' ] )
			: '';

		$words = str_word_count( wp_strip_all_tags( strip_shortcodes( $post_obj->post_content ) ) );
		$mins  = max( 1, (int) round( ( $words > 0 ? $words : 1 ) / 250 ) );
		$pub   = date_i18n( 'j M Y', strtotime( $post_obj->post_date ) );

		$visual_text_html = $visual
			? '<div class="qm-insights-card-visual-text">' . esc_html( $visual ) . '</div>'
			: '';

		// Footer row: meta line on left, "Read more" CTA on right. Inline
		// styles guarantee the layout survives WP Rocket UsedCSS strip; the
		// existing /insights/ page CSS provides the base look, the inline
		// styles here add the flex + CTA that isn't in that CSS yet.
		$footer_style   = 'display:flex;align-items:baseline;justify-content:space-between;gap:12px;margin-top:auto;';
		$cta_link_style = 'flex-shrink:0;font-size:13px;font-weight:600;color:#30a759;text-decoration:none;white-space:nowrap;';

		$cards .= '<article class="qm-insights-card">'
			. '<div class="qm-insights-card-visual">'
			. $thumb_html
			. $visual_text_html
			. '</div>'
			. '<div class="qm-insights-card-body">'
			. '<span class="qm-insights-card-tag">' . esc_html( $tag ) . '</span>'
			. '<h3><a href="' . esc_url( $link ) . '">' . esc_html( $title ) . '</a></h3>'
			. '<p class="qm-insights-card-excerpt">' . esc_html( $excerpt ) . '</p>'
			. '<div class="qm-insights-card-footer" style="' . esc_attr( $footer_style ) . '">'
			. '<p class="qm-insights-card-meta" style="margin:0;"><span>' . $mins . ' min read</span><span>' . esc_html( $pub ) . '</span></p>'
			. '<a class="qm-insights-card-cta" href="' . esc_url( $link ) . '" style="' . esc_attr( $cta_link_style ) . '">Read me &rarr;</a>'
			. '</div>'
			. '</div>'
			. '</article>';
	}
	wp_reset_postdata();

	if ( '1' === (string) $atts['wrap'] ) {
		return '<div class="qm-insights-grid">' . $cards . '</div>';
	}
	return $cards;
}

// Slug-based overlay-text map for the .qm-insights-card-visual-text label
// that hovers over the card thumbnail. Extend as new pillars / clusters
// ship. Empty string means the card renders without an overlay.
function bullion_ops_get_insights_visual_text( $slug ) {
	$map = [
		'is-copper-a-good-investment' => 'Why Copper',
		'asx-copper-stocks'            => 'ASX Copper Stocks',
	];
	return isset( $map[ $slug ] ) ? $map[ $slug ] : '';
}

// --- ASX announcement page-enhancement scripts (v0.9.23) -------------------
//
// Emits the JS enhancement bundle (blockquote wrapping around management
// quotes + thead-th unit line-break formatting) via `wp_footer` on every
// singular asx_announcement page. Previously the same script was emitted
// inline in post_content by the ASX Webpage Pipeline; wpautop mangled it
// on the DFS-delivery announcement (2026-07-22), leaking the raw JS
// source as visible text at the bottom of the article. Moving the emitter
// into the plugin footer hook makes it wpautop-proof.

// ---------------------------------------------------------------------------
// asx_announcement ARCHIVE (/asx-announcements/)
// ---------------------------------------------------------------------------
// Three fixes, all 2026-07-31. QMines runs TWO announcement archives on
// purpose and they are not duplicates of each other:
//
//   /announcements/      the WebLink feed of PDFs exactly as lodged with ASX
//   /asx-announcements/  the readable HTML pages, with plain-English summary,
//                        key figures and charts
//
// Nothing on either page said which was which, so they read as duplicates to
// a crawler while serving genuinely different readers. /announcements/ is an
// Elementor page and was fixed in the editor data; this archive is rendered
// from a template, so its fixes belong here.

/**
 * Drop WordPress's "Archives:" prefix from the announcement archive H1.
 *
 * The theme renders `get_the_archive_title()` verbatim, so the H1 read
 * "Archives: ASX Announcements". That prefix is core boilerplate, not copy
 * anyone wrote, and it pushes the keyword to third word.
 */
add_filter( 'get_the_archive_title', 'bullion_ops_asx_archive_title' );

function bullion_ops_asx_archive_title( $title ) {
	if ( ! is_post_type_archive( 'asx_announcement' ) ) {
		return $title;
	}
	return 'QMines ASX Announcements';
}

/**
 * Give the archive its own title tag and meta description.
 *
 * Rank Math has no per-object meta for a CPT archive, so this is a filter
 * rather than a stored value. Both strings deliberately say "read" and
 * "online" where the PDF archive says "download" and "PDF": that contrast
 * is the whole point, and without it Google has to guess which page answers
 * which intent.
 */
add_filter( 'rank_math/frontend/title', 'bullion_ops_asx_archive_seo_title' );

function bullion_ops_asx_archive_seo_title( $title ) {
	if ( ! is_post_type_archive( 'asx_announcement' ) ) {
		return $title;
	}
	return 'Read QMines ASX Announcements Online | ASX:QML';
}

add_filter( 'rank_math/frontend/description', 'bullion_ops_asx_archive_seo_desc' );

function bullion_ops_asx_archive_seo_desc( $desc ) {
	if ( ! is_post_type_archive( 'asx_announcement' ) ) {
		return $desc;
	}
	return 'Every QMines (ASX:QML) announcement as a readable web page, with a plain-English summary, key figures and charts. No PDF download required.';
}

/**
 * Reciprocal link back to the PDF archive.
 *
 * /announcements/ links here; without the return leg, anyone who lands on
 * the readable pages looking for the lodged document has nowhere to go.
 *
 * Rendered server-side on `loop_start` rather than injected with JS. The
 * theme offers no hook between the page header and the post list, and a
 * client-side DOM insert is exactly the pattern that produced the runaway
 * blockquote fixed in v0.9.30. Server-rendered means it is also crawlable,
 * which is the point of adding it.
 */
add_action( 'loop_start', 'bullion_ops_asx_archive_pdf_link' );

function bullion_ops_asx_archive_pdf_link( $query ) {
	if ( ! is_post_type_archive( 'asx_announcement' ) || ! $query->is_main_query() || is_admin() ) {
		return;
	}
	// loop_start fires per loop; only decorate the first one on the page.
	static $done = false;
	if ( $done ) {
		return;
	}
	$done = true;

	// 16px radius, no accent border: matches the dominant card treatment on
	// this page (7 other elements use 16px). Operator call 2026-07-31 — the
	// green left rule read as a callout/alert, which this is not.
	echo '<p class="bullion-archive-crosslink" style="margin:0 0 2em;padding:1em 1.25em;background:#f4f6f7;border-radius:16px;">'
		. 'Looking for the original lodged documents? '
		. '<a href="' . esc_url( home_url( '/announcements/' ) ) . '">Download QMines ASX announcement PDFs</a>.'
		. '</p>';
}

add_action( 'wp_footer', 'bullion_ops_inject_asx_announcement_scripts', 20 );

function bullion_ops_inject_asx_announcement_scripts() {
	if ( ! is_singular( 'asx_announcement' ) ) {
		return;
	}
	?>
<script id="bullion-ops-asx-announcement-enhancer">
document.addEventListener('DOMContentLoaded', function() {

	// Wrap consecutive quoted <p> paragraphs inside .asx-official-announcement
	// in a <blockquote> so management comments render as blockquotes.
	//
	// Three guards, all added 2026-07-31 after the "Resource Growth Drilling"
	// announcement rendered its blockquote over most of the page and dragged
	// the figures out of position with it. The management comment had five
	// paragraphs; the fifth ended `...plans”.` with the full stop AFTER the
	// closing quote, so endsWith('”') was false, the blockquote never closed,
	// and every following paragraph and figure was absorbed into it.
	(function() {
		var body = document.querySelector('.asx-official-announcement');
		if (!body) return;
		var paras = Array.from(body.querySelectorAll('p'));
		var currentBQ = null;

		// Guard 2: a closing quote mark followed by trailing punctuation still
		// closes the quote. Covers `plans”.` as well as `plans.”`.
		function closesQuote(text) {
			return /[”"][\s.,;:!?]*$/.test(text);
		}

		paras.forEach(function(p) {
			// Guard 1: never re-wrap a paragraph the server already placed in
			// a blockquote. Double-wrapping was what put the runaway quote in
			// a position to swallow siblings in the first place.
			if (p.closest('blockquote')) { currentBQ = null; return; }

			var text = p.textContent.trim();
			var opensQuote = text.startsWith('“') || text.startsWith('"');

			// Guard 3: a blockquote may only ever absorb the paragraph that
			// immediately follows it. Anything else in between (a figure, a
			// heading, a table) ends the quote. Even if the closing mark is
			// missing entirely, a runaway can now swallow at most the rest of
			// one uninterrupted paragraph run, never a figure.
			if (currentBQ && p.previousElementSibling !== currentBQ) {
				currentBQ = null;
			}

			if (opensQuote) {
				currentBQ = document.createElement('blockquote');
				p.parentNode.insertBefore(currentBQ, p);
				currentBQ.appendChild(p);
				if (closesQuote(text)) currentBQ = null;
			} else if (currentBQ) {
				currentBQ.appendChild(p);
				if (closesQuote(text)) currentBQ = null;
			}
		});
	})();

	// Split thead th "Label (unit)" pattern into two visual lines, and
	// style "Not in Mine Plan" columns for the Ore Reserve tables.
	document.querySelectorAll('.asx-official-announcement thead th').forEach(function(th) {
		var originalText = th.textContent.trim();
		var words = originalText.replace(/\s+/g, ' ').split(' ');
		if (originalText === 'Not in Mine Plan') {
			th.style.setProperty('background', '#e8eced', 'important');
			th.style.setProperty('color', '#142934', 'important');
			th.style.border = '1px solid #dee2e6';
			var table = th.closest('table');
			var colIndex = Array.from(th.parentElement.children).indexOf(th);
			table.querySelectorAll('tbody tr').forEach(function(row) {
				var cell = row.children[colIndex];
				if (cell) {
					cell.style.setProperty('background', '#e8eced', 'important');
					cell.style.setProperty('color', '#142934', 'important');
					cell.style.fontStyle = 'italic';
				}
			});
			th.innerHTML = 'Not in<br>Mine Plan';
			return;
		}
		var html = th.innerHTML.replace(/\s+(\([^)]+\))/g, '<br><span style="font-weight:400;opacity:0.85;">$1</span>');
		if (!html.includes('<br>') && words.length >= 2 && originalText.length > 10) {
			var mid = Math.ceil(words.length / 2);
			html = words.slice(0, mid).join(' ') + '<br>' + words.slice(mid).join(' ');
		}
		th.innerHTML = html;
	});
});
</script>
	<?php
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

// --- v0.9.39: decode HTML entities inside JSON-LD string values -------------
//
// JSON-LD is not HTML. Rank Math builds its @graph from post titles that have
// already been HTML-escaped, so a title containing "&" ships as the literal
// characters "&amp;" (or "&#038;") inside the JSON string — and that is what a
// rich result renders, ampersand codes and all.
//
// Verified 5 Aug 2026 on the QIC-inspect announcement: six affected strings,
// including NewsArticle.headline and WebPage.name reading "Queensland
// Government &amp; QIC Inspect Mt Chalmers | ASX:QML", plus the BreadcrumbList
// leaf carrying "&#038;". Every page whose title contains an ampersand is
// affected, so this is fixed centrally rather than by rewriting titles.
//
// Walks the whole graph because the offending strings sit at several depths:
// top-level headline, nested WebPage.name, and inside BreadcrumbList
// itemListElement. URLs are left alone — decoding is a no-op on a clean URL,
// but skipping them avoids touching anything that legitimately contains an
// encoded character.
function bullion_ops_decode_jsonld_entities( $value ) {
	if ( is_array( $value ) ) {
		foreach ( $value as $k => $v ) {
			$value[ $k ] = bullion_ops_decode_jsonld_entities( $v );
		}
		return $value;
	}

	if ( ! is_string( $value ) || strpos( $value, '&' ) === false ) {
		return $value;
	}

	// URLs: decode ampersand entities only. A query string that reaches a
	// consumer as "?s=96&#038;d=mm" is a broken URL, so this does matter — but
	// a full entity decode could corrupt a legitimately percent- or
	// entity-encoded path, so it stays narrow.
	if ( preg_match( '#^https?://#i', $value ) ) {
		return str_replace( [ '&amp;', '&#038;' ], '&', $value );
	}

	return html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
}

// --- v0.9.44: apply the decode at OUTPUT, not via rank_math/json_ld ---------
//
// v0.9.39 hooked `rank_math/json_ld` at priority 99 and was architecturally
// incapable of working. Rank Math serialises the graph in
// includes/modules/schema/class-jsonld.php line 164:
//
//     wp_json_encode( wp_kses_post_deep( $json ), $options )
//
// `wp_kses_post_deep()` runs `wp_kses_normalize_entities()` over every string,
// which re-encodes each bare "&" back to "&amp;" — AFTER every
// `rank_math/json_ld` filter has run. class-jsonld.php contains no
// `apply_filters` at all, so there is no hook downstream of it.
//
// Proof this was the mechanism, 7 Aug 2026: the v0.9.43 filter (priority 97)
// writes NewsArticle.headline from `html_entity_decode( get_the_title() )`,
// and the served page still carried "&amp;" in that exact field. We wrote a
// decoded string and the output was encoded, so the re-encode had to be
// downstream of the filter chain. Confirmed on an uncached fetch, so it was
// not a stale page cache either.
//
// The fix buffers wp_head only, then re-encodes each application/ld+json
// block from its parsed form. Parsing first means a block that is not valid
// JSON is passed through untouched rather than mangled by a regex.
//
// Task #64 was closed on 5 Aug on the strength of the v0.9.39 filter existing,
// without checking the served output. The SEO auditor kept reporting it and
// was right each time.

add_action( 'wp_head', 'bullion_ops_jsonld_ob_start', 0 );
add_action( 'wp_head', 'bullion_ops_jsonld_ob_end', PHP_INT_MAX );

function bullion_ops_jsonld_ob_start() {
	ob_start( 'bullion_ops_rewrite_jsonld_entities' );
	$GLOBALS['bullion_ops_jsonld_ob_level'] = ob_get_level();
}

function bullion_ops_jsonld_ob_end() {
	$target = isset( $GLOBALS['bullion_ops_jsonld_ob_level'] )
		? $GLOBALS['bullion_ops_jsonld_ob_level']
		: null;

	// Only close our own buffer, and only if it is the innermost one. If
	// another plugin opened one inside wp_head and left it open, do nothing —
	// PHP flushes at shutdown, so the page still renders in full and we simply
	// skip the rewrite. Blindly unwinding the stack here could swallow another
	// plugin's output.
	if ( $target !== null && ob_get_level() === $target ) {
		ob_end_flush();
		$GLOBALS['bullion_ops_jsonld_ob_level'] = null;
	}
}

function bullion_ops_rewrite_jsonld_entities( $html ) {
	if ( ! is_string( $html ) || strpos( $html, 'application/ld+json' ) === false ) {
		return $html;
	}

	$out = preg_replace_callback(
		'#(<script[^>]*application/ld\+json[^>]*>)(.*?)(</script>)#is',
		function ( $m ) {
			$decoded = json_decode( $m[2], true );
			if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $decoded ) ) {
				return $m[0];
			}
			$json = wp_json_encode(
				bullion_ops_decode_jsonld_entities( $decoded ),
				JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
			);
			return is_string( $json ) ? $m[1] . $json . $m[3] : $m[0];
		},
		$html
	);

	// preg_replace_callback returns null on backtrack-limit failure. Never
	// return null from an output-buffer callback — it would blank the head.
	return is_string( $out ) ? $out : $html;
}

// --- v0.9.39: enrich Rank Math's Organization node -------------------------
//
// Rank Math publishes an Organization with name, url, address, logo, phone and
// description, but NOT `sameAs` (the social profiles) or `tickerSymbol`. Those
// two were supplied by WPCode snippet 18133, which wrapped them in a second
// standalone WebSite entity — so the site served two WebSite nodes, and the
// audit's advice to simply delete the snippet would have taken the socials and
// the ASX ticker down with it.
//
// Moving them here puts the company's identity schema in version control
// instead of an un-diffable WPCode snippet, and lets 18133 be deleted safely.
// Merges rather than overwrites: anything Rank Math already sets wins, so this
// cannot silently clobber a value set in the Rank Math UI.
function bullion_ops_organization_identity() {
	return [
		'alternateName' => 'QMines',
		'tickerSymbol'  => 'ASX:QML',
		'sameAs'        => [
			'https://www.facebook.com/qmines',
			'https://www.linkedin.com/company/qmines',
			'https://twitter.com/QminesL',
			'https://www.youtube.com/@QMines',
		],
	];
}

add_filter( 'rank_math/json_ld', function( $data, $jsonld ) {
	if ( ! is_array( $data ) ) {
		return $data;
	}
	foreach ( $data as $key => $node ) {
		if ( ! is_array( $node ) ) {
			continue;
		}
		$is_org = ( isset( $node['@type'] ) && 'Organization' === $node['@type'] )
			|| ( isset( $node['@id'] ) && substr( $node['@id'], -14 ) === '#organization' );
		if ( ! $is_org ) {
			continue;
		}
		foreach ( bullion_ops_organization_identity() as $prop => $value ) {
			if ( empty( $node[ $prop ] ) ) {
				$data[ $key ][ $prop ] = $value;
			}
		}
	}
	return $data;
}, 98, 2 );

// --- v0.9.39: flush WPCode's snippet cache after a remote edit --------------
//
// WPCode renders from a cached option, not from post_content, so the
// search-replace endpoint above wrote to the database and changed nothing on
// the page until someone opened the snippet in wp-admin and pressed Save.
// That made remote snippet edits look like they had worked when they had not.
function bullion_ops_flush_wpcode_cache() {
	$result = [ 'rebuilt' => false, 'method' => null, 'warning' => null ];

	// Use WPCode's own cache API if it exposes one. This is the ONLY safe way
	// to make a remote edit take effect.
	if ( function_exists( 'wpcode' ) ) {
		$wpcode = wpcode();
		if ( is_object( $wpcode ) && isset( $wpcode->cache ) && is_object( $wpcode->cache ) ) {
			foreach ( [ 'clear_all', 'clear' ] as $method ) {
				if ( method_exists( $wpcode->cache, $method ) ) {
					$wpcode->cache->$method();
					$result['rebuilt'] = true;
					$result['method']  = 'wpcode->cache->' . $method;
					return $result;
				}
			}
		}
	}

	// NEVER delete the wpcode_snippets option.
	//
	// v0.9.39 did exactly that, on the assumption the name meant a disposable
	// cache. It is the compiled registry WPCode executes from, and it does not
	// rebuild on a front-end request. Deleting it on 5 Aug 2026 left every
	// snippet on qmines.com.au and dev intact in the database but none of them
	// running — no announcement TOC, no compact-table CSS, no FAQPage or
	// ItemList schema, no ticker bar, no script-delay performance snippets —
	// until an operator opened wp-admin and pressed Update. The site looked
	// healthy the whole time: HTTP 200, full page weight, no error.
	//
	// If WPCode exposes no cache API, the honest answer is that a remote edit
	// cannot take effect on its own. Say so, rather than reaching for the
	// option and hoping.
	$result['warning'] = 'WPCode exposes no cache API on this install; the '
		. 'snippet was written to the database but will not render until an '
		. 'admin opens the snippet in wp-admin and clicks Update. Do not '
		. 'delete the wpcode_snippets option to force it.';

	return $result;
}


// --- v0.9.41: drop the theme's duplicate meta description ------------------
//
// Hello Elementor emits its own <meta name="description"> from the post
// excerpt, at wp_head priority 10 (hello-elementor/functions.php:199). Rank
// Math has already written one, so every page carrying an excerpt serves two
// competing description tags.
//
// Only announcement pages are affected in practice, because only they carry
// excerpts — all 10 of them, on live and dev, verified 5 Aug 2026 via the
// /head-hooks endpoint and by counting the rendered tags.
//
// Guarded on Rank Math being active: if it is ever disabled, the theme's tag
// is the only description the page would have, and removing it unguarded
// would silently strip the description site-wide instead of de-duplicating it.
add_action( 'wp_head', function() {
	if ( ! defined( 'RANK_MATH_VERSION' ) ) {
		return;
	}
	remove_action( 'wp_head', 'hello_elementor_add_description_meta_tag', 10 );
}, 1 );

// --- v0.9.42: expose Rank Math meta on pages to the REST API ---------------
//
// Rank Math stores its title and description in postmeta but does not
// register those keys for REST on the `page` post type. A PATCH to
// /wp/v2/pages/<id> carrying meta.rank_math_title therefore returns 200 and
// silently discards the value — the write looks like it worked and the
// database is unchanged.
//
// Hit on 5 Aug 2026 updating /mt-mackenzie/, whose meta title still read
// "Mt Mackenzie: 129koz Gold Project" after the resource was upgraded to
// 170koz. Same shape as the WPCode registry and the Elementor render source:
// the write is accepted, nothing changes, and nothing reports an error.
//
// Registering them makes the meta writable by the same tooling that already
// updates announcement pages, where these keys are registered for the CPT.
add_action( 'init', function() {
	$keys = [
		'rank_math_title'         => 'string',
		'rank_math_description'   => 'string',
		'rank_math_focus_keyword' => 'string',
	];
	foreach ( $keys as $key => $type ) {
		register_post_meta( 'page', $key, [
			'type'          => $type,
			'single'        => true,
			'show_in_rest'  => true,
			'auth_callback' => function() {
				return current_user_can( 'edit_posts' );
			},
		] );
	}
}, 20 );

// --- v0.9.43: NewsArticle headline is the announcement, not the SEO title ---
//
// Rank Math builds NewsArticle.headline from the SEO title, which is written
// to fit a search result — 52 characters ending "| ASX:QML". On announcement
// pages that produces a headline cut mid-sentence with a ticker separator
// glued to it:
//
//   schema : "MT MACKENZIE RESOURCE UPGRADE DELIVERS 32% | ASX:QML"   (52)
//   actual : "MT MACKENZIE RESOURCE UPGRADE DELIVERS 32% INCREASE IN
//             GOLD WITH 70% NOW IN INDICATED"                         (85)
//
// Google News and AI engines read the schema headline, so they were being
// handed a sentence that stops at "32%" and a stray "| ASX:QML". The post
// title is the announcement headline as lodged, which is what belongs here.
//
// Applies to asx_announcement only. On ordinary pages the SEO title is a
// deliberate choice and should be left alone.
add_filter( 'rank_math/json_ld', function( $data, $jsonld ) {
	if ( ! is_array( $data ) || ! is_singular( 'asx_announcement' ) ) {
		return $data;
	}
	$title = get_the_title();
	if ( ! $title ) {
		return $data;
	}
	$title = html_entity_decode( wp_strip_all_tags( $title ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );

	foreach ( $data as $key => $node ) {
		if ( ! is_array( $node ) || empty( $node['@type'] ) ) {
			continue;
		}
		$types = (array) $node['@type'];
		if ( in_array( 'NewsArticle', $types, true ) || in_array( 'Article', $types, true ) ) {
			$data[ $key ]['headline'] = $title;
			// name mirrors headline on these nodes; keep them consistent.
			if ( isset( $data[ $key ]['name'] ) ) {
				$data[ $key ]['name'] = $title;
			}
		}
	}
	return $data;
}, 97, 2 );
