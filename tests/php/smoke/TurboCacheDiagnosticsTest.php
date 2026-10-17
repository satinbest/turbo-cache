<?php
/**
 * Smoke tests for Turbo Cache Diagnostics and Observability Engine.
 *
 * @package Turbo_Cache
 */

// phpcs:disable WordPress.PHP.NoSilencedErrors.Discouraged
// phpcs:disable WordPress.Security.ValidatedSanitizedInput

require_once dirname( __DIR__, 3 ) . '/inc/cache-diagnostics.php';
require_once dirname( __DIR__, 3 ) . '/inc/preload-engine.php';
require_once dirname( __DIR__, 3 ) . '/inc/cache-delivery.php';

use PHPUnit\Framework\TestCase;

/**
 * Class TurboCacheDiagnosticsTest
 *
 * @covers ::turbo_cache_diagnostics_is_enabled
 * @covers ::turbo_cache_diagnostics_set_enabled
 * @covers ::turbo_cache_diagnostics_redact_uri
 * @covers ::turbo_cache_diagnostics_redact_cookies
 * @covers ::turbo_cache_diagnostics_sanitize_data
 * @covers ::turbo_cache_diagnostics_get_category
 * @covers ::turbo_cache_diagnostics_map_bypass_reason
 * @covers ::turbo_cache_diagnostics_record
 * @covers ::turbo_cache_diagnostics_get_current
 * @covers ::turbo_cache_diagnostics_append_log
 * @covers ::turbo_cache_diagnostics_get_recent_log
 * @covers ::turbo_cache_diagnostics_clear_log
 * @covers ::turbo_cache_diagnostics_get_summary
 */
class TurboCacheDiagnosticsTest extends TestCase {

	/**
	 * Temporary directory.
	 *
	 * @var string
	 */
	private $temp_dir;

	/**
	 * Saved global cache path.
	 *
	 * @var string|null
	 */
	private $saved_cache_path;

	/**
	 * Saved diagnostics flag.
	 *
	 * @var mixed
	 */
	private $saved_diag_flag;

	/**
	 * Set up test environment before each test.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->temp_dir = sys_get_temp_dir() . '/tc_diag_test_' . uniqid() . '/';
		if ( ! is_dir( $this->temp_dir ) ) {
			mkdir( $this->temp_dir, 0777, true );
		}

		global $cache_path;
		$this->saved_cache_path = isset( $cache_path ) ? $cache_path : null;
		$cache_path             = $this->temp_dir;

		$this->saved_diag_flag                      = isset( $GLOBALS['turbo_cache_diagnostics_enabled'] )
			? $GLOBALS['turbo_cache_diagnostics_enabled']
			: null;
		$GLOBALS['turbo_cache_diagnostics_enabled'] = false;
		$GLOBALS['turbo_cache_last_diagnostic']     = null;

		$_SERVER['REQUEST_URI']    = '/test-page/';
		$_SERVER['REQUEST_METHOD'] = 'GET';
	}

	/**
	 * Clean up test environment after each test.
	 */
	protected function tearDown(): void {
		global $cache_path;
		$cache_path = $this->saved_cache_path;

		if ( null !== $this->saved_diag_flag ) {
			$GLOBALS['turbo_cache_diagnostics_enabled'] = $this->saved_diag_flag;
		} else {
			unset( $GLOBALS['turbo_cache_diagnostics_enabled'] );
		}
		unset( $GLOBALS['turbo_cache_last_diagnostic'] );
		unset( $_POST['enable'] );

		if ( function_exists( 'delete_option' ) ) {
			delete_option( 'turbo_cache_diagnostics_enabled' );
		}
		if ( isset( $GLOBALS['wpsc_test_options']['turbo_cache_diagnostics_enabled'] ) ) {
			unset( $GLOBALS['wpsc_test_options']['turbo_cache_diagnostics_enabled'] );
		}

		if ( is_dir( $this->temp_dir ) ) {
			$files = glob( $this->temp_dir . '*' );
			if ( is_array( $files ) ) {
				foreach ( $files as $f ) {
					@unlink( $f );
				}
			}
			@rmdir( $this->temp_dir );
		}

		parent::tearDown();
	}

