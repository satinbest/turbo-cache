<?php
/**
 * Turbo Cache — Safe Cache Preload Engine.
 *
 * Provides a safe, resource-conscious, resumable cache preload mechanism
 * with XML sitemap discovery, strict host and safety validations,
 * WooCommerce dynamic endpoint protection, bounded batching,
 * circuit-breaker protection, and comprehensive diagnostics.
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
 * Safe wrapper for URL parsing.
 *
 * @param string $url       URL to parse.
 * @param int    $component Optional PHP_URL_* component.
 * @return mixed Parsed components.
 */
function turbo_cache_parse_url( $url, $component = -1 ) {
	if ( function_exists( 'wp_parse_url' ) ) {
		return wp_parse_url( (string) $url, $component );
	}
	return parse_url( (string) $url, $component );
}

/**
 * Safe JSON encoder.
 *
 * @param mixed $data    Data to encode.
 * @param int   $options Encode options.
 * @return string|false JSON string or false.
 */
function turbo_cache_json_encode( $data, $options = 0 ) {
	if ( function_exists( 'wp_json_encode' ) ) {
		return wp_json_encode( $data, $options );
	}
	return json_encode( $data, $options );
}

/**
 * Normalize a URL for consistent queue handling and deduplication.
 *
 * Strips fragments, normalizes scheme and host to lowercase, trims whitespace,
 * and removes redundant default ports.
 *
 * @param string $url Input URL.
 * @return string Normalized URL or empty string if invalid.
 */
function turbo_cache_preload_normalize_url( $url ) {
	if ( ! is_string( $url ) || '' === trim( $url ) ) {
		return '';
	}

	$url = trim( $url );

	// Strip fragment.
	$fragment_pos = strpos( $url, '#' );
	if ( false !== $fragment_pos ) {
		$url = substr( $url, 0, $fragment_pos );
	}

	$parts = turbo_cache_parse_url( $url );
	if ( ! is_array( $parts ) || empty( $parts['host'] ) || empty( $parts['scheme'] ) ) {
		return '';
	}

	$scheme = strtolower( $parts['scheme'] );
	$host   = strtolower( $parts['host'] );

	$port = '';
	if ( isset( $parts['port'] ) ) {
		if ( ( 'http' === $scheme && 80 !== (int) $parts['port'] ) ||
			( 'https' === $scheme && 443 !== (int) $parts['port'] ) ) {
			$port = ':' . $parts['port'];
		}
	}

	$path = isset( $parts['path'] ) ? $parts['path'] : '/';
	if ( '' === $path ) {
		$path = '/';
	}

	$query = '';
	if ( isset( $parts['query'] ) && '' !== $parts['query'] ) {
		$query = '?' . $parts['query'];
	}

	return $scheme . '://' . $host . $port . $path . $query;
}

/**
 * Validate whether a URL is safe and eligible for preloading.
 *
 * Enforces strict criteria:
 * 1. Scheme must be http or https.
 * 2. Host must strictly match the site's own host (prevents external SSRF/crawling).
 * 3. Path must not contain directory traversal sequences (..).
 * 4. Must not match WordPress backend, login, cron, or admin endpoints.
 * 5. Must not match REST API or AJAX endpoints.
 * 6. Must not match WooCommerce sensitive/dynamic endpoints (cart, checkout, account).
 * 7. Must not contain cache-bypass query arguments.
 * 8. Must respect user-configured rejected URIs (wp_cache_is_rejected).
 *
 * @param string      $url      The URL to validate.
 * @param string|null $site_url Optional site URL override for testing.
 * @return array Array with 'eligible' (bool) and 'reason' (string).
 */
function turbo_cache_preload_is_safe_url( $url, $site_url = null ) {
	if ( ! is_string( $url ) || '' === trim( $url ) ) {
		return array(
			'eligible' => false,
			'reason'   => 'empty_url',
		);
	}

	$parts = turbo_cache_parse_url( $url );
	if ( ! is_array( $parts ) || empty( $parts['scheme'] ) ) {
		return array(
			'eligible' => false,
			'reason'   => 'malformed_url',
		);
	}

	// 1. Scheme check.
	$scheme = strtolower( $parts['scheme'] );
	if ( 'http' !== $scheme && 'https' !== $scheme ) {
		return array(
			'eligible' => false,
			'reason'   => 'invalid_scheme',
		);
	}

	if ( empty( $parts['host'] ) ) {
		return array(
			'eligible' => false,
			'reason'   => 'malformed_url',
		);
	}

	// 2. Host verification (supports multisite, mapped domains, and domain aliases).
	if ( null === $site_url ) {
		$site_url = function_exists( 'home_url' ) ? home_url() : ( function_exists( 'site_url' ) ? site_url() : ( function_exists( 'get_option' ) ? (string) get_option( 'home' ) : '' ) );
	}
	$site_parts = turbo_cache_parse_url( $site_url );
	$site_host  = ( is_array( $site_parts ) && ! empty( $site_parts['host'] ) ) ? strtolower( $site_parts['host'] ) : '';

	$allowed_hosts = array( $site_host );
	if ( function_exists( 'site_url' ) ) {
		$su_parts = turbo_cache_parse_url( site_url() );
		if ( is_array( $su_parts ) && ! empty( $su_parts['host'] ) ) {
			$allowed_hosts[] = strtolower( $su_parts['host'] );
		}
	}
	$allowed_hosts = array_values( array_unique( array_filter( $allowed_hosts ) ) );
	if ( function_exists( 'apply_filters' ) ) {
		$allowed_hosts = (array) apply_filters( 'turbo_cache_preload_allowed_hosts', $allowed_hosts, $url );
		$allowed_hosts = array_map( 'strtolower', $allowed_hosts );
	}

	if ( ! empty( $allowed_hosts ) && ! in_array( strtolower( $parts['host'] ), $allowed_hosts, true ) ) {
		return array(
			'eligible' => false,
			'reason'   => 'external_host',
		);
	}

	// 2a. Port verification (SSRF prevention against non-HTTP/internal ports).
	$target_port = isset( $parts['port'] ) ? (int) $parts['port'] : ( 'https' === $scheme ? 443 : 80 );
	$site_port   = ( is_array( $site_parts ) && isset( $site_parts['port'] ) ) ? (int) $site_parts['port'] : ( ( is_array( $site_parts ) && isset( $site_parts['scheme'] ) && 'https' === strtolower( $site_parts['scheme'] ) ) ? 443 : 80 );

	$standard_ports = array( 80, 443 );
	if ( in_array( $site_port, $standard_ports, true ) ) {
		// For standard web sites, restrict strictly to standard web ports (80 and 443).
		if ( ! in_array( $target_port, $standard_ports, true ) ) {
			return array(
				'eligible' => false,
				'reason'   => 'invalid_port',
			);
		}
	} elseif ( $target_port !== $site_port ) {
		// For sites running on non-standard ports (e.g. localhost:8080), port must match explicitly.
		return array(
			'eligible' => false,
			'reason'   => 'invalid_port',
		);
	}

	$path = isset( $parts['path'] ) ? $parts['path'] : '/';

	// 2b. Subdirectory scope verification.
	if ( is_array( $site_parts ) && ! empty( $site_parts['path'] ) ) {
		$site_path = rtrim( $site_parts['path'], '/' ) . '/';
		if ( '/' !== $site_path && 0 !== strpos( $path, $site_path ) && $path !== rtrim( $site_path, '/' ) ) {
			return array(
				'eligible' => false,
				'reason'   => 'out_of_path_scope',
			);
		}
	}

	// 3. Path traversal check.
	if ( false !== strpos( $path, '..' ) || false !== strpos( $path, "\0" ) ) {
		return array(
			'eligible' => false,
			'reason'   => 'path_traversal',
		);
	}

	$path_lower = strtolower( $path );

	// 4. Backend, login, cron blacklist.
	$backend_patterns = array(
		'/wp-admin/',
		'wp-login.php',
		'wp-register.php',
		'wp-cron.php',
		'xmlrpc.php',
		'wp-app.php',
		'wp-trackback.php',
	);
	foreach ( $backend_patterns as $pattern ) {
		if ( false !== strpos( $path_lower, $pattern ) ) {
			return array(
				'eligible' => false,
				'reason'   => 'backend_admin',
			);
		}
	}

	// 5. REST API & AJAX.
	if ( 0 === strpos( $path_lower, '/wp-json/' ) || false !== strpos( $path_lower, 'admin-ajax.php' ) ) {
		return array(
			'eligible' => false,
			'reason'   => 'rest_or_ajax',
		);
	}

	// 6. WooCommerce sensitive endpoints.
	$wc_patterns    = array(
		'/cart/',
		'/checkout/',
		'/my-account/',
		'/order-received/',
		'wc-api',
		'wc-ajax',
		'add-to-cart',
	);
	$path_and_query = $path_lower . ( isset( $parts['query'] ) ? '?' . strtolower( $parts['query'] ) : '' );
	foreach ( $wc_patterns as $pattern ) {
		if ( false !== strpos( $path_and_query, $pattern ) ) {
			return array(
				'eligible' => false,
				'reason'   => 'woocommerce_sensitive',
			);
		}
	}

	// 7. Query parameters bypass check.
	if ( isset( $parts['query'] ) && '' !== $parts['query'] ) {
		$query       = $parts['query'];
		$query_lower = strtolower( $query );

		$bypass_params = array(
			'donotcachepage',
			'customize_changeset_uuid',
			'preview=true',
			'rest_route=',
		);
		foreach ( $bypass_params as $param ) {
			if ( false !== strpos( $query_lower, $param ) ) {
				return array(
					'eligible' => false,
					'reason'   => 'query_bypass',
				);
			}
		}
	}

	// 8. WP Super Cache rejected URI rules.
	if ( function_exists( 'wp_cache_is_rejected' ) && wp_cache_is_rejected( $path ) ) {
		return array(
			'eligible' => false,
			'reason'   => 'rejected_uri_rule',
		);
	}

	// Filter hook for custom extensions.
	$filtered = apply_filters( 'turbo_cache_preload_is_safe_url', true, $url, $path );
	if ( ! $filtered ) {
		return array(
			'eligible' => false,
			'reason'   => 'filtered_out',
		);
	}

	return array(
		'eligible' => true,
		'reason'   => 'eligible',
	);
}

