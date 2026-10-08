<?php
/**
 * Turbo Cache — Core Invalidation Primitives & Hook Integration Layer.
 *
 * Provides lightweight, procedural helpers for targeted cache invalidation,
 * request-local deduplication, canonical target normalization, and seamless
 * integration with WordPress core content mutation hooks.
 *
 * @package Turbo_Cache
 */

// phpcs:disable WordPress.PHP.NoSilencedErrors.Discouraged
// phpcs:disable WordPress.WP.AlternativeFunctions
// phpcs:disable WordPress.Security.ValidatedSanitizedInput
// phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.system_calls_parse_url
// phpcs:disable WordPress.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
// phpcs:disable Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) && ! defined( 'WP_CONTENT_DIR' ) && ! defined( 'WPCACHEHOME' ) ) {
	if ( ! defined( 'PHPUNIT_COMPOSER_INSTALL' ) && ! defined( 'WPSC_SMOKE_TEST' ) ) {
		return;
	}
}

/**
 * Deduplicate cache purge targets within the current PHP request lifecycle.
 *
 * Request-local in-memory gate: ensures that identical canonical targets
 * triggered by multiple cascading hooks (e.g. transition_post_status,
 * clean_post_cache, publish_post, edit_post) are purged on disk at most once.
 *
 * @param string $target Canonical cache target identifier or directory path.
 * @param bool   $reset  Optional. When true, clears the internal registry (for tests).
 * @return bool True if target was NOT previously purged in this request and should proceed;
 *              false if target is a duplicate or empty.
 */
function turbo_cache_deduplicate_purge( $target, $reset = false ) {
	static $purged_targets = array();

	if ( true === $reset ) {
		$purged_targets = array();
		return true;
	}

	if ( empty( $target ) || ! is_string( $target ) ) {
		return false;
	}

	// Canonical target normalization for deduplication.
	$canonical_key = strtolower( str_replace( '\\', '/', trim( $target ) ) );
	$canonical_key = rtrim( $canonical_key, '/' );
	if ( '' === $canonical_key ) {
		$canonical_key = '/';
	}

	if ( isset( $purged_targets[ $canonical_key ] ) ) {
		return false;
	}

	$purged_targets[ $canonical_key ] = true;
	return true;
}

/**
 * Reset request-local deduplicated purge targets.
 *
 * Primary testing and request boundary helper.
 *
 * @return void
 */
function turbo_cache_reset_purged_targets() {
	turbo_cache_deduplicate_purge( '', true );
}

/**
 * Get the list of all targets purged during the current request.
 *
 * @return array<string,bool> Map of canonical targets purged.
 */
function turbo_cache_get_purged_targets() {
	return turbo_cache_purged_targets_registry( 'get' );
}

/**
 * Internal registry helper for inspecting purged targets.
 *
 * @param string $action 'get', 'set', or 'reset'.
 * @param string $target Target to register when action is 'set'.
 * @return array<string,bool> Current map of registered targets.
 */
function turbo_cache_purged_targets_registry( $action = 'get', $target = '' ) {
	static $targets = array();

	if ( 'reset' === $action ) {
		$targets = array();
		return $targets;
	}

	if ( 'set' === $action && '' !== $target ) {
		$key             = strtolower( str_replace( '\\', '/', trim( $target ) ) );
		$key             = rtrim( $key, '/' );
		$targets[ $key ] = true;
		return $targets;
	}

	return $targets;
}

/**
 * Normalize an invalidation target (URL, path, or directory) into a canonical key.
 *
 * @param string      $url_or_path Target URL or path.
 * @param string|null $host        Optional host override.
 * @return string Canonical target key.
 */
function turbo_cache_normalize_invalidation_target( $url_or_path, $host = null ) {
	if ( ! is_string( $url_or_path ) || '' === trim( $url_or_path ) ) {
		return '';
	}

	$url_or_path = trim( $url_or_path );

	// If absolute URL, extract host and path.
	if ( preg_match( '#^https?://#i', $url_or_path ) ) {
		$parsed_host = (string) parse_url( $url_or_path, PHP_URL_HOST );
		if ( '' !== $parsed_host ) {
			$host = $parsed_host;
		}
		$url_or_path = (string) parse_url( $url_or_path, PHP_URL_PATH );
	}

	$normalized_host = turbo_cache_normalize_host( $host );
	$normalized_path = turbo_cache_normalize_uri_path( $url_or_path );

	if ( '' === $normalized_path || '/' !== $normalized_path[0] ) {
		$normalized_path = '/' . $normalized_path;
	}

	return strtolower( $normalized_host . $normalized_path );
}

