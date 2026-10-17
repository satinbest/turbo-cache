<?php
/**
 * Smoke tests for Turbo Cache Safe Preload Admin UI integration (Phase 11).
 *
 * @package Turbo_Cache
 */

// phpcs:disable WordPress.PHP.NoSilencedErrors.Discouraged
// phpcs:disable WordPress.Security.ValidatedSanitizedInput
// phpcs:disable VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
// phpcs:disable Generic.Formatting.MultipleStatementAlignment.NotSameWarning

require_once dirname( __DIR__, 3 ) . '/inc/preload-engine.php';
require_once dirname( __DIR__, 3 ) . '/inc/preload.php';
require_once dirname( __DIR__, 3 ) . '/inc/admin-ui.php';

use PHPUnit\Framework\TestCase;

/**
 * Class TurboCachePreloadAdminTest
 *
 * Tests the Preload admin controls, conditional asset enqueuing,
 * authorization, nonce verification, AJAX endpoints, safe data rendering,
 * and state resets.
 */
class TurboCachePreloadAdminTest extends TestCase {

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
	 * Saved user capabilities flag.
	 *
	 * @var mixed
	 */
	private $saved_user_can;

	/**
	 * Set up test environment before each test.
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->temp_dir = sys_get_temp_dir() . '/tc_preload_admin_' . uniqid() . '/';
		if ( ! is_dir( $this->temp_dir ) ) {
			mkdir( $this->temp_dir, 0777, true );
		}

		global $cache_path;
		$this->saved_cache_path = isset( $cache_path ) ? $cache_path : null;
		$cache_path             = $this->temp_dir;

		$this->saved_user_can                    = isset( $GLOBALS['wpsc_test_current_user_can'] )
			? $GLOBALS['wpsc_test_current_user_can']
			: null;
		$GLOBALS['wpsc_test_current_user_can']   = true;
		$GLOBALS['wpsc_test_fail_nonce']         = false;
		$GLOBALS['wpsc_test_last_json_response'] = null;
		$GLOBALS['wpsc_test_enqueued_scripts']   = array();
		$GLOBALS['wpsc_test_localized_scripts']  = array();

		$_SERVER['REQUEST_URI']    = '/wp-admin/options-general.php?page=wpsupercache&tab=preload';
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_GET                      = array();
		$_POST                     = array();
	}

	/**
	 * Clean up test environment after each test.
	 */
	protected function tearDown(): void {
		global $cache_path;
		$cache_path = $this->saved_cache_path;

		if ( null !== $this->saved_user_can ) {
			$GLOBALS['wpsc_test_current_user_can'] = $this->saved_user_can;
		} else {
			unset( $GLOBALS['wpsc_test_current_user_can'] );
		}

		unset( $GLOBALS['wpsc_test_fail_nonce'] );
		unset( $GLOBALS['wpsc_test_last_json_response'] );
		unset( $GLOBALS['wpsc_test_enqueued_scripts'] );
		unset( $GLOBALS['wpsc_test_localized_scripts'] );

		$_GET  = array();
		$_POST = array();

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
	 * Test script enqueuing on preload tab for authorized administrator.
	 */
	public function test_admin_enqueue_scripts_on_preload_tab_with_permission() {
		$_GET['tab'] = 'preload';

		$GLOBALS['wpsc_test_current_user_can'] = true;

		wp_super_cache_admin_enqueue_scripts( 'settings_page_wpsupercache' );

		$this->assertArrayHasKey( 'wpsc-preload-admin', $GLOBALS['wpsc_test_enqueued_scripts'] );
		$this->assertStringContainsString( 'js/preload.js', $GLOBALS['wpsc_test_enqueued_scripts']['wpsc-preload-admin']['src'] );

		$this->assertArrayHasKey( 'wpsc-preload-admin', $GLOBALS['wpsc_test_localized_scripts'] );
		$this->assertArrayHasKey( 'turboCachePreload', $GLOBALS['wpsc_test_localized_scripts']['wpsc-preload-admin'] );

		$preload_config = $GLOBALS['wpsc_test_localized_scripts']['wpsc-preload-admin']['turboCachePreload'];
		$this->assertNotEmpty( $preload_config['ajaxUrl'] );
		$this->assertNotEmpty( $preload_config['nonce'] );
		$this->assertArrayHasKey( 'strings', $preload_config );
		$this->assertArrayHasKey( 'processing', $preload_config['strings'] );
		$this->assertArrayHasKey( 'refreshing', $preload_config['strings'] );
		$this->assertArrayHasKey( 'resetting', $preload_config['strings'] );
		$this->assertArrayHasKey( 'confirmReset', $preload_config['strings'] );
	}

	/**
	 * Test script is omitted on other tabs or other admin pages.
	 */
	public function test_admin_enqueue_scripts_omitted_on_other_tabs_or_screens() {
		$_GET['tab'] = 'easy';
		wp_super_cache_admin_enqueue_scripts( 'settings_page_wpsupercache' );
		$this->assertArrayNotHasKey( 'wpsc-preload-admin', $GLOBALS['wpsc_test_enqueued_scripts'] );

		$_GET['tab'] = 'preload';
		wp_super_cache_admin_enqueue_scripts( 'tools_page_other' );
		$this->assertArrayNotHasKey( 'wpsc-preload-admin', $GLOBALS['wpsc_test_enqueued_scripts'] );
	}

	/**
	 * Test script is omitted if user lacks manage_options capability.
	 */
	public function test_admin_enqueue_scripts_omitted_without_manage_options() {
		$_GET['tab'] = 'preload';
		$GLOBALS['wpsc_test_current_user_can'] = false;

		wp_super_cache_admin_enqueue_scripts( 'settings_page_wpsupercache' );
		$this->assertArrayNotHasKey( 'wpsc-preload-admin', $GLOBALS['wpsc_test_enqueued_scripts'] );
	}

	/**
	 * Test wpsc_preload_format_admin_state provides expected structure and omits raw queue array.
	 */
	public function test_preload_format_admin_state_structure() {
		$raw_state = array(
			'status'               => 'running',
			'started_at'           => 1700000000,
			'updated_at'           => 1700000050,
			'finished_at'          => 0,
			'queue'                => array( 'https://example.com/1', 'https://example.com/2' ),
			'queue_cursor'         => 1,
			'consecutive_failures' => 0,
			'stats'                => array(
				'total_discovered' => 2,
				'processed'        => 1,
				'cached'           => 1,
				'skipped'          => 0,
				'failed'           => 0,
			),
			'last_error'           => null,
		);

		$formatted = wpsc_preload_format_admin_state( $raw_state );

		$this->assertSame( 'running', $formatted['status'] );
		$this->assertSame( 1700000000, $formatted['started_at'] );
		$this->assertSame( 1700000050, $formatted['updated_at'] );
		$this->assertSame( 0, $formatted['finished_at'] );
		$this->assertSame( 1, $formatted['queue_cursor'] );
		$this->assertSame( 2, $formatted['total_discovered'] );
		$this->assertSame( 1, $formatted['processed'] );
		$this->assertSame( 1, $formatted['cached'] );
		$this->assertSame( 0, $formatted['skipped'] );
		$this->assertSame( 0, $formatted['failed'] );
		$this->assertNull( $formatted['last_error'] );
		// Ensure raw queue is NOT leaked.
		$this->assertArrayNotHasKey( 'queue', $formatted );
	}

	/**
	 * Test AJAX get state rejects unauthorized users.
	 */
	public function test_ajax_get_state_unauthorized() {
		$GLOBALS['wpsc_test_current_user_can'] = false;
		$GLOBALS['wpsc_test_fail_nonce']       = false;

		try {
			wpsc_ajax_preload_get_state();
			$this->fail( 'Expected wp_send_json_error was not called' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'wp_send_json_error', $e->getMessage() );
			$response = $GLOBALS['wpsc_test_last_json_response'];
			$this->assertFalse( $response['success'] );
			$this->assertSame( 403, $response['code'] );
		}
	}

	/**
	 * Test AJAX get state rejects invalid nonces.
	 */
	public function test_ajax_get_state_invalid_nonce() {
		$GLOBALS['wpsc_test_current_user_can'] = true;
		$GLOBALS['wpsc_test_fail_nonce']       = true;

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'Nonce verification failed' );
		wpsc_ajax_preload_get_state();
	}

