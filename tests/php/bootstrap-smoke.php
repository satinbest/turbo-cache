<?php
/**
 * Smoke-tier bootstrap (CI: lint + this suite, no database, no WordPress runtime).
 *
 * Loads the procedural caching files so their WordPress-free functions can be
 * exercised directly. WordPress core is NOT loaded here, so the handful of core
 * functions those files call at runtime are provided as minimal, pure-PHP test
 * doubles below. Do NOT add WordPress-runtime behaviour here — tests that need a
 * real WordPress runtime (options, hooks, transients, a database) belong in the
 * integration tier (tests/php/integration, run via `make test-integration`).
 *
 * @package automattic/wp-super-cache
 */

require_once __DIR__ . '/../../vendor/autoload.php';

/*
 * Minimal filter registry.
 *
 * The procedural files call apply_filters()/add_filter() at runtime. Under the
 * smoke tier there is no WordPress, so we provide a tiny callback registry that
 * matches WordPress's calling convention closely enough for these functions:
 * apply_filters() returns $value unchanged when no filters are registered, and
 * runs registered callbacks (in priority order) otherwise. This lets a test
 * register a hostile filter and assert the function under test sanitises its
 * output — e.g. the supercache_filename() #1050 regression guard.
 *
 * Guarded by function_exists() so a real WordPress runtime always wins; this
 * registry only exists in the no-WordPress smoke process.
 */
if ( ! function_exists( 'apply_filters' ) ) {
	$GLOBALS['wpsc_test_filters'] = array();

	/**
	 * Register a filter callback (test double for WordPress add_filter()).
	 *
	 * @param string   $hook_name     Filter hook name.
	 * @param callable $callback      Callback to run.
	 * @param int      $priority      Lower runs earlier. Default 10.
	 * @param int      $accepted_args Number of args passed to the callback. Default 1.
	 */
	function add_filter( $hook_name, $callback, $priority = 10, $accepted_args = 1 ) {
		$GLOBALS['wpsc_test_filters'][ $hook_name ][ $priority ][] = array(
			'callback'      => $callback,
			'accepted_args' => $accepted_args,
		);
		return true;
	}

	/**
	 * Apply registered filters to a value (test double for WordPress apply_filters()).
	 *
	 * @param string $hook_name Filter hook name.
	 * @param mixed  $value     Value to filter.
	 * @param mixed  ...$args   Additional arguments passed to callbacks.
	 * @return mixed Filtered value, or $value unchanged when no filters are registered.
	 */
	function apply_filters( $hook_name, $value, ...$args ) {
		if ( empty( $GLOBALS['wpsc_test_filters'][ $hook_name ] ) ) {
			return $value;
		}

		$buckets = $GLOBALS['wpsc_test_filters'][ $hook_name ];
		ksort( $buckets );
		foreach ( $buckets as $callbacks ) {
			foreach ( $callbacks as $registered ) {
				$call_args = array_merge( array( $value ), $args );
				$call_args = array_slice( $call_args, 0, $registered['accepted_args'] );
				$value     = call_user_func_array( $registered['callback'], $call_args );
			}
		}

		return $value;
	}

	/**
	 * Remove every callback registered for a hook (test cleanup helper).
	 *
	 * @param string $hook_name Filter hook name.
	 */
	function remove_all_filters( $hook_name ) {
		unset( $GLOBALS['wpsc_test_filters'][ $hook_name ] );
		return true;
	}
}

/*
 * wp-cache-phase2.php calls wp_rand() in several places, the first a smoke test
 * reaches being the temporary file name in wp_cache_replace_line(). Nothing in the
 * smoke tier depends on the randomness, only on the function existing.
 */
if ( ! function_exists( 'wp_rand' ) ) {
	/**
	 * Random integer (test double for WordPress wp_rand()).
	 *
	 * @param int $min Lower bound.
	 * @param int $max Upper bound.
	 * @return int
	 */
	function wp_rand( $min = 0, $max = 4294967295 ) {
		return random_int( $min, $max );
	}
}

