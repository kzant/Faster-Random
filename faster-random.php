<?php
/**
 * Plugin Name:       Faster Random
 * Description:       Speeds up the /random/ redirect in WordPress 7.2 by replacing ORDER BY RAND() with cheaper ways to pick one post.
 * Version:           1.2.0
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
 * If a strategy finds nothing, or the post it picked is no longer eligible,
 * the plugin steps aside and core's RAND() query runs as usual.
 *
 * Strategies, set with the faster_random_strategy filter (it receives the
 * default name and the WP_Query, so you can choose per request):
 *
 *   cache  A cached list of eligible IDs: one array lookup and one primary
 *          key check per request. The default when a persistent object cache
 *          (Redis, Memcached) is in use.
 *   probe  Test random IDs with primary key lookups. The default for the
 *          site-wide /random/ when there is no persistent object cache, and
 *          only when eligible posts are a fair share of the posts table.
 *   core   Do nothing. The default for category, tag and author archives
 *          without an object cache, for searches, and useful for comparing.
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
 * Runs on posts_clauses_request, the last clause filter WP_Query applies, and
 * as late as possible, so it sees the join and where the real query will run.
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

	// Every search phrase is a different query, so searches never get a cached list.
	$search = $query->is_search();
	$plain  = ! $search && ! $query->is_archive();

	if ( $search ) {
		$default = 'core';
	} elseif ( wp_using_ext_object_cache() ) {
		$default = 'cache';
	} elseif ( $plain ) {
		$default = 'probe';
	} else {
		$default = 'core';
	}

	$strategy = apply_filters( 'faster_random_strategy', $default, $query );

	$id = null;
	if ( 'cache' === $strategy ) {
		$id = faster_random_pick_cached( $clauses, $query );
		// The cache steps aside for huge sites and while another request rebuilds the list.
		if ( ! $id && $plain ) {
			$id = faster_random_pick_probe( $clauses );
		}
	} elseif ( 'probe' === $strategy ) {
		$id = faster_random_pick_probe( $clauses );
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
add_filter( 'posts_clauses_request', 'faster_random_clauses', PHP_INT_MAX, 2 );

/**
 * Checks that one post ID passes the query's join, where and group by.
 *
 * @return bool|null True or false for eligible or not, null if the check itself failed.
 */
function faster_random_is_eligible( $id, $clauses ) {
	global $wpdb;

	$groupby = ! empty( $clauses['groupby'] ) ? ' GROUP BY ' . $clauses['groupby'] : '';

	$found = $wpdb->get_var(
		"SELECT {$wpdb->posts}.ID FROM {$wpdb->posts} " . ( $clauses['join'] ?? '' ) . '
		 WHERE 1=1 ' . ( $clauses['where'] ?? '' ) . "
		 AND {$wpdb->posts}.ID = " . (int) $id . $groupby . ' LIMIT 1'
	);

	// A failed check is not the same as a post that is not eligible.
	if ( '' !== $wpdb->last_error ) {
		return null;
	}

	return (int) $found === (int) $id;
}

/**
 * Tells whether eligible posts are a big enough share of the posts table for
 * probing to work. The share is measured once an hour.
 *
 * Probe guesses IDs between the smallest and largest ID in the table. If most
 * of the table is revisions, attachments or orders, or the IDs start in the
 * billions, most guesses miss and probe costs more than it saves.
 */
function faster_random_probe_is_dense( $clauses, $bounds ) {
	global $wpdb;

	$min_share = (float) apply_filters( 'faster_random_min_density', 0.03 );
	$key       = 'fr_dense_' . md5( ( $clauses['join'] ?? '' ) . ( $clauses['where'] ?? '' ) );
	$share     = get_transient( $key );

	if ( false === $share ) {
		$count = $wpdb->get_var(
			"SELECT COUNT(DISTINCT {$wpdb->posts}.ID) FROM {$wpdb->posts} " . ( $clauses['join'] ?? '' ) . '
			 WHERE 1=1 ' . ( $clauses['where'] ?? '' )
		);
		if ( '' !== $wpdb->last_error || null === $count ) {
			return false;
		}
		$span  = (int) $bounds->hi - (int) $bounds->lo + 1;
		$share = $span > 0 ? round( (int) $count / $span, 4 ) : 0;
		set_transient( $key, (string) $share, HOUR_IN_SECONDS );
	}

	return (float) $share >= $min_share;
}

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
 * a complete miss very unlikely when eligible posts are at least 3% of the
 * ID range (about 0.2%). Below that, probe steps aside before it spends any
 * guesses. When all five rounds still miss, core's RAND() query runs, so the
 * answer is still correct, just not faster.
 */
