<?php
/**
 * Turbo Cache — Cache Lifecycle & Storage Maintenance Layer.
 *
 * Provides safe, bounded, and resource-metered cache expiration, stale storage
 * pruning, orphaned temporary file cleanup, and empty directory garbage
 * collection without risking accidental deletion of active cache files.
 *
 * @package Turbo_Cache
 */

// phpcs:disable WordPress.PHP.NoSilencedErrors.Discouraged
// phpcs:disable WordPress.WP.AlternativeFunctions
// phpcs:disable WordPress.Security.ValidatedSanitizedInput
// phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
// phpcs:disable WordPress.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
// phpcs:disable Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) && ! defined( 'WP_CONTENT_DIR' ) && ! defined( 'WPCACHEHOME' ) ) {
	if ( ! defined( 'PHPUNIT_COMPOSER_INSTALL' ) && ! defined( 'WPSC_SMOKE_TEST' ) ) {
		return;
	}
}

/**
 * Normalize path separators and append a trailing slash.
 *
 * Standalone equivalent of trailingslashit() to avoid runtime dependency.
 *
 * @param string $path_or_uri Input path or URI.
 * @return string Normalized path with trailing slash.
 */
function turbo_cache_trailingslash( $path_or_uri ) {
	return rtrim( str_replace( '\\', '/', (string) $path_or_uri ), '/' ) . '/';
}

/**
 * Check whether a file or directory path is safe for maintenance operations.
 *
 * Enforces boundaries:
 * 1. Must reside strictly within the cache directory or authorized direct page directories.
 * 2. Must not contain directory traversal sequences (..).
 * 3. Root directory protection files (.htaccess, index.html at root level) must NEVER be pruned.
 * 4. Critical system directories (cache_path, blogs, supercache, meta) must NEVER be removed.
 *
 * @param string      $path       Absolute filesystem path.
 * @param string|null $cache_root Optional root cache directory override.
 * @return bool True if safe to inspect or prune, false if protected or out of bounds.
 */
