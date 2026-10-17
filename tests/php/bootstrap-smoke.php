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

// phpcs:disable Universal.Files.SeparateFunctionsFromOO.Mixed

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

if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	/**
	 * Retrieve response body (test double for WordPress wp_remote_retrieve_body()).
	 *
	 * @param array $response HTTP response array.
	 * @return string
	 */
	function wp_remote_retrieve_body( $response ) {
		if ( is_array( $response ) && isset( $response['body'] ) ) {
			return (string) $response['body'];
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

if ( ! function_exists( 'check_ajax_referer' ) ) {
	/**
	 * Test double for WordPress check_ajax_referer().
	 *
	 * @param int|string   $action    Action nonce.
	 * @param false|string $query_arg Key to check for nonce in $_REQUEST.
	 * @param bool         $die       Whether to die if nonce is invalid.
	 * @return int|false
	 * @throws RuntimeException When nonce is set to fail.
	 */
	function check_ajax_referer( $action = -1, $query_arg = false, $die = true ) {
		if ( ! empty( $GLOBALS['wpsc_test_fail_nonce'] ) ) {
			if ( $die ) {
				throw new RuntimeException( 'Nonce verification failed' );
			}
			return false;
		}
		return 1;
	}
}

if ( ! function_exists( 'current_user_can' ) ) {
	/**
	 * Test double for WordPress current_user_can().
	 *
	 * @param string $capability Capability name.
	 * @return bool
	 */
	function current_user_can( $capability ) {
		unset( $capability );
		if ( isset( $GLOBALS['wpsc_test_current_user_can'] ) ) {
			return (bool) $GLOBALS['wpsc_test_current_user_can'];
		}
		return true;
	}
}

if ( ! function_exists( 'wp_send_json_success' ) ) {
	/**
	 * Test double for WordPress wp_send_json_success().
	 *
	 * @param mixed $data        Data to encode.
	 * @param int   $status_code HTTP status code.
	 * @param int   $options     json_encode options.
	 * @throws RuntimeException Exit simulation.
	 */
	function wp_send_json_success( $data = null, $status_code = null, $options = 0 ) {
		unset( $options );
		$GLOBALS['wpsc_test_last_json_response'] = array(
			'success' => true,
			'data'    => $data,
			'code'    => $status_code,
		);
		throw new RuntimeException( 'wp_send_json_success' );
	}
}

if ( ! function_exists( 'wp_send_json_error' ) ) {
	/**
	 * Test double for WordPress wp_send_json_error().
	 *
	 * @param mixed $data        Data to encode.
	 * @param int   $status_code HTTP status code.
	 * @param int   $options     json_encode options.
	 * @throws RuntimeException Exit simulation.
	 */
	function wp_send_json_error( $data = null, $status_code = null, $options = 0 ) {
		unset( $options );
		$GLOBALS['wpsc_test_last_json_response'] = array(
			'success' => false,
			'data'    => $data,
			'code'    => $status_code,
		);
		throw new RuntimeException( 'wp_send_json_error' );
	}
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action( $hook_name, $callback, $priority = 10, $accepted_args = 1 ) {
		return add_filter( $hook_name, $callback, $priority, $accepted_args );
	}
}

if ( ! function_exists( 'do_action' ) ) {
	function do_action( $hook_name, ...$args ) {
		apply_filters( $hook_name, '', ...$args );
	}
}

if ( ! function_exists( 'wp_create_nonce' ) ) {
	function wp_create_nonce( $action = -1 ) {
		return substr( md5( 'nonce_' . $action ), 0, 10 );
	}
}

if ( ! function_exists( 'wp_nonce_field' ) ) {
	function wp_nonce_field( $action = -1, $name = '_wpnonce', $referer = true, $echo = true ) {
		$nonce = wp_create_nonce( $action );
		$html  = '<input type="hidden" id="' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $nonce ) . '" />';
		if ( $referer ) {
			$html .= '<input type="hidden" name="_wp_http_referer" value="/wp-admin/" />';
		}
		if ( $echo ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo $html;
		}
		return $html;
	}
}

if ( ! function_exists( 'admin_url' ) ) {
	function admin_url( $path = '', $scheme = 'admin' ) {
		unset( $scheme );
		return 'https://example.com/wp-admin/' . ltrim( $path, '/' );
	}
}

if ( ! function_exists( 'plugin_dir_url' ) ) {
	function plugin_dir_url( $file ) {
		unset( $file );
		return 'https://example.com/wp-content/plugins/wp-super-cache/';
	}
}

if ( ! function_exists( 'plugin_dir_path' ) ) {
	function plugin_dir_path( $file ) {
		return dirname( $file ) . '/';
	}
}

if ( ! function_exists( 'wp_enqueue_script' ) ) {
	function wp_enqueue_script( $handle, $src = '', $deps = array(), $ver = false, $in_footer = false ) {
		$GLOBALS['wpsc_test_enqueued_scripts'][ $handle ] = array(
			'src'       => $src,
			'deps'      => $deps,
			'ver'       => $ver,
			'in_footer' => $in_footer,
		);
	}
}

if ( ! function_exists( 'wp_localize_script' ) ) {
	function wp_localize_script( $handle, $object_name, $l10n ) {
		$GLOBALS['wpsc_test_localized_scripts'][ $handle ][ $object_name ] = $l10n;
		return true;
	}
}

if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $value ) {
		return is_string( $value ) ? stripslashes( $value ) : $value;
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $str ) {
		return trim( strip_tags( (string) $str ) );
	}
}

