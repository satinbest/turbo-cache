<?php
/**
 * Turbo Cache — High-Performance Cache Delivery & Conditional GET Layer.
 *
 * Provides RFC 7232-compliant HTTP conditional validation (ETag & If-None-Match,
 * If-Modified-Since), streamlined 304 Not Modified responses, accurate delivery
 * headers, and memory-efficient streaming for static cache serving.
 *
 * @package Turbo_Cache
 */

// phpcs:disable WordPress.PHP.NoSilencedErrors.Discouraged
// phpcs:disable WordPress.WP.AlternativeFunctions
// phpcs:disable WordPress.Security.ValidatedSanitizedInput
// phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) && ! defined( 'WP_CONTENT_DIR' ) && ! defined( 'WPCACHEHOME' ) ) {
	if ( ! defined( 'PHPUNIT_COMPOSER_INSTALL' ) && ! defined( 'WPSC_SMOKE_TEST' ) ) {
		return;
	}
}

/**
 * Generate an RFC 7232-compliant strong ETag for a cached file.
 *
 * Combines hex-encoded file modification time and file size into a quoted string.
 *
 * @param int $mtime File modification timestamp.
 * @param int $size  File size in bytes.
 * @return string Quoted ETag string (e.g. '"68f12a-4a2b"'), or '' on invalid input.
 */
function turbo_cache_generate_etag( $mtime, $size ) {
	$mtime = (int) $mtime;
	$size  = (int) $size;

	if ( $mtime <= 0 || $size < 0 ) {
		return '';
	}

	return sprintf( '"%x-%x"', $mtime, $size );
}

/**
 * Parse an If-None-Match header value into normalized ETag identifiers.
 *
 * Handles:
 * - Wildcard '*'
 * - Weak indicators (W/"...") per RFC 7232 Section 3.2
 * - Quoted and unquoted tags
 * - Comma-delimited lists of tags
 *
 * @param string|null $header Raw If-None-Match header value.
 * @return array<int, string> List of normalized ETag strings, or empty array.
 */
function turbo_cache_parse_if_none_match( $header ) {
	if ( ! is_string( $header ) ) {
		return array();
	}

	$header = trim( $header );
	if ( '' === $header ) {
		return array();
	}

	if ( '*' === $header ) {
		return array( '*' );
	}

	$raw_tags = explode( ',', $header );
	$tags     = array();

	foreach ( $raw_tags as $tag ) {
		$tag = trim( $tag );
		if ( '' === $tag ) {
			continue;
		}

		// Strip weak indicator W/ (case-insensitive).
		if ( 0 === stripos( $tag, 'W/' ) ) {
			$tag = substr( $tag, 2 );
		}

		// Strip surrounding quotes and trim whitespace.
		$tag = trim( trim( $tag ), '"\'' );
		$tag = trim( $tag );

		if ( '' !== $tag ) {
			$tags[] = $tag;
		}
	}

	return array_values( array_unique( $tags ) );
}

/**
 * Retrieve a request header case-insensitively across server environments.
 *
 * Checks in order:
 * 1. Explicit $headers array (if provided).
 * 2. wpsc_apache_request_headers() / apache_request_headers().
 * 3. $_SERVER / $server HTTP_* variables.
 *
 * @param string            $header_name Name of the header (e.g. 'If-None-Match').
 * @param array|null        $server      Optional $_SERVER override.
 * @param array|null        $headers     Optional headers map override.
 * @return string|null Header value if found, null otherwise.
 */