	/**
	 * Test AJAX get state succeeds for authorized admin.
	 */
	public function test_ajax_get_state_success() {
		$GLOBALS['wpsc_test_current_user_can'] = true;
		$GLOBALS['wpsc_test_fail_nonce']       = false;

		$custom_state = array(
			'status'       => 'running',
			'started_at'   => 1700000000,
			'queue'        => array( 'https://example.com/' ),
			'queue_cursor' => 0,
			'stats'        => array(
				'total_discovered' => 1,
				'processed'        => 0,
				'cached'           => 0,
				'skipped'          => 0,
				'failed'           => 0,
			),
		);
		turbo_cache_preload_save_state( $custom_state );

		try {
			wpsc_ajax_preload_get_state();
			$this->fail( 'Expected wp_send_json_success was not called' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'wp_send_json_success', $e->getMessage() );
			$response = $GLOBALS['wpsc_test_last_json_response'];
			$this->assertTrue( $response['success'] );
			$this->assertSame( 'running', $response['data']['status'] );
			$this->assertSame( 1, $response['data']['total_discovered'] );
		}
	}

	/**
	 * Test AJAX process batch rejects unauthorized users.
	 */
	public function test_ajax_process_batch_unauthorized() {
		$GLOBALS['wpsc_test_current_user_can'] = false;
		$GLOBALS['wpsc_test_fail_nonce']       = false;

		try {
			wpsc_ajax_preload_process_batch();
			$this->fail( 'Expected wp_send_json_error was not called' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'wp_send_json_error', $e->getMessage() );
			$response = $GLOBALS['wpsc_test_last_json_response'];
			$this->assertFalse( $response['success'] );
			$this->assertSame( 403, $response['code'] );
		}
	}