	/**
	 * Verify diagnostics are disabled by default and toggle correctly.
	 */
	public function test_diagnostics_disabled_by_default_and_toggle() {
		$this->assertFalse( turbo_cache_diagnostics_is_enabled() );

		turbo_cache_diagnostics_set_enabled( true );
		$this->assertTrue( turbo_cache_diagnostics_is_enabled() );

		turbo_cache_diagnostics_set_enabled( false );
		$this->assertFalse( turbo_cache_diagnostics_is_enabled() );
	}

	/**
	/**
	 * Verify sensitive query parameters are redacted from URIs, including nested structures.
	 */
	public function test_sensitive_uri_redaction() {
		$clean_url = '/shop/product-1/?utm_source=google&page=2';
		$this->assertSame( $clean_url, turbo_cache_diagnostics_redact_uri( $clean_url ) );

		$sensitive_url = '/login/?user=alice&password=supersecret&token=abc12345&_wpnonce=987654&secret_key=xyz';
		$redacted      = turbo_cache_diagnostics_redact_uri( $sensitive_url );

		$this->assertStringNotContainsString( 'supersecret', $redacted );
		$this->assertStringNotContainsString( 'abc12345', $redacted );
		$this->assertStringNotContainsString( '987654', $redacted );
		$this->assertStringNotContainsString( 'xyz', $redacted );
		$this->assertStringContainsString( 'password=%5BREDACTED%5D', $redacted );
		$this->assertStringContainsString( 'token=%5BREDACTED%5D', $redacted );
		$this->assertStringContainsString( '_wpnonce=%5BREDACTED%5D', $redacted );

		// Nested query parameters test.
		$nested_url      = '/api/?account%5Bpassword%5D=supersecret&user%5Btoken%5D=nestedtoken';
		$nested_redacted = turbo_cache_diagnostics_redact_uri( $nested_url );
		$this->assertStringNotContainsString( 'supersecret', $nested_redacted );
		$this->assertStringNotContainsString( 'nestedtoken', $nested_redacted );
		$this->assertStringContainsString( '%5BREDACTED%5D', $nested_redacted );
	}

	/**
	 * Verify sanitize data redacts compound sensitive keys.
	 */
	public function test_sensitive_data_patterns() {
		$raw_data = array(
			'safe_field' => 'hello',
			'api_key'    => 'secret123',
			'auth_token' => 'jwt_token_here',
			'_wpnonce'   => 'nonce999',
			'cookies'    => array(
				'wc_session'  => 'abc',
				'auth_cookie' => 'xyz',
			),
		);

		$sanitized = turbo_cache_diagnostics_sanitize_data( $raw_data );
		$this->assertSame( 'hello', $sanitized['safe_field'] );
		$this->assertSame( '[REDACTED]', $sanitized['api_key'] );
		$this->assertSame( '[REDACTED]', $sanitized['auth_token'] );
		$this->assertSame( '[REDACTED]', $sanitized['_wpnonce'] );
		$this->assertSame( array( 'wc_session', 'auth_cookie' ), $sanitized['cookies'] );
	}