if ( ! function_exists( 'wp_parse_url' ) ) {
	/**
	 * Parse a URL (test double for WordPress wp_parse_url()).
	 *
	 * @param string $url       The URL to parse.
	 * @param int    $component The specific component to retrieve.
	 * @return mixed
	 */
	function wp_parse_url( $url, $component = -1 ) {
		return parse_url( (string) $url, $component );
	}
}

if ( ! function_exists( 'wp_remote_get' ) ) {
	/**
	 * Dispatch HTTP GET (test double for WordPress wp_remote_get()).
	 *
	 * @param string $url  Request URL.
	 * @param array  $args Request arguments.
	 * @return array
	 */
	function wp_remote_get( $url, $args = array() ) {
		$mock = apply_filters( 'pre_http_request', false, $args, $url );
		if ( false !== $mock ) {
			return $mock;
		}
		return array(
			'response' => array( 'code' => 200 ),
			'headers'  => array(),
			'body'     => '',
		);
	}
}

if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	/**
	 * Retrieve response code (test double for WordPress wp_remote_retrieve_response_code()).
	 *
	 * @param array $response HTTP response array.
	 * @return int
	 */
	function wp_remote_retrieve_response_code( $response ) {
		if ( is_array( $response ) && isset( $response['response']['code'] ) ) {
			return (int) $response['response']['code'];
		}
		return 0;
	}
}

if ( ! function_exists( 'wp_remote_retrieve_header' ) ) {
	/**
	 * Retrieve response header (test double for WordPress wp_remote_retrieve_header()).
	 *
	 * @param array  $response HTTP response array.
	 * @param string $header   Header name.
	 * @return string
	 */
	function wp_remote_retrieve_header( $response, $header ) {
		$header_lower = strtolower( $header );
		if ( is_array( $response ) && isset( $response['headers'] ) && is_array( $response['headers'] ) ) {
			foreach ( $response['headers'] as $k => $v ) {
				if ( strtolower( $k ) === $header_lower ) {
					return is_array( $v ) ? implode( ', ', $v ) : (string) $v;
				}
			}
		}
		return '';
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	/**
	 * Check whether variable is a WP_Error (test double for WordPress is_wp_error()).
	 *
	 * @param mixed $thing The variable to check.
	 * @return bool
	 */
	function is_wp_error( $thing ) {
		unset( $thing );
		return false;
	}
}

if ( ! function_exists( 'get_option' ) ) {
	/**
	 * Test double for WordPress get_option().
	 *
	 * @param string $option  Option name.
	 * @param mixed  $default Default value.
	 * @return mixed
	 */
	function get_option( $option, $default = false ) {
		if ( isset( $GLOBALS['wpsc_test_options'][ $option ] ) ) {
			return $GLOBALS['wpsc_test_options'][ $option ];
		}
		if ( 'home' === $option || 'siteurl' === $option ) {
			return 'https://example.com';
		}
		return $default;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	/**
	 * Test double for WordPress update_option().
	 *
	 * @param string $option Option name.
	 * @param mixed  $value  Option value.
	 * @return bool
	 */
	function update_option( $option, $value ) {
		$GLOBALS['wpsc_test_options'][ $option ] = $value;
		return true;
	}
}

if ( ! function_exists( 'delete_option' ) ) {
	/**
	 * Test double for WordPress delete_option().
	 *
	 * @param string $option Option name.
	 * @return bool
	 */
	function delete_option( $option ) {
		unset( $GLOBALS['wpsc_test_options'][ $option ] );
		return true;
	}
}

if ( ! function_exists( 'trailingslashit' ) ) {
	/**
	 * Appends a trailing slash (test double for WordPress trailingslashit()).
	 *
	 * @param string $string Value to trailingslash.
	 * @return string
	 */
	function trailingslashit( $string ) {
		return rtrim( (string) $string, '/\\' ) . '/';
	}
}

/*
 * Load the procedural caching engine. Its functions become callable from tests.
 * require_once keeps test files that also require it (for self-documentation)
 * idempotent.
 */
require_once dirname( __DIR__, 2 ) . '/wp-cache-phase2.php';
