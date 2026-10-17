<?php
/**
 * Turbo Cache — Cache Diagnostics and Observability Engine.
 *
 * Provides a lightweight, privacy-preserving, evidence-based diagnostic
 * and observability layer for cache decision inspection, bounded event logging,
 * sensitive-data redaction, and preload metrics.
 *
 * @package Turbo_Cache
 */

// phpcs:disable WordPress.PHP.NoSilencedErrors.Discouraged
// phpcs:disable WordPress.WP.AlternativeFunctions
// phpcs:disable WordPress.Security.ValidatedSanitizedInput

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) && ! defined( 'WP_CONTENT_DIR' ) && ! defined( 'WPCACHEHOME' ) ) {
	if ( ! defined( 'PHPUNIT_COMPOSER_INSTALL' ) && ! defined( 'WPSC_SMOKE_TEST' ) ) {
		return;
	}
}

if ( ! defined( 'TURBO_CACHE_DIAG_MAX_ENTRIES' ) ) {
	define( 'TURBO_CACHE_DIAG_MAX_ENTRIES', 100 );
}

if ( ! defined( 'TURBO_CACHE_DIAG_MAX_BYTES' ) ) {
	define( 'TURBO_CACHE_DIAG_MAX_BYTES', 262144 ); // 256 KB.
}

/**
 * Determine if persistent diagnostic event logging to disk is enabled.
 * Disabled by default to preserve zero disk I/O on production traffic.
 *
 * @return bool True if persistent logging is enabled, false otherwise.
 */
function turbo_cache_diagnostics_is_enabled() {
	if ( defined( 'TURBO_CACHE_DIAGNOSTICS' ) ) {
		return (bool) TURBO_CACHE_DIAGNOSTICS;
	}

	if ( isset( $GLOBALS['turbo_cache_diagnostics_enabled'] ) ) {
		return (bool) $GLOBALS['turbo_cache_diagnostics_enabled'];
	}

	if ( function_exists( 'get_option' ) ) {
		$enabled                                    = (bool) get_option( 'turbo_cache_diagnostics_enabled', false );
		$GLOBALS['turbo_cache_diagnostics_enabled'] = $enabled;
		return $enabled;
	}

	return false;
}

/**
 * Enable or disable persistent diagnostic event logging.
 *
 * @param bool $enabled True to enable, false to disable.
 * @return bool Success status.
 */
function turbo_cache_diagnostics_set_enabled( $enabled ) {
	$enabled                                    = (bool) $enabled;
	$GLOBALS['turbo_cache_diagnostics_enabled'] = $enabled;

	if ( function_exists( 'update_option' ) ) {
		return update_option( 'turbo_cache_diagnostics_enabled', $enabled, false );
	}

	return true;
}

/**
 * Recursively redact sensitive keys from parsed query arguments.
 *
 * @param array $args Array of query arguments.
 * @param array $sensitive_keys List of sensitive substring patterns.
 * @return array Tuple of array( redacted_args, was_redacted_bool ).
 */
function _turbo_cache_diagnostics_redact_query_args( array $args, array $sensitive_keys ) {
	$redacted = false;
	foreach ( $args as $k => $v ) {
		$lower_k      = strtolower( (string) $k );
		$is_sensitive = false;
		foreach ( $sensitive_keys as $pattern ) {
			if ( false !== strpos( $lower_k, $pattern ) ) {
				$args[ $k ]   = '[REDACTED]';
				$redacted     = true;
				$is_sensitive = true;
				break;
			}
		}

		if ( ! $is_sensitive && is_array( $v ) ) {
			$sub        = _turbo_cache_diagnostics_redact_query_args( $v, $sensitive_keys );
			$args[ $k ] = $sub[0];
			if ( $sub[1] ) {
				$redacted = true;
			}
		}
	}

	return array( $args, $redacted );
}