function turbo_cache_is_safe_maintenance_target( $path, $cache_root = null ) {
	global $cache_path, $cached_direct_pages;

	if ( ! is_string( $path ) || '' === trim( $path ) ) {
		return false;
	}

	$norm_path = str_replace( '\\', '/', $path );
	if ( false !== strpos( $norm_path, '..' ) ) {
		return false;
	}

	if ( null === $cache_root ) {
		$cache_root = isset( $cache_path ) && '' !== $cache_path ? $cache_path : ( defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR . '/cache/' : '' );
	}

	$norm_root = turbo_cache_trailingslash( $cache_root );

	$in_cache = false;
	if ( '' !== $norm_root && 0 === strpos( $norm_path, $norm_root ) ) {
		$in_cache = true;
	} elseif ( function_exists( 'wpsc_is_in_cache_directory' ) && wpsc_is_in_cache_directory( $path ) ) {
		$in_cache = true;
	}

	// Check direct pages if not in cache directory.
	if ( ! $in_cache ) {
		if ( is_array( $cached_direct_pages ) && defined( 'ABSPATH' ) && '' !== ABSPATH ) {
			$norm_abs = turbo_cache_trailingslash( ABSPATH );
			if ( 0 === strpos( $norm_path, $norm_abs ) ) {
				foreach ( $cached_direct_pages as $page ) {
					$norm_page = trim( (string) $page, '/' );
					if ( '' !== $norm_page && 0 === strpos( $norm_path, $norm_abs . $norm_page ) ) {
						$in_cache = true;
						break;
					}
				}
			}
		}
	}

	if ( ! $in_cache ) {
		return false;
	}

	// If the file or directory exists and is a symlink, verify the resolved realpath does not escape.
	if ( @file_exists( $path ) ) {
		$real = @realpath( $path );
		if ( false !== $real ) {
			$norm_real     = str_replace( '\\', '/', $real );
			$real_in_cache = false;
			if ( '' !== $norm_root && 0 === strpos( $norm_real, rtrim( $norm_root, '/' ) ) ) {
				$real_in_cache = true;
			} elseif ( is_array( $cached_direct_pages ) && defined( 'ABSPATH' ) && '' !== ABSPATH ) {
				$norm_abs = turbo_cache_trailingslash( ABSPATH );
				if ( 0 === strpos( $norm_real, rtrim( $norm_abs, '/' ) ) ) {
					foreach ( $cached_direct_pages as $page ) {
						$norm_page = trim( (string) $page, '/' );
						if ( '' !== $norm_page && 0 === strpos( $norm_real, $norm_abs . $norm_page ) ) {
							$real_in_cache = true;
							break;
						}
					}
				}
			}
			if ( ! $real_in_cache ) {
				return false;
			}
		}
	}

	// Protect root security guard files.
	$basename = basename( $norm_path );
	if ( in_array( $basename, array( '.htaccess', 'index.html' ), true ) ) {
		// Only protect if at root or structural levels.
		$parent = turbo_cache_trailingslash( dirname( $norm_path ) );
		if (
			$parent === $norm_root ||
			$parent === $norm_root . 'supercache/' ||
			$parent === $norm_root . 'blogs/' ||
			$parent === $norm_root . 'meta/'
		) {
			return false;
		}
	}

	// Protect structural root directories from deletion.
	$trimmed_path    = rtrim( $norm_path, '/' );
	$protected_roots = array(
		rtrim( $norm_root, '/' ),
		rtrim( $norm_root, '/' ) . '/supercache',
		rtrim( $norm_root, '/' ) . '/blogs',
		rtrim( $norm_root, '/' ) . '/meta',
	);

	if ( defined( 'ABSPATH' ) && '' !== ABSPATH ) {
		$protected_roots[] = rtrim( str_replace( '\\', '/', ABSPATH ), '/' );
	}

	if ( in_array( $trimmed_path, $protected_roots, true ) ) {
		return false;
	}

	// Respect upstream wpsc_get_protected_directories() if available.
	if ( function_exists( 'wpsc_get_protected_directories' ) ) {
		$upstream_protected = wpsc_get_protected_directories();
		if ( is_array( $upstream_protected ) ) {
			foreach ( $upstream_protected as $prot_item ) {
				$norm_prot = rtrim( str_replace( '\\', '/', (string) $prot_item ), '/' );
				if ( $trimmed_path === $norm_prot ) {
					return false;
				}
			}
		}
	}

	return true;
}

/**
 * Check if a directory is completely empty (contains no files or subdirectories).
 *
 * @param string $dir Target directory.
 * @return bool True if directory exists and is empty, false otherwise.
 */
function turbo_cache_is_dir_empty( $dir ) {
	if ( ! is_dir( $dir ) ) {
		return false;
	}

	$handle = @opendir( $dir );
	if ( ! $handle ) {
		return false;
	}

	$is_empty = true;
	while ( false !== ( $entry = @readdir( $handle ) ) ) {
		if ( '.' !== $entry && '..' !== $entry ) {
			$is_empty = false;
			break;
		}
	}
	@closedir( $handle );

	return $is_empty;
}

/**
 * Prune expired cache files and stale temporary artifacts within a directory tree.
 *
 * Key guarantees:
 * - Bounded execution: respects 'max_files' count limit and 'time_limit' clock budget.
 * - Cache preservation: active/valid cache files (filemtime + max_age > now) are NEVER unlinked.
 * - Stale temp cleanup: unlinks abandoned .tmp files older than tmp_max_age.
 * - Clean directory tree: prunes empty directories left behind after child cache files expire.
 *
 * @param string $directory Path to directory to prune.
 * @param array  $options {
 *     Optional pruning configuration options.
 *
 *     @type int   $max_age          Maximum cache age in seconds. Default from $cache_max_time or 3600.
 *     @type int   $max_files        Maximum number of files to delete in this run. Default 500 (0 = unlimited).
 *     @type float $time_limit       Maximum wall-clock execution time in seconds. Default 15.0 (0 = unlimited).
 *     @type int   $tmp_max_age      Threshold in seconds for stale .tmp files. Default 300.
 *     @type bool  $clean_empty_dirs Whether to delete directories that become empty. Default true.
 *     @type bool  $clean_stale_tmp  Whether to immediately delete stale temporary files. Default true.
 *     @type bool  $force            If true, delete all cache files regardless of age. Default false.
 *     @type int   $now              Timestamp to evaluate against (defaults to time()).
 * }
 * @return array{
 *     deleted_files: int,
 *     deleted_dirs: int,
 *     deleted_tmp: int,
 *     scanned_files: int,
 *     scanned_dirs: int,
 *     timed_out: bool,
 *     hit_limit: bool,
 *     duration: float
 * } Structured execution metrics.
 */
