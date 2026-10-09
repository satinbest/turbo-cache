<?php
/**
 * Turbo Cache — Functional Cache Storage Layer.
 *
 * Provides lightweight, procedural helpers for request identity, path
 * normalization, cache directory/filename resolution, and atomic writes
 * while strictly preserving upstream WP Super Cache filesystem compatibility.
 *
 * @package Turbo_Cache
 */

// phpcs:disable WordPress.PHP.NoSilencedErrors.Discouraged
// phpcs:disable WordPress.WP.AlternativeFunctions
// phpcs:disable WordPress.Security.ValidatedSanitizedInput
// phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.system_calls_parse_url

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) && ! defined( 'WP_CONTENT_DIR' ) && ! defined( 'WPCACHEHOME' ) ) {
	if ( ! defined( 'PHPUNIT_COMPOSER_INSTALL' ) && ! defined( 'WPSC_SMOKE_TEST' ) ) {
		return;
	}
}

/**
 * Case-normalize a URI or path fragment.
 *
 * Converts percent-escapes (%xx) to uppercase (%XX) and ASCII letters ([A-Z]+)
 * to lowercase ([a-z]+) while leaving raw UTF-8 intact.
 *
 * @param string $uri URI or path fragment.
 * @return string Normalized URI, or '' if $uri is not a string.
 */
function turbo_cache_normalize_uri_case( $uri ) {
	if ( ! is_string( $uri ) ) {
		return '';
	}

	return preg_replace_callback(
		'/%[0-9A-Fa-f]{2}|[A-Z]+/',
		function ( $matches ) {
			return '%' === $matches[0][0] ? strtoupper( $matches[0] ) : strtolower( $matches[0] );
		},
		$uri
	);
}

/**
 * Recursively strip search patterns from subject.
 *
 * Standalone equivalent of upstream wpsc_deep_replace().
 *
 * @param string|array $search  Values to search and remove.
 * @param string       $subject Target string.
 * @return string Cleaned string.
 */
function turbo_cache_deep_replace( $search, $subject ) {
	$subject = (string) $subject;

	$count = 1;
	while ( $count ) {
		$subject = str_replace( $search, '', $subject, $count );
	}

	return $subject;
}

/**
 * Normalize a request URI path for cache storage.
 *
 * Preserves upstream WP Super Cache path normalization behavior:
 * 1. Normalizes case (uppercase hex escapes, lowercase ASCII letters).
 * 2. Strips query strings (?...) and hash fragments (#...).
 * 3. Strips unsafe characters (<, >, ', ", CR, LF, TAB, spaces, parentheses).
 * 4. Deep-replaces directory traversal (..), backslashes (\), and index.php.
 *
 * @param string $uri Request URI or path.
 * @return string Normalized path.
 */
function turbo_cache_normalize_uri_path( $uri ) {
	if ( ! is_string( $uri ) ) {
		return '';
	}

	$uri = turbo_cache_normalize_uri_case( $uri );
	$uri = preg_replace( '/(\?.*)?(#.*)?$/', '', $uri );
	$uri = preg_replace( '/[ <>\'\"\r\n\t\(\)]/', '', (string) $uri );
	$uri = turbo_cache_deep_replace( array( '..', '\\', 'index.php' ), $uri );

	return $uri;
}

/**
 * Normalize request hostname for cache directory naming.
 *
 * Strips port (:8080), lowercases, and encodes entities (ENT_COMPAT) to match
 * upstream $WPSC_HTTP_HOST directory formatting.
 *
 * @param string|null $host Optional host string. If null, falls back to globals.
 * @return string Normalized hostname without port.
 */
function turbo_cache_normalize_host( $host = null ) {
	global $WPSC_HTTP_HOST;

	if ( null === $host || '' === $host ) {
		if ( ! empty( $WPSC_HTTP_HOST ) ) {
			$host = $WPSC_HTTP_HOST;
		} elseif ( ! empty( $_SERVER['HTTP_HOST'] ) ) {
			$host = $_SERVER['HTTP_HOST'];
		} elseif ( function_exists( 'get_option' ) ) {
			$host = (string) parse_url( (string) get_option( 'home' ), PHP_URL_HOST );
		} else {
			$host = '';
		}
	}

	$host = (string) $host;
	$host = function_exists( 'mb_strtolower' ) ? mb_strtolower( $host ) : strtolower( $host );
	$host = htmlentities( $host, ENT_COMPAT );
	$host = preg_replace( '/:.*$/', '', $host );

	return $host;
}

/**
 * Build the lightweight request identity for page caching.
 *
 * @param string|null $uri      Optional URI to evaluate.
 * @param string|null $host     Optional host to evaluate.
 * @param bool|null   $is_https Optional HTTPS flag.
 * @return array{host:string,path:string,is_https:bool} Request identity.
 */
