<?php
/**
 * Smoke tests for Turbo Cache delivery performance & conditional GET validation layer.
 *
 * @package Turbo_Cache
 */

require_once dirname( __DIR__, 3 ) . '/inc/cache-delivery.php';

use PHPUnit\Framework\TestCase;

/**
 * Class TurboCacheDeliveryPerformanceTest
 *
 * @covers ::turbo_cache_generate_etag
 * @covers ::turbo_cache_parse_if_none_match
 * @covers ::turbo_cache_get_request_header
 * @covers ::turbo_cache_validate_conditional_request
 * @covers ::turbo_cache_send_conditional_304
 * @covers ::turbo_cache_send_delivery_headers
 * @covers ::turbo_cache_stream_cache_file
 */
class TurboCacheDeliveryPerformanceTest extends TestCase {

	/**
	 * Temporary test directory.
	 *
	 * @var string
	 */
	private $temp_dir;

	/**
	 * Set up test environment before each test.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->temp_dir = sys_get_temp_dir() . '/tc_delivery_test_' . uniqid();
		if ( ! is_dir( $this->temp_dir ) ) {
			mkdir( $this->temp_dir, 0777, true );
		}
	}

	/**
	 * Tear down test environment after each test.
	 */
	protected function tearDown(): void {
		if ( is_dir( $this->temp_dir ) ) {
			$files = glob( $this->temp_dir . '/*' );
			if ( is_array( $files ) ) {
				foreach ( $files as $file ) {
					@unlink( $file );
				}
			}
			@rmdir( $this->temp_dir );
		}
		parent::tearDown();
	}

	/**
	 * Test ETag generation with valid inputs produces expected format and is deterministic.
	 */
	public function test_generate_etag_valid_and_deterministic(): void {
		$mtime = 1760000000;
		$size  = 4096;

		$etag = turbo_cache_generate_etag( $mtime, $size );

		$this->assertNotEmpty( $etag );
		$this->assertStringStartsWith( '"', $etag );
		$this->assertStringEndsWith( '"', $etag );
		$this->assertEquals( sprintf( '"%x-%x"', $mtime, $size ), $etag );

		// Determinism check: identical inputs yield identical output.
		$this->assertSame( $etag, turbo_cache_generate_etag( $mtime, $size ) );

		// Variation check: different mtime or size yields different ETag.
		$this->assertNotSame( $etag, turbo_cache_generate_etag( $mtime + 1, $size ) );
		$this->assertNotSame( $etag, turbo_cache_generate_etag( $mtime, $size + 1 ) );
	}

	/**
	 * Test ETag generation with invalid or boundary inputs returns empty string.
	 */
	public function test_generate_etag_invalid_inputs(): void {
		$this->assertSame( '', turbo_cache_generate_etag( 0, 100 ) );
		$this->assertSame( '', turbo_cache_generate_etag( -1, 100 ) );
		$this->assertSame( '', turbo_cache_generate_etag( 1000, -1 ) );
	}

	/**
	 * Test parsing of standard quoted If-None-Match headers.
	 */
	public function test_parse_if_none_match_single_tag(): void {
		$parsed = turbo_cache_parse_if_none_match( '"68e7f8a1-1000"' );
		$this->assertSame( array( '68e7f8a1-1000' ), $parsed );

		// Unquoted tag fallback.
		$parsed_unquoted = turbo_cache_parse_if_none_match( '68e7f8a1-1000' );
		$this->assertSame( array( '68e7f8a1-1000' ), $parsed_unquoted );
	}

	/**
	 * Test parsing of multiple comma-separated If-None-Match tags.
	 */
	public function test_parse_if_none_match_multiple_tags(): void {
		$header = ' "tag-alpha" , "tag-beta", "tag-gamma" ';
		$parsed = turbo_cache_parse_if_none_match( $header );

		$this->assertSame( array( 'tag-alpha', 'tag-beta', 'tag-gamma' ), $parsed );
	}

	/**
	 * Test parsing of weak ETag identifiers per RFC 7232.
	 */
	public function test_parse_if_none_match_weak_tags(): void {
		$header = 'W/"weak-tag-1", w/"weak-tag-2", "strong-tag"';
		$parsed = turbo_cache_parse_if_none_match( $header );

		$this->assertSame( array( 'weak-tag-1', 'weak-tag-2', 'strong-tag' ), $parsed );
	}

	/**
	 * Test parsing of wildcard If-None-Match.
	 */
	public function test_parse_if_none_match_wildcard(): void {
		$parsed = turbo_cache_parse_if_none_match( '*' );
		$this->assertSame( array( '*' ), $parsed );
	}

	/**
	 * Test parsing of empty or invalid If-None-Match headers.
	 */
	public function test_parse_if_none_match_empty_or_invalid(): void {
		$this->assertSame( array(), turbo_cache_parse_if_none_match( '' ) );
		$this->assertSame( array(), turbo_cache_parse_if_none_match( '   ' ) );
		$this->assertSame( array(), turbo_cache_parse_if_none_match( null ) );
	}