/**
 * Redact sensitive query parameters from a URL or URI string.
 *
 * Replaces values of security-sensitive keys (passwords, tokens, nonces, secrets)
 * with '[REDACTED]'.
 *
 * @param string $uri The raw URL or URI.
 * @return string Sanitized URI with sensitive parameters redacted.
 */
function turbo_cache_diagnostics_redact_uri( $uri ) {
	if ( ! is_string( $uri ) || '' === trim( $uri ) ) {
		return '';
	}

	$parts = parse_url( $uri );
	if ( ! is_array( $parts ) ) {
		return $uri;
	}

	if ( empty( $parts['query'] ) ) {
		return $uri;
	}

	$sensitive_keys = array(
		'password',
		'pwd',
		'secret',
		'token',
		'nonce',
		'_wpnonce',
		'key',
		'auth',
		'signature',
		'donotcachepage',
		'api_key',
		'session',
	);

	parse_str( $parts['query'], $query_args );
	if ( ! is_array( $query_args ) ) {
		return $uri;
	}

	$redacted_res = _turbo_cache_diagnostics_redact_query_args( $query_args, $sensitive_keys );
	if ( ! $redacted_res[1] ) {
		return $uri;
	}

	$new_query = http_build_query( $redacted_res[0] );

	// Reassemble URI.
	$scheme   = isset( $parts['scheme'] ) ? $parts['scheme'] . '://' : '';
	$host     = isset( $parts['host'] ) ? $parts['host'] : '';
	$port     = isset( $parts['port'] ) ? ':' . $parts['port'] : '';
	$path     = isset( $parts['path'] ) ? $parts['path'] : '';
	$fragment = isset( $parts['fragment'] ) ? '#' . $parts['fragment'] : '';

	return $scheme . $host . $port . $path . '?' . $new_query . $fragment;
}

/**
 * Sanitize and redact cookies for diagnostic logging.
 *
 * Cookie values are NEVER stored. Only matched cookie names are returned.
 *
 * @param array|string|null $cookies Cookies array or header string.
 * @return array List of cookie names present, with values omitted.
 */
function turbo_cache_diagnostics_redact_cookies( $cookies = null ) {
	if ( null === $cookies && isset( $_COOKIE ) ) {
		$cookies = $_COOKIE;
	}

	if ( is_string( $cookies ) ) {
		$cookie_names = array();
		$pairs        = explode( ';', $cookies );
		foreach ( $pairs as $pair ) {
			$pair = trim( $pair );
			if ( '' === $pair ) {
				continue;
			}
			$eq_pos         = strpos( $pair, '=' );
			$name           = ( false !== $eq_pos ) ? substr( $pair, 0, $eq_pos ) : $pair;
			$cookie_names[] = trim( $name );
		}
		return array_values( array_unique( $cookie_names ) );
	}

	if ( is_array( $cookies ) ) {
		return array_keys( $cookies );
	}

	return array();
}

/**
 * Sanitize arbitrary diagnostic detail array, ensuring sensitive keys are redacted.
 *
 * @param mixed $data Data to sanitize.
 * @return mixed Sanitized data.
 */
function turbo_cache_diagnostics_sanitize_data( $data ) {
	if ( is_array( $data ) ) {
		$sanitized      = array();
		$sensitive_keys = array( 'password', 'pwd', 'token', 'secret', 'auth', 'nonce', 'signature', 'cookie', 'cookies', 'pass', 'key' );
		foreach ( $data as $key => $val ) {
			$lower_k   = strtolower( (string) $key );
			$is_secret = false;

			if ( false !== strpos( $lower_k, 'cookie' ) ) {
				$sanitized[ $key ] = turbo_cache_diagnostics_redact_cookies( $val );
				$is_secret         = true;
			} else {
				foreach ( $sensitive_keys as $pat ) {
					if ( false !== strpos( $lower_k, $pat ) ) {
						$sanitized[ $key ] = '[REDACTED]';
						$is_secret         = true;
						break;
					}
				}
			}

			if ( ! $is_secret ) {
				if ( is_array( $val ) ) {
					$sanitized[ $key ] = turbo_cache_diagnostics_sanitize_data( $val );
				} elseif ( is_string( $val ) ) {
					$sanitized[ $key ] = strip_tags( substr( $val, 0, 500 ) );
				} else {
					$sanitized[ $key ] = $val;
				}
			}
		}
		return $sanitized;
	}

	if ( is_string( $data ) ) {
		return strip_tags( substr( $data, 0, 500 ) );
	}

	return $data;
}