function turbo_cache_get_request_identity( $uri = null, $host = null, $is_https = null ) {
	global $wp_cache_request_uri;

	if ( null === $uri ) {
		$uri = isset( $wp_cache_request_uri ) ? $wp_cache_request_uri : ( isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '' );
	}

	$normalized_path = turbo_cache_normalize_uri_path( $uri );
	if ( '' === $normalized_path ) {
		$normalized_path = '/';
	} elseif ( '/' !== $normalized_path[0] ) {
		$normalized_path = '/' . $normalized_path;
	}
	$normalized_host = turbo_cache_normalize_host( $host );

	if ( null === $is_https ) {
		if ( function_exists( 'wpsc_is_https' ) ) {
			$is_https = wpsc_is_https();
		} else {
			$is_https = ( ( isset( $_SERVER['HTTPS'] ) && 'on' === strtolower( $_SERVER['HTTPS'] ) ) ||
				( isset( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) && 'https' === strtolower( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) ) );
		}
	}

	return array(
		'host'     => $normalized_host,
		'path'     => $normalized_path,
		'is_https' => (bool) $is_https,
	);
}

/**
 * Resolve the cache filename for the current request.
 *
 * Handles HTTP vs HTTPS separation, mobile variant filters, and direct page
 * exceptions.
 *
 * @param bool|null $is_https Optional HTTPS override.
 * @return string Cache filename (e.g. 'index.html', 'index-https.html').
 */
function turbo_cache_get_cache_filename( $is_https = null ) {
	global $cached_direct_pages;

	if ( null === $is_https ) {
		if ( function_exists( 'wpsc_is_https' ) ) {
			$is_https = wpsc_is_https();
		} else {
			$is_https = ( ( isset( $_SERVER['HTTPS'] ) && 'on' === strtolower( $_SERVER['HTTPS'] ) ) ||
				( isset( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) && 'https' === strtolower( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) ) );
		}
	}

	$extra_str = $is_https ? '-https' : '';

	if ( function_exists( 'apply_filters' ) ) {
		$extra_str = apply_filters( 'supercache_filename_str', $extra_str );
	} elseif ( function_exists( 'do_cacheaction' ) ) {
		$extra_str = do_cacheaction( 'supercache_filename_str', $extra_str );
	}

	if ( is_array( $cached_direct_pages ) && isset( $_SERVER['REQUEST_URI'] ) && in_array( $_SERVER['REQUEST_URI'], $cached_direct_pages, true ) ) {
		$extra_str = '';
	}

	$extra_str = preg_replace( '/[^a-zA-Z0-9_-]/', '', (string) $extra_str );

	return 'index' . $extra_str . '.html';
}

/**
 * Resolve the absolute directory path where cache files live for a URI.
 *
 * @param string|null $uri        Optional URI.
 * @param string|null $host       Optional host.
 * @param string|null $cache_path Optional root cache path.
 * @return string Absolute directory path with trailing slash.
 */
function turbo_cache_get_storage_dir( $uri = null, $host = null, $cache_path = null ) {
	global $cached_direct_pages;

	if ( null === $cache_path ) {
		$cache_path = isset( $GLOBALS['cache_path'] ) ? $GLOBALS['cache_path'] : '';
	}

	if ( '' !== $cache_path && ! str_ends_with( $cache_path, '/' ) && ! str_ends_with( $cache_path, '\\' ) ) {
		$cache_path .= '/';
	}

	$identity = turbo_cache_get_request_identity( $uri, $host );
	$dir      = $identity['host'] . $identity['path'];

	if ( function_exists( 'apply_filters' ) ) {
		$dir = apply_filters( 'supercache_dir', $dir );
	} elseif ( function_exists( 'do_cacheaction' ) ) {
		$dir = do_cacheaction( 'supercache_dir', $dir );
	}

	$dir = $cache_path . 'supercache/' . $dir . '/';
	if ( is_array( $cached_direct_pages ) ) {
		$uri_path  = $identity['path'];
		$norm_uri  = '/' . trim( $uri_path, '/' ) . '/';
		$is_direct = false;
		foreach ( $cached_direct_pages as $page ) {
			$norm_page = '/' . trim( (string) $page, '/' ) . '/';
			if ( $norm_uri === $norm_page ) {
				$is_direct = true;
				break;
			}
		}
		if ( ! $is_direct && isset( $_SERVER['REQUEST_URI'] ) && in_array( $_SERVER['REQUEST_URI'], $cached_direct_pages, true ) ) {
			$is_direct = true;
		}
		if ( $is_direct ) {
			$base_dir = defined( 'ABSPATH' ) ? ABSPATH : '';
			$dir      = $base_dir . trim( $uri_path, '/' ) . '/';
		}
	}
	$dir = str_replace( '..', '', str_replace( '//', '/', $dir ) );

	return $dir;
}