	/**
	 * Test retrieving headers case-insensitively from arrays and server variables.
	 */
	public function test_get_request_header_lookup(): void {
		$headers = array(
			'if-none-match' => '"etag-123"',
		);
		$server  = array(
			'HTTP_IF_MODIFIED_SINCE' => 'Wed, 21 Oct 2026 07:28:00 GMT',
		);

		$this->assertSame( '"etag-123"', turbo_cache_get_request_header( 'If-None-Match', null, $headers ) );
		$this->assertSame( '"etag-123"', turbo_cache_get_request_header( 'IF-NONE-MATCH', null, $headers ) );
		$this->assertSame( 'Wed, 21 Oct 2026 07:28:00 GMT', turbo_cache_get_request_header( 'If-Modified-Since', $server, null ) );
		$this->assertNull( turbo_cache_get_request_header( 'X-Missing-Header', $server, $headers ) );
	}

	/**
	 * Test conditional GET validation hit with matching ETag.
	 */
	public function test_validate_conditional_request_if_none_match_hit(): void {
		$file_path = $this->temp_dir . '/index.html';
		file_put_contents( $file_path, '<html><body>Hello Cached Page</body></html>' );

		$mtime = filemtime( $file_path );
		$size  = filesize( $file_path );
		$etag  = turbo_cache_generate_etag( $mtime, $size );

		// 1. Exact quoted match.
		$res = turbo_cache_validate_conditional_request( $file_path, null, array( 'If-None-Match' => $etag ) );
		$this->assertTrue( $res['is_304'] );
		$this->assertSame( 'if_none_match', $res['matched_by'] );
		$this->assertSame( $etag, $res['etag'] );
		$this->assertSame( $mtime, $res['mtime'] );

		// 2. Weak quoted match.
		$weak_etag = 'W/' . $etag;
		$res_weak  = turbo_cache_validate_conditional_request( $file_path, null, array( 'If-None-Match' => $weak_etag ) );
		$this->assertTrue( $res_weak['is_304'] );
		$this->assertSame( 'if_none_match', $res_weak['matched_by'] );

		// 3. Wildcard match.
		$res_wild = turbo_cache_validate_conditional_request( $file_path, null, array( 'If-None-Match' => '*' ) );
		$this->assertTrue( $res_wild['is_304'] );
		$this->assertSame( 'if_none_match', $res_wild['matched_by'] );

		// 4. Multiple ETags containing target.
		$res_multi = turbo_cache_validate_conditional_request( $file_path, null, array( 'If-None-Match' => '"other-etag", ' . $etag ) );
		$this->assertTrue( $res_multi['is_304'] );
		$this->assertSame( 'if_none_match', $res_multi['matched_by'] );
	}

	/**
	 * Test conditional GET validation miss with non-matching ETag.
	 */
	public function test_validate_conditional_request_if_none_match_miss(): void {
		$file_path = $this->temp_dir . '/index.html';
		file_put_contents( $file_path, '<html><body>Hello Cached Page</body></html>' );

		$res = turbo_cache_validate_conditional_request( $file_path, null, array( 'If-None-Match' => '"outdated-etag"' ) );
		$this->assertFalse( $res['is_304'] );
		$this->assertNull( $res['matched_by'] );
		$this->assertNotEmpty( $res['etag'] );
	}

	/**
	 * Test RFC 7232 precedence: If If-None-Match is present and fails, If-Modified-Since must be ignored.
	 */
	public function test_validate_conditional_request_precedence_rfc7232(): void {
		$file_path = $this->temp_dir . '/index.html';
		file_put_contents( $file_path, '<html><body>Hello Cached Page</body></html>' );

		$headers = array(
			'If-None-Match'     => '"non-matching-etag"',
			'If-Modified-Since' => gmdate( 'D, d M Y H:i:s', time() + 3600 ) . ' GMT', // future date would match if evaluated
		);

		$res = turbo_cache_validate_conditional_request( $file_path, null, $headers );
		$this->assertFalse( $res['is_304'], 'RFC 7232 mandates If-Modified-Since MUST NOT be evaluated when If-None-Match fails.' );
		$this->assertNull( $res['matched_by'] );
	}

