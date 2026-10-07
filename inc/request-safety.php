<?php
/**
 * Request safety and dynamic state detection for Turbo Cache.
 *
 * Provides early cacheability checks on the Phase 1 hot path before
 * cached files are served, as well as Phase 2 guardrails.
 *
 * @package automattic/wp-super-cache
 */

/**
 * Detect sensitive WooCommerce dynamic cookies in the request.
 *
 * Matches:
 * - woocommerce_items_in_cart
 * - woocommerce_cart_hash
 * - wp_woocommerce_session_* (exact prefix match)
 *
 * @param array|null $cookies Optional cookies array (defaults to $_COOKIE).
 * @return string|false Matched cookie name if detected, false otherwise.
 */
function turbo_cache_detect_woocommerce_cookie( $cookies = null ) {
	if ( null === $cookies ) {
		$cookies = $_COOKIE;
	}

	if ( empty( $cookies ) || ! is_array( $cookies ) ) {
		return false;
	}

	// 1. Fast O(1) checks for exact cookie names.
	if ( isset( $cookies['woocommerce_items_in_cart'] ) ) {
		return 'woocommerce_items_in_cart';
	}
	if ( isset( $cookies['woocommerce_cart_hash'] ) ) {
		return 'woocommerce_cart_hash';
	}

	// 2. Exact prefix check for wp_woocommerce_session_*.
	// Using strncmp for high performance without regex engine overhead.
	$prefix     = 'wp_woocommerce_session_';
	$prefix_len = 23;

	foreach ( array_keys( $cookies ) as $cookie_name ) {
		$cookie_name = (string) $cookie_name;
		if ( 0 === strncmp( $cookie_name, $prefix, $prefix_len ) ) {
			return $cookie_name;
		}
	}

	return false;
}

/**
 * Boolean check for sensitive WooCommerce dynamic cookies.
 *
 * @param array|null $cookies Optional cookies array.
 * @return bool True if a sensitive WooCommerce cookie is present, false otherwise.
 */
function turbo_cache_is_woocommerce_cookie( $cookies = null ) {
	return false !== turbo_cache_detect_woocommerce_cookie( $cookies );
}

/**
 * Evaluate early request safety on the Phase 1 hot path.
 *
 * Checks if the incoming request should bypass caching due to dynamic
 * states such as active WooCommerce shopping cart or session cookies.
 *
 * @param array|null $cookies Optional cookies array (defaults to $_COOKIE).
 * @return string|false Bypass reason if request must bypass cache, false if safe to continue.
 */
function turbo_cache_check_request_safety( $cookies = null ) {
	$matched_wc_cookie = turbo_cache_detect_woocommerce_cookie( $cookies );
	if ( false !== $matched_wc_cookie ) {
		return 'woocommerce_cookie:' . $matched_wc_cookie;
	}

	return false;
}

/**
 * Rule: Check if caching is globally disabled.
 *
 * @param bool|null $cache_enabled_override Optional override for testing.
 * @return string|false Bypass reason if disabled, false if enabled.
 */
function turbo_cache_rule_cache_disabled( $cache_enabled_override = null ) {
	global $cache_enabled;
	$enabled = ( null !== $cache_enabled_override ) ? $cache_enabled_override : ( isset( $cache_enabled ) ? (bool) $cache_enabled : true );

	if ( ! $enabled ) {
		return 'cache_disabled';
	}

	return false;
}

/**
 * Rule: Check if HTTP request method requires cache bypass.
 * Preserves upstream behavior matching POST, PUT, DELETE.
 *
 * @param string|null $method Optional method override for testing.
 * @return string|false Bypass reason if method is not cacheable, false otherwise.
 */
function turbo_cache_rule_http_method( $method = null ) {
	if ( null === $method ) {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Phase 1 executes before WordPress sanitization functions load.
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : 'GET';
	}

	if ( in_array( $method, array( 'POST', 'PUT', 'DELETE' ), true ) ) {
		return 'http_method:' . $method;
	}

	return false;
}

/**
 * Rule: Check if request targets WordPress backend, admin, login, cron, or CLI.
 *
 * @return string|false Bypass reason if backend/system request, false otherwise.
 */
function turbo_cache_rule_backend_or_system() {
	if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
		return 'system:cron';
	}

	if ( function_exists( 'is_admin' ) && is_admin() ) {
		return 'backend:admin';
	}

	if ( function_exists( 'is_admin' ) && function_exists( 'wpsc_is_backend' ) && wpsc_is_backend() ) {
		return 'backend:admin';
	}

	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
	$script = isset( $_SERVER['PHP_SELF'] ) ? basename( $_SERVER['PHP_SELF'] ) : '';
	if ( '' !== $script && 'index.php' !== $script ) {
		if ( in_array( $script, array( 'wp-login.php', 'xmlrpc.php', 'wp-cron.php' ), true ) ) {
			return 'backend:admin';
		}
	}

	return false;
}