function turbo_cache_prune_storage( $directory, array $options = array() ) {
	global $cache_max_time;

	$start_time = microtime( true );

	$default_max_age    = isset( $cache_max_time ) && (int) $cache_max_time > 0 ? (int) $cache_max_time : 3600;
	$is_forced          = isset( $options['force'] ) && (bool) $options['force'];
	$default_max_files  = $is_forced ? 0 : 500;
	$default_time_limit = $is_forced ? 0.0 : 15.0;

	$config = array_merge(
		array(
			'max_age'          => $default_max_age,
			'max_files'        => $default_max_files,
			'time_limit'       => $default_time_limit,
			'tmp_max_age'      => 300,
			'clean_empty_dirs' => true,
			'clean_stale_tmp'  => true,
			'force'            => false,
			'now'              => time(),
		),
		$options
	);

	$metrics = array(
		'deleted_files' => 0,
		'deleted_dirs'  => 0,
		'deleted_tmp'   => 0,
		'scanned_files' => 0,
		'scanned_dirs'  => 0,
		'timed_out'     => false,
		'hit_limit'     => false,
		'duration'      => 0.0,
	);

	if ( ! is_dir( $directory ) ) {
		$metrics['duration'] = round( microtime( true ) - $start_time, 4 );
		return $metrics;
	}

	turbo_cache_prune_storage_recursive( $directory, $config, $metrics, $start_time );

	$metrics['duration'] = round( microtime( true ) - $start_time, 4 );
	return $metrics;
}

/**
 * Internal recursive worker for storage pruning.
 *
 * @param string $dir        Current directory being scanned.
 * @param array  $config     Pruning configuration.
 * @param array  &$metrics   Cumulative metrics array (passed by reference).
 * @param float  $start_time Process start timestamp.
 * @return void
 */