/**
 * Safely parse XML sitemap content to extract public URLs.
 *
 * Implements strict XML entity security (disables external entities)
 * and supports both standard <urlset> and <sitemapindex> formats.
 *
 * @param string      $xml_content       Raw XML string.
 * @param string|null $site_url          Optional site URL override.
 * @param int         $depth             Current recursion depth (max 2).
 * @param array       $visited_sitemaps  Array of already visited sitemap URLs.
 * @return array List of discovered valid URLs.
 */
function turbo_cache_preload_parse_sitemap_xml( $xml_content, $site_url = null, $depth = 0, &$visited_sitemaps = array() ) {
	if ( ! is_string( $xml_content ) || '' === trim( $xml_content ) ) {
		return array();
	}

	if ( $depth > 2 || count( $visited_sitemaps ) > 20 ) {
		return array();
	}

	// Secure XML parsing: disable entity loader if available.
	if ( function_exists( 'libxml_disable_entity_loader' ) && PHP_VERSION_ID < 80000 ) {
		// phpcs:ignore Generic.PHP.DeprecatedFunctions.Deprecated
		$previous_loader = libxml_disable_entity_loader( true );
	}

	$prev_errors = libxml_use_internal_errors( true );

	$xml = simplexml_load_string( $xml_content, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING );

	libxml_clear_errors();
	libxml_use_internal_errors( $prev_errors );

	if ( isset( $previous_loader ) && function_exists( 'libxml_disable_entity_loader' ) && PHP_VERSION_ID < 80000 ) {
		// phpcs:ignore Generic.PHP.DeprecatedFunctions.Deprecated
		libxml_disable_entity_loader( $previous_loader );
	}

	if ( false === $xml ) {
		return array();
	}

	$discovered = array();

	// Case 1: Standard URL set (<urlset><url><loc>...</loc></url></urlset>)
	if ( isset( $xml->url ) ) {
		foreach ( $xml->url as $url_entry ) {
			if ( isset( $url_entry->loc ) ) {
				$loc = trim( (string) $url_entry->loc );
				if ( '' !== $loc ) {
					$safety = turbo_cache_preload_is_safe_url( $loc, $site_url );
					if ( $safety['eligible'] ) {
						$normalized = turbo_cache_preload_normalize_url( $loc );
						if ( '' !== $normalized ) {
							$discovered[] = $normalized;
						}
					}
				}
			}
			if ( count( $discovered ) >= 5000 ) {
				break;
			}
		}
	}

	// Case 2: Sitemap index (<sitemapindex><sitemap><loc>...</loc></sitemap></sitemapindex>)
	if ( isset( $xml->sitemap ) && $depth < 2 ) {
		foreach ( $xml->sitemap as $sitemap_entry ) {
			if ( isset( $sitemap_entry->loc ) ) {
				$sub_loc = trim( (string) $sitemap_entry->loc );
				if ( '' !== $sub_loc && ! in_array( $sub_loc, $visited_sitemaps, true ) ) {
					$safety = turbo_cache_preload_is_safe_url( $sub_loc, $site_url );
					if ( $safety['eligible'] ) {
						$visited_sitemaps[] = $sub_loc;
						$sub_content        = turbo_cache_preload_fetch_sitemap_content( $sub_loc );
						if ( '' !== $sub_content ) {
							$sub_urls = turbo_cache_preload_parse_sitemap_xml( $sub_content, $site_url, $depth + 1, $visited_sitemaps );
							foreach ( $sub_urls as $sub_url ) {
								$discovered[] = $sub_url;
								if ( count( $discovered ) >= 5000 ) {
									break 2;
								}
							}
						}
					}
				}
			}
		}
	}

	return array_values( array_unique( $discovered ) );
}

