<?php
/**
 * Tests for Cache Rules Layer predicates and dispatcher.
 *
 * @package automattic/wp-super-cache
 */

use PHPUnit\Framework\TestCase;

/**
 * Smoke tests for Turbo Cache early rules dispatcher and predicates.
 *
 * @covers ::turbo_cache_evaluate_early_request_rules
 * @covers ::turbo_cache_rule_cache_disabled
 * @covers ::turbo_cache_rule_http_method
 * @covers ::turbo_cache_rule_backend_or_system
 * @covers ::turbo_cache_rule_query_params
 * @covers ::turbo_cache_rule_woocommerce_cookies
 * @covers ::turbo_cache_rule_auth_cookies
 * @covers ::turbo_cache_rule_rejected_cookies
 */
class TurboCacheRulesLayerTest extends TestCase {

	/**
	 * Clean up globals after each test.
	 */
	protected function tearDown(): void {
		parent::tearDown();
		unset( $GLOBALS['cache_enabled'], $GLOBALS['cache_page_secret'] );
	}

	/**
	 * Verify globally disabled cache causes early bypass.
	 */
	public function test_cache_disabled_bypasses(): void {
		$reason = turbo_cache_evaluate_early_request_rules(
			array(
				'cache_enabled' => false,
			)
		);
		$this->assertSame( 'cache_disabled', $reason );
	}

	/**
	 * Verify HTTP methods POST, PUT, DELETE cause early bypass.
	 */
	public function test_non_get_methods_bypass(): void {
		$this->assertSame( 'http_method:POST', turbo_cache_evaluate_early_request_rules( array( 'method' => 'POST' ) ) );
		$this->assertSame( 'http_method:PUT', turbo_cache_evaluate_early_request_rules( array( 'method' => 'PUT' ) ) );
		$this->assertSame( 'http_method:DELETE', turbo_cache_evaluate_early_request_rules( array( 'method' => 'DELETE' ) ) );
	}

	/**
	 * Verify GET method is allowed.
	 */
	public function test_get_method_is_cacheable(): void {
		$this->assertFalse( turbo_cache_rule_http_method( 'GET' ) );
		$this->assertFalse( turbo_cache_evaluate_early_request_rules( array( 'method' => 'GET' ) ) );
	}

	/**
	 * Verify customizer parameter causes bypass.
	 */
	public function test_customizer_query_param_bypasses(): void {
		$reason = turbo_cache_evaluate_early_request_rules(
			array(
				'get' => array( 'customize_changeset_uuid' => 'test-uuid-1234' ),
			)
		);
		$this->assertSame( 'query:customizer', $reason );
	}

	/**
	 * Verify secret bypass parameter causes bypass when secret matches.
	 */
	public function test_secret_bypass_param_matches(): void {
		$GLOBALS['cache_page_secret'] = 'my_secret_token';

		$reason = turbo_cache_evaluate_early_request_rules(
			array(
				'get' => array( 'donotcachepage' => 'my_secret_token' ),
			)
		);
		$this->assertSame( 'query:secret_bypass', $reason );
	}

	/**
	 * Verify wrong secret bypass parameter does not cause bypass.
	 */
	public function test_wrong_secret_bypass_param_does_not_match(): void {
		$GLOBALS['cache_page_secret'] = 'my_secret_token';

		$reason = turbo_cache_evaluate_early_request_rules(
			array(
				'get' => array( 'donotcachepage' => 'wrong_token' ),
			)
		);
		$this->assertFalse( $reason );
	}

	/**
	 * Verify empty cookies optimization skips cookie checks but does NOT skip non-cookie rules.
	 */
	public function test_empty_cookie_optimization_preserves_non_cookie_rules(): void {
		// Empty cookies with POST method -> must still bypass due to method!
		$reason = turbo_cache_evaluate_early_request_rules(
			array(
				'method'  => 'POST',
				'cookies' => array(),
			)
		);
		$this->assertSame( 'http_method:POST', $reason );

		// Empty cookies with GET and no bypass params -> must be cacheable (false)!
		$reason = turbo_cache_evaluate_early_request_rules(
			array(
				'method'  => 'GET',
				'cookies' => array(),
			)
		);
		$this->assertFalse( $reason );
	}

	/**
	 * Verify WooCommerce dynamic cookies trigger early bypass.
	 */
	public function test_woocommerce_cookies_trigger_bypass(): void {
		// items_in_cart
		$reason = turbo_cache_evaluate_early_request_rules(
			array(
				'cookies' => array( 'woocommerce_items_in_cart' => '1' ),
			)
		);
		$this->assertSame( 'cookie:woocommerce:woocommerce_items_in_cart', $reason );

		// cart_hash
		$reason = turbo_cache_evaluate_early_request_rules(
			array(
				'cookies' => array( 'woocommerce_cart_hash' => 'hash_abc' ),
			)
		);
		$this->assertSame( 'cookie:woocommerce:woocommerce_cart_hash', $reason );

		// session prefix
		$reason = turbo_cache_evaluate_early_request_rules(
			array(
				'cookies' => array( 'wp_woocommerce_session_customer123' => 'token' ),
			)
		);
		$this->assertSame( 'cookie:woocommerce:wp_woocommerce_session_customer123', $reason );
	}

	/**
	 * Verify invalid substring cookie is not matched as WooCommerce cookie.
	 */
	public function test_woocommerce_false_substring_not_matched(): void {
		$reason = turbo_cache_evaluate_early_request_rules(
			array(
				'cookies' => array( 'some_wp_woocommerce_session_fake' => 'val' ),
			)
		);
		$this->assertFalse( $reason );
	}

	/**
	 * Verify rule priority: method rule is evaluated before cookie rules.
	 */
	public function test_rule_priority_method_before_cookie(): void {
		$reason = turbo_cache_evaluate_early_request_rules(
			array(
				'method'  => 'POST',
				'cookies' => array( 'woocommerce_items_in_cart' => '1' ),
			)
		);
		$this->assertSame( 'http_method:POST', $reason );
	}

	/**
	 * Verify rule priority: globally disabled cache is evaluated before method and cookie rules.
	 */
	public function test_rule_priority_cache_disabled_first(): void {
		$reason = turbo_cache_evaluate_early_request_rules(
			array(
				'cache_enabled' => false,
				'method'        => 'POST',
				'cookies'       => array( 'woocommerce_items_in_cart' => '1' ),
			)
		);
		$this->assertSame( 'cache_disabled', $reason );
	}

	/**
	 * Verify rule priority: query params evaluated before cookies.
	 */
	public function test_rule_priority_query_param_before_cookie(): void {
		$reason = turbo_cache_evaluate_early_request_rules(
			array(
				'get'     => array( 'customize_changeset_uuid' => 'uuid' ),
				'cookies' => array( 'woocommerce_items_in_cart' => '1' ),
			)
		);
		$this->assertSame( 'query:customizer', $reason );
	}

	/**
	 * Verify normal tracking or third-party cookies do not cause bypass.
	 */
	public function test_innocent_cookies_do_not_bypass(): void {
		$reason = turbo_cache_evaluate_early_request_rules(
			array(
				'cookies' => array(
					'_ga'        => 'GA1.1.12345',
					'theme_mode' => 'dark',
				),
			)
		);
		$this->assertFalse( $reason );
	}
}