/**
 * Map diagnostic outcome to high-level category.
 *
 * @param string $outcome Diagnostic outcome string.
 * @return string Category ('hit', 'miss', 'bypass', 'generation', 'preload').
 */
function turbo_cache_diagnostics_get_category( $outcome ) {
	$outcome = (string) $outcome;

	if ( 0 === strpos( $outcome, 'HIT_' ) ) {
		return 'hit';
	}
	if ( 0 === strpos( $outcome, 'MISS_' ) ) {
		return 'miss';
	}
	if ( 0 === strpos( $outcome, 'BYPASS_' ) ) {
		return 'bypass';
	}
	if ( 0 === strpos( $outcome, 'GENERATION_' ) ) {
		return 'generation';
	}
	if ( 0 === strpos( $outcome, 'PRELOAD_' ) ) {
		return 'preload';
	}

	return 'diagnostic';
}

/**
 * Map early request rules or output buffer bypass reason to diagnostic outcome.
 *
 * @param string $reason Raw bypass reason.
 * @return string Standardized diagnostic outcome code.
 */
function turbo_cache_diagnostics_map_bypass_reason( $reason ) {
	$reason = (string) $reason;

	if ( 'cache_disabled' === $reason || 0 === strpos( $reason, 'disabled:' ) ) {
		return 'BYPASS_DISABLED';
	}
	if ( 0 === strpos( $reason, 'http_method:' ) || 0 === strpos( $reason, 'method:' ) ) {
		return 'BYPASS_METHOD';
	}
	if ( 0 === strpos( $reason, 'backend_or_system:' ) || 0 === strpos( $reason, 'backend:' ) || 0 === strpos( $reason, 'system:' ) || false !== strpos( $reason, 'admin' ) ) {
		return 'BYPASS_BACKEND';
	}
	if ( 'rest_or_ajax' === $reason ) {
		return 'BYPASS_REST_OR_AJAX';
	}
	if ( 0 === strpos( $reason, 'woocommerce_cookie:' ) || false !== strpos( $reason, 'woocommerce' ) ) {
		return 'BYPASS_WOOCOMMERCE_COOKIE';
	}
	if ( 0 === strpos( $reason, 'cookie:wordpress_logged_in' ) || 0 === strpos( $reason, 'cookie:auth' ) || false !== strpos( $reason, 'logged_in' ) ) {
		return 'BYPASS_AUTH_COOKIE';
	}
	if ( 'cookie:rejected_cookie' === $reason || false !== strpos( $reason, 'rejected_cookie' ) ) {
		return 'BYPASS_REJECTED_COOKIE';
	}
	if ( 0 === strpos( $reason, 'query_params:' ) || 0 === strpos( $reason, 'query:' ) || 'query_bypass' === $reason ) {
		return 'BYPASS_QUERY_PARAMS';
	}
	if ( 'rejected_uri_rule' === $reason || 'URI rejected' === $reason || false !== strpos( $reason, 'uri_rule' ) ) {
		return 'BYPASS_REJECTED_URI';
	}
	if ( 'rejected_user_agent' === $reason || false !== strpos( $reason, 'user_agent' ) ) {
		return 'BYPASS_REJECTED_UA';
	}
	if ( false !== strpos( $reason, 'DONOTCACHEPAGE' ) ) {
		return 'BYPASS_CUSTOM_FILTER';
	}

	return 'BYPASS_REQUEST_SAFETY';
}

/**
 * Retrieve path to the persistent diagnostics log file.
 *
 * @return string File path.
 */