/**
 * Fetch sitemap content safely via HTTP GET.
 *
 * @param string $sitemap_url URL of the sitemap.
 * @return string Sitemap XML body or empty string on failure.
 */
function turbo_cache_preload_fetch_sitemap_content( $sitemap_url ) {
	if ( ! function_exists( 'wp_remote_get' ) ) {
		return '';
	}

	$response = wp_remote_get(
		$sitemap_url,
		array(
			'timeout'     => 10,
			'redirection' => 2,
			'headers'     => array(
				'Accept' => 'application/xml, text/xml, */*',
			),
			'sslverify'   => apply_filters( 'turbo_cache_preload_sslverify', true ),
		)
	);

	if ( is_wp_error( $response ) ) {
		return '';
	}

	$code = wp_remote_retrieve_response_code( $response );
	if ( 200 !== (int) $code ) {
		return '';
	}

	return wp_remote_retrieve_body( $response );
}

/**
 * Returns the file path for Turbo Cache preload state file.
 *
 * @return string File path.
 */
function turbo_cache_preload_get_state_file_path() {
	global $cache_path;
	$path = ( isset( $cache_path ) && '' !== $cache_path ) ? $cache_path : ( defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR . '/cache/' : sys_get_temp_dir() . '/' );
	return rtrim( str_replace( '\\', '/', $path ), '/' ) . '/preload_state.json';
}

/**
 * Get current Turbo Cache preload state.
 *
 * @return array Preload state array.
 */
function turbo_cache_preload_get_state() {
	$default_state = array(
		'status'               => 'idle', // idle, running, paused, completed, cancelled.
		'generation'           => 'gen_0',
		'started_at'           => 0,
		'updated_at'           => 0,
		'finished_at'          => 0,
		'queue'                => array(),
		'queue_cursor'         => 0,
		'consecutive_failures' => 0,
		'stats'                => array(
			'total_discovered'         => 0,
			'processed'                => 0,
			'http_requests_successful' => 0,
			'cached'                   => 0,
			'skipped'                  => 0,
			'failed'                   => 0,
			'skip_reasons'             => array(),
		),
		'failures'             => array(), // url => array( code, message, permanent, timestamp )
		'last_error'           => null,
	);

	$filename = turbo_cache_preload_get_state_file_path();
	if ( file_exists( $filename ) ) {
		$content = file_get_contents( $filename );
		if ( false !== $content && '' !== $content ) {
			$data = json_decode( $content, true );
			if ( is_array( $data ) ) {
				return array_merge( $default_state, $data );
			}
		}
	}

	// Fallback to option if file does not exist.
	if ( function_exists( 'get_option' ) ) {
		$option_data = get_option( 'turbo_cache_preload_state' );
		if ( is_array( $option_data ) ) {
			return array_merge( $default_state, $option_data );
		}
	}

	return $default_state;
}

/**
 * Execute a callback within an exclusive coordination critical section.
 *
 * Uses a persistent coordination mutex file (`preload_coord.lock`) and PHP flock(LOCK_EX)
 * to serialize short metadata operations (lock acquisition, stale-lock recovery,
/**
 * Check whether the execution environment is an authorized test runner
 * with test seams explicitly enabled.
 *
 * In normal production execution, this always returns false to prevent
 * arbitrary plugins from manipulating mutex paths or weakening locking exclusivity.
 *
 * @return bool True if test seam is active, false otherwise.
 */
function turbo_cache_preload_is_test_seam_active() {
	if ( ! empty( $GLOBALS['wpsc_test_force_production_mode'] ) ) {
		return false;
	}

	return ( defined( 'PHPUNIT_COMPOSER_INSTALL' ) || defined( 'WPSC_SMOKE_TEST' ) || defined( 'WP_TESTS_DOMAIN' ) )
		&& ( isset( $GLOBALS['wpsc_test_filters'] ) || ! empty( $GLOBALS['wpsc_test_coordination_seam'] ) || defined( 'WPSC_TEST_SEAM_ENABLED' ) );
}

/**
 * Execute a critical section callback under the persistent coordination mutex.
 *
 * Serializes state transitions, lock acquisition, and reset operations across
 * all concurrent PHP processes (CLI, cron, web workers).
 *
 * @param callable $callback Operation to execute under exclusive coordination.
 * @return mixed Result of callback execution.
 */