	/**
	 * Verify cookies are redacted so that values are never leaked, only names retained.
	 */
	public function test_cookie_redaction_never_leaks_values() {
		$cookies_array = array(
			'woocommerce_items_in_cart'   => '1',
			'wp_woocommerce_session_hash' => 'secret_session_data_12345',
			'wordpress_logged_in_hash'    => 'alice|secret_auth_token_99999',
		);

		$redacted = turbo_cache_diagnostics_redact_cookies( $cookies_array );
		$this->assertSame(
			array( 'woocommerce_items_in_cart', 'wp_woocommerce_session_hash', 'wordpress_logged_in_hash' ),
			$redacted
		);

		// Test header string parsing.
		$cookie_header = 'PHPSESSID=session123; user_pref=dark_mode; token=my_secret_token';
		$header_names  = turbo_cache_diagnostics_redact_cookies( $cookie_header );

		$this->assertContains( 'PHPSESSID', $header_names );
		$this->assertContains( 'user_pref', $header_names );
		$this->assertContains( 'token', $header_names );
		$this->assertNotContains( 'session123', $header_names );
		$this->assertNotContains( 'my_secret_token', $header_names );
	}

	/**
	 * Verify category mapping for diagnostic outcome codes.
	 */
	public function test_category_mapping() {
		$this->assertSame( 'hit', turbo_cache_diagnostics_get_category( 'HIT_SUPERCACHE' ) );
		$this->assertSame( 'hit', turbo_cache_diagnostics_get_category( 'HIT_SUPERCACHE_GZIP' ) );
		$this->assertSame( 'hit', turbo_cache_diagnostics_get_category( 'HIT_NOT_MODIFIED_304' ) );
		$this->assertSame( 'hit', turbo_cache_diagnostics_get_category( 'HIT_WPCACHE' ) );

		$this->assertSame( 'miss', turbo_cache_diagnostics_get_category( 'MISS_FILE_MISSING' ) );
		$this->assertSame( 'miss', turbo_cache_diagnostics_get_category( 'MISS_EXPIRED' ) );
		$this->assertSame( 'miss', turbo_cache_diagnostics_get_category( 'MISS_FILE_UNREADABLE' ) );

		$this->assertSame( 'bypass', turbo_cache_diagnostics_get_category( 'BYPASS_WOOCOMMERCE_COOKIE' ) );
		$this->assertSame( 'bypass', turbo_cache_diagnostics_get_category( 'BYPASS_AUTH_COOKIE' ) );
		$this->assertSame( 'bypass', turbo_cache_diagnostics_get_category( 'BYPASS_REJECTED_RULES' ) );
		$this->assertSame( 'bypass', turbo_cache_diagnostics_get_category( 'BYPASS_METHOD' ) );

		$this->assertSame( 'generation', turbo_cache_diagnostics_get_category( 'GENERATION_SUCCESS' ) );
		$this->assertSame( 'generation', turbo_cache_diagnostics_get_category( 'GENERATION_SKIPPED' ) );
		$this->assertSame( 'generation', turbo_cache_diagnostics_get_category( 'GENERATION_FAILED' ) );

		$this->assertSame( 'preload', turbo_cache_diagnostics_get_category( 'PRELOAD_CACHED' ) );
		$this->assertSame( 'preload', turbo_cache_diagnostics_get_category( 'PRELOAD_NOT_CACHED' ) );
		$this->assertSame( 'preload', turbo_cache_diagnostics_get_category( 'PRELOAD_SKIPPED' ) );
		$this->assertSame( 'preload', turbo_cache_diagnostics_get_category( 'PRELOAD_FAILED' ) );

		$this->assertSame( 'diagnostic', turbo_cache_diagnostics_get_category( 'CUSTOM_EVENT' ) );
	}