/**
 * Check if a directory is safe to purge (inside supercache, not a protected root).
 *
 * @param string $dir Absolute directory path.
 * @return bool True if safe, false otherwise.
 */
function turbo_cache_is_safe_purge_dir( $dir ) {
	global $cache_path;

	if ( empty( $dir ) || ! is_string( $dir ) ) {
		return false;
	}

	if ( function_exists( 'wpsc_is_in_cache_directory' ) ) {
		if ( ! wpsc_is_in_cache_directory( $dir ) ) {
			return false;
		}
	}

	$rp_dir = function_exists( 'wpsc_get_realpath' ) ? wpsc_get_realpath( $dir ) : realpath( $dir );
	if ( ! $rp_dir ) {
		// Directory might not exist or already be deleted.
		return false;
	}

	$rp_dir = trailingslashit( str_replace( '\\', '/', $rp_dir ) );

	// Protected directories that must NEVER be purged as a whole target.
	$base_cache = isset( $cache_path ) ? $cache_path : '';
	if ( '' !== $base_cache ) {
		$protected = array(
			trailingslashit( str_replace( '\\', '/', $base_cache ) ),
			trailingslashit( str_replace( '\\', '/', $base_cache . 'blogs' ) ),
			trailingslashit( str_replace( '\\', '/', $base_cache . 'supercache' ) ),
		);
		foreach ( $protected as $p ) {
			$rp_p = function_exists( 'wpsc_get_realpath' ) ? wpsc_get_realpath( $p ) : realpath( $p );
			if ( $rp_p ) {
				$norm_p = trailingslashit( str_replace( '\\', '/', $rp_p ) );
				if ( defined( 'PHP_OS_FAMILY' ) && 'Windows' === PHP_OS_FAMILY ) {
					if ( strtolower( $norm_p ) === strtolower( $rp_dir ) ) {
						return false;
					}
				} elseif ( $norm_p === $rp_dir ) {
					return false;
				}
			}
		}
	}

	return true;
}

/**
 * Purge cache files within a specific targeted storage directory on disk.
 *
 * Removes index.html, index-https.html, .gz companions, .needs-rebuild,
 * and paginated subdirectories (page/) without touching parent or sibling caches.
 *
 * @param string $dir        Target cache storage directory.
 * @param bool   $delete_dir Optional. Whether to remove the directory if empty. Default true.
 * @return int Number of cache files unlinked.
 */
function turbo_cache_purge_storage_dir( $dir, $delete_dir = true ) {
	if ( ! is_dir( $dir ) ) {
		return 0;
	}

	if ( ! turbo_cache_is_safe_purge_dir( $dir ) ) {
		return 0;
	}

	$dir     = trailingslashit( str_replace( '\\', '/', $dir ) );
	$deleted = 0;

	$handle = @opendir( $dir );
	if ( ! $handle ) {
		return 0;
	}

	// phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
	while ( false !== ( $file = @readdir( $handle ) ) ) {
		if ( '.' === $file || '..' === $file || '.htaccess' === $file ) {
			continue;
		}

		$filepath = $dir . $file;

		if ( is_file( $filepath ) ) {
			// Delete cache files, compressed variants, and rebuild staging files.
			if (
				str_starts_with( $file, 'index' ) ||
				str_ends_with( $file, '.html' ) ||
				str_ends_with( $file, '.gz' ) ||
				str_ends_with( $file, '.needs-rebuild' ) ||
				str_ends_with( $file, '.tmp' )
			) {
				if ( @unlink( $filepath ) ) {
					++$deleted;
				}
			}
		} elseif ( is_dir( $filepath ) && 'page' === $file ) {
			// Targeted pagination archive directory underneath this target.
			$deleted += turbo_cache_purge_storage_dir( $filepath, true );
			@rmdir( $filepath );
		}
	}
	@closedir( $handle );

	if ( $delete_dir ) {
		@rmdir( $dir );
	}

	return $deleted;
}

/**
 * Targeted purge of a specific URL from the cache storage.
 *
 * Applies canonicalization, request-local deduplication, and file removal.
 *
 * @param string $url Absolute URL or relative URI to purge.
 * @return bool True if target was purged, false if duplicate or invalid.
 */