/**
 * Check if a storage path exceeds the platform filesystem limits.
 *
 * @param string $path Path to evaluate.
 * @return bool True if path exceeds maximum allowable length.
 */
function turbo_cache_path_is_too_long( $path ) {
	if ( function_exists( 'wpsc_path_is_too_long' ) ) {
		return wpsc_path_is_too_long( $path );
	}

	$max = defined( 'PHP_MAXPATHLEN' ) ? PHP_MAXPATHLEN : 1024;
	return ( strlen( (string) $path ) + 64 ) > $max;
}

/**
 * Resolve the full filepath of the cache file for a request.
 *
 * @param string|null $uri        Optional URI.
 * @param string|null $host       Optional host.
 * @param string|null $cache_path Optional root cache path.
 * @param bool|null   $is_https   Optional HTTPS override.
 * @return string Absolute cache file path.
 */
function turbo_cache_get_cache_filepath( $uri = null, $host = null, $cache_path = null, $is_https = null ) {
	$dir      = turbo_cache_get_storage_dir( $uri, $host, $cache_path );
	$filename = turbo_cache_get_cache_filename( $is_https );

	return $dir . $filename;
}

/**
 * Generate a unique temporary file path within the specified directory.
 *
 * @param string $dir Target directory.
 * @return string Absolute temporary file path ending in .tmp.
 */
function turbo_cache_get_temp_filename( $dir ) {
	$rand_id = function_exists( 'wp_rand' ) ? wp_rand() : random_int( 0, 4294967295 );
	return rtrim( $dir, '/\\' ) . '/' . uniqid( (string) $rand_id, true ) . '.tmp';
}

/**
 * Atomically rename a temporary file to the target cache file.
 *
 * Includes Windows filesystem fallback: on Windows, rename() fails if the target
 * already exists, requiring an unlink() immediately followed by rename().
 *
 * @param string $tmp_file    Source temporary file.
 * @param string $target_file Destination target file.
 * @return bool True on successful rename, false on failure.
 */
function turbo_cache_atomic_rename( $tmp_file, $target_file ) {
	if ( ! @rename( $tmp_file, $target_file ) ) {
		@unlink( $target_file );
		return @rename( $tmp_file, $target_file );
	}
	return true;
}

/**
 * Atomically write cache content to disk.
 *
 * Follows upstream WP Super Cache contract:
 * 1. Writes to a unique .tmp file in the target directory.
 * 2. Closes file handle before renaming.
 * 3. Enforces zero-byte protection (discards empty files).
 * 4. Atomically renames with Windows fallback.
 * 5. Optionally writes compressed .gz file alongside.
 *
 * @param string      $target_file  Target cache filepath.
 * @param string      $content      Uncompressed HTML content.
 * @param string|null $gzip_content Optional pre-compressed gzip content.
 * @return bool True if written successfully, false on error.
 */
function turbo_cache_atomic_write_file( $target_file, $content, $gzip_content = null ) {
	if ( empty( $target_file ) || '' === $content || null === $content ) {
		return false;
	}

	$dir = dirname( $target_file );
	if ( ! @is_dir( $dir ) ) {
		if ( function_exists( 'wp_mkdir_p' ) ) {
			@wp_mkdir_p( $dir );
		} else {
			@mkdir( $dir, 0777, true );
		}
	}

	if ( ! @is_dir( $dir ) ) {
		return false;
	}

	$tmp_file = turbo_cache_get_temp_filename( $dir );

	$fr = @fopen( $tmp_file, 'w' );
	if ( ! $fr ) {
		return false;
	}

	$written = @fwrite( $fr, $content );
	@fclose( $fr );

	if ( false === $written || @filesize( $tmp_file ) === 0 ) {
		@unlink( $tmp_file );
		return false;
	}

	if ( ! turbo_cache_atomic_rename( $tmp_file, $target_file ) ) {
		@unlink( $tmp_file );
		return false;
	}

	// Gzip version if provided.
	if ( null !== $gzip_content && '' !== $gzip_content ) {
		$target_gz = $target_file . '.gz';
		$tmp_gz    = $tmp_file . '.gz';

		$gz_handle = @fopen( $tmp_gz, 'w' );
		if ( $gz_handle ) {
			$gz_written = @fwrite( $gz_handle, $gzip_content );
			@fclose( $gz_handle );

			if ( false !== $gz_written && @filesize( $tmp_gz ) > 0 ) {
				turbo_cache_atomic_rename( $tmp_gz, $target_gz );
			} else {
				@unlink( $tmp_gz );
			}
		}
	}

	return @file_exists( $target_file ) && ( @filesize( $target_file ) > 0 );
}