function turbo_cache_get_request_header( $header_name, $server = null, $headers = null ) {
	if ( ! is_string( $header_name ) || '' === $header_name ) {
		return null;
	}

	$target_lower = strtolower( $header_name );

	// 1. Explicit headers map override.
	if ( is_array( $headers ) ) {
		foreach ( $headers as $key => $val ) {
			if ( strtolower( (string) $key ) === $target_lower ) {
				return is_scalar( $val ) ? (string) $val : null;
			}
		}
	}

	// 2. Apache request headers if available.
	$apache_headers = null;
	if ( function_exists( 'wpsc_apache_request_headers' ) ) {
		$apache_headers = wpsc_apache_request_headers();
	} elseif ( function_exists( 'apache_request_headers' ) ) {
		$apache_headers = @apache_request_headers();
	}

	if ( is_array( $apache_headers ) ) {
		foreach ( $apache_headers as $key => $val ) {
			if ( strtolower( (string) $key ) === $target_lower ) {
				return is_scalar( $val ) ? (string) $val : null;
			}
		}
	}

	// 3. $_SERVER environment lookup.
	if ( null === $server ) {
		$server = isset( $_SERVER ) ? $_SERVER : array();
	}

	if ( is_array( $server ) ) {
		$server_key = 'HTTP_' . strtoupper( str_replace( '-', '_', $header_name ) ) ;
		if ( isset( $server[ $server_key ] ) && is_scalar( $server[ $server_key ] ) ) {
			return (string) $server[ $server_key ];
		}
	}

	return null;
}

/**
 * Evaluate conditional GET request headers (If-None-Match, If-Modified-Since).
 *
 * Conforms to RFC 7232:
 * - If-None-Match takes precedence over If-Modified-Since.
 * - If If-None-Match is present and fails, If-Modified-Since MUST NOT be evaluated.
 * - Weak comparison is permitted for If-None-Match 304 responses.
 *
 * @param string     $file_path Full filesystem path to the cache file.
 * @param array|null $server    Optional $_SERVER override for testing.
 * @param array|null $headers   Optional headers map override for testing.
 * @return array{is_304:bool,etag:string,mtime:int,matched_by:string|null} Validation result.
 */
function turbo_cache_validate_conditional_request( $file_path, $server = null, $headers = null ) {
	$result = array(
		'is_304'     => false,
		'etag'       => '',
		'mtime'      => 0,
		'matched_by' => null,
	);

	if ( ! is_string( $file_path ) || '' === $file_path || ! @file_exists( $file_path ) ) {
		return $result;
	}

	$mtime = (int) @filemtime( $file_path );
	$size  = (int) @filesize( $file_path );

	if ( $mtime <= 0 ) {
		return $result;
	}

	$etag             = turbo_cache_generate_etag( $mtime, $size );
	$result['etag']   = $etag;
	$result['mtime']  = $mtime;

	$clean_local_etag = trim( $etag, '"' );

	$if_none_match = turbo_cache_get_request_header( 'If-None-Match', $server, $headers );

	// Case 1: If-None-Match is present.
	if ( null !== $if_none_match && '' !== trim( $if_none_match ) ) {
		$client_etags = turbo_cache_parse_if_none_match( $if_none_match );

		if ( in_array( '*', $client_etags, true ) || in_array( $clean_local_etag, $client_etags, true ) ) {
			$result['is_304']     = true;
			$result['matched_by'] = 'if_none_match';
			return $result;
		}

		// RFC 7232 § 3.3: If If-None-Match was evaluated and did NOT match,
		// do NOT evaluate If-Modified-Since.
		return $result;
	}

	// Case 2: If-Modified-Since fallback.
	$if_modified_since = turbo_cache_get_request_header( 'If-Modified-Since', $server, $headers );
	if ( null !== $if_modified_since && '' !== trim( $if_modified_since ) ) {
		// Strip any trailing semicolon parameters (e.g. "; length=1234").
		$semicolon_pos = strpos( $if_modified_since, ';' );
		if ( false !== $semicolon_pos ) {
			$if_modified_since = substr( $if_modified_since, 0, $semicolon_pos );
		}

		$client_mtime = @strtotime( trim( $if_modified_since ) );
		if ( false !== $client_mtime && $client_mtime >= $mtime ) {
			$result['is_304']     = true;
			$result['matched_by'] = 'if_modified_since';
			return $result;
		}
	}

	return $result;
}

/**
 * Send HTTP 304 Not Modified headers and terminate or return.
 *
 * @param string $etag  RFC 7232 ETag string.
 * @param int    $mtime File modification timestamp.
 * @param bool   $exit  Whether to exit after sending headers (false for testing).
 * @return array<string, string> Array of headers sent.
 */