function turbo_cache_purge_url( $url ) {
	if ( ! is_string( $url ) || '' === trim( $url ) ) {
		return false;
	}

	// URLs with query parameters are not stored in static supercache.
	if ( str_contains( $url, '?' ) ) {
		return false;
	}

	$target_key = turbo_cache_normalize_invalidation_target( $url );
	if ( '' === $target_key ) {
		return false;
	}

	if ( ! turbo_cache_deduplicate_purge( 'url:' . $target_key ) ) {
		return false;
	}
	turbo_cache_purged_targets_registry( 'set', 'url:' . $target_key );

	// Resolve the absolute cache storage directory.
	$host = (string) parse_url( $url, PHP_URL_HOST );
	$path = (string) parse_url( $url, PHP_URL_PATH );
	if ( '' === $path ) {
		$path = $url;
	}

	$storage_dir = turbo_cache_get_storage_dir( $path, $host );
	turbo_cache_purge_storage_dir( $storage_dir, true );

	if ( function_exists( 'do_action' ) ) {
		do_action( 'wpsc_purge_url', $url );
		do_action( 'gc_cache', 'prune', $path );
	}

	return true;
}

/**
 * Targeted invalidation of the site homepage cache.
 *
 * Purges only the homepage static files (index.html, index-https.html, etc.)
 * and paginated archives (page/) without deleting the entire supercache directory
 * or affecting sibling subpages.
 *
 * @param int $blog_id Optional blog ID for multisite. Default 0.
 * @return bool True if homepage was invalidated, false if deduplicated.
 */
function turbo_cache_invalidate_homepage( $blog_id = 0 ) {
	$blog_id       = (int) $blog_id;
	$canonical_tag = 'homepage:' . $blog_id;

	if ( ! turbo_cache_deduplicate_purge( $canonical_tag ) ) {
		return false;
	}
	turbo_cache_purged_targets_registry( 'set', $canonical_tag );

	$homepage_dir = '';
	if ( function_exists( 'get_supercache_dir' ) ) {
		$homepage_dir = get_supercache_dir( $blog_id );
	} else {
		$cache_path   = isset( $GLOBALS['cache_path'] ) ? $GLOBALS['cache_path'] : '';
		$host         = turbo_cache_normalize_host();
		$homepage_dir = turbo_cache_get_storage_dir( '/', $host, $cache_path );
	}

	if ( '' === $homepage_dir || ! is_dir( $homepage_dir ) ) {
		return true;
	}

	// Purge homepage static files directly within root supercache directory.
	// We pass $delete_dir = false so the directory containing subpage folders is NOT removed!
	turbo_cache_purge_storage_dir( $homepage_dir, false );

	// Also purge paginated front page archives if present: supercache/{host}/page/
	$page_dir = trailingslashit( $homepage_dir ) . 'page/';
	if ( is_dir( $page_dir ) ) {
		turbo_cache_purge_storage_dir( $page_dir, true );
		@rmdir( $page_dir );
	}

	if ( function_exists( 'do_action' ) ) {
		do_action( 'gc_cache', 'prune', 'homepage' );
	}

	return true;
}

/**
 * Determine whether a post/page mutation legitimately affects the homepage.
 *
 * Avoids unnecessary homepage purges:
 * - Ordinary pages do NOT purge the homepage (unless designated as page_on_front).
 * - Blog posts purge the homepage when the front page displays the post feed.
 *
 * @param WP_Post|object|int $post Post object or post ID.
 * @return bool True if mutation should invalidate homepage, false otherwise.
 */
function turbo_cache_post_affects_homepage( $post ) {
	if ( is_numeric( $post ) && function_exists( 'get_post' ) ) {
		$post = get_post( (int) $post );
	}

	if ( ! is_object( $post ) || empty( $post->post_type ) ) {
		return false;
	}

	$front_type = function_exists( 'get_option' ) ? (string) get_option( 'show_on_front', 'posts' ) : 'posts';
	$front_page = function_exists( 'get_option' ) ? (int) get_option( 'page_on_front', 0 ) : 0;
	$post_id    = isset( $post->ID ) ? (int) $post->ID : 0;

	// Pages only affect homepage if explicitly set as page_on_front.
	if ( 'page' === $post->post_type ) {
		return ( 'page' === $front_type && $post_id > 0 && $post_id === $front_page );
	}

	// Standard posts affect homepage when homepage shows latest posts.
	if ( 'post' === $post->post_type ) {
		return ( 'posts' === $front_type );
	}

	return false;
}

/**
 * Resolve the canonical public URL for a post or page.
 *
 * Works across published, private, and transition states.
 *
 * @param WP_Post|object|int $post Post object or post ID.
 * @return string|false Canonical URL string, or false on failure.
 */
