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
