<?php
/**
 * Smoke tests for Turbo Cache Diagnostics Admin UI integration (Phase 10).
 *
 * @package Turbo_Cache
 */

// phpcs:disable WordPress.PHP.NoSilencedErrors.Discouraged
// phpcs:disable WordPress.Security.ValidatedSanitizedInput

require_once dirname( __DIR__, 3 ) . '/inc/cache-diagnostics.php';
require_once dirname( __DIR__, 3 ) . '/inc/admin-ui.php';

use PHPUnit\Framework\TestCase;

/**
 * Class TurboCacheDiagnosticsAdminTest
 *
 * Tests the Diagnostics admin tab, conditional asset enqueuing,
 * authorization, nonce verification, AJAX endpoints, safe data rendering,
 * and escaping.
 */
class TurboCacheDiagnosticsAdminTest extends TestCase {

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

		$this->temp_dir = sys_get_temp_dir() . '/tc_diag_admin_' . uniqid() . '/';
		if ( ! is_dir( $this->temp_dir ) ) {
			mkdir( $this->temp_dir, 0777, true );
		}

		global $cache_path;
		$this->saved_cache_path = isset( $cache_path ) ? $cache_path : null;
		$cache_path             = $this->temp_dir;

		$this->saved_user_can                       = isset( $GLOBALS['wpsc_test_current_user_can'] )
			? $GLOBALS['wpsc_test_current_user_can']
			: null;
		$GLOBALS['wpsc_test_current_user_can']      = true;
		$GLOBALS['wpsc_test_fail_nonce']            = false;
		$GLOBALS['wpsc_test_last_json_response']    = null;
		$GLOBALS['wpsc_test_enqueued_scripts']      = array();
		$GLOBALS['wpsc_test_localized_scripts']     = array();
		$GLOBALS['turbo_cache_diagnostics_enabled'] = false;