	/**
	 * Test AJAX process batch rejects invalid nonce.
	 */
	public function test_ajax_process_batch_invalid_nonce() {
		$GLOBALS['wpsc_test_current_user_can'] = true;
		$GLOBALS['wpsc_test_fail_nonce']       = true;

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'Nonce verification failed' );
		wpsc_ajax_preload_process_batch();
	}

	/**
	 * Test AJAX process batch succeeds and returns batch data plus updated state.
	 */
	public function test_ajax_process_batch_success() {
		$GLOBALS['wpsc_test_current_user_can'] = true;
		$GLOBALS['wpsc_test_fail_nonce']       = false;

		// Mock discovery so we have a predictable URL queue.
		add_filter(
			'turbo_cache_preload_sitemap_urls',
			function () {
				return array( 'https://example.com/page1' );
			}
		);

		try {
			wpsc_ajax_preload_process_batch();
			$this->fail( 'Expected wp_send_json_success was not called' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'wp_send_json_success', $e->getMessage() );
			$response = $GLOBALS['wpsc_test_last_json_response'];
			$this->assertTrue( $response['success'] );
			$this->assertArrayHasKey( 'batch', $response['data'] );
			$this->assertArrayHasKey( 'state', $response['data'] );
			$this->assertArrayHasKey( 'status', $response['data']['state'] );
			$this->assertArrayHasKey( 'processed', $response['data']['state'] );
		} finally {
			remove_all_filters( 'turbo_cache_preload_sitemap_urls' );
		}
	}

	/**
	 * Test AJAX process batch when lock is already acquired returns locked status.
	 */
	public function test_ajax_process_batch_when_locked() {
		$GLOBALS['wpsc_test_current_user_can'] = true;
		$GLOBALS['wpsc_test_fail_nonce']       = false;

		// Acquire lock beforehand.
		$acquired = turbo_cache_preload_acquire_lock();
		$this->assertTrue( $acquired );

		try {
			wpsc_ajax_preload_process_batch();
			$this->fail( 'Expected wp_send_json_success was not called' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'wp_send_json_success', $e->getMessage() );
			$response = $GLOBALS['wpsc_test_last_json_response'];
			$this->assertTrue( $response['success'] );
			$this->assertArrayHasKey( 'batch', $response['data'] );
			$this->assertSame( 'locked', $response['data']['batch']['status'] );
			$this->assertStringContainsString( 'Another preload batch is in progress', $response['data']['batch']['message'] );
		} finally {
			turbo_cache_preload_release_lock();
		}
	}

	/**
	 * Test AJAX process batch when circuit breaker is tripped returns paused state.
	 */
	public function test_ajax_process_batch_circuit_breaker() {
		$GLOBALS['wpsc_test_current_user_can'] = true;
		$GLOBALS['wpsc_test_fail_nonce']       = false;

		$tripped_state = array(
			'status'               => 'running',
			'queue'                => array( 'https://example.com/item' ),
			'queue_cursor'         => 0,
			'consecutive_failures' => 5,
			'stats'                => array(
				'total_discovered' => 1,
				'processed'        => 5,
				'cached'           => 0,
				'skipped'          => 0,
				'failed'           => 5,
			),
		);
		turbo_cache_preload_save_state( $tripped_state );

		try {
			wpsc_ajax_preload_process_batch();
			$this->fail( 'Expected wp_send_json_success was not called' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'wp_send_json_success', $e->getMessage() );
			$response = $GLOBALS['wpsc_test_last_json_response'];
			$this->assertTrue( $response['success'] );
			$this->assertSame( 'paused', $response['data']['batch']['status'] );
			$this->assertStringContainsString( 'Circuit breaker triggered', $response['data']['batch']['message'] );
			$this->assertSame( 'paused', $response['data']['state']['status'] );
		}
	}

	/**
	 * Test AJAX reset state rejects unauthorized users.
	 */
	public function test_ajax_reset_state_unauthorized() {
		$GLOBALS['wpsc_test_current_user_can'] = false;
		$GLOBALS['wpsc_test_fail_nonce']       = false;

		try {
			wpsc_ajax_preload_reset_state();
			$this->fail( 'Expected wp_send_json_error was not called' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'wp_send_json_error', $e->getMessage() );
			$response = $GLOBALS['wpsc_test_last_json_response'];
			$this->assertFalse( $response['success'] );
			$this->assertSame( 403, $response['code'] );
		}
	}

	/**
	 * Test AJAX reset state clears state, removes stop flag, and returns clean state.
	 */
	public function test_ajax_reset_state_success() {
		global $cache_path;
		$GLOBALS['wpsc_test_current_user_can'] = true;
		$GLOBALS['wpsc_test_fail_nonce']       = false;

		// Create active state and a stop_preload flag.
		$state = array(
			'status'       => 'running',
			'started_at'   => 1700000000,
			'queue'        => array( 'https://example.com/' ),
			'queue_cursor' => 1,
			'stats'        => array(
				'total_discovered' => 1,
				'processed'        => 1,
				'cached'           => 1,
				'skipped'          => 0,
				'failed'           => 0,
			),
		);
		turbo_cache_preload_save_state( $state );

		$stop_file = $cache_path . 'stop_preload.txt';
		file_put_contents( $stop_file, 'stop' );
		$this->assertFileExists( $stop_file );

		try {
			wpsc_ajax_preload_reset_state();
			$this->fail( 'Expected wp_send_json_success was not called' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'wp_send_json_success', $e->getMessage() );
			$response = $GLOBALS['wpsc_test_last_json_response'];
			$this->assertTrue( $response['success'] );
			$this->assertSame( 'idle', $response['data']['state']['status'] );
			$this->assertSame( 0, $response['data']['state']['total_discovered'] );
			$this->assertSame( 0, $response['data']['state']['processed'] );

			// Verify stop flag was removed.
			$this->assertFileDoesNotExist( $stop_file );
		}
	}

	/**
	 * Test rendering of partials/preload.php displays Turbo Cache preload controls and metrics.
	 */
	public function test_partials_preload_rendering() {
		$cache_enabled         = true;
		$super_cache_enabled   = true;
		$admin_url             = 'https://example.com/wp-admin/options-general.php?page=wpsupercache';
		$wp_cache_preload_posts = 10;
		$wp_cache_preload_interval = 600;
		$wp_cache_preload_on   = 1;
		$wp_cache_preload_taxonomies = 0;

		$state = array(
			'status'       => 'idle',
			'started_at'   => 0,
			'updated_at'   => 0,
			'finished_at'  => 0,
			'queue'        => array(),
			'queue_cursor' => 0,
			'stats'        => array(
				'total_discovered' => 42,
				'processed'        => 20,
				'cached'           => 18,
				'skipped'          => 2,
				'failed'           => 0,
			),
		);
		turbo_cache_preload_save_state( $state );

		ob_start();
		require dirname( __DIR__, 3 ) . '/partials/preload.php';
		$output = ob_get_clean();

		$this->assertStringContainsString( 'id="turbo-cache-preload-app"', $output );
		$this->assertStringContainsString( 'id="turbo-preload-status-badge"', $output );
		$this->assertStringContainsString( 'id="turbo-preload-batch-btn"', $output );
		$this->assertStringContainsString( 'id="turbo-preload-refresh-btn"', $output );
		$this->assertStringContainsString( 'id="turbo-preload-reset-btn"', $output );
		$this->assertStringContainsString( 'id="turbo-preload-stat-discovered"', $output );
		$this->assertStringContainsString( '42', $output );
		$this->assertStringContainsString( 'id="turbo-preload-stat-processed"', $output );
		$this->assertStringContainsString( '20', $output );
		$this->assertStringContainsString( 'id="turbo-preload-stat-cached"', $output );
		$this->assertStringContainsString( '18', $output );
		$this->assertStringContainsString( 'id="turbo-preload-stat-skipped"', $output );
		$this->assertStringContainsString( 'id="turbo-preload-stat-failed"', $output );
		$this->assertStringContainsString( 'id="turbo-preload-feedback"', $output );

		// Legacy controls must still exist.
		$this->assertStringContainsString( 'name="cache_filler"', $output );
		$this->assertStringContainsString( 'name="do_preload"', $output );
	}
}