function turbo_cache_preload_with_coordination( $callback ) {
	global $cache_path;
	static $lock_depth = 0;
	static $coord_fp   = null;

	if ( $lock_depth > 0 && is_resource( $coord_fp ) ) {
		// Re-entrant lock in same process: already holding coordination mutex.
		++$lock_depth;
		try {
			return call_user_func( $callback );
		} finally {
			--$lock_depth;
		}
	}

	$mutex_file = ( isset( $cache_path ) && '' !== $cache_path ? $cache_path : sys_get_temp_dir() . '/' ) . 'preload_coord.lock';

	// Test seam: only available in authorized test environments for deterministic failure testing.
	if ( turbo_cache_preload_is_test_seam_active() && function_exists( 'apply_filters' ) ) {
		$filtered_file = apply_filters( 'turbo_cache_preload_coordination_file', $mutex_file );
		if ( is_string( $filtered_file ) && '' !== trim( $filtered_file ) ) {
			$mutex_file = $filtered_file;
		}
	}

	$dir = dirname( $mutex_file );
	if ( ! is_dir( $dir ) && function_exists( 'wp_mkdir_p' ) ) {
		wp_mkdir_p( $dir );
	}

	$fp = @fopen( $mutex_file, 'c+' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	if ( false === $fp ) {
		// Fail-closed: do NOT execute protected callback if coordination file cannot be opened.
		return false;
	}

	// Production execution must ALWAYS use exclusive locking (LOCK_EX).
	$lock_flags = LOCK_EX;
	if ( turbo_cache_preload_is_test_seam_active() && function_exists( 'apply_filters' ) ) {
		$filtered_flags = apply_filters( 'turbo_cache_preload_coordination_flags', $lock_flags );
		// Ensure LOCK_EX is never stripped; only non-blocking (LOCK_NB) may be combined in tests.
		$lock_flags = LOCK_EX | ( (int) $filtered_flags & LOCK_NB );
	}

	$locked = @flock( $fp, $lock_flags ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	if ( ! $locked ) {
		@fclose( $fp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		// Fail-closed: do NOT execute protected callback if coordination lock cannot be acquired.
		return false;
	}

	$coord_fp   = $fp;
	$lock_depth = 1;

	try {
		return call_user_func( $callback );
	} finally {
		--$lock_depth;
		if ( 0 === $lock_depth ) {
			$coord_fp = null;
			@flock( $fp, LOCK_UN ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			@fclose( $fp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
	}
}

/**
 * Read the current ownership token from the lock file, if any.
 *
 * @return string|null Lock token string if locked, empty string if legacy lock, or null if unlocked.
 */
function turbo_cache_preload_get_lock_token() {
	global $cache_path;
	$lock_file = ( isset( $cache_path ) && '' !== $cache_path ? $cache_path : sys_get_temp_dir() . '/' ) . 'preload_batch.lock';
	if ( ! file_exists( $lock_file ) ) {
		return null;
	}
	$content = @file_get_contents( $lock_file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	if ( false === $content || '' === trim( $content ) ) {
		return null;
	}
	$data = json_decode( $content, true );
	if ( is_array( $data ) && ! empty( $data['token'] ) ) {
		return (string) $data['token'];
	}
	return '';
}

/**
 * Save current Turbo Cache preload state.
 *
 * Validates lock ownership and state generation if provided, preventing stale
 * in-memory workers from overwriting newer resets or active worker states.
 * All checks and file replacement are serialized under the coordination mutex.
 *
 * @param array       $state               Preload state array.
 * @param string|null $expected_generation Optional generation ID expected on disk.
 * @param string|null $worker_token        Optional worker lock token required to match active lock.
 * @param bool        $force               If true, bypasses generation and token checks (used by reset).
 * @return bool True on success, false on failure or validation failure.
 */
function turbo_cache_preload_save_state( $state, $expected_generation = null, $worker_token = null, $force = false ) {
	if ( ! is_array( $state ) ) {
		return false;
	}

	return (bool) turbo_cache_preload_with_coordination(
		function () use ( $state, $expected_generation, $worker_token, $force ) {
			$filename = turbo_cache_preload_get_state_file_path();

			if ( ! $force ) {
				// 1. Worker token validation: if worker token passed, ensure worker still owns lock.
				if ( null !== $worker_token && '' !== $worker_token ) {
					$current_lock = turbo_cache_preload_get_lock_token();
					if ( $current_lock !== $worker_token ) {
						return false;
					}
				}

				// 2. Generation validation: if expected generation passed, ensure disk matches.
				if ( null === $expected_generation && isset( $state['generation'] ) && 'gen_0' !== $state['generation'] ) {
					$expected_generation = $state['generation'];
				}

				if ( null !== $expected_generation && '' !== $expected_generation && 'gen_0' !== $expected_generation ) {
					if ( file_exists( $filename ) ) {
						$disk_content = @file_get_contents( $filename ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
						if ( false !== $disk_content && '' !== trim( $disk_content ) ) {
							$disk_state = json_decode( $disk_content, true );
							if ( is_array( $disk_state ) ) {
								$disk_gen = isset( $disk_state['generation'] ) ? $disk_state['generation'] : '';
								if ( '' !== $disk_gen && 'gen_0' !== $disk_gen && $disk_gen !== $expected_generation ) {
									// Disk state has been superseded by a newer worker or reset.
									return false;
								}
							}
						}
					}
				}
			}

			$state['updated_at'] = time();

			$dir = dirname( $filename );
			if ( ! is_dir( $dir ) && function_exists( 'wp_mkdir_p' ) ) {
				wp_mkdir_p( $dir );
			}

			$json = turbo_cache_json_encode( $state, JSON_UNESCAPED_SLASHES );
			if ( false === $json ) {
				return false;
			}

			// Atomic write: write to unique temporary file then rename under coordination mutex.
			$temp_file = $filename . '.' . uniqid( 'tc_tmp_', true );
			$written   = @file_put_contents( $temp_file, $json ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( false === $written ) {
				return false;
			}

			// On Windows, unlink destination first if rename cannot overwrite.
			if ( file_exists( $filename ) && strtoupper( substr( PHP_OS, 0, 3 ) ) === 'WIN' ) {
				@unlink( $filename ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
			$renamed = @rename( $temp_file, $filename ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( ! $renamed ) {
				$copied = @copy( $temp_file, $filename ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				@unlink( $temp_file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				if ( ! $copied ) {
					return false;
				}
			}

			if ( function_exists( 'update_option' ) ) {
				// Store compact state in options table as persistent fallback (omit huge queue).
				$compact_state          = $state;
				$compact_state['queue'] = array();
				update_option( 'turbo_cache_preload_state', $compact_state, false );
			}

			return true;
		}
	);
}

/**
 * Acquire lock for preload batch processing.
 *
 * Atomically acquires the worker lock file under the coordination mutex.
 * If the existing lock is stale (older than 600s), safely recovers the lock
 * without racing with newly acquired locks. Returns true on success with unique
 * ownership token, or false if already locked.
 *
 * @param string|null &$token Optional variable to receive the unique lock token.
 * @return bool True if lock acquired, false if already locked.
 */
function turbo_cache_preload_acquire_lock( &$token = null ) {
	$acquired_token = null;

	$result = turbo_cache_preload_with_coordination(
		function () use ( &$acquired_token ) {
			global $cache_path;
			$lock_file = ( isset( $cache_path ) && '' !== $cache_path ? $cache_path : sys_get_temp_dir() . '/' ) . 'preload_batch.lock';

			if ( file_exists( $lock_file ) ) {
				$mtime = @filemtime( $lock_file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				if ( $mtime && ( time() - $mtime ) < 600 ) {
					return false;
				}

				// Stale lock recovery: safely unlink under coordination mutex.
				@unlink( $lock_file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}

			$fp = @fopen( $lock_file, 'x' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( false === $fp ) {
				$fp = @fopen( $lock_file, 'w' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				if ( false === $fp ) {
					return false;
				}
			}

			$unique_token = 'worker_' . uniqid( '', true ) . '.' . mt_rand( 1000, 9999 );
			$payload      = turbo_cache_json_encode(
				array(
					'token' => $unique_token,
					'time'  => time(),
				)
			);

			fwrite( $fp, false !== $payload ? $payload : (string) time() );
			fflush( $fp );
			fclose( $fp );

			$acquired_token = $unique_token;
			return true;
		}
	);

	if ( $result ) {
		$token = $acquired_token;
		return true;
	}

	$token = null;
	return false;
}

/**
 * Release lock for preload batch processing.
 *
 * If a token is provided, verifies that the current lock is owned by this token
 * before deleting the lock file. If the token does not match or the lock content
 * is empty/unreadable, the lock is preserved. Verification and removal execute
 * atomically under the coordination mutex.
 *
 * @param string|null $token Optional ownership token.
 * @return bool True if released, false if ownership check failed.
 */
function turbo_cache_preload_release_lock( $token = null ) {
	return (bool) turbo_cache_preload_with_coordination(
		function () use ( $token ) {
			global $cache_path;
			$lock_file = ( isset( $cache_path ) && '' !== $cache_path ? $cache_path : sys_get_temp_dir() . '/' ) . 'preload_batch.lock';
			if ( ! file_exists( $lock_file ) ) {
				return true;
			}

			if ( null !== $token && '' !== $token ) {
				$content = @file_get_contents( $lock_file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				// If lock content is empty or unreadable, an ownership-verified release must NOT delete it.
				if ( false === $content || '' === trim( $content ) ) {
					return false;
				}

				$data           = json_decode( $content, true );
				$existing_token = ( is_array( $data ) && ! empty( $data['token'] ) ) ? $data['token'] : '';
				if ( $existing_token !== $token ) {
					// Token mismatch: lock belongs to another worker.
					return false;
				}
			}

			@unlink( $lock_file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return true;
		}
	);
}

/**
 * Reset Turbo Cache preload state.
 *
 * Updates preload state to a clean idle state with a new generation ID under
 * the coordination mutex, invalidating any in-flight workers. Preserves a valid
 * active worker lock so no new worker can start while the old worker is finishing,
 * while removing any stale (> 600s) lock.
 *
 * @return bool True on success, false on failure or coordination failure.
 */
function turbo_cache_preload_reset_state() {
	return (bool) turbo_cache_preload_with_coordination(
		function () {
			global $cache_path;
			$new_generation = 'gen_' . microtime( true ) . '_' . mt_rand( 1000, 9999 );

			$reset_state = array(
				'status'               => 'idle',
				'generation'           => $new_generation,
				'started_at'           => 0,
				'updated_at'           => time(),
				'finished_at'          => 0,
				'queue'                => array(),
				'queue_cursor'         => 0,
				'consecutive_failures' => 0,
				'stats'                => array(
					'total_discovered'         => 0,
					'processed'                => 0,
					'http_requests_successful' => 0,
					'cached'                   => 0,
					'skipped'                  => 0,
					'failed'                   => 0,
					'skip_reasons'             => array(),
				),
				'failures'             => array(),
				'last_error'           => null,
			);

			// Commit clean idle state with new generation under coordination mutex.
			$saved = turbo_cache_preload_save_state( $reset_state, null, null, true );

			// Check lock file: if stale (> 600s), remove it.
			// If lock is held by a valid active worker, DO NOT unlink it.
			// The active worker will observe the generation change, abort, and release its own lock.
			$lock_file = ( isset( $cache_path ) && '' !== $cache_path ? $cache_path : sys_get_temp_dir() . '/' ) . 'preload_batch.lock';
			if ( file_exists( $lock_file ) ) {
				$mtime = @filemtime( $lock_file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				if ( ! $mtime || ( time() - $mtime ) >= 600 ) {
					@unlink( $lock_file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				}
			}

			if ( function_exists( 'delete_option' ) ) {
				delete_option( 'turbo_cache_preload_state' );
			}

			return (bool) $saved;
		}
	);
}

/**
 * Discover all eligible public URLs from WordPress content and XML sitemaps.
 *
 * @param string|null $site_url Optional site URL override.
 * @return array List of normalized, safe, deduplicated URLs.
 */
function turbo_cache_preload_discover_urls( $site_url = null ) {
	if ( null === $site_url ) {
		$site_url = function_exists( 'home_url' ) ? home_url( '/' ) : ( function_exists( 'site_url' ) ? site_url( '/' ) : 'http://localhost/' );
	}

	$discovered_urls = array();

	// 1. Front page & Blog page.
	$home_normalized = turbo_cache_preload_normalize_url( $site_url );
	if ( '' !== $home_normalized ) {
		$safety = turbo_cache_preload_is_safe_url( $home_normalized, $site_url );
		if ( $safety['eligible'] ) {
			$discovered_urls[] = $home_normalized;
		}
	}

	if ( function_exists( 'get_option' ) ) {
		$page_for_posts = (int) get_option( 'page_for_posts' );
		if ( $page_for_posts > 0 && function_exists( 'get_permalink' ) ) {
			$blog_url = get_permalink( $page_for_posts );
			if ( is_string( $blog_url ) ) {
				$norm = turbo_cache_preload_normalize_url( $blog_url );
				if ( '' !== $norm ) {
					$safety = turbo_cache_preload_is_safe_url( $norm, $site_url );
					if ( $safety['eligible'] ) {
						$discovered_urls[] = $norm;
					}
				}
			}
		}
	}

	// 2. Published posts and pages.
	if ( function_exists( 'wpsc_get_post_types' ) && function_exists( 'get_posts' ) ) {
		$post_types = apply_filters( 'wpsc_preload_post_types', array( 'post', 'page' ) );
		$posts      = get_posts(
			array(
				'post_type'      => $post_types,
				'post_status'    => 'publish',
				'posts_per_page' => 1000, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page
				'fields'         => 'ids',
			)
		);

		if ( is_array( $posts ) ) {
			foreach ( $posts as $post_id ) {
				$permalink = get_permalink( $post_id );
				if ( is_string( $permalink ) ) {
					$norm = turbo_cache_preload_normalize_url( $permalink );
					if ( '' !== $norm ) {
						$safety = turbo_cache_preload_is_safe_url( $norm, $site_url );
						if ( $safety['eligible'] ) {
							$discovered_urls[] = $norm;
						}
					}
				}
			}
		}
	}

	// 3. Taxonomies.
	if ( function_exists( 'get_terms' ) && function_exists( 'get_term_link' ) ) {
		$taxonomies = apply_filters(
			'wp_cache_preload_taxonomies',
			array(
				'post_tag' => 'tag',
				'category' => 'category',
			)
		);

		if ( is_array( $taxonomies ) ) {
			foreach ( array_keys( $taxonomies ) as $taxonomy ) {
				$terms = get_terms(
					array(
						'taxonomy'   => $taxonomy,
						'hide_empty' => true,
						'number'     => 500,
					)
				);
				if ( is_array( $terms ) && ! is_wp_error( $terms ) ) {
					foreach ( $terms as $term ) {
						$term_link = get_term_link( $term );
						if ( is_string( $term_link ) && ! is_wp_error( $term_link ) ) {
							$norm = turbo_cache_preload_normalize_url( $term_link );
							if ( '' !== $norm ) {
								$safety = turbo_cache_preload_is_safe_url( $norm, $site_url );
								if ( $safety['eligible'] ) {
									$discovered_urls[] = $norm;
								}
							}
						}
					}
				}
			}
		}
	}

	// 4. Sitemap discovery.
	$sitemap_candidates = array(
		rtrim( $site_url, '/' ) . '/wp-sitemap.xml',
		rtrim( $site_url, '/' ) . '/sitemap_index.xml',
		rtrim( $site_url, '/' ) . '/sitemap.xml',
	);

	$sitemap_candidates = (array) apply_filters( 'turbo_cache_preload_sitemap_sources', $sitemap_candidates );

	$visited_sitemaps = array();
	foreach ( $sitemap_candidates as $sitemap_url ) {
		$content = turbo_cache_preload_fetch_sitemap_content( $sitemap_url );
		if ( '' !== $content ) {
			$visited_sitemaps[] = $sitemap_url;
			$sitemap_urls       = turbo_cache_preload_parse_sitemap_xml( $content, $site_url, 0, $visited_sitemaps );
			foreach ( $sitemap_urls as $s_url ) {
				$discovered_urls[] = $s_url;
			}
			// If WordPress core or major index matched, stop probing fallbacks.
			if ( ! empty( $sitemap_urls ) ) {
				break;
			}
		}
	}

	return array_values( array_unique( $discovered_urls ) );
}

/**
 * Dispatch safe HTTP GET request to warm/generate cache entry.
 *
 * @param string $url The URL to request.
 * @return array Result array with 'success' (bool), 'code' (int), 'redirect' (bool), 'permanent_error' (bool), 'error' (string|null).
 */
function turbo_cache_preload_fetch_url( $url ) {
	if ( ! function_exists( 'wp_remote_get' ) ) {
		return array(
			'success'         => false,
			'code'            => 0,
			'redirect'        => false,
			'permanent_error' => false,
			'error'           => 'wp_remote_get unavailable',
		);
	}

	$args = array(
		'timeout'     => apply_filters( 'turbo_cache_preload_request_timeout', 15 ),
		'redirection' => 0, // Do not follow redirects automatically.
		'cookies'     => array(), // Explicitly empty cookies (no auth / no cart session).
		'headers'     => array(
			'User-Agent'            => 'TurboCache-Preloader/1.0; ' . ( function_exists( 'home_url' ) ? home_url() : '' ),
			'X-Turbo-Cache-Preload' => '1',
			'Cache-Control'         => 'no-cache',
			'Pragma'                => 'no-cache',
		),
		'sslverify'   => apply_filters( 'turbo_cache_preload_sslverify', true ),
	);

	$response = wp_remote_get( $url, $args );

	if ( is_wp_error( $response ) ) {
		return array(
			'success'         => false,
			'code'            => 0,
			'redirect'        => false,
			'permanent_error' => false,
			'error'           => $response->get_error_message(),
		);
	}

	$code = (int) wp_remote_retrieve_response_code( $response );

	// Success 200 OK.
	if ( 200 === $code ) {
		return array(
			'success'         => true,
			'code'            => 200,
			'redirect'        => false,
			'permanent_error' => false,
			'error'           => null,
		);
	}

	// Redirects 3xx.
	if ( $code >= 300 && $code < 400 ) {
		$location      = wp_remote_retrieve_header( $response, 'location' );
		$target_url    = is_string( $location ) ? trim( $location ) : '';
		$target_safe   = false;
		$target_reason = 'unknown_redirect';

		if ( '' !== $target_url ) {
			if ( ! preg_match( '#^https?://#i', $target_url ) ) {
				$origin_parts  = turbo_cache_parse_url( $url );
				$origin_scheme = isset( $origin_parts['scheme'] ) ? $origin_parts['scheme'] : 'http';
				$origin_host   = isset( $origin_parts['host'] ) ? $origin_parts['host'] : '';
				$origin_port   = isset( $origin_parts['port'] ) ? ':' . $origin_parts['port'] : '';
				if ( 0 === strpos( $target_url, '/' ) ) {
					$target_url = $origin_scheme . '://' . $origin_host . $origin_port . $target_url;
				} else {
					$origin_path = isset( $origin_parts['path'] ) ? $origin_parts['path'] : '/';
					$origin_dir  = substr( $origin_path, 0, (int) strrpos( $origin_path, '/' ) + 1 );
					$target_url  = $origin_scheme . '://' . $origin_host . $origin_port . $origin_dir . $target_url;
				}
			}
			$site_base = function_exists( 'home_url' ) ? home_url() : ( function_exists( 'site_url' ) ? site_url() : ( function_exists( 'get_option' ) ? (string) get_option( 'home' ) : '' ) );
			if ( '' === $site_base ) {
				$origin_parts  = turbo_cache_parse_url( $url );
				$origin_scheme = isset( $origin_parts['scheme'] ) ? $origin_parts['scheme'] : 'http';
				$origin_host   = isset( $origin_parts['host'] ) ? $origin_parts['host'] : '';
				$origin_port   = isset( $origin_parts['port'] ) ? ':' . $origin_parts['port'] : '';
				$site_base     = $origin_scheme . '://' . $origin_host . $origin_port . '/';
			}

			$validation    = turbo_cache_preload_is_safe_url( $target_url, $site_base );
			$target_safe   = $validation['eligible'];
			$target_reason = $validation['reason'];
		}

		return array(
			'success'         => false,
			'code'            => $code,
			'redirect'        => true,
			'target_url'      => $target_url,
			'target_safe'     => $target_safe,
			'target_reason'   => $target_reason,
			'permanent_error' => ( 301 === $code || 308 === $code ),
			'error'           => 'Redirected (' . $code . ') to ' . ( '' !== $target_url ? $target_url : 'unknown' ),
		);
	}

	// Permanent client errors (404 Not Found, 410 Gone).
	if ( 404 === $code || 410 === $code ) {
		return array(
			'success'         => false,
			'code'            => $code,
			'redirect'        => false,
			'permanent_error' => true,
			'error'           => 'HTTP ' . $code,
		);
	}

	// Other client/server errors.
	return array(
		'success'         => false,
		'code'            => $code,
		'redirect'        => false,
		'permanent_error' => false,
		'error'           => 'HTTP ' . $code,
	);
}

/**
 * Resolve candidate supercache directory paths for a URL.
 *
 * Checks both Turbo Cache storage directory resolution (handles subdirectory
 * installs, ports, and HTTPS) and upstream wpsc_supercache_dir_for_url.
 *
 * @param string $url The target URL.
 * @return array List of candidate directory paths with trailing slashes.
 */
function turbo_cache_preload_get_cache_dirs_for_url( $url ) {
	$dirs = array();

	// Primary: Turbo Cache canonical storage directory resolution.
	if ( function_exists( 'turbo_cache_get_storage_dir' ) ) {
		$parts = turbo_cache_parse_url( $url );
		$host  = isset( $parts['host'] ) ? $parts['host'] : null;
		$path  = isset( $parts['path'] ) ? $parts['path'] : '/';
		$dir   = turbo_cache_get_storage_dir( $path, $host );
		if ( is_string( $dir ) && '' !== trim( $dir ) ) {
			$dirs[] = rtrim( str_replace( '\\', '/', $dir ), '/' ) . '/';
		}
	}

	// Secondary: Upstream wpsc_supercache_dir_for_url.
	if ( function_exists( 'wpsc_supercache_dir_for_url' ) ) {
		$wpsc_dir = wpsc_supercache_dir_for_url( $url );
		if ( is_string( $wpsc_dir ) && '' !== trim( $wpsc_dir ) ) {
			$dirs[] = rtrim( str_replace( '\\', '/', $wpsc_dir ), '/' ) . '/';
		}
	}

	return array_values( array_unique( $dirs ) );
}

/**
 * Verify whether a valid static cache entry was created on disk for a URL.
 *
 * @param string $url The requested URL.
 * @return bool True if a non-empty cache file exists, false otherwise.
 */
function turbo_cache_preload_verify_cache_entry( $url ) {
	$candidate_dirs = turbo_cache_preload_get_cache_dirs_for_url( $url );
	if ( empty( $candidate_dirs ) ) {
		return false;
	}

	$target_files = array(
		'index.html',
		'index-https.html',
		'index.html.gz',
		'index-https.html.gz',
		'index.xml',
		'index-https.xml',
	);

	foreach ( $candidate_dirs as $cache_dir ) {
		foreach ( $target_files as $file ) {
			$filepath = $cache_dir . $file;
			if ( file_exists( $filepath ) && filesize( $filepath ) > 0 ) {
				return true;
			}
		}

		// Also check for any wp-cache phase 2 cache files in directory.
		if ( is_dir( $cache_dir ) ) {
			$files = glob( $cache_dir . 'wp-cache-*.php' );
			if ( ! empty( $files ) ) {
				return true;
			}
		}
	}

	return false;
}

/**
 * Process a bounded batch of URLs from the preload queue.
 *
 * @param int|null $batch_size Optional batch size override (default WPSC_PRELOAD_POST_COUNT or 10).
 * @return array Batch execution summary.
 */
function turbo_cache_preload_process_batch( $batch_size = null ) {
	global $cache_path;

	$worker_token  = null;
	$lock_released = false;
	if ( ! turbo_cache_preload_acquire_lock( $worker_token ) ) {
		return array(
			'status'  => 'locked',
			'message' => 'Another preload batch is in progress.',
		);
	}

	try {
		if ( null === $batch_size ) {
			$batch_size = defined( 'WPSC_PRELOAD_POST_COUNT' ) ? (int) WPSC_PRELOAD_POST_COUNT : 10;
		}
		$batch_size = (int) apply_filters( 'turbo_cache_preload_batch_size', max( 1, min( 50, $batch_size ) ) );

		$state = turbo_cache_preload_get_state();

		// Initialize worker generation ID if missing.
		if ( empty( $state['generation'] ) || 'gen_0' === $state['generation'] ) {
			$state['generation'] = 'gen_' . microtime( true ) . '_' . mt_rand( 1000, 9999 );
		}
		$worker_generation = $state['generation'];

		// Check cancellation.
		$stop_flag = ( isset( $cache_path ) ? $cache_path : '' ) . 'stop_preload.txt';
		if ( file_exists( $stop_flag ) || 'cancelled' === $state['status'] ) {
			$state['status'] = 'cancelled';
			turbo_cache_preload_save_state( $state, $worker_generation, $worker_token );
			if ( function_exists( 'wpsc_update_idle_preload' ) ) {
				wpsc_update_idle_preload( time() );
			}
			$lock_released = true;
			turbo_cache_preload_release_lock( $worker_token );
			return array(
				'status'  => 'cancelled',
				'message' => 'Preload cancelled by user flag.',
			);
		}

		// Circuit breaker check: consecutive failures >= 5.
		if ( isset( $state['consecutive_failures'] ) && $state['consecutive_failures'] >= 5 ) {
			$state['status']     = 'paused';
			$state['last_error'] = 'Circuit breaker triggered: 5 consecutive request failures.';
			turbo_cache_preload_save_state( $state, $worker_generation, $worker_token );
			$lock_released = true;
			turbo_cache_preload_release_lock( $worker_token );
			return array(
				'status'  => 'paused',
				'message' => $state['last_error'],
			);
		}

		// Initialize discovery if queue is empty or starting fresh.
		if ( empty( $state['queue'] ) || 'idle' === $state['status'] ) {
			$discovered                    = turbo_cache_preload_discover_urls();
			$state['queue']                = $discovered;
			$state['queue_cursor']         = 0;
			$state['status']               = 'running';
			$state['started_at']           = time();
			$state['consecutive_failures'] = 0;
			$state['stats']                = array(
				'total_discovered'         => count( $discovered ),
				'processed'                => 0,
				'http_requests_successful' => 0,
				'cached'                   => 0,
				'skipped'                  => 0,
				'failed'                   => 0,
				'skip_reasons'             => array(),
			);
			$state['failures']             = array();
			$state['last_error']           = null;
			$saved                         = turbo_cache_preload_save_state( $state, null, $worker_token );
			if ( ! $saved ) {
				$lock_released = true;
				turbo_cache_preload_release_lock( $worker_token );
				return array(
					'status'  => 'superseded',
					'message' => 'Preload batch superseded before discovery commit.',
				);
			}
		}

		$total_urls = count( $state['queue'] );
		if ( $total_urls === 0 || $state['queue_cursor'] >= $total_urls ) {
			$state['status']      = 'completed';
			$state['finished_at'] = time();
			turbo_cache_preload_save_state( $state, $worker_generation, $worker_token );
			if ( function_exists( 'wpsc_update_idle_preload' ) ) {
				wpsc_update_idle_preload( time() );
			}
			$lock_released = true;
			turbo_cache_preload_release_lock( $worker_token );
			return array(
				'status'  => 'completed',
				'message' => 'All queued URLs have been processed.',
				'stats'   => $state['stats'],
			);
		}

		$start_time         = time();
		$processed_in_batch = 0;

		while ( $state['queue_cursor'] < $total_urls && $processed_in_batch < $batch_size ) {
			// Responsive cancellation check before every URL request.
			if ( file_exists( $stop_flag ) ) {
				$state['status'] = 'cancelled';
				turbo_cache_preload_save_state( $state, $worker_generation, $worker_token );
				if ( function_exists( 'wpsc_update_idle_preload' ) ) {
					wpsc_update_idle_preload( time() );
				}
				$lock_released = true;
				turbo_cache_preload_release_lock( $worker_token );
				return array(
					'status'             => 'cancelled',
					'processed_in_batch' => $processed_in_batch,
					'queue_cursor'       => $state['queue_cursor'],
					'total_urls'         => $total_urls,
					'stats'              => $state['stats'],
					'message'            => 'Preload cancelled by user flag.',
				);
			}

			// Revalidate ownership and generation before every URL request.
			$current_lock = turbo_cache_preload_get_lock_token();
			if ( $current_lock !== $worker_token ) {
				$lock_released = true;
				return array(
					'status'             => 'superseded',
					'processed_in_batch' => $processed_in_batch,
					'queue_cursor'       => $state['queue_cursor'],
					'total_urls'         => $total_urls,
					'stats'              => $state['stats'],
					'message'            => 'Preload batch superseded: lock ownership lost.',
				);
			}

			$disk_check = turbo_cache_preload_get_state();
			if ( isset( $disk_check['generation'] ) && '' !== $disk_check['generation'] && 'gen_0' !== $disk_check['generation'] && $disk_check['generation'] !== $worker_generation ) {
				$lock_released = true;
				turbo_cache_preload_release_lock( $worker_token );
				return array(
					'status'             => 'superseded',
					'processed_in_batch' => $processed_in_batch,
					'queue_cursor'       => $state['queue_cursor'],
					'total_urls'         => $total_urls,
					'stats'              => $state['stats'],
					'message'            => 'Preload batch superseded: state generation mismatch.',
				);
			}

			// Time and memory bounds check.
			if ( ( time() - $start_time ) >= 20 ) {
				break; // Yield slice.
			}

			$current_url = $state['queue'][ $state['queue_cursor'] ];

			// Check if URL is marked as permanent failure.
			if ( isset( $state['failures'][ $current_url ] ) && ! empty( $state['failures'][ $current_url ]['permanent'] ) ) {
				++$state['stats']['skipped'];
				$state['stats']['skip_reasons']['permanent_failure'] = isset( $state['stats']['skip_reasons']['permanent_failure'] ) ? $state['stats']['skip_reasons']['permanent_failure'] + 1 : 1;
				++$state['queue_cursor'];
				++$processed_in_batch;
				continue;
			}

			// Validate URL safety.
			$safety = turbo_cache_preload_is_safe_url( $current_url );
			if ( ! $safety['eligible'] ) {
				++$state['stats']['skipped'];
				$reason                                    = $safety['reason'];
				$state['stats']['skip_reasons'][ $reason ] = isset( $state['stats']['skip_reasons'][ $reason ] ) ? $state['stats']['skip_reasons'][ $reason ] + 1 : 1;
				if ( function_exists( 'turbo_cache_diagnostics_record' ) ) {
					turbo_cache_diagnostics_record(
						'PRELOAD_SKIPPED',
						'Preload skipped URL: ' . $reason,
						array(
							'url'    => $current_url,
							'reason' => $reason,
						)
					);
				}
				++$state['queue_cursor'];
				++$processed_in_batch;
				continue;
			}

			// Update UI active progress if upstream helper exists.
			if ( function_exists( 'wpsc_update_active_preload' ) ) {
				wpsc_update_active_preload( 'queue', $state['queue_cursor'] + 1, $current_url );
			}

			// Clear existing cache for URL before warming.
			if ( function_exists( 'wpsc_delete_files' ) ) {
				$candidate_dirs = turbo_cache_preload_get_cache_dirs_for_url( $current_url );
				foreach ( $candidate_dirs as $cache_dir ) {
					wpsc_delete_files( $cache_dir );
				}
			}

			// Dispatch request outside critical section.
			$fetch_result = turbo_cache_preload_fetch_url( $current_url );

			++$state['stats']['processed'];

			if ( $fetch_result['success'] ) {
				$state['stats']['http_requests_successful'] = isset( $state['stats']['http_requests_successful'] ) ? $state['stats']['http_requests_successful'] + 1 : 1;
				// Verify that cache entry was actually generated on disk. An HTTP 200 alone never counts as cached.
				$cache_verified = turbo_cache_preload_verify_cache_entry( $current_url );

				if ( $cache_verified ) {
					++$state['stats']['cached'];
					$state['consecutive_failures'] = 0;
					if ( function_exists( 'turbo_cache_diagnostics_record' ) ) {
						turbo_cache_diagnostics_record( 'PRELOAD_CACHED', 'Preload warmed and verified cache on disk', array( 'url' => $current_url ) );
					}
				} else {
					++$state['stats']['skipped'];
					$state['stats']['skip_reasons']['not_cached'] = isset( $state['stats']['skip_reasons']['not_cached'] ) ? $state['stats']['skip_reasons']['not_cached'] + 1 : 1;
					if ( function_exists( 'turbo_cache_diagnostics_record' ) ) {
						turbo_cache_diagnostics_record( 'PRELOAD_NOT_CACHED', 'Preload HTTP request succeeded (200) but cache file was not created on disk', array( 'url' => $current_url ) );
					}
				}
			} elseif ( $fetch_result['redirect'] ) {
				++$state['stats']['skipped'];
				$redirect_reason                                    = ( isset( $fetch_result['target_safe'] ) && ! $fetch_result['target_safe'] ) ? 'unsafe_redirect_target' : 'redirect';
				$state['stats']['skip_reasons'][ $redirect_reason ] = isset( $state['stats']['skip_reasons'][ $redirect_reason ] ) ? $state['stats']['skip_reasons'][ $redirect_reason ] + 1 : 1;
				if ( function_exists( 'turbo_cache_diagnostics_record' ) ) {
					turbo_cache_diagnostics_record( 'PRELOAD_SKIPPED', 'Preload skipped redirect: ' . $redirect_reason, array( 'url' => $current_url ) );
				}
				if ( ! empty( $fetch_result['permanent_error'] ) ) {
					$state['failures'][ $current_url ] = array(
						'code'      => $fetch_result['code'],
						'message'   => $fetch_result['error'],
						'permanent' => true,
						'timestamp' => time(),
					);
				}
			} else {
				++$state['stats']['failed'];
				++$state['consecutive_failures'];
				$state['failures'][ $current_url ] = array(
					'code'      => $fetch_result['code'],
					'message'   => $fetch_result['error'],
					'permanent' => $fetch_result['permanent_error'],
					'timestamp' => time(),
				);
				$state['last_error']               = $fetch_result['error'];
				if ( function_exists( 'turbo_cache_diagnostics_record' ) ) {
					turbo_cache_diagnostics_record(
						'PRELOAD_FAILED',
						'Preload request failed: ' . $fetch_result['error'],
						array(
							'url'  => $current_url,
							'code' => $fetch_result['code'],
						)
					);
				}

				if ( $state['consecutive_failures'] >= 5 ) {
					$state['status']     = 'paused';
					$state['last_error'] = 'Circuit breaker triggered: 5 consecutive request failures.';
					break;
				}
			}

			++$state['queue_cursor'];
			++$processed_in_batch;

			// Optional polite delay between requests.
			$delay = defined( 'WPSC_PRELOAD_POST_INTERVAL' ) ? (int) WPSC_PRELOAD_POST_INTERVAL : 0;
			if ( $delay > 0 ) {
				sleep( min( 2, $delay ) );
			}
		}

		// Responsive cancellation check after loop (catches cancellation arriving during the final URL).
		if ( file_exists( $stop_flag ) ) {
			$state['status'] = 'cancelled';
			if ( function_exists( 'wpsc_update_idle_preload' ) ) {
				wpsc_update_idle_preload( time() );
			}
		} elseif ( $state['queue_cursor'] >= $total_urls ) {
			$state['status']      = 'completed';
			$state['finished_at'] = time();
			if ( function_exists( 'wpsc_update_idle_preload' ) ) {
				wpsc_update_idle_preload( time() );
			}
		}

		$saved         = turbo_cache_preload_save_state( $state, $worker_generation, $worker_token );
		$lock_released = true;
		turbo_cache_preload_release_lock( $worker_token );

		if ( ! $saved ) {
			return array(
				'status'             => 'superseded',
				'processed_in_batch' => $processed_in_batch,
				'queue_cursor'       => $state['queue_cursor'],
				'total_urls'         => $total_urls,
				'stats'              => $state['stats'],
				'message'            => 'Preload batch superseded before final state save.',
			);
		}

		return array(
			'status'             => $state['status'],
			'processed_in_batch' => $processed_in_batch,
			'queue_cursor'       => $state['queue_cursor'],
			'total_urls'         => $total_urls,
			'stats'              => $state['stats'],
		);
	} finally {
		if ( ! $lock_released && null !== $worker_token ) {
			turbo_cache_preload_release_lock( $worker_token );
		}
	}
}