function turbo_cache_get_post_canonical_url( $post ) {
	if ( is_numeric( $post ) && function_exists( 'get_post' ) ) {
		$post = get_post( (int) $post );
	}

	if ( ! is_object( $post ) || empty( $post->ID ) ) {
		return false;
	}

	$url = function_exists( 'get_permalink' ) ? get_permalink( $post->ID ) : false;

	// When post is unpublishing or drafted, get_permalink() may return ?p=123.
	// Fall back to sample permalink to locate its public cache directory on disk.
	if ( ! empty( $post->post_name ) && ( false === $url || str_contains( (string) $url, '?p=' ) || str_contains( (string) $url, '?page_id=' ) ) ) {
		if ( function_exists( 'get_sample_permalink' ) ) {
			list( $permalink_structure ) = get_sample_permalink( $post );
			if ( ! empty( $permalink_structure ) ) {
				$url = str_replace( array( '%postname%', '%pagename%' ), $post->post_name, $permalink_structure );
			}
		}
	}

	if ( empty( $url ) || ! is_string( $url ) ) {
		return false;
	}

	return $url;
}

/**
 * Invalidate cache for a specific post or page.
 *
 * Invalidates the canonical post URL cache, checks if homepage invalidation
 * is required, purges the designated blog page (page_for_posts) if needed,
 * and cleans related archives.
 *
 * @param int|WP_Post $post_id          Post ID or post object.
 * @param bool|null   $include_homepage Optional override for homepage invalidation.
 * @return bool True if invalidation completed, false if skipped or rejected.
 */
function turbo_cache_invalidate_post( $post_id, $include_homepage = null ) {
	if ( is_object( $post_id ) && isset( $post_id->ID ) ) {
		$post_id = $post_id->ID;
	}
	$post_id = (int) $post_id;

	if ( $post_id <= 0 ) {
		return false;
	}

	$post = function_exists( 'get_post' ) ? get_post( $post_id ) : null;
	if ( ! is_object( $post ) || 'auto-draft' === $post->post_status ) {
		return false;
	}

	// Filter hook: allow plugins to reject post cache clears.
	if ( function_exists( 'apply_filters' ) && ! apply_filters( 'wp_super_cache_clear_post_cache', true, $post ) ) {
		return false;
	}

	// Only public post types produce public page cache.
	if ( function_exists( 'get_post_type_object' ) ) {
		$ptype = get_post_type_object( $post->post_type );
		if ( empty( $ptype ) || ! $ptype->public ) {
			return false;
		}
	}

	// 1. Invalidate post's canonical URL cache.
	$url = turbo_cache_get_post_canonical_url( $post );
	if ( $url ) {
		turbo_cache_purge_url( $url );
	}

	// 2. Invalidate homepage if mutation affects it.
	if ( null === $include_homepage ) {
		$include_homepage = turbo_cache_post_affects_homepage( $post );
	}

	if ( true === $include_homepage ) {
		turbo_cache_invalidate_homepage();
	}

	// 3. If site uses static front page, check if page_for_posts needs invalidation.
	if ( function_exists( 'get_option' ) && 'page' === get_option( 'show_on_front' ) ) {
		$posts_page = (int) get_option( 'page_for_posts', 0 );
		if ( $posts_page > 0 && ( 'post' === $post->post_type || $post_id === $posts_page ) ) {
			$posts_page_url = function_exists( 'get_permalink' ) ? get_permalink( $posts_page ) : false;
			if ( $posts_page_url ) {
				turbo_cache_purge_url( $posts_page_url );
			}
		}
	}

	// 4. Invalidate related archives (taxonomies, author, post type) via upstream helper.
	if ( function_exists( 'wpsc_delete_post_archives' ) && function_exists( 'get_object_taxonomies' ) ) {
		wpsc_delete_post_archives( $post );
	}

	return true;
}

/**
 * Handle post status transition invalidation.
 *
 * Ensures proper invalidation on status transitions:
 * draft -> publish, publish -> draft, publish -> trash, trash -> publish.
 *
 * @param string  $new_status New post status.
 * @param string  $old_status Old post status.
 * @param WP_Post $post       Post object.
 * @return bool True if handled, false otherwise.
 */
function turbo_cache_post_status_transition( $new_status, $old_status, $post ) {
	if ( ! is_object( $post ) || empty( $post->ID ) ) {
		return false;
	}

	// If no status transition actually occurred, exit.
	if ( $new_status === $old_status ) {
		return false;
	}

	$public_statuses = array( 'publish', 'private' );

	$was_public = in_array( $old_status, $public_statuses, true );
	$is_public  = in_array( $new_status, $public_statuses, true );

	// Only transitions involving a public state generate or remove public cache.
	if ( ! $was_public && ! $is_public ) {
		return false;
	}

	return turbo_cache_invalidate_post( $post->ID );
}