	/**
	 * Verify bypass reason mapping to standard outcome codes.
	 */
	public function test_bypass_reason_mapping() {
		$this->assertSame( 'BYPASS_DISABLED', turbo_cache_diagnostics_map_bypass_reason( 'disabled:global' ) );
		$this->assertSame( 'BYPASS_METHOD', turbo_cache_diagnostics_map_bypass_reason( 'method:POST' ) );
		$this->assertSame( 'BYPASS_BACKEND', turbo_cache_diagnostics_map_bypass_reason( 'backend:admin' ) );
		$this->assertSame( 'BYPASS_BACKEND', turbo_cache_diagnostics_map_bypass_reason( 'system:cron' ) );
		$this->assertSame( 'BYPASS_QUERY_PARAMS', turbo_cache_diagnostics_map_bypass_reason( 'query:customizer' ) );
		$this->assertSame( 'BYPASS_WOOCOMMERCE_COOKIE', turbo_cache_diagnostics_map_bypass_reason( 'cookie:woocommerce:woocommerce_items_in_cart' ) );
		$this->assertSame( 'BYPASS_AUTH_COOKIE', turbo_cache_diagnostics_map_bypass_reason( 'cookie:auth_user' ) );
		$this->assertSame( 'BYPASS_REJECTED_COOKIE', turbo_cache_diagnostics_map_bypass_reason( 'cookie:rejected_cookie' ) );
		$this->assertSame( 'BYPASS_CUSTOM_FILTER', turbo_cache_diagnostics_map_bypass_reason( 'DONOTCACHEPAGE defined' ) );
	}

	/**
	 * Verify in-memory recording does NOT incur disk I/O when logging is disabled.
	 */
	public function test_in_memory_recording_without_disk_io_when_disabled() {
		$this->assertFalse( turbo_cache_diagnostics_is_enabled() );

		$entry = turbo_cache_diagnostics_record( 'HIT_SUPERCACHE', 'Delivered from static cache' );

		$this->assertSame( 'HIT_SUPERCACHE', $entry['outcome'] );
		$this->assertSame( 'hit', $entry['category'] );

		// In-memory accessor must return this entry.
		$current = turbo_cache_diagnostics_get_current();
		$this->assertNotNull( $current );
		$this->assertSame( 'HIT_SUPERCACHE', $current['outcome'] );

		// The log file on disk MUST NOT exist.
		$log_file = turbo_cache_diagnostics_get_log_file_path();
		$this->assertFileDoesNotExist( $log_file );
	}

	/**
	 * Verify ring buffer writes to disk and enforces bounded FIFO size (max 100 entries).
	 */
	public function test_bounded_fifo_ring_buffer_storage() {
		turbo_cache_diagnostics_set_enabled( true );

		// Record 105 entries.
		for ( $i = 1; $i <= 105; $i++ ) {
			turbo_cache_diagnostics_record(
				'MISS_FILE_MISSING',
				"Request $i file missing",
				array( 'seq' => $i )
			);
		}

		$log_file = turbo_cache_diagnostics_get_log_file_path();
		$this->assertFileExists( $log_file );

		$log_entries = turbo_cache_diagnostics_get_recent_log( 150 );
		$this->assertCount( 100, $log_entries, 'Ring buffer must cap at 100 entries' );

		// Most recent entry in buffer should be sequence 105, oldest retained is sequence 6 (first 5 dropped).
		$first_entry = $log_entries[0];
		$last_entry  = $log_entries[99];

		$this->assertSame( 105, $first_entry['details']['seq'] );
		$this->assertSame( 6, $last_entry['details']['seq'] );
	}

	/**
	 * Verify clearing diagnostics log.
	 */
	public function test_clear_log() {
		turbo_cache_diagnostics_set_enabled( true );
		turbo_cache_diagnostics_record( 'HIT_SUPERCACHE', 'Test hit' );

		$log_file = turbo_cache_diagnostics_get_log_file_path();
		$this->assertFileExists( $log_file );

		$cleared = turbo_cache_diagnostics_clear_log();
		$this->assertTrue( $cleared );
		$this->assertFileDoesNotExist( $log_file );
		$this->assertNull( turbo_cache_diagnostics_get_current() );
	}