function turbo_cache_diagnostics_get_log_file_path() {
	global $cache_path;
	$base = ( isset( $cache_path ) && '' !== $cache_path ) ? $cache_path : sys_get_temp_dir() . '/';
	return rtrim( str_replace( '\\', '/', $base ), '/' ) . '/turbo_diagnostics.json';
}

/**
 * Record a cache decision and diagnostic outcome.
 *
 * Always populates in-memory context for the current request.
 * If persistent logging is enabled, atomically appends to bounded ring buffer.
 *
 * @param string $outcome Outcome code (e.g. 'HIT_SUPERCACHE', 'BYPASS_WOOCOMMERCE_COOKIE').
 * @param string $reason  Human-readable explanation or bypass reason.
 * @param array  $details Optional metadata (timing, file size, etag) without secrets.
 * @return array The recorded diagnostic entry.
 */
function turbo_cache_diagnostics_record( $outcome, $reason = '', $details = array() ) {
	$category = turbo_cache_diagnostics_get_category( $outcome );

	$raw_uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '/';
	$uri     = turbo_cache_diagnostics_redact_uri( $raw_uri );
	$method  = isset( $_SERVER['REQUEST_METHOD'] ) ? (string) $_SERVER['REQUEST_METHOD'] : 'GET';

	$entry = array(
		'timestamp' => time(),
		'outcome'   => (string) $outcome,
		'category'  => $category,
		'reason'    => strip_tags( (string) $reason ),
		'method'    => $method,
		'uri'       => $uri,
		'details'   => turbo_cache_diagnostics_sanitize_data( (array) $details ),
	);

	// 1. In-memory record for current request (zero disk I/O, sub-microsecond).
	$GLOBALS['turbo_cache_last_diagnostic'] = $entry;

	// 2. Integration with upstream wp_cache_debug if debug logging is enabled.
	if ( function_exists( 'wp_cache_debug' ) && ! empty( $GLOBALS['wp_super_cache_debug'] ) ) {
		wp_cache_debug( "[TurboCache Diagnostic] outcome={$entry['outcome']} category={$entry['category']} reason={$entry['reason']} uri={$entry['uri']}" );
	}

	// 3. Persistent disk logging if explicitly enabled.
	if ( turbo_cache_diagnostics_is_enabled() ) {
		turbo_cache_diagnostics_append_log( $entry );
	}

	return $entry;
}

/**
 * Retrieve the diagnostic record for the current request.
 *
 * @return array|null The active request diagnostic entry, or null if none recorded.
 */
function turbo_cache_diagnostics_get_current() {
	return isset( $GLOBALS['turbo_cache_last_diagnostic'] ) ? $GLOBALS['turbo_cache_last_diagnostic'] : null;
}

/**
 * Append an entry to the persistent bounded circular ring buffer.
 *
 * Enforces maximum entry count (100) and maximum file size (256 KB) via FIFO.
 *
 * @param array $entry Diagnostic entry to append.
 * @return bool True on success, false on failure.
 */
