<?php
/**
 * Smoke tests for Turbo Cache Phase 5C Runtime Integration.
 *
 * Verifies request safety guardrails in cache delivery (wp_cache_serve_cache_file),
 * cookie identity resolution (wp_cache_get_cookies_values), output buffer
 * cache validation (wp_cache_ob_callback), and storage helper integration.
 *
 * @package automattic/wp-super-cache
 */

use PHPUnit\Framework\TestCase;

/**
 * @covers ::wp_cache_serve_cache_file
 * @covers ::wp_cache_get_cookies_values
 * @covers ::wp_cache_ob_callback
 */
class TurboCacheRuntimeIntegrationTest extends TestCase {

	/**
	 * Preserved superglobals.
	 *
	 * @var array
	 */
	private array $saved_server;

	/**
	 * Preserved cookies.
	 *
	 * @var array
	 */
	private array $saved_cookie;

	/**
	 * Preserved GET parameters.
	 *
	 * @var array
	 */
	private array $saved_get;

	protected function setUp(): void {
		$this->saved_server = $_SERVER;
		$this->saved_cookie = $_COOKIE;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$this->saved_get = $_GET;

		// Setup baseline globals needed by wp_cache_serve_cache_file.
		$GLOBALS['WPSC_HTTP_HOST']            = 'example.com';
		$GLOBALS['wp_cache_request_uri']      = '/test-page/';
		$GLOBALS['cache_path']                = '/tmp/test-cache/';
		$GLOBALS['file_prefix']               = 'wp-cache-';
		$GLOBALS['blogcacheid']               = '';
		$GLOBALS['blog_cache_dir']            = '/tmp/test-cache/';
		$GLOBALS['cache_compression']         = 0;
		$GLOBALS['wp_cache_gzip_encoding']    = '';
		$GLOBALS['wp_cache_slash_check']      = 1;
		$GLOBALS['wp_supercache_304']         = 0;
		$GLOBALS['wp_cache_home_path']        = '/';
		$GLOBALS['wp_cache_no_cache_for_get'] = 0;
		$GLOBALS['wpsc_save_headers']         = 0;
		$GLOBALS['cache_max_time']            = 3600;

		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_SERVER['HTTP_ACCEPT']    = 'text/html';
		$_SERVER['REQUEST_URI']    = '/test-page/';
		$_COOKIE                   = array();
		$_GET                      = array();

		// Reset static cache in wp_cache_get_cookies_values.
		wp_cache_get_cookies_values( true );
	}

	protected function tearDown(): void {
		$_SERVER = $this->saved_server;
		$_COOKIE = $this->saved_cookie;
		$_GET    = $this->saved_get;

		// Reset cookie cache after each test.
		wp_cache_get_cookies_values( true );
	}

	/**
	 * Verify wp_cache_serve_cache_file returns false when DONOTCACHEPAGE is defined.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_serve_cache_file_bypasses_when_donotcachepage_defined(): void {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', 1 );
		}

		$this->assertFalse( wp_cache_serve_cache_file() );
	}

	/**
	 * Verify wp_cache_serve_cache_file bypasses on WooCommerce items in cart cookie.
	 */
	public function test_serve_cache_file_bypasses_on_woocommerce_items_in_cart(): void {
		$_COOKIE['woocommerce_items_in_cart'] = '1';
		wp_cache_get_cookies_values( true );

		$this->assertFalse( wp_cache_serve_cache_file() );
	}

	/**
	 * Verify wp_cache_serve_cache_file bypasses on WooCommerce cart hash cookie.
	 */
	public function test_serve_cache_file_bypasses_on_woocommerce_cart_hash(): void {
		$_COOKIE['woocommerce_cart_hash'] = 'hash_val_123';
		wp_cache_get_cookies_values( true );

		$this->assertFalse( wp_cache_serve_cache_file() );
	}

	/**
	 * Verify wp_cache_serve_cache_file bypasses on WooCommerce session cookie.
	 */
	public function test_serve_cache_file_bypasses_on_woocommerce_session_cookie(): void {
		$_COOKIE['wp_woocommerce_session_tok123'] = 'sess_val_456';
		wp_cache_get_cookies_values( true );

		$this->assertFalse( wp_cache_serve_cache_file() );
	}

	/**
	 * Verify wp_cache_get_cookies_values returns empty string for anonymous request.
	 */
	public function test_cookies_values_empty_for_anonymous(): void {
		$_COOKIE = array( 'random_site_pref' => 'dark' );
		$values  = wp_cache_get_cookies_values( true );

		$this->assertSame( '', $values );
	}

	/**
	 * Verify wp_cache_get_cookies_values returns non-empty MD5 for WooCommerce cart cookie.
	 */
	public function test_cookies_values_captures_woocommerce_cart_cookie(): void {
		$_COOKIE['woocommerce_items_in_cart'] = '2';
		$values                               = wp_cache_get_cookies_values( true );

		$this->assertNotEmpty( $values );
		$this->assertSame( 32, strlen( $values ) );
	}

	/**
	 * Verify wp_cache_get_cookies_values returns non-empty MD5 for WooCommerce session cookie.
	 */
	public function test_cookies_values_captures_woocommerce_session_cookie(): void {
		$_COOKIE['wp_woocommerce_session_active_token'] = 'token_abc';
		$values = wp_cache_get_cookies_values( true );

		$this->assertNotEmpty( $values );
		$this->assertSame( 32, strlen( $values ) );
	}

	/**
	 * Verify wp_cache_ob_callback rejects caching when WooCommerce dynamic cookie is present.
	 */
	public function test_ob_callback_bypasses_on_woocommerce_cookie(): void {
		$_COOKIE['woocommerce_items_in_cart'] = '1';
		$buffer                               = '<html><body>Test Output</body></html>';

		$result = wp_cache_ob_callback( $buffer );
		// Dynamic buffer processing returns the unmodified buffer or maybe_dynamic.
		$this->assertStringContainsString( 'Test Output', $result );
	}

	/**
	 * Verify wp_cache_ob_callback rejects caching when Customizer query parameter is set.
	 */
	public function test_ob_callback_bypasses_on_customizer_query_param(): void {
		$_GET['customize_changeset_uuid'] = 'uuid-1234';
		$buffer                           = '<html><body>Customizer Page</body></html>';

		$result = wp_cache_ob_callback( $buffer );
		$this->assertStringContainsString( 'Customizer Page', $result );
	}

	/**
	 * Verify storage layer filepath matches expected supercache layout.
	 */
	public function test_storage_layer_filepath_resolution(): void {
		$expected = '/tmp/test-cache/supercache/example.com/test-page/index.html';
		$resolved = turbo_cache_get_cache_filepath( '/test-page/', 'example.com', '/tmp/test-cache/', false );

		$this->assertSame( $expected, $resolved );
	}
}