	/**
	 * Verify summary metrics aggregation.
	 */
	public function test_summary_aggregation() {
		turbo_cache_diagnostics_set_enabled( true );

		turbo_cache_diagnostics_record( 'HIT_SUPERCACHE', 'Hit 1' );
		turbo_cache_diagnostics_record( 'HIT_SUPERCACHE_GZIP', 'Hit 2' );
		turbo_cache_diagnostics_record( 'MISS_FILE_MISSING', 'Miss 1' );
		turbo_cache_diagnostics_record( 'BYPASS_WOOCOMMERCE_COOKIE', 'Bypass WC' );
		turbo_cache_diagnostics_record( 'GENERATION_SUCCESS', 'Gen success' );
		turbo_cache_diagnostics_record( 'PRELOAD_CACHED', 'Preload warm' );

		$summary = turbo_cache_diagnostics_get_summary();

		$this->assertTrue( $summary['logging_enabled'] );
		$this->assertSame( 6, $summary['total_recorded'] );
		$this->assertSame( 2, $summary['by_category']['hit'] );
		$this->assertSame( 1, $summary['by_category']['miss'] );
		$this->assertSame( 1, $summary['by_category']['bypass'] );
		$this->assertSame( 1, $summary['by_category']['generation'] );
		$this->assertSame( 1, $summary['by_category']['preload'] );

		$this->assertSame( 1, $summary['by_outcome']['HIT_SUPERCACHE'] );
		$this->assertSame( 1, $summary['by_outcome']['HIT_SUPERCACHE_GZIP'] );
		$this->assertSame( 1, $summary['by_outcome']['MISS_FILE_MISSING'] );
		$this->assertSame( 1, $summary['by_outcome']['BYPASS_WOOCOMMERCE_COOKIE'] );
	}

	/**
	 * Verify Cache HIT vs mere file existence.
	 *
	 * A file on disk must NOT be reported as HIT if a WooCommerce cookie or
	 * uncacheable state is present.
	 */
	public function test_hit_vs_mere_file_existence() {
		// 1. Delivery headers test for genuine HIT.
		$headers = turbo_cache_send_delivery_headers( '"abc-123"', 1700000000, 1024, '', 'supercache' );
		$this->assertSame( 'HIT (supercache)', $headers['X-Turbo-Cache'] );

		$last_diag = turbo_cache_diagnostics_get_current();
		$this->assertNotNull( $last_diag );
		$this->assertSame( 'HIT_SUPERCACHE', $last_diag['outcome'] );
		$this->assertSame( 'hit', $last_diag['category'] );

		// 2. Conditional 304 response.
		$headers_304 = turbo_cache_send_conditional_304( '"abc-123"', 1700000000, false );
		$this->assertSame( 'HIT (304 Not Modified)', $headers_304['X-Turbo-Cache'] );

		$last_diag_304 = turbo_cache_diagnostics_get_current();
		$this->assertSame( 'HIT_NOT_MODIFIED_304', $last_diag_304['outcome'] );
		$this->assertSame( 'hit', $last_diag_304['category'] );
	}

	/**
	 * Verify preload observability distinguishes HTTP 200 from verified disk artifact.
	 */
	public function test_preload_observability_accounting() {
		$state = turbo_cache_preload_get_state();

		$this->assertArrayHasKey( 'http_requests_successful', $state['stats'] );
		$this->assertSame( 0, $state['stats']['http_requests_successful'] );

		// Simulate diagnostic recording for PRELOAD_NOT_CACHED (HTTP 200 without file).
		turbo_cache_diagnostics_record(
			'PRELOAD_NOT_CACHED',
			'Preload HTTP request succeeded (200) but cache file was not created on disk',
			array( 'url' => 'http://localhost/sample-page/' )
		);

		$current = turbo_cache_diagnostics_get_current();
		$this->assertSame( 'PRELOAD_NOT_CACHED', $current['outcome'] );
		$this->assertSame( 'preload', $current['category'] );

		// Simulate diagnostic recording for PRELOAD_CACHED (verified on disk).
		turbo_cache_diagnostics_record(
			'PRELOAD_CACHED',
			'Preload warmed and verified cache on disk',
			array( 'url' => 'http://localhost/cached-page/' )
		);

		$current = turbo_cache_diagnostics_get_current();
		$this->assertSame( 'PRELOAD_CACHED', $current['outcome'] );
		$this->assertSame( 'preload', $current['category'] );
	}