function turbo_cache_diagnostics_append_log( $entry ) {
	$log_file = turbo_cache_diagnostics_get_log_file_path();
	$dir      = dirname( $log_file );

	if ( ! is_dir( $dir ) ) {
		if ( function_exists( 'wp_mkdir_p' ) ) {
			@wp_mkdir_p( $dir );
		} else {
			@mkdir( $dir, 0777, true );
		}
	}

	// Concurrency safety: acquire exclusive file lock on lockfile.
	$lock_file = $log_file . '.lock';
	$lock_fp   = @fopen( $lock_file, 'c+' );
	if ( $lock_fp ) {
		@flock( $lock_fp, LOCK_EX );
	}

	$entries = array();
	if ( file_exists( $log_file ) ) {
		$content = file_get_contents( $log_file );
		if ( false !== $content && '' !== $content ) {
			$data = json_decode( $content, true );
			if ( is_array( $data ) && isset( $data['entries'] ) && is_array( $data['entries'] ) ) {
				$entries = $data['entries'];
			}
		}
	}

	// Append new entry.
	$entries[] = $entry;

	// Bounded ring buffer: enforce TURBO_CACHE_DIAG_MAX_ENTRIES (FIFO).
	$max_entries = function_exists( 'apply_filters' )
		? (int) apply_filters( 'turbo_cache_diagnostics_max_entries', TURBO_CACHE_DIAG_MAX_ENTRIES )
		: TURBO_CACHE_DIAG_MAX_ENTRIES;
	if ( count( $entries ) > $max_entries ) {
		$entries = array_slice( $entries, -1 * $max_entries );
	}

	$payload = array(
		'updated_at'    => time(),
		'total_entries' => count( $entries ),
		'entries'       => $entries,
	);

	$json = function_exists( 'wp_json_encode' ) ? wp_json_encode( $payload, JSON_UNESCAPED_SLASHES ) : json_encode( $payload, JSON_UNESCAPED_SLASHES );
	if ( false === $json ) {
		if ( $lock_fp ) {
			@flock( $lock_fp, LOCK_UN );
			@fclose( $lock_fp );
		}
		return false;
	}

	// Bounded file size: enforce TURBO_CACHE_DIAG_MAX_BYTES (256 KB) via FIFO.
	$max_bytes = defined( 'TURBO_CACHE_DIAG_MAX_BYTES' ) ? TURBO_CACHE_DIAG_MAX_BYTES : 262144;
	$json_len  = strlen( $json );
	$entry_ct  = count( $entries );
	while ( $json_len > $max_bytes && $entry_ct > 10 ) {
		array_shift( $entries );
		--$entry_ct;
		$payload['entries']       = $entries;
		$payload['total_entries'] = $entry_ct;
		$json                     = function_exists( 'wp_json_encode' ) ? wp_json_encode( $payload, JSON_UNESCAPED_SLASHES ) : json_encode( $payload, JSON_UNESCAPED_SLASHES );
		$json_len                 = ( false !== $json ) ? strlen( $json ) : 0;
	}

	// Atomic write: write to unique temporary file then rename.
	$temp_file = $log_file . '.' . uniqid( 'diag_tmp_', true );
	$written   = @file_put_contents( $temp_file, $json );
	if ( false !== $written ) {
		if ( file_exists( $log_file ) && strtoupper( substr( PHP_OS, 0, 3 ) ) === 'WIN' ) {
			@unlink( $log_file );
		}
		$renamed = @rename( $temp_file, $log_file );
		if ( ! $renamed ) {
			@copy( $temp_file, $log_file );
			@unlink( $temp_file );
		}
	}

	if ( $lock_fp ) {
		@flock( $lock_fp, LOCK_UN );
		@fclose( $lock_fp );
	}

	return false !== $written;
}

/**
 * Retrieve recent diagnostic entries from the persistent log.
 *
 * @param int $limit Maximum number of entries to return.
 * @return array List of recent entries (most recent first).
 */
function turbo_cache_diagnostics_get_recent_log( $limit = 50 ) {
	$log_file = turbo_cache_diagnostics_get_log_file_path();
	if ( ! file_exists( $log_file ) ) {
		return array();
	}

	$content = file_get_contents( $log_file );
	if ( false === $content || '' === $content ) {
		return array();
	}

	$data = json_decode( $content, true );
	if ( ! is_array( $data ) || ! isset( $data['entries'] ) || ! is_array( $data['entries'] ) ) {
		return array();
	}

	$entries = array_reverse( $data['entries'] );
	return array_slice( $entries, 0, max( 1, (int) $limit ) );
}

/**
 * Clear the persistent diagnostics log file.
 *
 * @return bool True if deleted or did not exist.
 */