		$_SERVER['REQUEST_URI']    = '/wp-admin/options-general.php?page=wpsupercache&tab=diagnostics';
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
		unset( $GLOBALS['turbo_cache_diagnostics_enabled'] );

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
	 * Test that wpsc_admin_tabs renders the Diagnostics tab link and active class.
	 */
	public function test_admin_tabs_renders_diagnostics_tab() {
		ob_start();
		wpsc_admin_tabs( 'diagnostics' );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'tab=diagnostics', $output );
		$this->assertStringContainsString( 'Diagnostics', $output );
		$this->assertStringContainsString( 'wpsc-nav-tab-selected', $output );
	}

	/**
	 * Test script enqueuing when on the diagnostics tab with manage_options capability.
	 */
	public function test_admin_enqueue_scripts_on_diagnostics_tab_with_permission() {
		$_GET['tab'] = 'diagnostics';

		$GLOBALS['wpsc_test_current_user_can'] = true;

		wp_super_cache_admin_enqueue_scripts( 'settings_page_wpsupercache' );

		$this->assertArrayHasKey( 'wpsc-diagnostics-admin', $GLOBALS['wpsc_test_enqueued_scripts'] );
		$this->assertStringContainsString( 'js/diagnostics.js', $GLOBALS['wpsc_test_enqueued_scripts']['wpsc-diagnostics-admin']['src'] );

		$this->assertArrayHasKey( 'wpsc-diagnostics-admin', $GLOBALS['wpsc_test_localized_scripts'] );
		$this->assertArrayHasKey( 'turboCacheDiag', $GLOBALS['wpsc_test_localized_scripts']['wpsc-diagnostics-admin'] );

		$diag_config = $GLOBALS['wpsc_test_localized_scripts']['wpsc-diagnostics-admin']['turboCacheDiag'];
		$this->assertNotEmpty( $diag_config['ajaxUrl'] );
		$this->assertNotEmpty( $diag_config['nonce'] );
		$this->assertArrayHasKey( 'strings', $diag_config );
		$this->assertArrayHasKey( 'confirmClear', $diag_config['strings'] );
		$this->assertArrayHasKey( 'refreshing', $diag_config['strings'] );
	}

	/**
	 * Test script is not enqueued on other tabs or other admin pages.
	 */
	public function test_admin_enqueue_scripts_omitted_on_other_tabs_or_screens() {
		$_GET['tab'] = 'easy';
		wp_super_cache_admin_enqueue_scripts( 'settings_page_wpsupercache' );
		$this->assertArrayNotHasKey( 'wpsc-diagnostics-admin', $GLOBALS['wpsc_test_enqueued_scripts'] );

		$_GET['tab'] = 'diagnostics';
		wp_super_cache_admin_enqueue_scripts( 'tools_page_other' );
		$this->assertArrayNotHasKey( 'wpsc-diagnostics-admin', $GLOBALS['wpsc_test_enqueued_scripts'] );
	}

	/**
	 * Test script is not enqueued when user lacks manage_options.
	 */
	public function test_admin_enqueue_scripts_omitted_without_capability() {
		$_GET['tab']                           = 'diagnostics';
		$GLOBALS['wpsc_test_current_user_can'] = false;

		wp_super_cache_admin_enqueue_scripts( 'settings_page_wpsupercache' );
		$this->assertArrayNotHasKey( 'wpsc-diagnostics-admin', $GLOBALS['wpsc_test_enqueued_scripts'] );
	}

	/**
	 * Test diagnostics partial renders properly for authorized administrator.
	 */
	public function test_diagnostics_partial_renders_for_authorized_admin() {
		$GLOBALS['wpsc_test_current_user_can'] = true;

		ob_start();
		wpsc_render_partial( 'diagnostics' );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'id="turbo-cache-diagnostics-app"', $output );
		$this->assertStringContainsString( 'Cache Diagnostics &amp; Observability', $output );
		$this->assertStringContainsString( 'Persistent Logging Status:', $output );
		$this->assertStringContainsString( 'Disabled', $output );
		$this->assertStringContainsString( 'stat-total-recorded', $output );
		$this->assertStringContainsString( 'turbo-diag-table', $output );
	}

	/**
	 * Test diagnostics partial blocks unauthorized users with wp_die.
	 */
	public function test_diagnostics_partial_blocks_unauthorized_user() {
		$GLOBALS['wpsc_test_current_user_can'] = false;

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'wp_die:' );

		wpsc_render_partial( 'diagnostics' );
	}

	/**
	 * Test wpsc_ajax_toggle_diagnostics succeeds for authorized admin.
	 */
	public function test_ajax_toggle_diagnostics_authorized() {
		$GLOBALS['wpsc_test_current_user_can'] = true;
		$_POST['enable']                       = '1';

		try {
			wpsc_ajax_toggle_diagnostics();
			$this->fail( 'Expected wp_send_json_success exception' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'wp_send_json_success', $e->getMessage() );
		}

		$response = $GLOBALS['wpsc_test_last_json_response'];
		$this->assertTrue( $response['success'] );
		$this->assertTrue( $response['data']['enabled'] );
		$this->assertTrue( turbo_cache_diagnostics_is_enabled() );
	}

	/**
	 * Test wpsc_ajax_toggle_diagnostics fails without capability.
	 */
	public function test_ajax_toggle_diagnostics_unauthorized() {
		$GLOBALS['wpsc_test_current_user_can'] = false;
		$_POST['enable']                       = '1';

		try {
			wpsc_ajax_toggle_diagnostics();
			$this->fail( 'Expected wp_send_json_error exception' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'wp_send_json_error', $e->getMessage() );
		}

		$response = $GLOBALS['wpsc_test_last_json_response'];
		$this->assertFalse( $response['success'] );
		$this->assertSame( 403, $response['code'] );
	}

	/**
	 * Test wpsc_ajax_toggle_diagnostics fails with invalid nonce.
	 */
	public function test_ajax_toggle_diagnostics_invalid_nonce() {
		$GLOBALS['wpsc_test_current_user_can'] = true;
		$GLOBALS['wpsc_test_fail_nonce']       = true;

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'Nonce verification failed' );

		wpsc_ajax_toggle_diagnostics();
	}

	/**
	 * Test wpsc_ajax_clear_diagnostics clears log and returns success.
	 */
	public function test_ajax_clear_diagnostics_success() {
		$GLOBALS['wpsc_test_current_user_can'] = true;
		turbo_cache_diagnostics_set_enabled( true );
		turbo_cache_diagnostics_record( 'HIT_FRESH', 'Fresh file served', array( 'bytes' => 1024 ) );

		$this->assertNotEmpty( turbo_cache_diagnostics_get_recent_log( 10 ) );

		try {
			wpsc_ajax_clear_diagnostics();
			$this->fail( 'Expected wp_send_json_success exception' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'wp_send_json_success', $e->getMessage() );
		}

		$response = $GLOBALS['wpsc_test_last_json_response'];
		$this->assertTrue( $response['success'] );
		$this->assertTrue( $response['data']['cleared'] );
		$this->assertEmpty( turbo_cache_diagnostics_get_recent_log( 10 ) );
	}

	/**
	 * Test wpsc_ajax_get_diagnostics returns actual data.
	 */
	public function test_ajax_get_diagnostics_success() {
		$GLOBALS['wpsc_test_current_user_can'] = true;
		turbo_cache_diagnostics_set_enabled( true );
		turbo_cache_diagnostics_record( 'HIT_FRESH', 'Cached page hit', array( 'bytes' => 2048 ) );
		turbo_cache_diagnostics_record( 'BYPASS_COOKIE', 'Bypass due to cookie', array( 'cookie' => 'test' ) );

		try {
			wpsc_ajax_get_diagnostics();
			$this->fail( 'Expected wp_send_json_success exception' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'wp_send_json_success', $e->getMessage() );
		}

		$response = $GLOBALS['wpsc_test_last_json_response'];
		$this->assertTrue( $response['success'] );
		$this->assertArrayHasKey( 'summary', $response['data'] );
		$this->assertArrayHasKey( 'recent', $response['data'] );

		$summary = $response['data']['summary'];
		$this->assertSame( 2, $summary['total_recorded'] );
		$this->assertSame( 1, $summary['by_category']['hit'] );
		$this->assertSame( 1, $summary['by_category']['bypass'] );

		$recent = $response['data']['recent'];
		$this->assertCount( 2, $recent );
		// Most recent first:
		$this->assertSame( 'BYPASS_COOKIE', $recent[0]['outcome'] );
		$this->assertSame( 'HIT_FRESH', $recent[1]['outcome'] );
	}

	/**
	 * Test safe rendering of malformed and XSS-bearing events in the partial.
	 */
	public function test_partial_safe_rendering_and_escaping() {
		$GLOBALS['wpsc_test_current_user_can'] = true;
		turbo_cache_diagnostics_set_enabled( true );

		// Hostile XSS inputs in diagnostic events
		$_SERVER['REQUEST_URI'] = '/<script>alert("xss")</script>';
		turbo_cache_diagnostics_record(
			'BYPASS_HOSTILE',
			'Dangerous & Hostile Reason',
			array( 'key' => '<img src=x onerror=alert(1)>' )
		);

		// Malformed event manually appended to log with unescaped markup in reason
		$malformed = array(
			'timestamp' => 'not-a-number',
			'method'    => null,
			'uri'       => '/direct-injection/<svg onload=alert(2)>',
			'reason'    => '<em>Direct Injection</em>',
			'details'   => array( 'tag' => '<b>safe</b>' ),
		);
		turbo_cache_diagnostics_append_log( $malformed );

		ob_start();
		wpsc_render_partial( 'diagnostics' );
		$output = ob_get_clean();

		// Assert raw HTML/XSS tags are NOT present in output
		$this->assertStringNotContainsString( '<script>alert("xss")</script>', $output );
		$this->assertStringNotContainsString( '<img src=x onerror=alert(1)>', $output );
		$this->assertStringNotContainsString( '<svg onload=alert(2)>', $output );
		$this->assertStringNotContainsString( '<em>Direct Injection</em>', $output );

		// Assert escaped entities ARE present
		$this->assertStringContainsString( '&lt;script&gt;alert(&quot;xss&quot;)&lt;/script&gt;', $output );
		$this->assertStringContainsString( '&lt;svg onload=alert(2)&gt;', $output );
		$this->assertStringContainsString( '&lt;em&gt;Direct Injection&lt;/em&gt;', $output );
		$this->assertStringContainsString( 'Dangerous &amp; Hostile Reason', $output );

		// Assert malformed entry rendered with fallbacks without fatal
		$this->assertStringContainsString( 'UNKNOWN', $output );
	}

	/**
	 * Test PHP 7.4 compatibility of wpsc_render_partial without relying on str_ends_with.
	 */
	public function test_wpsc_render_partial_php74_compatibility() {
		$GLOBALS['wpsc_test_current_user_can'] = true;

		// Without .php extension
		ob_start();
		wpsc_render_partial( 'diagnostics' );
		$output1 = ob_get_clean();
		$this->assertStringContainsString( 'id="turbo-cache-diagnostics-app"', $output1 );

		// With .php extension
		ob_start();
		wpsc_render_partial( 'diagnostics.php' );
		$output2 = ob_get_clean();
		$this->assertStringContainsString( 'id="turbo-cache-diagnostics-app"', $output2 );
	}
}
