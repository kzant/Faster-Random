# Faster Random

WordPress 7.2 adds a `/random/` URL that redirects visitors to a random post. Core picks the post with `ORDER BY RAND()`, which reads every eligible post on every request and cannot be cached. Faster Random picks the post ID a cheaper way, then lets core's own query fetch that one post.

Everything else stays as core built it: the same eligibility rules (published posts, no password, randomable post types), the same 302 redirect and headers, and any `pre_get_posts` exclusions other plugins add.

Read the write-up: [Stop Shuffling the Whole Deck](https://www.zant.com/) (link coming once the post is live).

## Strategies

Set with the `faster_random_strategy` filter. The default is chosen for you.

| Strategy | How it picks | Default when |
| --- | --- | --- |
| `cache` | Keeps the eligible post IDs in the object cache, picks a random slot | A persistent object cache (Redis, Memcached) is in use |
| `probe` | Guesses random IDs and keeps the ones that are eligible posts | No persistent object cache |
| `core` | Does nothing, so core's `RAND()` query runs | Never. Useful for comparing |

If a strategy cannot find an answer, the plugin steps aside and core's `RAND()` query runs, so the result is always correct.

```php
// Force a strategy.
add_filter( 'faster_random_strategy', fn() => 'probe' );
```

## Install

Copy `faster-random.php` into `wp-content/plugins/` and activate it. It does nothing on WordPress versions before 7.2.

## What was tested

Against the WordPress 7.2 beta 5 development source on a local SQLite test site: no draft, password-protected post or page was ever returned; empty archives return a 404 like core; the picks were fair in a 12,000-draw test; and the cache strategy follows trashing and publishing.

Not tested: MySQL or MariaDB timings. SQLite timings are directional only. If you benchmark it on a big site, please open an issue with your numbers.

## Just want it off?

Return `false` from core's own switch:

```php
add_filter( 'wp_enable_random_content_redirect', '__return_false' );
```

## License

GPL-2.0-or-later.
