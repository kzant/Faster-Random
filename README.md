# Faster Random

WordPress 7.2 adds a `/random/` URL that redirects visitors to a random post. Core picks the post with `ORDER BY RAND()`, which reads every eligible post on every request and cannot be cached. Faster Random picks the post ID a cheaper way, then lets core's own query fetch that one post.

Everything else stays as core built it: the same eligibility rules (published posts, no password, randomable post types), the same 302 redirect and headers, and any `pre_get_posts` exclusions other plugins add.

Read the write-up: [Stop Shuffling the Whole Deck](https://www.zant.com/) (link coming once the post is live).

## Strategies

Set with the `faster_random_strategy` filter. The default is chosen for you.

| Strategy | How it picks | Default when |
| --- | --- | --- |
| `cache` | Keeps the eligible post IDs in the object cache, picks a random slot | A persistent object cache (Redis, Memcached) is in use, except for searches |
| `probe` | Guesses random IDs and keeps the ones that are eligible posts | No object cache, the site-wide `/random/`, and eligible posts are at least 3% of the ID range (measured hourly) |
| `core` | Does nothing, so core's `RAND()` query runs | Searches always; with no object cache, category, tag and author archives and sites where probe's odds are poor. Also useful for comparing |

If a strategy cannot find an answer, or the post it picked is no longer eligible, the plugin steps aside and core's `RAND()` query runs, so a stale pick does not send a visitor to a 404.

Safeguards in the `cache` strategy: the list is stored under the exact query conditions and tied to WordPress's posts (and, for category and tag archives, terms) last-changed markers; custom field changes are picked up when the list expires after an hour; a cached pick is checked against the query (join, where, group by) before it is used; one request rebuilds a stale list while the others run core's query; database errors are never cached and empty results are remembered for five minutes; lists over 200,000 IDs (filter `faster_random_max_ids`) are not cached, and that is remembered for an hour; IDs above 4 billion use an 8-byte list. Probe's minimum share is the filter `faster_random_min_density` (default 0.03).

```php
// Force a strategy. The filter also receives the WP_Query, so you can choose per request.
add_filter( 'faster_random_strategy', fn( $default, $query ) => 'probe', 10, 2 );
```

## Install

Copy `faster-random.php` into `wp-content/plugins/` and activate it. It does nothing on WordPress versions before 7.2.

## What was tested

Versions 1.1 and 1.2 fix problems two independent code reviews found (a stale cached list could produce a 404, category changes did not refresh category lists, errors could be cached, probe wasted queries on narrow archives and thin ID ranges, the "too big" memory was lost after a post change, IDs above 4 billion broke the packed list). Each fix has a test. The MySQL numbers below were taken on 1.2.0.

Against the WordPress 7.2 beta 5 development source on a local SQLite test site: no draft, password-protected post or page was ever returned; empty archives return a 404 like core; the picks were fair in a 12,000-draw test; and the cache strategy follows trashing and publishing.

On MySQL 8.4 (small shared-hosting test site, 250,006 rows in the posts table, 50,001 eligible posts), core's query averaged 174.4 ms and `probe` averaged 2.2 ms, about 80 times faster. The first probe request of each hour also runs a density count that costs about as much as one core request (188 ms). No draft, password-protected post or page was returned in the 1.2.0 runs.

The `cache` strategy was tested on the same site with a file-backed stand-in for an object cache: 1.5 ms per warm pick (150 requests, median 1.3 ms), about 230 ms to rebuild the list after a post was published or trashed, and the list followed both changes. A list poisoned with a draft's ID produced no 404 and no draft: one request fell through to core, the next rebuilt the list.

Not tested: `cache` against real Redis or Memcached, MariaDB, or the fairness test on MySQL. This is one site, so treat it as one data point. If you benchmark it on a big site, please open an issue with your numbers.

## Just want it off?

Return `false` from core's own switch:

```php
add_filter( 'wp_enable_random_content_redirect', '__return_false' );
```

## License

GPL-2.0-or-later.