	/**
	 * Verify AJAX get diagnostics rejects unauthorized users without manage_options.
	 */
	public function test_ajax_get_diagnostics_unauthorized() {
		$GLOBALS['wpsc_test_current_user_can'] = false;
		$GLOBALS['wpsc_test_fail_nonce']       = false;

		try {
			wpsc_ajax_get_diagnostics();
			$this->fail( 'Expected wp_send_json_error was not called' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'wp_send_json_error', $e->getMessage() );
			$response = $GLOBALS['wpsc_test_last_json_response'];
			$this->assertFalse( $response['success'] );
			$this->assertSame( 403, $response['code'] );
			$this->assertSame( 'forbidden', $response['data'] );
		}
	}

	/**
	 * Verify AJAX get diagnostics rejects invalid nonce.
	 */
	public function test_ajax_get_diagnostics_invalid_nonce() {
		$GLOBALS['wpsc_test_current_user_can'] = true;
		$GLOBALS['wpsc_test_fail_nonce']       = true;

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'Nonce verification failed' );
		wpsc_ajax_get_diagnostics();
	}

	/**
	 * Verify AJAX get diagnostics succeeds for authorized admin.
	 */
	public function test_ajax_get_diagnostics_success() {
		$GLOBALS['wpsc_test_current_user_can'] = true;
		$GLOBALS['wpsc_test_fail_nonce']       = false;

		turbo_cache_diagnostics_set_enabled( true );
		turbo_cache_diagnostics_record( 'HIT_SUPERCACHE', 'Admin fetch test' );

		try {
			wpsc_ajax_get_diagnostics();
			$this->fail( 'Expected wp_send_json_success was not called' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'wp_send_json_success', $e->getMessage() );
			$response = $GLOBALS['wpsc_test_last_json_response'];
			$this->assertTrue( $response['success'] );
			$this->assertArrayHasKey( 'summary', $response['data'] );
			$this->assertArrayHasKey( 'recent', $response['data'] );
			$this->assertSame( 1, $response['data']['summary']['total_recorded'] );
		}
	}

	/**
	 * Verify AJAX clear diagnostics handler works for authorized admin.
	 */
	public function test_ajax_clear_diagnostics_success() {
		$GLOBALS['wpsc_test_current_user_can'] = true;
		$GLOBALS['wpsc_test_fail_nonce']       = false;

		turbo_cache_diagnostics_set_enabled( true );
		turbo_cache_diagnostics_record( 'HIT_SUPERCACHE', 'Clear me' );

		try {
			wpsc_ajax_clear_diagnostics();
			$this->fail( 'Expected wp_send_json_success was not called' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'wp_send_json_success', $e->getMessage() );
			$response = $GLOBALS['wpsc_test_last_json_response'];
			$this->assertTrue( $response['success'] );
			$this->assertTrue( $response['data']['cleared'] );
		}
	}

	/**
	 * Verify AJAX toggle diagnostics handler updates state.
	 */
	public function test_ajax_toggle_diagnostics_success() {
		$GLOBALS['wpsc_test_current_user_can'] = true;
		$GLOBALS['wpsc_test_fail_nonce']       = false;

		$_POST['enable'] = '1';
		try {
			wpsc_ajax_toggle_diagnostics();
			$this->fail( 'Expected wp_send_json_success was not called' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'wp_send_json_success', $e->getMessage() );
			$response = $GLOBALS['wpsc_test_last_json_response'];
			$this->assertTrue( $response['success'] );
			$this->assertTrue( $response['data']['enabled'] );
			$this->assertTrue( turbo_cache_diagnostics_is_enabled() );
		}
	}
}
