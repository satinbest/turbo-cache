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
 * @param string      $target Canonical cache target identifier or directory path.
 * @param bool|string $action Optional. When true or 'reset', clears registry.
 *                            When 'remove' or 'unmark', removes target.
 *                            When 'check', tests if target is unpurged without marking.
 * @return bool True if target was NOT previously purged in this request and should proceed;
 *              false if target is a duplicate or empty.
 */
function turbo_cache_deduplicate_purge( $target, $action = false ) {
	static $purged_targets = array();

	if ( true === $action || 'reset' === $action ) {
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

	if ( 'remove' === $action || 'unmark' === $action ) {
		unset( $purged_targets[ $canonical_key ] );
		return true;
	}

	if ( 'check' === $action ) {
		return ! isset( $purged_targets[ $canonical_key ] );
	}

	if ( isset( $purged_targets[ $canonical_key ] ) ) {
		return false;
	}

	$purged_targets[ $canonical_key ] = true;
	return true;
}

/**
 * Reset request-local deduplicated purge targets across all internal registries.
 *
 * Primary testing and request boundary helper.
 *
 * @return void
 */
function turbo_cache_reset_purged_targets() {
	turbo_cache_deduplicate_purge( '', true );
	turbo_cache_purged_targets_registry( 'reset' );
}

/**
 * Remove a specific target from the request-local deduplication registers.
 *
 * Used to unmark targets when a filesystem purge fails or when a fresh mutation
 * occurs within a long-running batch process.
 *
 * @param string $target Target to unmark.
 * @return void
 */
function turbo_cache_unmark_purged( $target ) {
	turbo_cache_deduplicate_purge( $target, 'remove' );
	turbo_cache_purged_targets_registry( 'remove', $target );
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
 * @param string $action 'get', 'set', 'remove', or 'reset'.
 * @param string $target Target to register when action is 'set' or remove when 'remove'.
 * @return array<string,bool> Current map of registered targets.
 */
function turbo_cache_purged_targets_registry( $action = 'get', $target = '' ) {
	static $targets = array();

	if ( 'reset' === $action ) {
		$targets = array();
		return $targets;
	}

	if ( 'remove' === $action && '' !== $target ) {
		$key = strtolower( str_replace( '\\', '/', trim( $target ) ) );
		$key = rtrim( $key, '/' );
		unset( $targets[ $key ] );
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
	global $cache_path, $cached_direct_pages;

	if ( empty( $dir ) || ! is_string( $dir ) ) {
		return false;
	}

	$in_cache = false;
	if ( function_exists( 'wpsc_is_in_cache_directory' ) ) {
		$in_cache = wpsc_is_in_cache_directory( $dir );
	}

	// If not inside standard cache directory, check if it is an authorized direct-page directory under ABSPATH.
	if ( ! $in_cache ) {
		$is_direct_dir = false;
		if ( is_array( $cached_direct_pages ) && defined( 'ABSPATH' ) && '' !== ABSPATH ) {
			$rp_d         = realpath( $dir );
			$norm_dir     = trailingslashit( str_replace( '\\', '/', false !== $rp_d ? $rp_d : $dir ) );
			$rp_abs       = realpath( ABSPATH );
			$norm_abspath = trailingslashit( str_replace( '\\', '/', false !== $rp_abs ? $rp_abs : ABSPATH ) );
			if ( $norm_dir !== $norm_abspath && 0 === strncmp( $norm_dir, $norm_abspath, strlen( $norm_abspath ) ) ) {
				foreach ( $cached_direct_pages as $page ) {
					$norm_page = trim( (string) $page, '/' );
					if ( '' !== $norm_page && $norm_dir === $norm_abspath . $norm_page . '/' ) {
						$is_direct_dir = true;
						break;
					}
				}
			}
		}
		if ( ! $is_direct_dir ) {
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
		if ( defined( 'ABSPATH' ) && '' !== ABSPATH ) {
			$protected[] = trailingslashit( str_replace( '\\', '/', ABSPATH ) );
		}
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
 * @param string    $dir        Target cache storage directory.
 * @param bool      $delete_dir Optional. Whether to remove the directory if empty. Default true.
 * @param bool|null $success    Optional by-ref parameter set to true on success, false on I/O error.
 * @return int Number of cache files unlinked.
 */
function turbo_cache_purge_storage_dir( $dir, $delete_dir = true, &$success = null ) {
	$success = true;

	if ( ! is_dir( $dir ) ) {
		return 0;
	}

	if ( ! turbo_cache_is_safe_purge_dir( $dir ) ) {
		$success = false;
		return 0;
	}

	$dir     = trailingslashit( str_replace( '\\', '/', $dir ) );
	$deleted = 0;

	// Direct page directories under ABSPATH must never have their directory unlinked.
	$norm_abspath_dir = trailingslashit( str_replace( '\\', '/', ABSPATH ) );
	if ( $delete_dir && defined( 'ABSPATH' ) && '' !== ABSPATH && 0 === strncmp( $dir, $norm_abspath_dir, strlen( $norm_abspath_dir ) ) && false === strpos( $dir, '/cache/' ) ) {
		$delete_dir = false;
	}

	$handle = @opendir( $dir );
	if ( ! $handle ) {
		$success = false;
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
				0 === strncmp( $file, 'index', 5 ) ||
				'.html' === substr( $file, -5 ) ||
				'.gz' === substr( $file, -3 ) ||
				'.needs-rebuild' === substr( $file, -14 ) ||
				'.tmp' === substr( $file, -4 )
			) {
				if ( @unlink( $filepath ) ) {
					++$deleted;
				} else {
					$success = false;
				}
			}
		} elseif ( is_dir( $filepath ) && 'page' === $file ) {
			// Targeted pagination archive directory underneath this target.
			$page_success = true;
			$deleted     += turbo_cache_purge_storage_dir( $filepath, true, $page_success );
			if ( ! $page_success ) {
				$success = false;
			}
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
	if ( false !== strpos( $url, '?' ) ) {
		return false;
	}

	$target_key = turbo_cache_normalize_invalidation_target( $url );
	if ( '' === $target_key ) {
		return false;
	}

	if ( ! turbo_cache_deduplicate_purge( 'url:' . $target_key ) ) {
		return false;
	}

	// Resolve the absolute cache storage directory.
	$host = (string) parse_url( $url, PHP_URL_HOST );
	$path = (string) parse_url( $url, PHP_URL_PATH );
	if ( '' === $path ) {
		$path = '/';
	}

	$delete_dir  = ( '/' !== $path );
	$storage_dir = turbo_cache_get_storage_dir( $path, $host );
	$success     = true;

	turbo_cache_purge_storage_dir( $storage_dir, $delete_dir, $success );

	if ( ! $success ) {
		turbo_cache_unmark_purged( 'url:' . $target_key );
		return false;
	}

	turbo_cache_purged_targets_registry( 'set', 'url:' . $target_key );

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

	$homepage_dir = '';
	if ( function_exists( 'get_supercache_dir' ) ) {
		$homepage_dir = get_supercache_dir( $blog_id );
	} else {
		$cache_path   = isset( $GLOBALS['cache_path'] ) ? $GLOBALS['cache_path'] : '';
		$host         = turbo_cache_normalize_host();
		$homepage_dir = turbo_cache_get_storage_dir( '/', $host, $cache_path );
	}

	if ( '' === $homepage_dir || ! is_dir( $homepage_dir ) ) {
		turbo_cache_purged_targets_registry( 'set', $canonical_tag );
		return true;
	}

	$success = true;

	// Purge homepage static files directly within root supercache directory.
	// We pass $delete_dir = false so the directory containing subpage folders is NOT removed!
	turbo_cache_purge_storage_dir( $homepage_dir, false, $success );

	// Also purge paginated front page archives if present: supercache/{host}/page/
	$page_dir = trailingslashit( $homepage_dir ) . 'page/';
	if ( is_dir( $page_dir ) ) {
		$page_success = true;
		turbo_cache_purge_storage_dir( $page_dir, true, $page_success );
		if ( ! $page_success ) {
			$success = false;
		}
		@rmdir( $page_dir );
	}

	if ( ! $success ) {
		turbo_cache_unmark_purged( $canonical_tag );
		return false;
	}

	turbo_cache_purged_targets_registry( 'set', $canonical_tag );

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
	if ( ! empty( $post->post_name ) && ( false === $url || false !== strpos( (string) $url, '?p=' ) || false !== strpos( (string) $url, '?page_id=' ) ) ) {
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

/**
 * Resolve public permalink specifically from post object properties.
 *
 * Avoids constructing previous URLs by string substitution on new permalinks.
 *
 * @param WP_Post|object $post Post object to resolve URL for.
 * @return string|false Resolved public URL, or false on failure.
 */
function turbo_cache_resolve_post_object_url( $post ) {
	if ( ! is_object( $post ) || empty( $post->post_name ) ) {
		return false;
	}

	$clean_slug = preg_replace( '/__trashed.*$/', '', (string) $post->post_name );
	if ( '' === $clean_slug ) {
		return false;
	}

	$home = function_exists( 'get_option' ) ? (string) get_option( 'home' ) : '';

	// 1. Hierarchical pages: walk parent hierarchy.
	if ( 'page' === $post->post_type ) {
		$parent_id = isset( $post->post_parent ) ? (int) $post->post_parent : 0;
		if ( $parent_id > 0 ) {
			$parent_url = function_exists( 'get_permalink' ) ? get_permalink( $parent_id ) : false;
			if ( $parent_url ) {
				return trailingslashit( $parent_url ) . $clean_slug . '/';
			}
		}
		if ( '' !== $home ) {
			return trailingslashit( $home ) . $clean_slug . '/';
		}
	}

	// 2. Standard posts: resolve via sample permalink structure with original post object.
	if ( ! function_exists( 'get_sample_permalink' ) && defined( 'ABSPATH' ) && file_exists( ABSPATH . 'wp-admin/includes/post.php' ) ) {
		require_once ABSPATH . 'wp-admin/includes/post.php';
	}

	if ( function_exists( 'get_sample_permalink' ) ) {
		// Trashed posts cause core get_sample_permalink to return ?p=ID.
		// Temporarily use clean status and slug on a clone to extract the canonical structure.
		$sample_post = $post;
		if ( 'trash' === $post->post_status ) {
			$sample_post              = clone $post;
			$sample_post->post_status = 'publish';
			$sample_post->post_name   = $clean_slug;
		}
		list( $structure ) = get_sample_permalink( $sample_post, null, $clean_slug );
		if ( ! empty( $structure ) && false === strpos( $structure, '?p=' ) && false === strpos( $structure, '?page_id=' ) ) {
			return str_replace( array( '%postname%', '%pagename%' ), $clean_slug, $structure );
		}
	}

	// 3. Fallback: get_permalink with post object.
	if ( function_exists( 'get_permalink' ) ) {
		$url = get_permalink( $post );
		if ( $url && false === strpos( (string) $url, '?p=' ) && false === strpos( (string) $url, '?page_id=' ) ) {
			return $url;
		}
	}

	if ( '' !== $home ) {
		return trailingslashit( $home ) . $clean_slug . '/';
	}

	return false;
}

/**
 * Handle post update lifecycle invalidation, including slug and parent hierarchy changes.
 *
 * Connected to WordPress core 'post_updated' hook.
 *
 * @param int            $post_id     Post ID.
 * @param WP_Post|object $post_after  Post object following update.
 * @param WP_Post|object $post_before Post object prior to update.
 * @return bool True if invalidation occurred, false otherwise.
 */
function turbo_cache_post_updated( $post_id, $post_after, $post_before ) {
	if ( ! is_object( $post_after ) || ! is_object( $post_before ) ) {
		return false;
	}

	if ( function_exists( 'get_post_type_object' ) ) {
		$ptype = get_post_type_object( $post_after->post_type );
		if ( empty( $ptype ) || ! $ptype->public ) {
			return false;
		}
	}

	$public_statuses = array( 'publish', 'private' );
	$was_public      = in_array( $post_before->post_status, $public_statuses, true );
	$is_public       = in_array( $post_after->post_status, $public_statuses, true );

	if ( ! $was_public && ! $is_public ) {
		return false;
	}

	// Unmark targets from previous mutations to support repeated updates in WP-CLI or batch processes.
	$new_url = turbo_cache_get_post_canonical_url( $post_after );
	if ( $new_url ) {
		$new_key = turbo_cache_normalize_invalidation_target( $new_url );
		turbo_cache_unmark_purged( 'url:' . $new_key );
	}
	turbo_cache_unmark_purged( 'homepage:0' );

	// Detect if slug, parent hierarchy, or date changed while previous state was public.
	$slug_changed   = ( $post_before->post_name !== $post_after->post_name );
	$parent_changed = ( (int) $post_before->post_parent !== (int) $post_after->post_parent );
	$date_changed   = ( $post_before->post_date !== $post_after->post_date );

	if ( $was_public && ( $slug_changed || $parent_changed || $date_changed ) ) {
		$old_url = turbo_cache_resolve_post_object_url( $post_before );
		if ( $old_url && $old_url !== $new_url ) {
			$old_key = turbo_cache_normalize_invalidation_target( $old_url );
			turbo_cache_unmark_purged( 'url:' . $old_key );
			turbo_cache_purge_url( $old_url );
		}
	}

	return turbo_cache_invalidate_post( $post_id );
}

/**
 * Handle permanent deletion from WordPress before post data and metadata are removed.
 *
 * Connected to WordPress core 'before_delete_post' hook.
 *
 * @param int            $post_id Post ID.
 * @param WP_Post|object $post    Optional. Post object.
 * @return bool True if invalidation occurred, false otherwise.
 */
function turbo_cache_before_delete_post( $post_id, $post = null ) {
	if ( ! is_object( $post ) && function_exists( 'get_post' ) ) {
		$post = get_post( $post_id );
	}

	if ( ! is_object( $post ) || empty( $post->ID ) ) {
		return false;
	}

	if ( function_exists( 'get_post_type_object' ) ) {
		$ptype = get_post_type_object( $post->post_type );
		if ( empty( $ptype ) || ! $ptype->public ) {
			return false;
		}
	}

	$public_statuses = array( 'publish', 'private' );

	// 1. Post is permanently deleted from Trash:
	if ( 'trash' === $post->post_status ) {
		$orig_status = function_exists( 'get_post_meta' ) ? (string) get_post_meta( $post_id, '_wp_trash_meta_status', true ) : '';
		// If the post was never published (e.g. draft, auto-draft), skip public cache invalidation.
		if ( ! in_array( $orig_status, $public_statuses, true ) ) {
			return false;
		}

		$clean_slug             = preg_replace( '/__trashed.*$/', '', (string) $post->post_name );
		$orig_post              = clone $post;
		$orig_post->post_status = $orig_status;
		$orig_post->post_name   = $clean_slug;

		$orig_url = turbo_cache_resolve_post_object_url( $orig_post );
		if ( $orig_url ) {
			$orig_key = turbo_cache_normalize_invalidation_target( $orig_url );
			turbo_cache_unmark_purged( 'url:' . $orig_key );
			turbo_cache_purge_url( $orig_url );
		}

		if ( turbo_cache_post_affects_homepage( $orig_post ) ) {
			turbo_cache_unmark_purged( 'homepage:0' );
			turbo_cache_invalidate_homepage();
		}

		if ( function_exists( 'wpsc_delete_post_archives' ) ) {
			wpsc_delete_post_archives( $orig_post );
		}

		return true;
	}

	// 2. Post is permanently deleted directly without going through Trash:
	if ( in_array( $post->post_status, $public_statuses, true ) ) {
		$url = turbo_cache_resolve_post_object_url( $post );
		if ( $url ) {
			$key = turbo_cache_normalize_invalidation_target( $url );
			turbo_cache_unmark_purged( 'url:' . $key );
		}
		turbo_cache_unmark_purged( 'homepage:0' );
		return turbo_cache_invalidate_post( $post_id );
	}

	return false;
}

/**
 * Handle taxonomy term assignment changes that alter permalink structures (e.g. %category%).
 *
 * Connected to WordPress core 'set_object_terms' hook.
 *
 * @param int    $object_id  Post or object ID.
 * @param array  $terms      New terms array.
 * @param array  $tt_ids     New term taxonomy IDs.
 * @param string $taxonomy   Taxonomy slug.
 * @param bool   $append     Whether terms were appended.
 * @param array  $old_tt_ids Old term taxonomy IDs before mutation.
 * @return void
 */
function turbo_cache_terms_changed( $object_id, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids ) {
	if ( 'category' !== $taxonomy ) {
		return;
	}

	// If old and new terms are identical, no permalink transition occurred.
	if ( (array) $old_tt_ids === (array) $tt_ids ) {
		return;
	}

	$post = function_exists( 'get_post' ) ? get_post( $object_id ) : null;
	if ( ! is_object( $post ) || ! in_array( $post->post_status, array( 'publish', 'private' ), true ) ) {
		return;
	}

	$structure = function_exists( 'get_option' ) ? (string) get_option( 'permalink_structure' ) : '';

	// When permalink structure does not contain %category%, the URL is unchanged,
	// but the post content (category display) and category archives must be invalidated.
	if ( false === strpos( $structure, '%category%' ) ) {
		$new_url = turbo_cache_get_post_canonical_url( $post );
		if ( $new_url ) {
			$new_key = turbo_cache_normalize_invalidation_target( $new_url );
			turbo_cache_unmark_purged( 'url:' . $new_key );
		}
		turbo_cache_invalidate_post( $object_id );
		return;
	}

	$old_url = false;

	if ( ! empty( $old_tt_ids ) ) {
		// Resolve terms for all old taxonomy IDs to mirror WordPress core get_permalink() selection.
		$old_terms = array();
		foreach ( (array) $old_tt_ids as $tt_id ) {
			$t = function_exists( 'get_term_by' ) ? get_term_by( 'term_taxonomy_id', (int) $tt_id, 'category' ) : null;
			if ( is_object( $t ) && ( ! function_exists( 'is_wp_error' ) || ! is_wp_error( $t ) ) ) {
				$old_terms[] = $t;
			}
		}

		if ( ! empty( $old_terms ) ) {
			// WordPress core sorts categories by term_id ASC and selects the first one.
			if ( count( $old_terms ) > 1 ) {
				usort(
					$old_terms,
					function ( $a, $b ) {
						return ( (int) $a->term_id <=> (int) $b->term_id );
					}
				);
			}

			$old_term = $old_terms[0];
			if ( function_exists( 'apply_filters' ) ) {
				$filtered = apply_filters( 'post_link_category', $old_term, $old_terms, $post );
				if ( is_object( $filtered ) && ( ! function_exists( 'is_wp_error' ) || ! is_wp_error( $filtered ) ) ) {
					$old_term = $filtered;
				}
			}

			$cat_path = $old_term->slug;
			if ( function_exists( 'get_category_parents' ) ) {
				$cat_parents = get_category_parents( $old_term->term_id, false, '/', true );
				if ( is_string( $cat_parents ) && ( ! function_exists( 'is_wp_error' ) || ! is_wp_error( $cat_parents ) ) ) {
					$cat_path = trim( $cat_parents, '/' );
				}
			}

			$home = function_exists( 'home_url' ) ? home_url() : ( (string) get_option( 'home' ) );
			$path = str_replace( '%category%', $cat_path, $structure );
			$path = str_replace( array( '%postname%', '%pagename%' ), $post->post_name, $path );

			if ( ! empty( $post->post_date ) && ( false !== strpos( $path, '%year%' ) || false !== strpos( $path, '%monthnum%' ) || false !== strpos( $path, '%day%' ) ) ) {
				$date = explode( ' ', str_replace( array( '-', ':' ), ' ', (string) $post->post_date ) );
				$path = str_replace(
					array( '%year%', '%monthnum%', '%day%', '%hour%', '%minute%', '%second%' ),
					array(
						isset( $date[0] ) ? substr( $date[0], 0, 4 ) : '',
						isset( $date[1] ) ? $date[1] : '',
						isset( $date[2] ) ? $date[2] : '',
						isset( $date[3] ) ? $date[3] : '',
						isset( $date[4] ) ? $date[4] : '',
						isset( $date[5] ) ? $date[5] : '',
					),
					$path
				);
			}

			if ( false !== strpos( $path, '%post_id%' ) ) {
				$path = str_replace( '%post_id%', (string) $post->ID, $path );
			}

			if ( false !== strpos( $path, '%author%' ) && ! empty( $post->post_author ) && function_exists( 'get_userdata' ) ) {
				$authordata = get_userdata( $post->post_author );
				if ( is_object( $authordata ) && ! empty( $authordata->user_nicename ) ) {
					$path = str_replace( '%author%', $authordata->user_nicename, $path );
				}
			}

			$old_url = trailingslashit( rtrim( (string) $home, '/' ) . '/' . ltrim( $path, '/' ) );
		}
	}

	$new_url = turbo_cache_get_post_canonical_url( $post );

	// 1. Purge old permalink when it differs from the new permalink.
	if ( $old_url && $old_url !== $new_url ) {
		$old_key = turbo_cache_normalize_invalidation_target( $old_url );
		turbo_cache_unmark_purged( 'url:' . $old_key );
		turbo_cache_purge_url( $old_url );
	}

	// 2. Invalidate new permalink, post archives, and related pages.
	if ( $new_url ) {
		$new_key = turbo_cache_normalize_invalidation_target( $new_url );
		turbo_cache_unmark_purged( 'url:' . $new_key );
	}
	turbo_cache_unmark_purged( 'homepage:0' );

	turbo_cache_invalidate_post( $object_id );
}