/**
 * Rule: Check URL parameters requiring early cache bypass (Customizer, secret bypass).
 *
 * @param array|null $get_params Optional $_GET override for testing.
 * @return string|false Bypass reason if parameter matched, false otherwise.
 */
function turbo_cache_rule_query_params( $get_params = null ) {
	if ( null === $get_params ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$get_params = isset( $_GET ) ? $_GET : array();
	}

	if ( empty( $get_params ) || ! is_array( $get_params ) ) {
		return false;
	}

	if ( isset( $get_params['customize_changeset_uuid'] ) ) {
		return 'query:customizer';
	}

	global $cache_page_secret;
	if ( isset( $get_params['donotcachepage'] ) && isset( $cache_page_secret ) && $get_params['donotcachepage'] === $cache_page_secret ) {
		return 'query:secret_bypass';
	}

	return false;
}

/**
 * Rule: Check if request contains sensitive WooCommerce dynamic cookies.
 *
 * @param array|null $cookies Optional cookies override for testing.
 * @return string|false Bypass reason if WooCommerce dynamic cookie present, false otherwise.
 */
function turbo_cache_rule_woocommerce_cookies( $cookies = null ) {
	$matched = turbo_cache_detect_woocommerce_cookie( $cookies );
	if ( false !== $matched ) {
		return 'cookie:woocommerce:' . $matched;
	}

	return false;
}

/**
 * Rule: Check if caching is disabled for current user due to authentication or logged-in status.
 *
 * @return string|false Bypass reason if auth/logged-in user, false otherwise.
 */
function turbo_cache_rule_auth_cookies() {
	if ( function_exists( 'wpsc_is_caching_user_disabled' ) && wpsc_is_caching_user_disabled() ) {
		return 'cookie:auth_user';
	}

	return false;
}

/**
 * Rule: Check if request contains rejected cookies (upstream settings).
 *
 * @return string|false Bypass reason if rejected cookie present, false otherwise.
 */
function turbo_cache_rule_rejected_cookies() {
	if ( function_exists( 'wpsc_is_rejected_cookie' ) && wpsc_is_rejected_cookie() ) {
		return 'cookie:rejected_cookie';
	}

	return false;
}

/**
 * Dispatcher evaluating all early request rules on the Phase 1 hot path.
 *
 * Evaluates non-cookie rules first (globally disabled, method, backend, query params),
 * and only scans cookie rules if $_COOKIE is non-empty (fast shortcut).
 *
 * @param array|null $context Optional context for testing (method, cookies, get, cache_enabled).
 * @return string|false Bypass reason if request must bypass cache, false if cacheable.
 */
function turbo_cache_evaluate_early_request_rules( $context = null ) {
	// 1. Globally disabled cache
	$cache_enabled_val = ( is_array( $context ) && array_key_exists( 'cache_enabled', $context ) ) ? $context['cache_enabled'] : null;
	$reason            = turbo_cache_rule_cache_disabled( $cache_enabled_val );
	if ( false !== $reason ) {
		return $reason;
	}

	// 2. HTTP Method
	$method_val = ( is_array( $context ) && isset( $context['method'] ) ) ? $context['method'] : null;
	$reason     = turbo_cache_rule_http_method( $method_val );
	if ( false !== $reason ) {
		return $reason;
	}

	// 3. Backend / Admin / System
	$reason = turbo_cache_rule_backend_or_system();
	if ( false !== $reason ) {
		return $reason;
	}

	// 4. Early Query Parameters (Customizer, Secret Bypass)
	$get_val = ( is_array( $context ) && isset( $context['get'] ) ) ? $context['get'] : null;
	$reason  = turbo_cache_rule_query_params( $get_val );
	if ( false !== $reason ) {
		return $reason;
	}

	// 5. Cookie-dependent rules (Fast shortcut: ONLY run if cookies exist!)
	$cookies_val = ( is_array( $context ) && isset( $context['cookies'] ) ) ? $context['cookies'] : ( isset( $_COOKIE ) ? $_COOKIE : array() );

	if ( ! empty( $cookies_val ) && is_array( $cookies_val ) ) {
		// 5a. WooCommerce dynamic cookies (Cart & Session safety first)
		$reason = turbo_cache_rule_woocommerce_cookies( $cookies_val );
		if ( false !== $reason ) {
			return $reason;
		}

		// 5b. WordPress authentication / logged-in cookies
		$reason = turbo_cache_rule_auth_cookies();
		if ( false !== $reason ) {
			return $reason;
		}

		// 5c. Upstream user-configured rejected cookies
		$reason = turbo_cache_rule_rejected_cookies();
		if ( false !== $reason ) {
			return $reason;
		}
	}

	return false;
}
