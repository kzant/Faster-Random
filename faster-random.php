<?php
/**
 * Plugin Name:       Faster Random
 * Description:       Speeds up the /random/ redirect in WordPress 7.2 by replacing ORDER BY RAND() with cheaper ways to pick one post.
 * Version:           1.0.0
 * Requires at least: 7.2
 * Requires PHP:      7.4
 * Author:            Kathy Zant
 * License:           GPL-2.0-or-later
 * Text Domain:       faster-random
 */

defined( 'ABSPATH' ) || exit;

/**
 * Core picks the random post with ORDER BY RAND(), which reads every eligible
 * post and rolls a die for each one. This plugin picks the post ID first, a
 * cheaper way, then lets core's own query fetch that one post. Everything
 * else about the request is untouched: the same eligibility rules apply, the
 * same 302 redirect and headers are sent, and other plugins' pre_get_posts
 * filters (category exclusions and so on) are respected.
 *
 * If anything goes wrong or a strategy finds nothing, the plugin steps aside
 * and core's RAND() query runs as usual.
 *
 * Strategies, set with the faster_random_strategy filter:
 *
 *   cache  A cached list of eligible IDs, one array lookup per request.
 *          The default when a persistent object cache (Redis, Memcached) is in use.
 *   probe  Test random IDs with primary key lookups. The default otherwise.
 *   core   Do nothing. Useful for comparing against the original.
 */

/**
 * Stops WordPress from caching the result of the single-post lookup.
 *
 * Core skips its query cache for RAND() queries. Once the query asks for one
 * specific ID it becomes cacheable, and every post would get its own cache entry.
 */
function faster_random_pre_get_posts( $query ) {
	if ( function_exists( 'wp_is_random_content_query' ) && wp_is_random_content_query( $query ) ) {
		$query->set( 'cache_results', false );
	}
}
// Core adds its own pre_get_posts callback at priority 20. Run after it.
add_action( 'pre_get_posts', 'faster_random_pre_get_posts', 30 );

/**
 * Replaces the random ordering with a lookup of one chosen post ID.
 *
 * @param string[] $clauses The query clauses (where, join, orderby, limits and so on).
 * @param WP_Query $query   The query.
 * @return string[]
 */
function faster_random_clauses( $clauses, $query ) {
	global $wpdb;

	// Only touch the random content query. Everything else passes through.
	if ( ! function_exists( 'wp_is_random_content_query' ) || ! wp_is_random_content_query( $query ) ) {
		return $clauses;
	}

	$default  = wp_using_ext_object_cache() ? 'cache' : 'probe';
	$strategy = apply_filters( 'faster_random_strategy', $default );

	switch ( $strategy ) {
		case 'cache':
			$id = faster_random_pick_cached( $clauses );
			break;
		case 'probe':
			$id = faster_random_pick_probe( $clauses );
			break;
		default:
			$id = null;
	}

	// No answer? Leave the clauses alone and core runs its RAND() query.
	if ( ! $id ) {
		return $clauses;
	}

	// Keep core's eligibility rules and ask for just the chosen post.
	$clauses['where']  .= ' AND ' . $wpdb->posts . '.ID = ' . (int) $id;
	$clauses['orderby'] = '';
	$clauses['limits']  = 'LIMIT 0, 1';

	return $clauses;
}
// Core adds its RAND() and LIMIT filters at priority 20. Run after them.
add_filter( 'posts_clauses', 'faster_random_clauses', 30, 2 );

/**
 * Strategy: probe.
 *
 * Draw random IDs between the smallest and largest post ID, and check which
 * ones are eligible posts. Each check is a primary key lookup, so the cost
 * does not grow with the number of posts. Every eligible post is equally
 * likely to be drawn, so the pick is fair.
 *
 * The posts table also holds revisions, pages, attachments and menu items,
 * so many guesses miss. Guessing 40 at a time and trying up to 5 times makes
 * a complete miss very unlikely on the site-wide /random/. Narrow archives,
 * like a small category, miss often. When all five rounds miss, core's
 * RAND() query runs, so the answer is still correct, just not faster.
 */
function faster_random_pick_probe( $clauses ) {
	global $wpdb;

	$bounds = $wpdb->get_row( "SELECT MIN(ID) AS lo, MAX(ID) AS hi FROM {$wpdb->posts}" );
	if ( ! $bounds || ! $bounds->hi ) {
		return null;
	}

	for ( $round = 0; $round < 5; $round++ ) {
		$guesses = array();
		for ( $i = 0; $i < 40; $i++ ) {
			$guesses[] = (int) wp_rand( (int) $bounds->lo, (int) $bounds->hi );
		}

		$hits = $wpdb->get_col(
			"SELECT DISTINCT {$wpdb->posts}.ID FROM {$wpdb->posts} {$clauses['join']}
			 WHERE 1=1 {$clauses['where']}
			 AND {$wpdb->posts}.ID IN (" . implode( ',', $guesses ) . ')'
		);

		if ( $hits ) {
			// Any hit is as likely as any other, so a random hit is a fair pick.
			return (int) $hits[ array_rand( $hits ) ];
		}
	}

	return null;
}

/**
 * Strategy: cache.
 *
 * Keep the list of eligible IDs in the object cache, packed as 4 bytes per ID
 * (about 200 KB for 50,000 posts), and pick a random slot. The list is keyed
 * by the exact query conditions, so another plugin changing which posts are
 * eligible gets its own list, and by core's "posts last changed" marker, so
 * publishing, editing, trashing or deleting a post rebuilds it on the next visit.
 *
 * Needs a persistent object cache. Without one the list would be rebuilt on
 * every request, which is slower than core, so this strategy steps aside.
 */
function faster_random_pick_cached( $clauses ) {
	global $wpdb;

	if ( ! wp_using_ext_object_cache() ) {
		return null;
	}

	$key  = md5( $clauses['join'] . $clauses['where'] ) . ':' . wp_cache_get_last_changed( 'posts' );
	$list = wp_cache_get( $key, 'faster_random' );

	if ( ! is_string( $list ) ) {
		$ids = $wpdb->get_col(
			"SELECT DISTINCT {$wpdb->posts}.ID FROM {$wpdb->posts} {$clauses['join']}
			 WHERE 1=1 {$clauses['where']}"
		);

		$list = '';
		foreach ( array_chunk( array_map( 'intval', $ids ), 10000 ) as $chunk ) {
			$list .= pack( 'N*', ...$chunk );
		}

		wp_cache_set( $key, $list, 'faster_random', DAY_IN_SECONDS );
	}

	$count = intdiv( strlen( $list ), 4 );
	if ( $count < 1 ) {
		return null;
	}

	$slot = unpack( 'Nid', $list, 4 * (int) wp_rand( 0, $count - 1 ) );

	return $slot ? (int) $slot['id'] : null;
}