function turbo_cache_send_conditional_304( $etag, $mtime, $exit = true ) {
	$protocol = isset( $_SERVER['SERVER_PROTOCOL'] ) ? $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.1';
	$last_mod = gmdate( 'D, d M Y H:i:s', (int) $mtime ) . ' GMT';

	$vary = defined( 'WPSC_VARY_HEADER' ) && WPSC_VARY_HEADER !== '' ? WPSC_VARY_HEADER : 'Accept-Encoding, Cookie';
	$cache_control = defined( 'WPSC_CACHE_CONTROL_HEADER' ) && WPSC_CACHE_CONTROL_HEADER !== '' ? WPSC_CACHE_CONTROL_HEADER : 'max-age=3, must-revalidate';

	$headers = array(
		'Status'            => $protocol . ' 304 Not Modified',
		'ETag'              => $etag,
		'Last-Modified'     => $last_mod,
		'Vary'              => $vary,
		'Cache-Control'     => $cache_control,
		'X-Turbo-Cache'     => 'HIT (304 Not Modified)',
		'X-WP-Super-Cache'  => 'Served supercache 304 file from PHP',
	);

	if ( ! headers_sent() ) {
		header( $headers['Status'] );
		header( 'ETag: ' . $headers['ETag'] );
		header( 'Last-Modified: ' . $headers['Last-Modified'] );
		header( 'Vary: ' . $headers['Vary'] );
		header( 'Cache-Control: ' . $headers['Cache-Control'] );
		header( 'X-Turbo-Cache: ' . $headers['X-Turbo-Cache'] );
		header( 'X-WP-Super-Cache: ' . $headers['X-WP-Super-Cache'] );
	}

	if ( function_exists( 'wp_cache_debug' ) ) {
		wp_cache_debug( "turbo_cache_send_conditional_304: sent 304 Not Modified (ETag: $etag, Last-Modified: $last_mod)" );
	}

	if ( $exit && ! defined( 'PHPUNIT_COMPOSER_INSTALL' ) && ! defined( 'WPSC_SMOKE_TEST' ) ) {
		exit( 0 );
	}

	return $headers;
}

/**
 * Emit modern cache delivery headers for an HTTP 200 cache hit.
 *
 * @param string $etag           ETag string.
 * @param int    $mtime          File modification timestamp.
 * @param int    $content_length Byte length of the payload being served.
 * @param string $encoding       Optional content encoding ('gzip' or '').
 * @param string $served_from    Cache descriptor (e.g. 'supercache', 'supercache-gzip').
 * @return array<string, string> Headers emitted.
 */
function turbo_cache_send_delivery_headers( $etag, $mtime, $content_length, $encoding = '', $served_from = 'supercache' ) {
	$last_mod = gmdate( 'D, d M Y H:i:s', (int) $mtime ) . ' GMT';

	$headers = array(
		'ETag'          => $etag,
		'Last-Modified' => $last_mod,
		'Content-Length'=> (string) ( (int) $content_length ),
		'X-Turbo-Cache' => 'HIT (' . $served_from . ')',
	);

	if ( ! empty( $encoding ) ) {
		$headers['Content-Encoding'] = $encoding;
	}

	if ( ! headers_sent() ) {
		header( 'ETag: ' . $headers['ETag'] );
		header( 'Last-Modified: ' . $headers['Last-Modified'] );
		header( 'Content-Length: ' . $headers['Content-Length'] );
		header( 'X-Turbo-Cache: ' . $headers['X-Turbo-Cache'] );
		if ( ! empty( $encoding ) ) {
			header( 'Content-Encoding: ' . $encoding );
		}
	}

	return $headers;
}

/**
 * Stream a static cache file directly to output with zero PHP memory buffering.
 *
 * @param string $file_path Path to file to stream.
 * @return int|false Bytes written, or false on error.
 */
function turbo_cache_stream_cache_file( $file_path ) {
	if ( ! is_string( $file_path ) || '' === $file_path || ! @file_exists( $file_path ) ) {
		return false;
	}

	return @readfile( $file_path );
}
