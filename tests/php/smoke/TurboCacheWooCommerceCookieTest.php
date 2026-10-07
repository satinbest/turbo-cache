<?php
/**
 * Tests for WooCommerce cookie detection and request safety.
 *
 * @package automattic/wp-super-cache
 */

use PHPUnit\Framework\TestCase;

/**
 * Smoke tests for Turbo Cache WooCommerce cookie safety layer.
 *
 * @covers ::turbo_cache_detect_woocommerce_cookie
 * @covers ::turbo_cache_is_woocommerce_cookie
 * @covers ::turbo_cache_check_request_safety
 */
class TurboCacheWooCommerceCookieTest extends TestCase {

	/**
	 * Verify woocommerce_items_in_cart cookie causes match.
	 */
	public function test_woocommerce_items_in_cart_matches(): void {
		$cookies = array( 'woocommerce_items_in_cart' => '1' );
		$this->assertSame( 'woocommerce_items_in_cart', turbo_cache_detect_woocommerce_cookie( $cookies ) );
		$this->assertTrue( turbo_cache_is_woocommerce_cookie( $cookies ) );
		$this->assertSame( 'woocommerce_cookie:woocommerce_items_in_cart', turbo_cache_check_request_safety( $cookies ) );
	}

	/**
	 * Verify woocommerce_cart_hash cookie causes match.
	 */
	public function test_woocommerce_cart_hash_matches(): void {
		$cookies = array( 'woocommerce_cart_hash' => 'abc' );
		$this->assertSame( 'woocommerce_cart_hash', turbo_cache_detect_woocommerce_cookie( $cookies ) );
		$this->assertTrue( turbo_cache_is_woocommerce_cookie( $cookies ) );
		$this->assertSame( 'woocommerce_cookie:woocommerce_cart_hash', turbo_cache_check_request_safety( $cookies ) );
	}

	/**
	 * Verify wp_woocommerce_session_* exact prefix matches.
	 */
	public function test_woocommerce_session_prefix_matches(): void {
		$cookies1 = array( 'wp_woocommerce_session_abc' => 'xyz' );
		$this->assertSame( 'wp_woocommerce_session_abc', turbo_cache_detect_woocommerce_cookie( $cookies1 ) );
		$this->assertTrue( turbo_cache_is_woocommerce_cookie( $cookies1 ) );

		$cookies2 = array( 'wp_woocommerce_session_xyz' => 'session_data_123' );
		$this->assertSame( 'wp_woocommerce_session_xyz', turbo_cache_detect_woocommerce_cookie( $cookies2 ) );
		$this->assertTrue( turbo_cache_is_woocommerce_cookie( $cookies2 ) );
	}

	/**
	 * Verify substring containing wp_woocommerce_session does NOT match.
	 */
	public function test_substring_session_does_not_match(): void {
		$cookies = array( 'some_wp_woocommerce_session_test' => '1' );
		$this->assertFalse( turbo_cache_detect_woocommerce_cookie( $cookies ) );
		$this->assertFalse( turbo_cache_is_woocommerce_cookie( $cookies ) );
		$this->assertFalse( turbo_cache_check_request_safety( $cookies ) );
	}

	/**
	 * Verify standard WordPress auth cookie is not classified as WooCommerce cookie.
	 */
	public function test_wordpress_auth_cookie_does_not_match_wc_check(): void {
		$cookies = array( 'wordpress_logged_in_x' => '1' );
		$this->assertFalse( turbo_cache_detect_woocommerce_cookie( $cookies ) );
		$this->assertFalse( turbo_cache_is_woocommerce_cookie( $cookies ) );
		$this->assertFalse( turbo_cache_check_request_safety( $cookies ) );
	}

	/**
	 * Verify empty cookies array returns false cleanly.
	 */
	public function test_empty_cookies_returns_false(): void {
		$this->assertFalse( turbo_cache_detect_woocommerce_cookie( array() ) );
		$this->assertFalse( turbo_cache_is_woocommerce_cookie( array() ) );
		$this->assertFalse( turbo_cache_check_request_safety( array() ) );
	}

	/**
	 * Verify integer 0 key cookie does not cause PHP warnings or false matches.
	 */
	public function test_cookie_named_zero_does_not_break_matching(): void {
		$cookies = array(
			'0'                          => 'val',
			'wp_woocommerce_session_123' => 'active',
		);
		$this->assertSame( 'wp_woocommerce_session_123', turbo_cache_detect_woocommerce_cookie( $cookies ) );
		$this->assertTrue( turbo_cache_is_woocommerce_cookie( $cookies ) );
	}
}