	/**
	 * Test conditional GET validation hit with If-Modified-Since when If-None-Match is absent.
	 */
	public function test_validate_conditional_request_if_modified_since_hit(): void {
		$file_path = $this->temp_dir . '/index.html';
		file_put_contents( $file_path, '<html><body>Hello Cached Page</body></html>' );
		$mtime = filemtime( $file_path );

		// Exact modification time match.
		$exact_mod = gmdate( 'D, d M Y H:i:s', $mtime ) . ' GMT';
		$res_exact = turbo_cache_validate_conditional_request( $file_path, null, array( 'If-Modified-Since' => $exact_mod ) );
		$this->assertTrue( $res_exact['is_304'] );
		$this->assertSame( 'if_modified_since', $res_exact['matched_by'] );

		// Client timestamp newer than file modification time.
		$newer_mod = gmdate( 'D, d M Y H:i:s', $mtime + 600 ) . ' GMT';
		$res_newer = turbo_cache_validate_conditional_request( $file_path, null, array( 'If-Modified-Since' => $newer_mod ) );
		$this->assertTrue( $res_newer['is_304'] );
		$this->assertSame( 'if_modified_since', $res_newer['matched_by'] );

		// Trailing semicolon parameters (e.g. Mozilla length parameter).
		$param_mod = $exact_mod . '; length=1234';
		$res_param = turbo_cache_validate_conditional_request( $file_path, null, array( 'If-Modified-Since' => $param_mod ) );
		$this->assertTrue( $res_param['is_304'] );
	}

	/**
	 * Test conditional GET validation miss with outdated If-Modified-Since.
	 */
	public function test_validate_conditional_request_if_modified_since_miss(): void {
		$file_path = $this->temp_dir . '/index.html';
		file_put_contents( $file_path, '<html><body>Hello Cached Page</body></html>' );
		$mtime = filemtime( $file_path );

		// Client timestamp older than file modification time.
		$older_mod = gmdate( 'D, d M Y H:i:s', $mtime - 600 ) . ' GMT';
		$res       = turbo_cache_validate_conditional_request( $file_path, null, array( 'If-Modified-Since' => $older_mod ) );
		$this->assertFalse( $res['is_304'] );
		$this->assertNull( $res['matched_by'] );
	}

	/**
	 * Test conditional GET validation with non-existent file returns false.
	 */
	public function test_validate_conditional_request_missing_file(): void {
		$res = turbo_cache_validate_conditional_request( $this->temp_dir . '/does_not_exist.html' );
		$this->assertFalse( $res['is_304'] );
		$this->assertSame( '', $res['etag'] );
		$this->assertSame( 0, $res['mtime'] );
	}

	/**
	 * Test sending 304 headers returns all expected RFC and plugin metadata headers.
	 */
	public function test_send_conditional_304_headers(): void {
		$etag  = '"68e7f8a1-1000"';
		$mtime = 1760000000;

		$headers = turbo_cache_send_conditional_304( $etag, $mtime, false );

		$this->assertIsArray( $headers );
		$this->assertStringContainsString( '304 Not Modified', $headers['Status'] );
		$this->assertSame( $etag, $headers['ETag'] );
		$this->assertSame( gmdate( 'D, d M Y H:i:s', $mtime ) . ' GMT', $headers['Last-Modified'] );
		$this->assertStringContainsString( 'Accept-Encoding', $headers['Vary'] );
		$this->assertStringContainsString( 'max-age', $headers['Cache-Control'] );
		$this->assertSame( 'HIT (304 Not Modified)', $headers['X-Turbo-Cache'] );
		$this->assertStringContainsString( 'Served supercache', $headers['X-WP-Super-Cache'] );
	}

	/**
	 * Test delivery headers emitted for HTTP 200 cache hits.
	 */
	public function test_send_delivery_headers(): void {
		$etag   = '"68e7f8a1-1000"';
		$mtime  = 1760000000;
		$length = 8192;

		$headers = turbo_cache_send_delivery_headers( $etag, $mtime, $length, '', 'supercache' );

		$this->assertSame( $etag, $headers['ETag'] );
		$this->assertSame( gmdate( 'D, d M Y H:i:s', $mtime ) . ' GMT', $headers['Last-Modified'] );
		$this->assertSame( '8192', $headers['Content-Length'] );
		$this->assertSame( 'HIT (supercache)', $headers['X-Turbo-Cache'] );

		// With gzip encoding.
		$gz_headers = turbo_cache_send_delivery_headers( $etag, $mtime, 2048, 'gzip', 'supercache-gzip' );
		$this->assertSame( 'gzip', $gz_headers['Content-Encoding'] );
		$this->assertSame( 'HIT (supercache-gzip)', $gz_headers['X-Turbo-Cache'] );
	}

	/**
	 * Test direct file streaming with readfile.
	 */
	public function test_stream_cache_file(): void {
		$file_path = $this->temp_dir . '/test_stream.html';
		$content   = '<!-- Stream Test Payload --><h1>Turbo Cache Fast Delivery</h1>';
		file_put_contents( $file_path, $content );

		ob_start();
		$bytes_written = turbo_cache_stream_cache_file( $file_path );
		$output        = ob_get_clean();

		$this->assertSame( strlen( $content ), $bytes_written );
		$this->assertSame( $content, $output );

		// Non-existent file.
		$this->assertFalse( turbo_cache_stream_cache_file( $this->temp_dir . '/missing.html' ) );
	}
}
