<?php
/**
 * Smoke tests for Turbo Cache Functional Storage Layer.
 *
 * @package automattic/wp-super-cache
 */

use PHPUnit\Framework\TestCase;

/**
 * Tests for inc/cache-storage.php functional helpers.
 *
 * @covers ::turbo_cache_normalize_uri_case
 * @covers ::turbo_cache_deep_replace
 * @covers ::turbo_cache_normalize_uri_path
 * @covers ::turbo_cache_normalize_host
 * @covers ::turbo_cache_get_request_identity
 * @covers ::turbo_cache_get_cache_filename
 * @covers ::turbo_cache_get_storage_dir
 * @covers ::turbo_cache_get_cache_filepath
 * @covers ::turbo_cache_get_temp_filename
 * @covers ::turbo_cache_atomic_rename
 * @covers ::turbo_cache_atomic_write_file
 * @covers ::turbo_cache_path_is_too_long
 */
class TurboCacheStorageTest extends TestCase {

	/**
	 * Temporary test directory for atomic write tests.
	 *
	 * @var string
	 */
	private $test_dir;

	/**
	 * Set up test environment before each test.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->test_dir = sys_get_temp_dir() . '/turbo_cache_test_' . uniqid( (string) wp_rand(), true );
		if ( ! is_dir( $this->test_dir ) ) {
			mkdir( $this->test_dir, 0777, true );
		}
	}

	/**
	 * Clean up test fixtures and globals after each test.
	 */
	protected function tearDown(): void {
		parent::tearDown();

		if ( function_exists( 'remove_all_filters' ) ) {
			remove_all_filters( 'supercache_filename_str' );
			remove_all_filters( 'supercache_dir' );
		}

		$this->delete_directory( $this->test_dir );

		unset(
			$GLOBALS['WPSC_HTTP_HOST'],
			$GLOBALS['wp_cache_request_uri'],
			$GLOBALS['cache_path'],
			$_SERVER['HTTP_HOST'],
			$_SERVER['REQUEST_URI'],
			$_SERVER['HTTPS'],
			$_SERVER['HTTP_X_FORWARDED_PROTO']
		);
	}

	/**
	 * Recursively delete a directory and its contents.
	 *
	 * @param string $dir Directory to delete.
	 */
	private function delete_directory( $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}

		$items = scandir( $dir );
		if ( is_array( $items ) ) {
			foreach ( $items as $item ) {
				if ( '.' === $item || '..' === $item ) {
					continue;
				}
				$path = $dir . '/' . $item;
				if ( is_dir( $path ) ) {
					$this->delete_directory( $path );
				} elseif ( is_file( $path ) ) {
					unlink( $path );
				}
			}
		}