if ( ! function_exists( 'sanitize_html_class' ) ) {
	function sanitize_html_class( $class, $fallback = '' ) {
		$sanitized = preg_replace( '|%[a-fA-F0-9][a-fA-F0-9]|', '', (string) $class );
		$sanitized = preg_replace( '/[^A-Za-z0-9_-]/', '', $sanitized );
		return '' === $sanitized ? $fallback : $sanitized;
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $text, $domain = 'default' ) {
		unset( $domain );
		return esc_html( $text );
	}
}

if ( ! function_exists( 'esc_html_e' ) ) {
	function esc_html_e( $text, $domain = 'default' ) {
		unset( $domain );
		echo esc_html( $text );
	}
}

if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $url ) {
		$sanitized = filter_var( $url, FILTER_SANITIZE_URL );
		return false !== $sanitized ? $sanitized : '';
	}
}

if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( $url ) {
		$sanitized = filter_var( $url, FILTER_SANITIZE_URL );
		return false !== $sanitized ? $sanitized : '';
	}
}

if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = 'default' ) {
		unset( $domain );
		return (string) $text;
	}
}

if ( ! function_exists( '_e' ) ) {
	function _e( $text, $domain = 'default' ) {
		unset( $domain );
		echo esc_html( (string) $text );
	}
}

if ( ! function_exists( 'add_query_arg' ) ) {
	function add_query_arg( ...$args ) {
		if ( is_array( $args[0] ) ) {
			$new_params = $args[0];
			$url        = isset( $args[1] ) ? $args[1] : '';
		} else {
			$new_params = array( $args[0] => $args[1] );
			$url        = isset( $args[2] ) ? $args[2] : '';
		}
		$sep = strpos( $url, '?' ) !== false ? '&' : '?';
		return $url . $sep . http_build_query( $new_params );
	}
}

if ( ! function_exists( 'wp_die' ) ) {
	function wp_die( $message = '', $title = '', $args = array() ) {
		unset( $title, $args );
		throw new RuntimeException( 'wp_die: ' . (string) $message );
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, $options = 0, $depth = 512 ) {
		return json_encode( $data, $options, $depth );
	}
}

if ( ! function_exists( 'checked' ) ) {
	function checked( $checked, $current = true, $echo = true ) {
		$result = ( (string) $checked === (string) $current ) ? " checked='checked'" : '';
		if ( $echo ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo $result;
		}
		return $result;
	}
}

if ( ! function_exists( 'selected' ) ) {
	function selected( $selected, $current = true, $echo = true ) {
		$result = ( (string) $selected === (string) $current ) ? " selected='selected'" : '';
		if ( $echo ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo $result;
		}
		return $result;
	}
}

if ( ! defined( 'WPSC_VERSION_ID' ) ) {
	define( 'WPSC_VERSION_ID', '2.0.0-turbo' );
}