function turbo_cache_prune_storage_recursive( $dir, array $config, array &$metrics, $start_time ) {
	// Guard against execution limits.
	if ( $metrics['timed_out'] || $metrics['hit_limit'] ) {
		return;
	}

	// Time limit check.
	if ( $config['time_limit'] > 0 && ( microtime( true ) - $start_time ) >= $config['time_limit'] ) {
		$metrics['timed_out'] = true;
		return;
	}

	// Deletion limit check.
	$total_deleted = $metrics['deleted_files'] + $metrics['deleted_tmp'];
	if ( $config['max_files'] > 0 && $total_deleted >= $config['max_files'] ) {
		$metrics['hit_limit'] = true;
		return;
	}

	$dir = turbo_cache_trailingslash( $dir );

	$handle = @opendir( $dir );
	if ( ! $handle ) {
		return;
	}

	++$metrics['scanned_dirs'];

	$subdirs = array();

	while ( false !== ( $entry = @readdir( $handle ) ) ) {
		if ( '.' === $entry || '..' === $entry ) {
			continue;
		}

		$path = $dir . $entry;

		if ( is_link( $path ) ) {
			// Symlinks must never be traversed or treated as directories to prevent loops and escaping.
			continue;
		}

		if ( is_dir( $path ) ) {
			$subdirs[] = $path;
		} elseif ( is_file( $path ) ) {
			++$metrics['scanned_files'];

			// Safety check: protect root guard files.
			if ( ! turbo_cache_is_safe_maintenance_target( $path ) ) {
				continue;
			}

			$mtime   = @filemtime( $path );
			$is_temp = ( substr( $entry, -4 ) === '.tmp' || substr( $entry, -7 ) === '.tmp.gz' );

			// 1. Abandoned temporary files.
			if ( $is_temp ) {
				if ( $config['clean_stale_tmp'] && ( $config['force'] || ( $mtime && ( $config['now'] - $mtime ) >= $config['tmp_max_age'] ) ) ) {
					if ( @unlink( $path ) ) {
						++$metrics['deleted_tmp'];
						if ( $config['max_files'] > 0 && ( $metrics['deleted_files'] + $metrics['deleted_tmp'] ) >= $config['max_files'] ) {
							$metrics['hit_limit'] = true;
							break;
						}
					}
				}
				continue;
			}

			// 2. Regular cache files and companion files.
			$is_expired = $config['force'] || ( $mtime && ( $config['now'] - $mtime ) >= $config['max_age'] );

			if ( $is_expired ) {
				// Only unlink recognized cache artifacts unless forced.
				if (
					$config['force'] ||
					0 === strpos( $entry, 'index' ) ||
					substr( $entry, -5 ) === '.html' ||
					substr( $entry, -3 ) === '.gz' ||
					substr( $entry, -5 ) === '.meta' ||
					substr( $entry, -14 ) === '.needs-rebuild' ||
					substr( $entry, -4 ) === '.tmp'
				) {
					if ( @unlink( $path ) ) {
						++$metrics['deleted_files'];
						if ( $config['max_files'] > 0 && ( $metrics['deleted_files'] + $metrics['deleted_tmp'] ) >= $config['max_files'] ) {
							$metrics['hit_limit'] = true;
							break;
						}
					}
				}
			}
		}

		// Time check inside directory entry loop.
		if ( $config['time_limit'] > 0 && ( microtime( true ) - $start_time ) >= $config['time_limit'] ) {
			$metrics['timed_out'] = true;
			break;
		}
	}

	@closedir( $handle );

	// Recurse into subdirectories unless limits were reached.
	if ( ! $metrics['timed_out'] && ! $metrics['hit_limit'] ) {
		foreach ( $subdirs as $subdir ) {
			if ( $config['time_limit'] > 0 && ( microtime( true ) - $start_time ) >= $config['time_limit'] ) {
				$metrics['timed_out'] = true;
				break;
			}
			turbo_cache_prune_storage_recursive( $subdir, $config, $metrics, $start_time );
			if ( $metrics['timed_out'] || $metrics['hit_limit'] ) {
				break;
			}
		}
	}

	// 3. Clean empty directory if all child files expired and were removed.
	if ( $config['clean_empty_dirs'] && ! $metrics['timed_out'] ) {
		if ( $config['time_limit'] > 0 && ( microtime( true ) - $start_time ) >= $config['time_limit'] ) {
			$metrics['timed_out'] = true;
			return;
		}
		if ( turbo_cache_is_safe_maintenance_target( $dir ) && turbo_cache_is_dir_empty( $dir ) ) {
			if ( @rmdir( $dir ) ) {
				++$metrics['deleted_dirs'];
			}
		}
	}
}

/**
 * Coordinated cache maintenance runner.
 *
 * Resolves the appropriate cache root (with multisite awareness) and executes
 * a bounded maintenance pass.
 *
 * @param string|null $target_dir Optional target directory. If null, targets supercache directory.
 * @param array       $options    Maintenance options passed to turbo_cache_prune_storage().
 * @return array Structured metrics from turbo_cache_prune_storage().
 */
function turbo_cache_run_maintenance( $target_dir = null, array $options = array() ) {
	global $cache_path, $supercachedir;

	if ( null === $target_dir || '' === $target_dir ) {
		if ( ! empty( $supercachedir ) && is_dir( $supercachedir ) ) {
			$target_dir = $supercachedir;
		} elseif ( function_exists( 'get_supercache_dir' ) ) {
			$target_dir = get_supercache_dir();
		} else {
			$target_dir = ( isset( $cache_path ) ? $cache_path : '' ) . 'supercache';
		}
	}

	return turbo_cache_prune_storage( $target_dir, $options );
}