		rmdir( $dir );
	}

	/**
	 * Test root URL normalization.
	 */
	public function test_root_path_normalization(): void {
		$this->assertSame( '/', turbo_cache_normalize_uri_path( '/' ) );
		$this->assertSame( '', turbo_cache_normalize_uri_path( '' ) );

		$identity = turbo_cache_get_request_identity( '/', 'example.com', false );
		$this->assertSame( 'example.com', $identity['host'] );
		$this->assertSame( '/', $identity['path'] );
		$this->assertFalse( $identity['is_https'] );
	}

	/**
	 * Test standard subpage path normalization.
	 */
	public function test_subpage_path_normalization(): void {
		$this->assertSame( '/sample-page/', turbo_cache_normalize_uri_path( '/sample-page/' ) );
		$this->assertSame( '/sample-page', turbo_cache_normalize_uri_path( '/sample-page' ) );
	}

	/**
	 * Test duplicate slashes collapsed in storage directory.
	 */
	public function test_duplicate_slashes_in_storage_dir(): void {
		$dir = turbo_cache_get_storage_dir( '/foo//bar/', 'example.com', '/var/cache/' );
		$this->assertSame( '/var/cache/supercache/example.com/foo/bar/', str_replace( '\\', '/', $dir ) );
	}

	/**
	 * Test case normalization: ASCII uppercase to lower, hex escapes to upper.
	 */
	public function test_case_normalization(): void {
		$this->assertSame( '/foo/bar/', turbo_cache_normalize_uri_path( '/Foo/BAR/' ) );
		$this->assertSame( '/category/%D2%B1lytau/', turbo_cache_normalize_uri_path( '/category/%d2%b1lytau/' ) );
	}

	/**
	 * Test literal spaces are stripped matching upstream wpsc_deep_replace/preg_replace.
	 */
	public function test_literal_space_is_stripped(): void {
		$this->assertSame( '/foobar/', turbo_cache_normalize_uri_path( '/foo bar/' ) );
	}

	/**
	 * Test encoded space %20 is preserved.
	 */
	public function test_encoded_space_is_kept(): void {
		$this->assertSame( '/foo%20bar/', turbo_cache_normalize_uri_path( '/foo%20bar/' ) );
	}

	/**
	 * Test encoded slash %2F is preserved and uppercased.
	 */
	public function test_encoded_slash_is_kept(): void {
		$this->assertSame( '/foo%2Fbar/', turbo_cache_normalize_uri_path( '/foo%2fbar/' ) );
	}

	/**
	 * Test index.php is stripped from path.
	 */
	public function test_index_php_is_stripped(): void {
		$this->assertSame( '/', turbo_cache_normalize_uri_path( '/index.php' ) );
		$this->assertSame( '/sub/', turbo_cache_normalize_uri_path( '/sub/index.php' ) );
	}

	/**
	 * Test query strings are stripped from Supercache path.
	 */
	public function test_query_string_stripped_from_supercache_path(): void {
		$this->assertSame( '/foo', turbo_cache_normalize_uri_path( '/foo?a=1' ) );
		$this->assertSame( '/foo', turbo_cache_normalize_uri_path( '/foo?a=1&b=2' ) );
		$this->assertSame( '/foo', turbo_cache_normalize_uri_path( '/foo?b=2&a=1' ) );
		$this->assertSame( '/foo', turbo_cache_normalize_uri_path( '/foo?utm_source=x' ) );
	}

	/**
	 * Test host normalization strips port and lowercases.
	 */
	public function test_host_normalization_strips_port(): void {
		$this->assertSame( 'example.com', turbo_cache_normalize_host( 'example.com' ) );
		$this->assertSame( 'example.com', turbo_cache_normalize_host( 'EXAMPLE.COM' ) );
		$this->assertSame( 'example.com', turbo_cache_normalize_host( 'example.com:8080' ) );
		$this->assertSame( 'example.com', turbo_cache_normalize_host( 'EXAMPLE.COM:8443' ) );
	}

	/**
	 * Test HTTP vs HTTPS cache filename resolution.
	 */
	public function test_cache_filename_scheme(): void {
		$this->assertSame( 'index.html', turbo_cache_get_cache_filename( false ) );
		$this->assertSame( 'index-https.html', turbo_cache_get_cache_filename( true ) );
	}

	/**
	 * Test mobile variant via supercache_filename_str filter.
	 */
	public function test_cache_filename_mobile_variant(): void {
		if ( function_exists( 'add_filter' ) ) {
			add_filter(
				'supercache_filename_str',
				function ( $extra_str ) {
					return $extra_str . '-mobile';
				}
			);

			$this->assertSame( 'index-mobile.html', turbo_cache_get_cache_filename( false ) );
			$this->assertSame( 'index-https-mobile.html', turbo_cache_get_cache_filename( true ) );
		} else {
			$this->markTestSkipped( 'add_filter test double not present.' );
		}
	}

	/**
	 * Test full storage directory resolution.
	 */
	public function test_storage_dir_resolution(): void {
		$dir = turbo_cache_get_storage_dir( '/sample-page-2/', 'example.com', '/var/cache/' );
		$this->assertSame( '/var/cache/supercache/example.com/sample-page-2/', str_replace( '\\', '/', $dir ) );

		$root_dir = turbo_cache_get_storage_dir( '/', 'example.com', '/var/cache/' );
		$this->assertSame( '/var/cache/supercache/example.com/', str_replace( '\\', '/', $root_dir ) );
	}

	/**
	 * Test full cache filepath resolution.
	 */
	public function test_cache_filepath_resolution(): void {
		$path_http = turbo_cache_get_cache_filepath( '/sample-page-2/', 'example.com', '/var/cache/', false );
		$this->assertSame( '/var/cache/supercache/example.com/sample-page-2/index.html', str_replace( '\\', '/', $path_http ) );

		$path_https = turbo_cache_get_cache_filepath( '/sample-page-2/', 'example.com', '/var/cache/', true );
		$this->assertSame( '/var/cache/supercache/example.com/sample-page-2/index-https.html', str_replace( '\\', '/', $path_https ) );
	}

	/**
	 * Test path length protection.
	 */
	public function test_path_length_protection(): void {
		$normal_path = '/short/path/file.html';
		$this->assertFalse( turbo_cache_path_is_too_long( $normal_path ) );

		$overlong_path = '/' . str_repeat( 'a', 2000 ) . '/index.html';
		$this->assertTrue( turbo_cache_path_is_too_long( $overlong_path ) );
	}

	/**
	 * Test atomic write writes file and content correctly.
	 */
	public function test_atomic_write_file_success(): void {
		$target_file = $this->test_dir . '/test-page/index.html';
		$content     = '<html><body>Test Content</body></html>';

		$result = turbo_cache_atomic_write_file( $target_file, $content );

		$this->assertTrue( $result );
		$this->assertFileExists( $target_file );
		$this->assertSame( $content, file_get_contents( $target_file ) );

		// Verify no temporary files remain in directory.
		$tmp_files = glob( dirname( $target_file ) . '/*.tmp' );
		$this->assertEmpty( $tmp_files );
	}

	/**
	 * Test atomic write discards zero-byte content and does not create file.
	 */
	public function test_atomic_write_zero_byte_protection(): void {
		$target_file = $this->test_dir . '/empty-page/index.html';

		$result = turbo_cache_atomic_write_file( $target_file, '' );

		$this->assertFalse( $result );
		$this->assertFileDoesNotExist( $target_file );
	}

	/**
	 * Test atomic write cleanly overwrites an existing file (Windows rename fallback).
	 */
	public function test_atomic_write_replaces_existing_file(): void {
		$target_file = $this->test_dir . '/overwrite-page/index.html';

		$initial = turbo_cache_atomic_write_file( $target_file, 'Initial Content' );
		$this->assertTrue( $initial );
		$this->assertSame( 'Initial Content', file_get_contents( $target_file ) );

		$updated = turbo_cache_atomic_write_file( $target_file, 'Updated Content' );
		$this->assertTrue( $updated );
		$this->assertSame( 'Updated Content', file_get_contents( $target_file ) );
	}

	/**
	 * Test atomic write creates companion gzip file when gzip content provided.
	 */
	public function test_atomic_write_with_gzip(): void {
		$target_file  = $this->test_dir . '/gzip-page/index.html';
		$content      = '<html>Hello Gzip</html>';
		$gzip_content = gzencode( $content, 6, FORCE_GZIP );

		$result = turbo_cache_atomic_write_file( $target_file, $content, $gzip_content );

		$this->assertTrue( $result );
		$this->assertFileExists( $target_file );
		$this->assertFileExists( $target_file . '.gz' );
		$this->assertSame( $gzip_content, file_get_contents( $target_file . '.gz' ) );
	}
}