function faster_random_pick_probe( $clauses ) {
	global $wpdb;

	$bounds = $wpdb->get_row( "SELECT MIN(ID) AS lo, MAX(ID) AS hi FROM {$wpdb->posts}" );
	if ( ! $bounds || ! $bounds->hi || ! faster_random_probe_is_dense( $clauses, $bounds ) ) {
		return null;
	}

	for ( $round = 0; $round < 5; $round++ ) {
		$guesses = array();
		for ( $i = 0; $i < 40; $i++ ) {
			$guesses[] = (int) wp_rand( (int) $bounds->lo, (int) $bounds->hi );
		}

		$hits = $wpdb->get_col(
			"SELECT DISTINCT {$wpdb->posts}.ID FROM {$wpdb->posts} " . ( $clauses['join'] ?? '' ) . '
			 WHERE 1=1 ' . ( $clauses['where'] ?? '' ) . "
			 AND {$wpdb->posts}.ID IN (" . implode( ',', $guesses ) . ')'
		);

		if ( $hits ) {
			// Any hit is as likely as any other, so a random hit is a fair pick.
			return (int) $hits[ wp_rand( 0, count( $hits ) - 1 ) ];
		}
	}

	return null;
}

/**
 * Strategy: cache.
 *
 * Keep the list of eligible IDs in the object cache, packed as 4 bytes per ID
 * (8 if an ID is above 4 billion), and pick a random slot. About 200 KB for
 * 50,000 posts. The list is stored under a key built from the exact query
 * conditions, so another plugin changing which posts are eligible gets its
 * own list. It is salted with core's "posts last changed" marker, and with
 * the "terms last changed" marker when the query filters by taxonomy, so
 * publishing, editing, trashing or deleting a post, or changing its
 * categories, rebuilds it on the next visit. Changes core does not track,
 * such as custom fields, are picked up when the list expires after an hour.
 *
 * Safeguards:
 * - A pick that came from the cache is checked against the query's join, where
 *   and group by. If it is no longer eligible, the list is dropped and core
 *   runs. If the check itself fails, the list stays. A list built a moment ago
 *   skips the check unless the query has a GROUP BY.
 * - One request rebuilds a stale list at a time. The others step aside, and
 *   the lock stays in place if the cache refuses to store the list.
 * - A database error is never cached. An empty result is remembered for five
 *   minutes, or until a post changes.
 * - Lists over 200,000 IDs (filter: faster_random_max_ids) are not cached,
 *   and that is remembered for an hour so the scan is not repeated after every
 *   post change. Memcached's default item limit is 1 MB.
 *
 * Needs a persistent object cache. Without one the list would be rebuilt on
 * every request, which is slower than core, so this strategy steps aside.
 */
function faster_random_pick_cached( $clauses, $query ) {
	global $wpdb;

	if ( ! wp_using_ext_object_cache() ) {
		return null;
	}

	$group = 'faster_random';
	$key   = 'v2:' . md5( ( $clauses['join'] ?? '' ) . ( $clauses['where'] ?? '' ) . ( $clauses['groupby'] ?? '' ) );
	$salt  = (array) wp_cache_get_last_changed( 'posts' );
	if ( ! empty( $query->tax_query->queries ) ) {
		$salt[] = wp_cache_get_last_changed( 'terms' );
	}

	$list  = wp_cache_get_salted( $key, $group, $salt );
	$fresh = false;

	if ( ! is_string( $list ) ) {
		// Known to be too big, or known to be empty? Do not scan again.
		if ( false !== wp_cache_get( 'big:' . $key, $group ) || false !== wp_cache_get_salted( 'empty:' . $key, $group, $salt ) ) {
			return null;
		}

		// Let one request rebuild the list. The rest fall through to core.
		if ( ! wp_cache_add( 'lock:' . $key, 1, $group, 60 ) ) {
			return null;
		}

		$max = max( 1, (int) apply_filters( 'faster_random_max_ids', 200000 ) );
		$ids = $wpdb->get_col(
			"SELECT DISTINCT {$wpdb->posts}.ID FROM {$wpdb->posts} " . ( $clauses['join'] ?? '' ) . '
			 WHERE 1=1 ' . ( $clauses['where'] ?? '' ) . ' LIMIT ' . ( $max + 1 )
		);

		// A database error looks like an empty result. Do not remember either as a fact.
		if ( '' !== $wpdb->last_error ) {
			wp_cache_delete( 'lock:' . $key, $group );
			return null;
		}

		if ( ! $ids ) {
			wp_cache_set_salted( 'empty:' . $key, 1, $group, $salt, 5 * MINUTE_IN_SECONDS );
			wp_cache_delete( 'lock:' . $key, $group );
			return null;
		}

		$ids  = array_map( 'intval', $ids );
		$wide = max( $ids ) > 4294967295;

		// Wide IDs take twice the room, so the cap on the count is halved.
		if ( count( $ids ) > ( $wide ? intdiv( $max, 2 ) : $max ) || ( $wide && PHP_INT_SIZE < 8 ) ) {
			wp_cache_set( 'big:' . $key, 1, $group, HOUR_IN_SECONDS );
			wp_cache_delete( 'lock:' . $key, $group );
			return null;
		}

		$list = $wide ? 'j' : 'n'; // First byte: the width of the IDs that follow.
		foreach ( array_chunk( $ids, 10000 ) as $chunk ) {
			$list .= pack( ( $wide ? 'J' : 'N' ) . '*', ...$chunk );
		}

		// If the cache refuses the list, keep the lock so a failing cache is not hammered.
		if ( wp_cache_set_salted( $key, $list, $group, $salt, HOUR_IN_SECONDS ) ) {
			wp_cache_delete( 'lock:' . $key, $group );
		}
		$fresh = true;
	}

	$kind = $list[0] ?? '';
	if ( 'n' !== $kind && 'j' !== $kind ) {
		return null;
	}
	$width = 'j' === $kind ? 8 : 4;
	$count = intdiv( strlen( $list ) - 1, $width );
	if ( $count < 1 ) {
		return null;
	}

	$slot = unpack( ( 'j' === $kind ? 'J' : 'N' ) . 'id', $list, 1 + $width * wp_rand( 0, $count - 1 ) );
	$id   = $slot ? (int) $slot['id'] : 0;
	if ( ! $id ) {
		return null;
	}

	// A stale list must never turn into a 404. Check a pick that came from the cache,
	// and start over if it fails. A list built a moment ago needs no check, unless
	// the query has a GROUP BY, which the list was not built with.
	if ( ! $fresh || ! empty( $clauses['groupby'] ) ) {
		$eligible = faster_random_is_eligible( $id, $clauses );
		if ( null === $eligible ) {
			return null;
		}
		if ( ! $eligible ) {
			wp_cache_delete( $key, $group );
			return null;
		}
	}

	return $id;
}