function turbo_cache_diagnostics_clear_log() {
	$GLOBALS['turbo_cache_last_diagnostic'] = null;
	$log_file                               = turbo_cache_diagnostics_get_log_file_path();
	if ( file_exists( $log_file ) ) {
		return @unlink( $log_file );
	}
	return true;
}

/**
 * Generate aggregate diagnostic metrics summary.
 *
 * @return array Summary of metrics and preload status.
 */
function turbo_cache_diagnostics_get_summary() {
	$entries = turbo_cache_diagnostics_get_recent_log( 100 );

	$summary = array(
		'logging_enabled' => turbo_cache_diagnostics_is_enabled(),
		'total_recorded'  => count( $entries ),
		'by_category'     => array(
			'hit'        => 0,
			'miss'       => 0,
			'bypass'     => 0,
			'generation' => 0,
			'preload'    => 0,
		),
		'by_outcome'      => array(),
		'preload'         => array(
			'total_discovered' => 0,
			'processed'        => 0,
			'cached'           => 0,
			'skipped'          => 0,
			'failed'           => 0,
			'skip_reasons'     => array(),
		),
	);

	foreach ( $entries as $entry ) {
		$cat = isset( $entry['category'] ) ? $entry['category'] : 'other';
		if ( isset( $summary['by_category'][ $cat ] ) ) {
			++$summary['by_category'][ $cat ];
		}

		$out                           = isset( $entry['outcome'] ) ? $entry['outcome'] : 'unknown';
		$summary['by_outcome'][ $out ] = isset( $summary['by_outcome'][ $out ] ) ? $summary['by_outcome'][ $out ] + 1 : 1;
	}

	// Integrate with Phase 8 Preload state if available.
	if ( function_exists( 'turbo_cache_preload_get_state' ) ) {
		$state = turbo_cache_preload_get_state();
		if ( is_array( $state ) && isset( $state['stats'] ) && is_array( $state['stats'] ) ) {
			$summary['preload'] = $state['stats'];
		}
	}

	return $summary;
}

// -----------------------------------------------------------------------------
// Administrative AJAX Endpoints (Protected by Capability & Nonce)
// -----------------------------------------------------------------------------

/**
 * AJAX handler to retrieve diagnostic summary and recent entries.
 */
function wpsc_ajax_get_diagnostics() {
	check_ajax_referer( 'wpsc-diagnostics-nonce' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( 'forbidden', 403 );
	}

	$summary = turbo_cache_diagnostics_get_summary();
	$recent  = turbo_cache_diagnostics_get_recent_log( 50 );

	wp_send_json_success(
		array(
			'summary' => $summary,
			'recent'  => $recent,
		),
		null,
		JSON_UNESCAPED_SLASHES
	);
}
if ( function_exists( 'add_action' ) ) {
	add_action( 'wp_ajax_wpsc_get_diagnostics', 'wpsc_ajax_get_diagnostics' );
}

/**
 * AJAX handler to clear diagnostic logs.
 */
function wpsc_ajax_clear_diagnostics() {
	check_ajax_referer( 'wpsc-diagnostics-nonce' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( 'forbidden', 403 );
	}

	turbo_cache_diagnostics_clear_log();
	wp_send_json_success( array( 'cleared' => true ) );
}
if ( function_exists( 'add_action' ) ) {
	add_action( 'wp_ajax_wpsc_clear_diagnostics', 'wpsc_ajax_clear_diagnostics' );
}

/**
 * AJAX handler to toggle persistent diagnostic logging.
 */
function wpsc_ajax_toggle_diagnostics() {
	check_ajax_referer( 'wpsc-diagnostics-nonce' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( 'forbidden', 403 );
	}

	$enable = isset( $_POST['enable'] ) && '1' === (string) $_POST['enable'];
	turbo_cache_diagnostics_set_enabled( $enable );

	wp_send_json_success( array( 'enabled' => $enable ) );
}
if ( function_exists( 'add_action' ) ) {
	add_action( 'wp_ajax_wpsc_toggle_diagnostics', 'wpsc_ajax_toggle_diagnostics' );
}
