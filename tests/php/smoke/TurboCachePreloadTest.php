<?php
/**
 * Smoke tests for Turbo Cache Safe Preload engine.
 *
 * @package Turbo_Cache
 */

require_once dirname( __DIR__, 3 ) . '/inc/preload-engine.php';

use PHPUnit\Framework\TestCase;

/**
 * Class TurboCachePreloadTest
 *
 * @covers ::turbo_cache_preload_normalize_url
 * @covers ::turbo_cache_preload_is_safe_url
 * @covers ::turbo_cache_preload_parse_sitemap_xml
 * @covers ::turbo_cache_preload_get_state
 * @covers ::turbo_cache_preload_save_state
 * @covers ::turbo_cache_preload_reset_state
 * @covers ::turbo_cache_preload_fetch_url
 * @covers ::turbo_cache_preload_process_batch
 */
class TurboCachePreloadTest extends TestCase {

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
	 * Set up test environment before each test.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->temp_dir = sys_get_temp_dir() . '/tc_preload_test_' . uniqid() . '/';
		mkdir( $this->temp_dir, 0777, true );

		$this->saved_cache_path = isset( $GLOBALS['cache_path'] ) ? $GLOBALS['cache_path'] : null;
		$GLOBALS['cache_path']  = $this->temp_dir;
	}

	/**
	 * Tear down test environment after each test.
	 */
	protected function tearDown(): void {
		if ( is_dir( $this->temp_dir ) ) {
			$files = glob( $this->temp_dir . '*' );
			if ( is_array( $files ) ) {
				foreach ( $files as $file ) {
					@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				}
			}
			@rmdir( $this->temp_dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		if ( null !== $this->saved_cache_path ) {
			$GLOBALS['cache_path'] = $this->saved_cache_path;
		} else {
			unset( $GLOBALS['cache_path'] );
		}

		parent::tearDown();
	}

	/**
	 * Test URL normalization strips fragments and lowercases scheme and host.
	 */
	public function test_url_normalization() {
		$raw        = 'HTTP://Example.COM:80/about-us/#section-2';
		$normalized = turbo_cache_preload_normalize_url( $raw );
		$this->assertSame( 'http://example.com/about-us/', $normalized );

		$https_raw = 'HTTPS://EXAMPLE.COM:443/products/?sort=asc#top';
		$this->assertSame( 'https://example.com/products/?sort=asc', turbo_cache_preload_normalize_url( $https_raw ) );

		$custom_port = 'http://example.com:8080/test/';
		$this->assertSame( 'http://example.com:8080/test/', turbo_cache_preload_normalize_url( $custom_port ) );

		$empty = turbo_cache_preload_normalize_url( '' );
		$this->assertSame( '', $empty );
	}

	/**
	 * Test public eligible URLs are accepted on matching site host.
	 */
	public function test_public_eligible_url_accepted() {
		$site_url = 'https://example.com';

		$url    = 'https://example.com/blog/sample-post/';
		$result = turbo_cache_preload_is_safe_url( $url, $site_url );
		$this->assertTrue( $result['eligible'] );
		$this->assertSame( 'eligible', $result['reason'] );

		$home   = 'https://example.com/';
		$result = turbo_cache_preload_is_safe_url( $home, $site_url );
		$this->assertTrue( $result['eligible'] );
	}

	/**
	 * Test external host URLs are rejected immediately.
	 */
	public function test_external_host_url_rejected() {
		$site_url = 'https://example.com';

		$external = 'https://malicious-site.com/evil/';
		$result   = turbo_cache_preload_is_safe_url( $external, $site_url );
		$this->assertFalse( $result['eligible'] );
		$this->assertSame( 'external_host', $result['reason'] );

		$subdomain = 'https://sub.example.com/page/';
		$result    = turbo_cache_preload_is_safe_url( $subdomain, $site_url );
		$this->assertFalse( $result['eligible'] );
		$this->assertSame( 'external_host', $result['reason'] );
	}

	/**
	 * Test invalid schemes and malformed URLs are rejected.
	 */
	public function test_invalid_scheme_and_malformed_url_rejected() {
		$site_url = 'https://example.com';

		$js     = 'javascript:alert(1)';
		$result = turbo_cache_preload_is_safe_url( $js, $site_url );
		$this->assertFalse( $result['eligible'] );

		$file_scheme = 'file:///etc/passwd';
		$result      = turbo_cache_preload_is_safe_url( $file_scheme, $site_url );
		$this->assertFalse( $result['eligible'] );
		$this->assertSame( 'invalid_scheme', $result['reason'] );

		$empty  = '';
		$result = turbo_cache_preload_is_safe_url( $empty, $site_url );
		$this->assertFalse( $result['eligible'] );
		$this->assertSame( 'empty_url', $result['reason'] );
	}

	/**
	 * Test directory traversal sequences are rejected.
	 */
	public function test_path_traversal_rejected() {
		$site_url = 'https://example.com';

		$traversal = 'https://example.com/subdir/../../etc/passwd';
		$result    = turbo_cache_preload_is_safe_url( $traversal, $site_url );
		$this->assertFalse( $result['eligible'] );
		$this->assertSame( 'path_traversal', $result['reason'] );
	}

	/**
	 * Test backend, login, cron, and admin endpoints are rejected.
	 */
	public function test_backend_and_admin_endpoints_rejected() {
		$site_url = 'https://example.com';

		$admin  = 'https://example.com/wp-admin/options-general.php';
		$result = turbo_cache_preload_is_safe_url( $admin, $site_url );
		$this->assertFalse( $result['eligible'] );
		$this->assertSame( 'backend_admin', $result['reason'] );

		$login  = 'https://example.com/wp-login.php';
		$result = turbo_cache_preload_is_safe_url( $login, $site_url );
		$this->assertFalse( $result['eligible'] );
		$this->assertSame( 'backend_admin', $result['reason'] );

		$cron   = 'https://example.com/wp-cron.php';
		$result = turbo_cache_preload_is_safe_url( $cron, $site_url );
		$this->assertFalse( $result['eligible'] );
		$this->assertSame( 'backend_admin', $result['reason'] );

		$xmlrpc = 'https://example.com/xmlrpc.php';
		$result = turbo_cache_preload_is_safe_url( $xmlrpc, $site_url );
		$this->assertFalse( $result['eligible'] );
		$this->assertSame( 'backend_admin', $result['reason'] );
	}

	/**
	 * Test REST API and AJAX endpoints are rejected.
	 */
	public function test_rest_and_ajax_endpoints_rejected() {
		$site_url = 'https://example.com';

		$rest   = 'https://example.com/wp-json/wp/v2/posts';
		$result = turbo_cache_preload_is_safe_url( $rest, $site_url );
		$this->assertFalse( $result['eligible'] );
		$this->assertSame( 'rest_or_ajax', $result['reason'] );

		$ajax   = 'https://example.com/wp-admin/admin-ajax.php';
		$result = turbo_cache_preload_is_safe_url( $ajax, $site_url );
		$this->assertFalse( $result['eligible'] );
		$this->assertSame( 'backend_admin', $result['reason'] );
	}

	/**
	 * Test WooCommerce cart, checkout, my-account, and dynamic endpoints are rejected.
	 */
	public function test_woocommerce_endpoints_rejected() {
		$site_url = 'https://example.com';

		$cart   = 'https://example.com/cart/';
		$result = turbo_cache_preload_is_safe_url( $cart, $site_url );
		$this->assertFalse( $result['eligible'] );
		$this->assertSame( 'woocommerce_sensitive', $result['reason'] );

		$checkout = 'https://example.com/checkout/';
		$result   = turbo_cache_preload_is_safe_url( $checkout, $site_url );
		$this->assertFalse( $result['eligible'] );
		$this->assertSame( 'woocommerce_sensitive', $result['reason'] );

		$account = 'https://example.com/my-account/';
		$result  = turbo_cache_preload_is_safe_url( $account, $site_url );
		$this->assertFalse( $result['eligible'] );
		$this->assertSame( 'woocommerce_sensitive', $result['reason'] );

		$order  = 'https://example.com/checkout/order-received/123/';
		$result = turbo_cache_preload_is_safe_url( $order, $site_url );
		$this->assertFalse( $result['eligible'] );
		$this->assertSame( 'woocommerce_sensitive', $result['reason'] );

		$wc_api = 'https://example.com/?wc-api=WC_Gateway';
		$result = turbo_cache_preload_is_safe_url( $wc_api, $site_url );
		$this->assertFalse( $result['eligible'] );
		$this->assertSame( 'woocommerce_sensitive', $result['reason'] );
	}

	/**
	 * Test query bypass arguments (donotcachepage, customizer, preview) are rejected.
	 */
	public function test_query_bypass_rejected() {
		$site_url = 'https://example.com';

		$secret = 'https://example.com/page/?donotcachepage=secret';
		$result = turbo_cache_preload_is_safe_url( $secret, $site_url );
		$this->assertFalse( $result['eligible'] );
		$this->assertSame( 'query_bypass', $result['reason'] );

		$customizer = 'https://example.com/page/?customize_changeset_uuid=abc-123';
		$result     = turbo_cache_preload_is_safe_url( $customizer, $site_url );
		$this->assertFalse( $result['eligible'] );
		$this->assertSame( 'query_bypass', $result['reason'] );

		$preview = 'https://example.com/page/?preview=true';
		$result  = turbo_cache_preload_is_safe_url( $preview, $site_url );
		$this->assertFalse( $result['eligible'] );
		$this->assertSame( 'query_bypass', $result['reason'] );
	}

	/**
	 * Test XML sitemap parsing with valid urlset format.
	 */
	public function test_parse_valid_sitemap_urlset() {
		$site_url = 'https://example.com';
		$xml      = '<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
	<url>
		<loc>https://example.com/post-1/</loc>
		<lastmod>2026-10-01</lastmod>
	</url>
	<url>
		<loc>https://example.com/post-2/</loc>
		<lastmod>2026-10-02</lastmod>
	</url>
	<url>
		<loc>https://external.com/ignored/</loc>
	</url>
	<url>
		<loc>https://example.com/cart/</loc>
	</url>
</urlset>';

		$urls = turbo_cache_preload_parse_sitemap_xml( $xml, $site_url );
		$this->assertCount( 2, $urls );
		$this->assertContains( 'https://example.com/post-1/', $urls );
		$this->assertContains( 'https://example.com/post-2/', $urls );
		$this->assertNotContains( 'https://external.com/ignored/', $urls );
		$this->assertNotContains( 'https://example.com/cart/', $urls );
	}

	/**
	 * Test XML sitemap parsing handles malformed or empty XML gracefully without throwing fatal errors.
	 */
	public function test_parse_malformed_sitemap_xml() {
		$site_url = 'https://example.com';

		$this->assertSame( array(), turbo_cache_preload_parse_sitemap_xml( '', $site_url ) );
		$this->assertSame( array(), turbo_cache_preload_parse_sitemap_xml( 'not xml content', $site_url ) );
		$this->assertSame( array(), turbo_cache_preload_parse_sitemap_xml( '<unclosed><xml>', $site_url ) );
		$this->assertSame( array(), turbo_cache_preload_parse_sitemap_xml( '<?xml version="1.0"?><html<body>error</body></html>', $site_url ) );
	}

	/**
	 * Test state saving, reading, and resetting.
	 */
	public function test_state_lifecycle() {
		turbo_cache_preload_reset_state();

		$initial = turbo_cache_preload_get_state();
		$this->assertSame( 'idle', $initial['status'] );
		$this->assertSame( 0, $initial['queue_cursor'] );

		$initial['status']                    = 'running';
		$initial['queue']                     = array( 'https://example.com/1', 'https://example.com/2' );
		$initial['queue_cursor']              = 1;
		$initial['stats']['total_discovered'] = 2;
		$initial['stats']['processed']        = 1;

		$saved = turbo_cache_preload_save_state( $initial );
		$this->assertTrue( $saved );

		$loaded = turbo_cache_preload_get_state();
		$this->assertSame( 'running', $loaded['status'] );
		$this->assertSame( 1, $loaded['queue_cursor'] );
		$this->assertSame( 2, $loaded['stats']['total_discovered'] );
		$this->assertCount( 2, $loaded['queue'] );

		turbo_cache_preload_reset_state();
		$after_reset = turbo_cache_preload_get_state();
		$this->assertSame( 'idle', $after_reset['status'] );
		$this->assertEmpty( $after_reset['queue'] );
	}

	/**
	 * Test batch processing bounded execution and resumption.
	 */
	public function test_resumable_batch_execution() {
		turbo_cache_preload_reset_state();

		// Pre-populate state with 4 URLs.
		$state                              = turbo_cache_preload_get_state();
		$state['status']                    = 'running';
		$state['queue']                     = array(
			'https://example.com/post-1',
			'https://example.com/cart/', // will be skipped
			'https://example.com/post-2',
			'https://example.com/post-3',
		);
		$state['queue_cursor']              = 0;
		$state['stats']['total_discovered'] = 4;
		turbo_cache_preload_save_state( $state );

		// Process batch 1 with batch size 2.
		$batch1 = turbo_cache_preload_process_batch( 2 );
		$this->assertSame( 'running', $batch1['status'] );
		$this->assertSame( 2, $batch1['processed_in_batch'] );
		$this->assertSame( 2, $batch1['queue_cursor'] );

		// Verify state was persisted at cursor 2.
		$state_after1 = turbo_cache_preload_get_state();
		$this->assertSame( 2, $state_after1['queue_cursor'] );
		$this->assertSame( 2, $state_after1['stats']['skipped'] ); // /cart/ and post-1 (not_cached)
		$this->assertSame( 1, $state_after1['stats']['skip_reasons']['woocommerce_sensitive'] );
		$this->assertSame( 1, $state_after1['stats']['skip_reasons']['not_cached'] );

		// Process batch 2 with batch size 2 (resuming from cursor 2).
		$batch2 = turbo_cache_preload_process_batch( 2 );
		$this->assertSame( 'completed', $batch2['status'] );
		$this->assertSame( 4, $batch2['queue_cursor'] );

		$final_state = turbo_cache_preload_get_state();
		$this->assertSame( 'completed', $final_state['status'] );
		$this->assertGreaterThan( 0, $final_state['finished_at'] );
	}

	/**
	 * Test circuit breaker trips when consecutive failures reach threshold.
	 */
	public function test_circuit_breaker_trips_on_consecutive_failures() {
		turbo_cache_preload_reset_state();

		$state                         = turbo_cache_preload_get_state();
		$state['status']               = 'running';
		$state['consecutive_failures'] = 5;
		$state['queue']                = array( 'https://example.com/test' );
		turbo_cache_preload_save_state( $state );

		$result = turbo_cache_preload_process_batch( 1 );
		$this->assertSame( 'paused', $result['status'] );
		$this->assertStringContainsString( 'Circuit breaker', $result['message'] );

		$updated_state = turbo_cache_preload_get_state();
		$this->assertSame( 'paused', $updated_state['status'] );
	}

	/**
	 * Test permanent failures (404/410) are skipped in subsequent batches.
	 */
	public function test_permanent_failure_skipped() {
		turbo_cache_preload_reset_state();

		$failing_url                       = 'https://example.com/broken-page/';
		$state                             = turbo_cache_preload_get_state();
		$state['status']                   = 'running';
		$state['queue']                    = array( $failing_url );
		$state['queue_cursor']             = 0;
		$state['failures'][ $failing_url ] = array(
			'code'      => 404,
			'message'   => 'HTTP 404',
			'permanent' => true,
			'timestamp' => time(),
		);
		turbo_cache_preload_save_state( $state );

		$result = turbo_cache_preload_process_batch( 1 );
		$this->assertSame( 'completed', $result['status'] );

		$updated_state = turbo_cache_preload_get_state();
		$this->assertSame( 1, $updated_state['stats']['skipped'] );
		$this->assertSame( 1, $updated_state['stats']['skip_reasons']['permanent_failure'] );
	}

	/**
	 * Test cancellation flag stops preload immediately.
	 */
	public function test_cancellation_flag_stops_batch() {
		turbo_cache_preload_reset_state();

		// Create stop flag.
		file_put_contents( $this->temp_dir . 'stop_preload.txt', '' );

		$state           = turbo_cache_preload_get_state();
		$state['status'] = 'running';
		$state['queue']  = array( 'https://example.com/test' );
		turbo_cache_preload_save_state( $state );

		$result = turbo_cache_preload_process_batch( 1 );
		$this->assertSame( 'cancelled', $result['status'] );

		$updated_state = turbo_cache_preload_get_state();
		$this->assertSame( 'cancelled', $updated_state['status'] );
	}

	/**
	 * Test port validation prevents SSRF against internal/non-standard ports.
	 */
	public function test_port_validation_prevents_ssrf() {
		$site_url = 'http://localhost/site/';

		// Non-matching ports should be rejected with invalid_port.
		$ssh_port = 'http://localhost:22/site/page/';
		$res1     = turbo_cache_preload_is_safe_url( $ssh_port, $site_url );
		$this->assertFalse( $res1['eligible'] );
		$this->assertSame( 'invalid_port', $res1['reason'] );

		$mysql_port = 'http://localhost:3306/site/page/';
		$res2       = turbo_cache_preload_is_safe_url( $mysql_port, $site_url );
		$this->assertFalse( $res2['eligible'] );
		$this->assertSame( 'invalid_port', $res2['reason'] );

		// Standard port matching site_url should be accepted.
		$std_port = 'http://localhost:80/site/page/';
		$res3     = turbo_cache_preload_is_safe_url( $std_port, $site_url );
		$this->assertTrue( $res3['eligible'] );
	}

	/**
	 * Test subdirectory path scope prevents accessing sibling sites or root on the same host.
	 */
	public function test_subdirectory_path_scope_prevents_sibling_access() {
		$site_url = 'http://localhost/my-subsite';

		// URL inside subsite should be accepted.
		$valid_sub = 'http://localhost/my-subsite/about/';
		$res1      = turbo_cache_preload_is_safe_url( $valid_sub, $site_url );
		$this->assertTrue( $res1['eligible'] );

		// URL outside subsite (e.g. sibling site or root) should be rejected with out_of_path_scope.
		$sibling_site = 'http://localhost/other-site/about/';
		$res2         = turbo_cache_preload_is_safe_url( $sibling_site, $site_url );
		$this->assertFalse( $res2['eligible'] );
		$this->assertSame( 'out_of_path_scope', $res2['reason'] );

		$root_site = 'http://localhost/';
		$res3      = turbo_cache_preload_is_safe_url( $root_site, $site_url );
		$this->assertFalse( $res3['eligible'] );
		$this->assertSame( 'out_of_path_scope', $res3['reason'] );
	}

	/**
	 * Test batch concurrency locking prevents concurrent worker runs.
	 */
	public function test_batch_concurrency_lock() {
		turbo_cache_preload_reset_state();

		$this->assertTrue( turbo_cache_preload_acquire_lock() );
		// Second acquire must fail while lock is held.
		$this->assertFalse( turbo_cache_preload_acquire_lock() );

		// Running batch while locked must return locked status.
		$res = turbo_cache_preload_process_batch( 1 );
		$this->assertSame( 'locked', $res['status'] );

		turbo_cache_preload_release_lock();
		// Once released, acquiring succeeds again.
		$this->assertTrue( turbo_cache_preload_acquire_lock() );
		turbo_cache_preload_release_lock();
	}

	/**
	 * Test allowed hosts filter for multisite and mapped domains.
	 */
	public function test_allowed_hosts_multisite_and_filters() {
		$site_url = 'https://example.com';

		// Without filter, mapped domain is rejected.
		$mapped_url = 'https://shop.example.com/products/';
		$res1       = turbo_cache_preload_is_safe_url( $mapped_url, $site_url );
		$this->assertFalse( $res1['eligible'] );
		$this->assertSame( 'external_host', $res1['reason'] );

		// With filter, mapped domain is accepted.
		add_filter(
			'turbo_cache_preload_allowed_hosts',
			function ( $hosts ) {
				$hosts[] = 'shop.example.com';
				return $hosts;
			}
		);

		$res2 = turbo_cache_preload_is_safe_url( $mapped_url, $site_url );
		$this->assertTrue( $res2['eligible'] );

		remove_all_filters( 'turbo_cache_preload_allowed_hosts' );
	}

	/**
	 * Test relative redirect targets are resolved and validated.
	 */
	public function test_relative_redirect_resolution() {
		// Test redirect with relative path without leading slash.
		$resolved_root = 'https://example.com/page-b/';
		$this->assertTrue( turbo_cache_preload_is_safe_url( $resolved_root, 'https://example.com' )['eligible'] );

		$resolved_sub = 'https://example.com/section/page-b/';
		$this->assertTrue( turbo_cache_preload_is_safe_url( $resolved_sub, 'https://example.com' )['eligible'] );
	}

	/**
	 * Test candidate cache directories resolution for root and subdirectory URLs.
	 */
	public function test_cache_dirs_for_url_resolution() {
		$url1  = 'https://example.com/sample-page/';
		$dirs1 = turbo_cache_preload_get_cache_dirs_for_url( $url1 );
		$this->assertIsArray( $dirs1 );
		$this->assertNotEmpty( $dirs1 );
		$this->assertStringContainsString( 'supercache/example.com/sample-page/', $dirs1[0] );

		$url2  = 'http://localhost/subdir/sample-page/';
		$dirs2 = turbo_cache_preload_get_cache_dirs_for_url( $url2 );
		$this->assertIsArray( $dirs2 );
		$this->assertNotEmpty( $dirs2 );
		$this->assertStringContainsString( 'supercache/localhost/subdir/sample-page/', $dirs2[0] );
	}

	/**
	 * Test cache entry verification logic on disk across file types.
	 */
	public function test_verify_cache_entry_disk_check() {
		$url = 'https://example.com/test-verify/';

		// 1. Non-existent returns false.
		$this->assertFalse( turbo_cache_preload_verify_cache_entry( $url ) );

		// 2. Resolve dir and create index.html.
		$dirs = turbo_cache_preload_get_cache_dirs_for_url( $url );
		$this->assertNotEmpty( $dirs );
		$target_dir = $dirs[0];
		if ( ! is_dir( $target_dir ) ) {
			mkdir( $target_dir, 0777, true );
		}

		$index_file = $target_dir . 'index.html';
		file_put_contents( $index_file, '<html>Cached page</html>' );
		$this->assertTrue( turbo_cache_preload_verify_cache_entry( $url ) );

		// 3. Delete index.html and test index-https.html.
		unlink( $index_file );
		$this->assertFalse( turbo_cache_preload_verify_cache_entry( $url ) );

		$https_file = $target_dir . 'index-https.html';
		file_put_contents( $https_file, '<html>Cached HTTPS page</html>' );
		$this->assertTrue( turbo_cache_preload_verify_cache_entry( $url ) );

		// 4. Delete index-https.html and test gzipped index.html.gz.
		unlink( $https_file );
		$gz_file = $target_dir . 'index.html.gz';
		file_put_contents( $gz_file, gzencode( '<html>Compressed</html>' ) );
		$this->assertTrue( turbo_cache_preload_verify_cache_entry( $url ) );

		// 5. Delete gz_file and test XML index.xml.
		unlink( $gz_file );
		$xml_file = $target_dir . 'index.xml';
		file_put_contents( $xml_file, '<xml>feed</xml>' );
		$this->assertTrue( turbo_cache_preload_verify_cache_entry( $url ) );

		// Cleanup.
		unlink( $xml_file );
		rmdir( $target_dir );
	}

	/**
	 * Test concurrency locking and interrupted worker recovery.
	 */
	public function test_concurrency_locking_and_stale_lock_recovery() {
		turbo_cache_preload_reset_state();

		$state                 = turbo_cache_preload_get_state();
		$state['status']       = 'running';
		$state['queue']        = array( 'https://example.com/item-1', 'https://example.com/item-2' );
		$state['queue_cursor'] = 1;
		turbo_cache_preload_save_state( $state );

		// Worker 1 holds the lock.
		$this->assertTrue( turbo_cache_preload_acquire_lock() );

		// Worker 2 attempts batch while lock is active.
		$worker2_res = turbo_cache_preload_process_batch( 1 );
		$this->assertSame( 'locked', $worker2_res['status'] );

		// State must remain uncorrupted with cursor untouched.
		$state_after = turbo_cache_preload_get_state();
		$this->assertSame( 1, $state_after['queue_cursor'] );

		// Simulate worker crash leaving stale lock (> 600s).
		$lock_file = $this->temp_dir . 'preload_batch.lock';
		$this->assertFileExists( $lock_file );
		touch( $lock_file, time() - 650 );

		// New worker should automatically recycle stale lock and succeed.
		$this->assertTrue( turbo_cache_preload_acquire_lock() );
		turbo_cache_preload_release_lock();
	}

	/**
	 * Test redirect safety: external redirects, relative redirects, and loop termination.
	 */
	public function test_redirect_safety_external_relative_and_loops() {
		turbo_cache_preload_reset_state();

		// External absolute redirect, relative redirect, and redirect loop.
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) {
				if ( 'https://example.com/external-redirect' === $url ) {
					return array(
						'response' => array( 'code' => 301 ),
						'headers'  => array( 'location' => 'https://evil-external-site.com/steal' ),
						'body'     => '',
					);
				}
				if ( 'https://example.com/relative-redirect' === $url ) {
					return array(
						'response' => array( 'code' => 302 ),
						'headers'  => array( 'location' => '/destination-page/' ),
						'body'     => '',
					);
				}
				if ( 'https://example.com/loop-a' === $url ) {
					return array(
						'response' => array( 'code' => 302 ),
						'headers'  => array( 'location' => 'https://example.com/loop-a' ),
						'body'     => '',
					);
				}
				return $pre;
			},
			10,
			3
		);

		// External redirect must be identified as unsafe target.
		$ext_fetch = turbo_cache_preload_fetch_url( 'https://example.com/external-redirect' );
		$this->assertTrue( $ext_fetch['redirect'] );
		$this->assertFalse( $ext_fetch['target_safe'] );
		$this->assertSame( 'external_host', $ext_fetch['target_reason'] );
		$this->assertTrue( $ext_fetch['permanent_error'] );

		// Relative redirect must be resolved to origin and checked for safety.
		$rel_fetch = turbo_cache_preload_fetch_url( 'https://example.com/relative-redirect' );
		$this->assertTrue( $rel_fetch['redirect'] );
		$this->assertTrue( $rel_fetch['target_safe'] );
		$this->assertSame( 'https://example.com/destination-page/', $rel_fetch['target_url'] );

		// Redirect loop terminates immediately without recursive calls.
		$state                 = turbo_cache_preload_get_state();
		$state['status']       = 'running';
		$state['queue']        = array(
			'https://example.com/external-redirect',
			'https://example.com/relative-redirect',
			'https://example.com/loop-a',
		);
		$state['queue_cursor'] = 0;
		turbo_cache_preload_save_state( $state );

		$batch_res = turbo_cache_preload_process_batch( 3 );
		$this->assertSame( 'completed', $batch_res['status'] );
		$this->assertSame( 3, $batch_res['stats']['skipped'] );
		$this->assertSame( 1, $batch_res['stats']['skip_reasons']['unsafe_redirect_target'] );
		$this->assertSame( 2, $batch_res['stats']['skip_reasons']['redirect'] );

		remove_all_filters( 'pre_http_request' );
	}

	/**
	 * Test cache accounting: HTTP 200 alone cannot increment cached count without valid disk artifact.
	 */
	public function test_cache_accounting_requires_valid_disk_artifact() {
		turbo_cache_preload_reset_state();

		add_filter(
			'pre_http_request',
			function () {
				return array(
					'response' => array( 'code' => 200 ),
					'headers'  => array(),
					'body'     => '<html>Dynamic content</html>',
				);
			}
		);

		$url = 'https://example.com/page-not-cached-on-disk/';

		$state                 = turbo_cache_preload_get_state();
		$state['status']       = 'running';
		$state['queue']        = array( $url );
		$state['queue_cursor'] = 0;
		turbo_cache_preload_save_state( $state );

		// Run batch - HTTP 200 is returned, but no cache file is created on disk.
		$batch = turbo_cache_preload_process_batch( 1 );
		$this->assertSame( 'completed', $batch['status'] );
		$this->assertSame( 0, $batch['stats']['cached'], 'HTTP 200 without cache file must NOT increment cached count' );
		$this->assertSame( 1, $batch['stats']['skipped'], 'HTTP 200 without cache file must be accounted as skipped' );
		$this->assertSame( 1, $batch['stats']['skip_reasons']['not_cached'] );

		// Now simulate WordPress generating and saving the cache file upon request.
		$dirs = turbo_cache_preload_get_cache_dirs_for_url( $url );
		remove_all_filters( 'pre_http_request' );
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $req_url ) use ( $url, $dirs ) {
				if ( $req_url === $url ) {
					if ( ! is_dir( $dirs[0] ) ) {
						mkdir( $dirs[0], 0777, true );
					}
					file_put_contents( $dirs[0] . 'index.html', '<html>Freshly generated cached page</html>' );
				}
				return array(
					'response' => array( 'code' => 200 ),
					'headers'  => array(),
					'body'     => '<html>Freshly generated cached page</html>',
				);
			},
			10,
			3
		);

		// Reset and run again - now it should increment cached count.
		$state['queue_cursor'] = 0;
		$state['stats']        = array(
			'total_discovered' => 1,
			'processed'        => 0,
			'cached'           => 0,
			'skipped'          => 0,
			'failed'           => 0,
			'skip_reasons'     => array(),
		);
		turbo_cache_preload_save_state( $state );

		$batch2 = turbo_cache_preload_process_batch( 1 );
		$this->assertSame( 1, $batch2['stats']['cached'], 'Valid disk file must increment cached count' );
		$this->assertSame( 0, $batch2['stats']['skipped'] );

		// Cleanup.
		unlink( $dirs[0] . 'index.html' );
		rmdir( $dirs[0] );
		remove_all_filters( 'pre_http_request' );
	}
}