if ( ! defined( 'WPSC_PRELOAD_POST_COUNT' ) ) {
	define( 'WPSC_PRELOAD_POST_COUNT', 10 );
}

if ( ! function_exists( 'wpsupercache_site_admin' ) ) {
	function wpsupercache_site_admin() {
		return true;
	}
}

if ( ! function_exists( 'add_options_page' ) ) {
	function add_options_page( ...$args ) {
		unset( $args );
		return true;
	}
}

if ( ! function_exists( 'add_submenu_page' ) ) {
	function add_submenu_page( ...$args ) {
		unset( $args );
		return true;
	}
}

if ( ! function_exists( 'wp_schedule_single_event' ) ) {
	function wp_schedule_single_event( $timestamp, $hook, $args = array() ) {
		if ( ! isset( $GLOBALS['wpsc_test_scheduled_events'] ) ) {
			$GLOBALS['wpsc_test_scheduled_events'] = array();
		}
		$GLOBALS['wpsc_test_scheduled_events'][ $hook ][] = array(
			'timestamp' => $timestamp,
			'args'      => $args,
		);
		return true;
	}
}

if ( ! function_exists( 'wp_next_scheduled' ) ) {
	function wp_next_scheduled( $hook, $args = array() ) {
		unset( $args );
		if ( isset( $GLOBALS['wpsc_test_scheduled_events'][ $hook ] ) && ! empty( $GLOBALS['wpsc_test_scheduled_events'][ $hook ] ) ) {
			return $GLOBALS['wpsc_test_scheduled_events'][ $hook ][0]['timestamp'];
		}
		return false;
	}
}

if ( ! function_exists( 'wp_unschedule_event' ) ) {
	function wp_unschedule_event( $timestamp, $hook, $args = array() ) {
		unset( $args );
		if ( isset( $GLOBALS['wpsc_test_scheduled_events'][ $hook ] ) ) {
			foreach ( $GLOBALS['wpsc_test_scheduled_events'][ $hook ] as $idx => $event ) {
				if ( $event['timestamp'] === $timestamp ) {
					unset( $GLOBALS['wpsc_test_scheduled_events'][ $hook ][ $idx ] );
				}
			}
			$GLOBALS['wpsc_test_scheduled_events'][ $hook ] = array_values( $GLOBALS['wpsc_test_scheduled_events'][ $hook ] );
		}
		return true;
	}
}

if ( ! function_exists( 'wp_json_file_decode' ) ) {
	function wp_json_file_decode( $filename, $options = array() ) {
		if ( ! file_exists( $filename ) ) {
			return null;
		}
		$content = file_get_contents( $filename );
		if ( false === $content ) {
			return null;
		}
		$assoc = isset( $options['associative'] ) ? (bool) $options['associative'] : false;
		return json_decode( $content, $assoc );
	}
}

if ( ! function_exists( 'wp_delete_file' ) ) {
	function wp_delete_file( $file ) {
		if ( file_exists( $file ) ) {
			return @unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		return true;
	}
}

if ( ! function_exists( 'get_post_types' ) ) {
	function get_post_types( $args = array(), $output = 'names', $operator = 'and' ) {
		unset( $args, $output, $operator );
		return array(
			'post' => 'post',
			'page' => 'page',
		);
	}
}

if ( ! function_exists( 'esc_sql' ) ) {
	function esc_sql( $data ) {
		return addslashes( (string) $data );
	}
}

// phpcs:ignore Universal.Files.SeparateFunctionsFromOO.Mixed
if ( ! class_exists( 'WpscSmokeTestWpdb' ) ) {
	class WpscSmokeTestWpdb {
		public $posts = 'wp_posts';
		public function get_var( $query = null, $x = 0, $y = 0 ) {
			unset( $query, $x, $y );
			return 10;
		}
	}
}

if ( ! isset( $GLOBALS['wpdb'] ) ) {
	// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	$GLOBALS['wpdb'] = new WpscSmokeTestWpdb();
}

/*
 * Load the procedural caching engine. Its functions become callable from tests.
 * require_once keeps test files that also require it (for self-documentation)
 * idempotent.
 */
require_once dirname( __DIR__, 2 ) . '/wp-cache-phase2.php';
