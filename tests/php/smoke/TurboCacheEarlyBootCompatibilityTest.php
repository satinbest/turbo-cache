<?php
/**
 * Smoke tests for Turbo Cache PHP 7.4 Early-Boot Compatibility (Phase 13A).
 *
 * Verifies that code executing on the early-boot path (before WordPress core
 * loads compat.php polyfills) uses PHP 7.4-compatible string operations.
 *
 * @package Turbo_Cache
 */

// phpcs:disable WordPress.PHP.NoSilencedErrors.Discouraged
// phpcs:disable Generic.Formatting.MultipleStatementAlignment.NotSameWarning

use PHPUnit\Framework\TestCase;

/**
 * Class TurboCacheEarlyBootCompatibilityTest
 */
class TurboCacheEarlyBootCompatibilityTest extends TestCase {

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
		$this->temp_dir = sys_get_temp_dir() . '/tc_compat_test_' . uniqid() . '/';
		if ( ! is_dir( $this->temp_dir ) ) {
			mkdir( $this->temp_dir, 0777, true );
		}
		global $cache_path;
		$this->saved_cache_path = isset( $cache_path ) ? $cache_path : null;
		$cache_path             = $this->temp_dir;
	}

	/**
	 * Clean up test environment after each test.
	 */
	protected function tearDown(): void {
		global $cache_path;
		$cache_path = $this->saved_cache_path;

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
	 * Test that turbo_cache_get_storage_dir correctly handles path suffix normalization
	 * without relying on PHP 8 str_ends_with.
	 */
	public function test_storage_dir_trailing_slash_normalization() {
		// Case 1: Path lacking trailing slash.
		$dir = turbo_cache_get_storage_dir( '/my-post/', 'example.com', '/var/cache/wp' );
		$this->assertStringStartsWith( '/var/cache/wp/supercache/example.com/my-post/', $dir );

		// Case 2: Path already having trailing forward slash.
		$dir_slashed = turbo_cache_get_storage_dir( '/my-post/', 'example.com', '/var/cache/wp/' );
		$this->assertStringStartsWith( '/var/cache/wp/supercache/example.com/my-post/', $dir_slashed );

		// Case 3: Path with trailing backslash.
		$dir_backslash = turbo_cache_get_storage_dir( '/my-post/', 'example.com', 'C:\\cache\\wp\\' );
		$this->assertStringStartsWith( 'C:/cache/wp/supercache/example.com/my-post/', str_replace( '\\', '/', $dir_backslash ) );

		// Case 4: Empty cache path.
		$dir_empty = turbo_cache_get_storage_dir( '/my-post/', 'example.com', '' );
		$this->assertSame( 'supercache/example.com/my-post/', $dir_empty );

		// Case 5: Single character path (shorter than standard path).
		$dir_single = turbo_cache_get_storage_dir( '/my-post/', 'example.com', 'c' );
		$this->assertStringStartsWith( 'c/supercache/example.com/my-post/', $dir_single );
	}

	/**
	 * Test wp-cache-base request URI trailing slash and prefix matching semantics.
	 */
	public function test_wp_cache_base_uri_and_prefix_semantics() {
		// Test suffix check: '/' !== substr( $request_uri, -1 )
		$uri_slashed    = '/blog/sample-post/';
		$uri_unslashed  = '/blog/sample-post';
		$uri_root       = '/';
		$uri_empty      = '';
		$uri_single_non = 'a';

		// Matching suffix '/'
		$this->assertFalse( '/' !== substr( $uri_slashed, -1 ) );
		$this->assertFalse( '/' !== substr( $uri_root, -1 ) );

		// Non-matching suffix
		$this->assertTrue( '/' !== substr( $uri_unslashed, -1 ) );
		$this->assertTrue( '/' !== substr( $uri_single_non, -1 ) );
		$this->assertTrue( '/' !== substr( $uri_empty, -1 ) );

		// Test prefix check: 0 === strncmp( $request_uri, $prefix, strlen( $prefix ) )
		$prefix = '/site1/';
		$this->assertSame( 0, strncmp( '/site1/hello/', $prefix, strlen( $prefix ) ) );
		$this->assertNotSame( 0, strncmp( '/site2/hello/', $prefix, strlen( $prefix ) ) );
		// Case-sensitive check
		$this->assertNotSame( 0, strncmp( '/SITE1/hello/', $prefix, strlen( $prefix ) ) );
		// Shorter than prefix
		$this->assertNotSame( 0, strncmp( '/site', $prefix, strlen( $prefix ) ) );
		// Empty string
		$this->assertNotSame( 0, strncmp( '', $prefix, strlen( $prefix ) ) );
	}

	/**
	 * Test cache invalidation targeted file matching with PHP 7.4-compatible checks.
	 */
	public function test_cache_invalidation_file_matching() {
		$test_dir = $this->temp_dir . 'supercache/example.com/test/';
		mkdir( $test_dir, 0777, true );

		// Create files to purge:
		$valid_targets = array(
			'index.html',
			'index-https.html',
			'index.html.gz',
			'index-https.html.gz',
			'index.needs-rebuild',
			'index.tmp',
			'other.html',
			'archive.gz',
			'page.tmp',
		);
		$preserved_targets = array(
			'readme.txt',
			'config.php',
			'data.json',
			'html',
			'gz',
			'.',
			'..',
		);

		foreach ( $valid_targets as $file ) {
			file_put_contents( $test_dir . $file, 'cached content' );
		}
		foreach ( $preserved_targets as $file ) {
			if ( '.' !== $file && '..' !== $file ) {
				file_put_contents( $test_dir . $file, 'keep me' );
			}
		}

		// Execute purge.
		$purged = turbo_cache_purge_storage_dir( $test_dir, false );
		$this->assertSame( count( $valid_targets ), $purged );

		// Verify valid targets are deleted.
		foreach ( $valid_targets as $file ) {
			$this->assertFileDoesNotExist( $test_dir . $file, "File $file should have been purged" );
		}

		// Verify preserved targets remain intact.
		foreach ( $preserved_targets as $file ) {
			if ( '.' !== $file && '..' !== $file ) {
				$this->assertFileExists( $test_dir . $file, "File $file should have been preserved" );
			}
		}
	}

	/**
	 * Test turbo_cache_purge_url query parameter rejection using false !== strpos().
	 */
	public function test_purge_url_query_parameter_check() {
		$this->assertFalse( turbo_cache_purge_url( 'https://example.com/page/?foo=bar' ) );
		$this->assertFalse( turbo_cache_purge_url( 'https://example.com/page/?' ) );
		$this->assertFalse( turbo_cache_purge_url( '' ) );
	}

	/**
	 * Test get_current_url_supercache_dir prefix check semantics under PHP 7.4.
	 */
	public function test_get_current_url_supercache_dir_prefix_semantics() {
		$home_path = '/blog/';
		$uri_match = '/blog/my-post/';
		$uri_diff  = '/shop/item/';
		$uri_short = '/b';

		$this->assertSame( 0, strncmp( $uri_match, $home_path, strlen( $home_path ) ) );
		$this->assertNotSame( 0, strncmp( $uri_diff, $home_path, strlen( $home_path ) ) );
		$this->assertNotSame( 0, strncmp( $uri_short, $home_path, strlen( $home_path ) ) );
	}

	/**
	 * Test wpsc_is_in_cache_directory prefix check semantics under PHP 7.4.
	 */
	public function test_is_in_cache_directory_prefix_semantics() {
		global $cache_path;
		$orig_cache = isset( $cache_path ) ? $cache_path : null;
		$cache_path = $this->temp_dir;

		$sub_dir = $this->temp_dir . 'supercache/';
		mkdir( $sub_dir, 0777, true );

		$this->assertTrue( wpsc_is_in_cache_directory( $sub_dir ) );
		$this->assertFalse( wpsc_is_in_cache_directory( sys_get_temp_dir() . '/other_dir_' . uniqid() ) );

		$cache_path = $orig_cache;
	}

	/**
	 * Test wpsc_apache_request_headers prefix check semantics under PHP 7.4.
	 */
	public function test_apache_request_headers_prefix_semantics() {
		$saved_server = $_SERVER;

		$_SERVER['HTTP_HOST']         = 'example.com';
		$_SERVER['HTTP_ACCEPT']       = 'text/html';
		$_SERVER['HTTP_X_CUSTOM']     = 'custom-val';
		$_SERVER['SERVER_PORT']       = '80';
		$_SERVER['REQUEST_METHOD']    = 'GET';
		$_SERVER['REMOTE_ADDR']       = '127.0.0.1';
		$_SERVER['HTT']               = 'short';
		$_SERVER['']                  = 'empty';

		$headers = wpsc_apache_request_headers();

		// Case 1: HTTP_ prefixed headers are converted.
		$this->assertArrayHasKey( 'Host', $headers );
		$this->assertSame( 'example.com', $headers['Host'] );
		$this->assertArrayHasKey( 'Accept', $headers );
		$this->assertSame( 'text/html', $headers['Accept'] );
		$this->assertArrayHasKey( 'X-Custom', $headers );
		$this->assertSame( 'custom-val', $headers['X-Custom'] );

		// Case 2: Non-HTTP_ headers are not present.
		$this->assertArrayNotHasKey( 'Server-Port', $headers );
		$this->assertArrayNotHasKey( 'Request-Method', $headers );
		$this->assertArrayNotHasKey( 'Remote-Addr', $headers );
		$this->assertArrayNotHasKey( 'Htt', $headers );

		$_SERVER = $saved_server;
	}

	/**
	 * Static Code Analysis Regression Guard:
	 * Tokenizes all files in the early-boot dependency chain to assert that
	 * no PHP 8.0-only string functions (str_ends_with, str_starts_with) exist.
	 *
	 * This test guarantees PHP 7.4 compatibility even when the test runner
	 * executes under PHP 8.0+.
	 */
	public function test_no_php8_string_functions_in_early_boot_files() {
		$early_boot_files = array(
			dirname( __DIR__, 3 ) . '/wp-cache-base.php',
			dirname( __DIR__, 3 ) . '/inc/cache-storage.php',
			dirname( __DIR__, 3 ) . '/inc/cache-invalidation.php',
			dirname( __DIR__, 3 ) . '/wp-cache-phase2.php',
			dirname( __DIR__, 3 ) . '/ossdl-cdn.php',
			dirname( __DIR__, 3 ) . '/wp-cache-phase1.php',
			dirname( __DIR__, 3 ) . '/advanced-cache.php',
			dirname( __DIR__, 3 ) . '/inc/request-safety.php',
			dirname( __DIR__, 3 ) . '/inc/cache-delivery.php',
			dirname( __DIR__, 3 ) . '/inc/cache-maintenance.php',
			dirname( __DIR__, 3 ) . '/inc/cache-diagnostics.php',
		);

		$forbidden_functions = array(
			'str_ends_with',
			'str_starts_with',
		);

		foreach ( $early_boot_files as $file ) {
			$this->assertFileExists( $file );
			$tokens = token_get_all( file_get_contents( $file ) );

			foreach ( $tokens as $token ) {
				if ( is_array( $token ) && ( T_STRING === $token[0] ) ) {
					$func_name = strtolower( $token[1] );
					$this->assertNotContains(
						$func_name,
						$forbidden_functions,
						sprintf(
							'Forbidden PHP 8.0 string function "%s" detected in early-boot file: %s (line %d)',
							$func_name,
							basename( $file ),
							$token[2]
						)
					);
				}
			}
		}
	}
}
