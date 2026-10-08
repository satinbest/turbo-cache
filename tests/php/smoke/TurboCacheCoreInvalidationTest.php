<?php
/**
 * Smoke tests for Turbo Cache Core Invalidation and Targeted Purge Engine.
 *
 * @package automattic/wp-super-cache
 */

require_once dirname( __DIR__, 3 ) . '/wp-cache-phase2.php';
require_once dirname( __DIR__, 3 ) . '/inc/cache-invalidation.php';

// phpcs:disable Universal.Files.SeparateFunctionsFromOO.Mixed
// phpcs:disable WordPress.PHP.NoSilencedErrors.Discouraged
// phpcs:disable Squiz.Commenting.FunctionComment.WrongStyle
// phpcs:disable Generic.Formatting.MultipleStatementAlignment.NotSameWarning

use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'trailingslashit' ) ) {
	/**
	 * Test double for WordPress trailingslashit().
	 *
	 * @param string $string Input string.
	 * @return string String with trailing slash.
	 */
	function trailingslashit( $string ) {
		return rtrim( (string) $string, '/\\' ) . '/';
	}
}

if ( ! function_exists( 'get_option' ) ) {
	/**
	 * Test double for WordPress get_option().
	 *
	 * @param string $option  Option name.
	 * @param mixed  $default Default value.
	 * @return mixed
	 */
	function get_option( $option, $default = false ) {
		return isset( $GLOBALS['wpsc_test_options'][ $option ] ) ? $GLOBALS['wpsc_test_options'][ $option ] : $default;
	}
}

if ( ! function_exists( 'get_post' ) ) {
	/**
	 * Test double for WordPress get_post().
	 *
	 * @param mixed $post Post ID or post object.
	 * @return object|null
	 */
	function get_post( $post = null ) {
		if ( is_object( $post ) ) {
			return $post;
		}
		$id = (int) $post;
		return isset( $GLOBALS['wpsc_test_posts'][ $id ] ) ? $GLOBALS['wpsc_test_posts'][ $id ] : null;
	}
}

if ( ! function_exists( 'get_permalink' ) ) {
	/**
	 * Test double for WordPress get_permalink().
	 *
	 * @param mixed $post Post ID or object.
	 * @return string|false
	 */
	function get_permalink( $post = 0 ) {
		$id = is_object( $post ) ? $post->ID : (int) $post;
		return isset( $GLOBALS['wpsc_test_permalinks'][ $id ] ) ? $GLOBALS['wpsc_test_permalinks'][ $id ] : false;
	}
}

if ( ! function_exists( 'get_post_type_object' ) ) {
	/**
	 * Test double for WordPress get_post_type_object().
	 *
	 * @param string $post_type Post type.
	 * @return object
	 */
	function get_post_type_object( $post_type ) {
		return (object) array(
			'public' => true,
			'name'   => $post_type,
		);
	}
}

/**
 * Tests for inc/cache-invalidation.php core primitives and WordPress hook invalidation.
 *
 * @covers ::turbo_cache_deduplicate_purge
 * @covers ::turbo_cache_reset_purged_targets
 * @covers ::turbo_cache_get_purged_targets
 * @covers ::turbo_cache_normalize_invalidation_target
 * @covers ::turbo_cache_purge_storage_dir
 * @covers ::turbo_cache_purge_url
 * @covers ::turbo_cache_invalidate_homepage
 * @covers ::turbo_cache_post_affects_homepage
 * @covers ::turbo_cache_get_post_canonical_url
 * @covers ::turbo_cache_invalidate_post
 * @covers ::turbo_cache_post_status_transition
 */
class TurboCacheCoreInvalidationTest extends TestCase {

	/**
	 * Temporary root test directory.
	 *
	 * @var string
	 */
	private $test_cache_dir;

	/**
	 * Set up test environment before each test.
	 */
	protected function setUp(): void {
		parent::setUp();

		turbo_cache_reset_purged_targets();

		$this->test_cache_dir = sys_get_temp_dir() . '/turbo_invalidation_test_' . uniqid( (string) wp_rand(), true );
		mkdir( $this->test_cache_dir . '/supercache/example.com/', 0777, true );

		$GLOBALS['cache_path']        = trailingslashit( str_replace( '\\', '/', $this->test_cache_dir ) );
		$GLOBALS['WPSC_HTTP_HOST']    = 'example.com';
		$GLOBALS['wpsc_test_options'] = array(
			'home'           => 'http://example.com',
			'show_on_front'  => 'posts',
			'page_on_front'  => 0,
			'page_for_posts' => 0,
		);
		$GLOBALS['wpsc_test_posts']      = array();
		$GLOBALS['wpsc_test_permalinks'] = array();

		unset(
			$_SERVER['REQUEST_URI'],
			$_SERVER['HTTPS'],
			$_SERVER['HTTP_X_FORWARDED_PROTO'],
			$GLOBALS['cached_direct_pages'],
			$GLOBALS['wp_cache_request_uri']
		);

		if ( function_exists( 'remove_all_filters' ) ) {
			remove_all_filters( 'supercache_dir' );
			remove_all_filters( 'supercache_filename_str' );
		}
	}

	/**
	 * Tear down and clean up test fixtures.
	 */
	protected function tearDown(): void {
		parent::tearDown();

		turbo_cache_reset_purged_targets();
		$this->delete_directory( $this->test_cache_dir );

		unset(
			$GLOBALS['cache_path'],
			$GLOBALS['WPSC_HTTP_HOST'],
			$GLOBALS['wpsc_test_options'],
			$GLOBALS['wpsc_test_posts'],
			$GLOBALS['wpsc_test_permalinks'],
			$GLOBALS['cached_direct_pages'],
			$GLOBALS['wp_cache_request_uri'],
			$_SERVER['REQUEST_URI'],
			$_SERVER['HTTPS'],
			$_SERVER['HTTP_X_FORWARDED_PROTO']
		);

		if ( function_exists( 'remove_all_filters' ) ) {
			remove_all_filters( 'supercache_dir' );
			remove_all_filters( 'supercache_filename_str' );
		}
	}

	/**
	 * Recursively delete directory fixture.
	 *
	 * @param string $dir Path to directory.
	 */
	private function delete_directory( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$items = scandir( $dir );
		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$path = $dir . '/' . $item;
			if ( is_dir( $path ) ) {
				$this->delete_directory( $path );
			} else {
				@unlink( $path );
			}
		}
		@rmdir( $dir );
	}

	/**
	 * Helper: Create mock cached page files on disk.
	 *
	 * @param string $path_fragment URI path fragment (e.g. '/hello-world/').
	 * @return string Target directory created.
	 */
	private function create_cache_fixture( $path_fragment ) {
		$dir = turbo_cache_get_storage_dir( $path_fragment, 'example.com', $GLOBALS['cache_path'] );
		if ( ! is_dir( $dir ) ) {
			mkdir( $dir, 0777, true );
		}
		file_put_contents( $dir . 'index.html', '<html>cached ' . $path_fragment . '</html>' );
		file_put_contents( $dir . 'index-https.html', '<html>cached https ' . $path_fragment . '</html>' );
		file_put_contents( $dir . 'index.html.gz', 'fake-gzip-data' );

		return $dir;
	}

	// -------------------------------------------------------------------------
	// 1. Deduplication Tests (Section 5 & 14)
	// -------------------------------------------------------------------------

	public function test_deduplicate_purge_first_call_returns_true(): void {
		$this->assertTrue( turbo_cache_deduplicate_purge( 'example.com/post-1/' ) );
	}

	public function test_deduplicate_purge_duplicate_call_returns_false(): void {
		$this->assertTrue( turbo_cache_deduplicate_purge( 'example.com/post-1/' ) );
		$this->assertFalse( turbo_cache_deduplicate_purge( 'example.com/post-1/' ) );
	}

	public function test_deduplicate_purge_is_case_and_slash_normalized(): void {
		$this->assertTrue( turbo_cache_deduplicate_purge( 'EXAMPLE.COM/Post-1/' ) );
		$this->assertFalse( turbo_cache_deduplicate_purge( 'example.com/post-1' ) );
		$this->assertFalse( turbo_cache_deduplicate_purge( 'example.com/post-1/' ) );
	}

	public function test_deduplicate_purge_reset_allows_repurge(): void {
		$this->assertTrue( turbo_cache_deduplicate_purge( 'example.com/post-1/' ) );
		$this->assertFalse( turbo_cache_deduplicate_purge( 'example.com/post-1/' ) );

		turbo_cache_reset_purged_targets();

		$this->assertTrue( turbo_cache_deduplicate_purge( 'example.com/post-1/' ) );
	}

	public function test_deduplicate_purge_rejects_empty(): void {
		$this->assertFalse( turbo_cache_deduplicate_purge( '' ) );
		$this->assertFalse( turbo_cache_deduplicate_purge( null ) );
	}

	// -------------------------------------------------------------------------
	// 2. Canonical Target Normalization (Section 6)
	// -------------------------------------------------------------------------

	public function test_normalize_invalidation_target_full_url(): void {
		$target = turbo_cache_normalize_invalidation_target( 'http://example.com/sample-page/' );
		$this->assertSame( 'example.com/sample-page/', $target );
	}

	public function test_normalize_invalidation_target_relative_path(): void {
		$target = turbo_cache_normalize_invalidation_target( '/sample-page/', 'example.com' );
		$this->assertSame( 'example.com/sample-page/', $target );
	}

	public function test_normalize_invalidation_target_with_port(): void {
		$target = turbo_cache_normalize_invalidation_target( 'http://example.com:8080/sample-page/' );
		$this->assertSame( 'example.com/sample-page/', $target );
	}

	// -------------------------------------------------------------------------
	// 3. Storage Directory Purging & Safety (Section 2 & 5)
	// -------------------------------------------------------------------------

	public function test_purge_storage_dir_removes_cache_files(): void {
		$dir = $this->create_cache_fixture( '/test-page/' );
		$this->assertFileExists( $dir . 'index.html' );
		$this->assertFileExists( $dir . 'index-https.html' );
		$this->assertFileExists( $dir . 'index.html.gz' );
		$count = turbo_cache_purge_storage_dir( $dir, true );
		$this->assertSame( 3, $count );
		$this->assertFileDoesNotExist( $dir . 'index.html' );
		$this->assertFileDoesNotExist( $dir . 'index-https.html' );
		$this->assertFileDoesNotExist( $dir . 'index.html.gz' );
	}

	public function test_purge_storage_dir_removes_paginated_subdirs(): void {
		$dir      = $this->create_cache_fixture( '/archive/' );
		$page_dir = $dir . 'page/';
		if ( ! is_dir( $page_dir ) ) {
			mkdir( $page_dir, 0777, true );
		}
		file_put_contents( $page_dir . 'index.html', 'page archive' );

		$count = turbo_cache_purge_storage_dir( $dir, true );
		$this->assertSame( 4, $count );
		$this->assertFileDoesNotExist( $page_dir . 'index.html' );
	}

	public function test_purge_storage_dir_rejects_non_cache_dir(): void {
		$outside_dir = sys_get_temp_dir() . '/outside_cache_' . uniqid();
		mkdir( $outside_dir, 0777, true );
		file_put_contents( $outside_dir . '/index.html', 'precious content' );

		$count = turbo_cache_purge_storage_dir( $outside_dir, true );
		$this->assertSame( 0, $count );
		$this->assertFileExists( $outside_dir . '/index.html' );

		@unlink( $outside_dir . '/index.html' );
		@rmdir( $outside_dir );
	}

	// -------------------------------------------------------------------------
	// 4. Targeted URL Purge
	// -------------------------------------------------------------------------

	public function test_purge_url_deletes_cache_for_specific_url(): void {
		$dir = $this->create_cache_fixture( '/hello-world/' );
		$this->assertFileExists( $dir . 'index.html' );

		$purged = turbo_cache_purge_url( 'http://example.com/hello-world/' );
		$this->assertTrue( $purged );
		$this->assertFileDoesNotExist( $dir . 'index.html' );
	}

	public function test_purge_url_skips_query_string_url(): void {
		$this->assertFalse( turbo_cache_purge_url( 'http://example.com/hello-world/?test=1' ) );
	}

	public function test_purge_url_deduplicates_consecutive_calls(): void {
		$this->create_cache_fixture( '/hello-world/' );

		$first  = turbo_cache_purge_url( 'http://example.com/hello-world/' );
		$second = turbo_cache_purge_url( 'http://example.com/hello-world/' );

		$this->assertTrue( $first );
		$this->assertFalse( $second );
	}

	// -------------------------------------------------------------------------
	// 5. Homepage Invalidation (Section 2.D)
	// -------------------------------------------------------------------------

	public function test_invalidate_homepage_purges_only_homepage_files(): void {
		$home_dir = $this->create_cache_fixture( '/' );
		$sub_dir  = $this->create_cache_fixture( '/unrelated-page/' );

		$this->assertFileExists( $home_dir . 'index.html' );
		$this->assertFileExists( $sub_dir . 'index.html' );

		$result = turbo_cache_invalidate_homepage();
		$this->assertTrue( $result );

		// Homepage cache was removed
		$this->assertFileDoesNotExist( $home_dir . 'index.html' );
		// Sibling page cache remains intact
		$this->assertFileExists( $sub_dir . 'index.html' );
	}

	public function test_invalidate_homepage_deduplicates(): void {
		$this->create_cache_fixture( '/' );

		$first  = turbo_cache_invalidate_homepage();
		$second = turbo_cache_invalidate_homepage();

		$this->assertTrue( $first );
		$this->assertFalse( $second );
	}

	// -------------------------------------------------------------------------
	// 6. Post Mutation Invalidation (Section 2.A)
	// -------------------------------------------------------------------------

	public function test_post_update_invalidates_post_and_homepage(): void {
		$post_id = 101;
		$GLOBALS['wpsc_test_posts'][ $post_id ] = (object) array(
			'ID'          => $post_id,
			'post_title'  => 'Test Post',
			'post_name'   => 'test-post',
			'post_type'   => 'post',
			'post_status' => 'publish',
		);
		$GLOBALS['wpsc_test_permalinks'][ $post_id ] = 'http://example.com/test-post/';

		$post_dir = $this->create_cache_fixture( '/test-post/' );
		$home_dir = $this->create_cache_fixture( '/' );
		$page_dir = $this->create_cache_fixture( '/unrelated-page/' );

		$res = turbo_cache_invalidate_post( $post_id );
		$this->assertTrue( $res );

		// Post cache is invalidated
		$this->assertFileDoesNotExist( $post_dir . 'index.html' );
		// Homepage cache is invalidated (blog feed contains posts)
		$this->assertFileDoesNotExist( $home_dir . 'index.html' );
		// Unrelated page is strictly preserved!
		$this->assertFileExists( $page_dir . 'index.html' );
	}

	// -------------------------------------------------------------------------
	// 7. Page Mutation Invalidation & Unrelated Cache Preservation (Section 2.A & 13)
	// -------------------------------------------------------------------------

	public function test_page_update_invalidates_page_and_preserves_homepage(): void {
		$page_id = 202;
		$GLOBALS['wpsc_test_posts'][ $page_id ] = (object) array(
			'ID'          => $page_id,
			'post_title'  => 'About Page',
			'post_name'   => 'about',
			'post_type'   => 'page',
			'post_status' => 'publish',
		);
		$GLOBALS['wpsc_test_permalinks'][ $page_id ] = 'http://example.com/about/';

		$page_dir  = $this->create_cache_fixture( '/about/' );
		$home_dir  = $this->create_cache_fixture( '/' );
		$other_dir = $this->create_cache_fixture( '/other-page/' );
		$post_dir  = $this->create_cache_fixture( '/sample-post/' );

		$res = turbo_cache_invalidate_post( $page_id );
		$this->assertTrue( $res );

		// Page itself is invalidated
		$this->assertFileDoesNotExist( $page_dir . 'index.html' );
		// Homepage is preserved because standalone page does not affect blog feed
		$this->assertFileExists( $home_dir . 'index.html' );
		// Other page is preserved
		$this->assertFileExists( $other_dir . 'index.html' );
		// Sample post is preserved
		$this->assertFileExists( $post_dir . 'index.html' );
	}

	public function test_front_page_update_invalidates_homepage(): void {
		$page_id = 303;
		$GLOBALS['wpsc_test_options']['show_on_front'] = 'page';
		$GLOBALS['wpsc_test_options']['page_on_front'] = $page_id;

		$GLOBALS['wpsc_test_posts'][ $page_id ] = (object) array(
			'ID'          => $page_id,
			'post_title'  => 'Home Static Page',
			'post_name'   => 'home',
			'post_type'   => 'page',
			'post_status' => 'publish',
		);
		$GLOBALS['wpsc_test_permalinks'][ $page_id ] = 'http://example.com/';

		$home_dir = $this->create_cache_fixture( '/' );
		$this->assertFileExists( $home_dir . 'index.html' );

		$res = turbo_cache_invalidate_post( $page_id );
		$this->assertTrue( $res );

		// Homepage is invalidated because page is page_on_front
		$this->assertFileDoesNotExist( $home_dir . 'index.html' );
	}

	// -------------------------------------------------------------------------
	// 8. Post Publish / Status Transition (Section 2.B)
	// -------------------------------------------------------------------------

	public function test_publish_transition_draft_to_publish(): void {
		$post = (object) array(
			'ID'          => 401,
			'post_title'  => 'New Article',
			'post_name'   => 'new-article',
			'post_type'   => 'post',
			'post_status' => 'publish',
		);
		$GLOBALS['wpsc_test_posts'][401]      = $post;
		$GLOBALS['wpsc_test_permalinks'][401] = 'http://example.com/new-article/';

		$home_dir = $this->create_cache_fixture( '/' );
		$post_dir = $this->create_cache_fixture( '/new-article/' );

		$result = turbo_cache_post_status_transition( 'publish', 'draft', $post );
		$this->assertTrue( $result );

		$this->assertFileDoesNotExist( $post_dir . 'index.html' );
		$this->assertFileDoesNotExist( $home_dir . 'index.html' );
	}

	public function test_publish_transition_publish_to_draft(): void {
		$post = (object) array(
			'ID'          => 402,
			'post_title'  => 'Old Article',
			'post_name'   => 'old-article',
			'post_type'   => 'post',
			'post_status' => 'draft',
		);
		$GLOBALS['wpsc_test_posts'][402]      = $post;
		$GLOBALS['wpsc_test_permalinks'][402] = 'http://example.com/old-article/';

		$home_dir = $this->create_cache_fixture( '/' );
		$post_dir = $this->create_cache_fixture( '/old-article/' );

		$result = turbo_cache_post_status_transition( 'draft', 'publish', $post );
		$this->assertTrue( $result );

		$this->assertFileDoesNotExist( $post_dir . 'index.html' );
		$this->assertFileDoesNotExist( $home_dir . 'index.html' );
	}

	public function test_publish_transition_publish_to_trash(): void {
		$post = (object) array(
			'ID'          => 403,
			'post_title'  => 'Trashed Article',
			'post_name'   => 'trashed-article',
			'post_type'   => 'post',
			'post_status' => 'trash',
		);
		$GLOBALS['wpsc_test_posts'][403]      = $post;
		$GLOBALS['wpsc_test_permalinks'][403] = 'http://example.com/trashed-article/';

		$home_dir = $this->create_cache_fixture( '/' );
		$post_dir = $this->create_cache_fixture( '/trashed-article/' );

		$result = turbo_cache_post_status_transition( 'trash', 'publish', $post );
		$this->assertTrue( $result );

		$this->assertFileDoesNotExist( $post_dir . 'index.html' );
		$this->assertFileDoesNotExist( $home_dir . 'index.html' );
	}

	public function test_publish_transition_trash_to_publish(): void {
		$post = (object) array(
			'ID'          => 404,
			'post_title'  => 'Restored Article',
			'post_name'   => 'restored-article',
			'post_type'   => 'post',
			'post_status' => 'publish',
		);
		$GLOBALS['wpsc_test_posts'][404]      = $post;
		$GLOBALS['wpsc_test_permalinks'][404] = 'http://example.com/restored-article/';

		$home_dir = $this->create_cache_fixture( '/' );
		$post_dir = $this->create_cache_fixture( '/restored-article/' );

		$result = turbo_cache_post_status_transition( 'publish', 'trash', $post );
		$this->assertTrue( $result );

		$this->assertFileDoesNotExist( $post_dir . 'index.html' );
		$this->assertFileDoesNotExist( $home_dir . 'index.html' );
	}

	public function test_publish_transition_draft_to_trash_skips_purge(): void {
		$post = (object) array(
			'ID'          => 405,
			'post_title'  => 'Draft Trashed',
			'post_name'   => 'draft-trashed',
			'post_type'   => 'post',
			'post_status' => 'trash',
		);
		$result = turbo_cache_post_status_transition( 'trash', 'draft', $post );
		$this->assertFalse( $result );
	}

	// -------------------------------------------------------------------------
	// 9. Post Delete Invalidation (Section 2.C)
	// -------------------------------------------------------------------------

	public function test_delete_post_purges_cache(): void {
		$post_id = 501;
		$GLOBALS['wpsc_test_posts'][ $post_id ] = (object) array(
			'ID'          => $post_id,
			'post_title'  => 'To Be Deleted',
			'post_name'   => 'delete-me',
			'post_type'   => 'post',
			'post_status' => 'publish',
		);
		$GLOBALS['wpsc_test_permalinks'][ $post_id ] = 'http://example.com/delete-me/';

		$post_dir = $this->create_cache_fixture( '/delete-me/' );
		$this->assertFileExists( $post_dir . 'index.html' );

		turbo_cache_invalidate_post( $post_id );
		$this->assertFileDoesNotExist( $post_dir . 'index.html' );
	}

	// -------------------------------------------------------------------------
	// 10. Multi-Hook Duplicate Invalidation Prevention (Section 14)
	// -------------------------------------------------------------------------

	public function test_cascading_hooks_duplicate_prevention(): void {
		$post_id = 601;
		$post    = (object) array(
			'ID'          => $post_id,
			'post_title'  => 'Multi-Hook Post',
			'post_name'   => 'multi-hook',
			'post_type'   => 'post',
			'post_status' => 'publish',
		);
		$GLOBALS['wpsc_test_posts'][ $post_id ]      = $post;
		$GLOBALS['wpsc_test_permalinks'][ $post_id ] = 'http://example.com/multi-hook/';

		$this->create_cache_fixture( '/multi-hook/' );
		$this->create_cache_fixture( '/' );

		// Hook 1: transition_post_status fires
		$res1 = turbo_cache_post_status_transition( 'publish', 'draft', $post );
		$this->assertTrue( $res1 );

		// Hook 2: clean_post_cache fires for the same post in the same request
		$res2 = turbo_cache_invalidate_post( $post_id );
		$this->assertTrue( $res2 ); // Function returns true but internal purge is deduplicated

		// Verify that homepage and post target were recorded in request-local registry
		$targets = turbo_cache_get_purged_targets();
		$this->assertArrayHasKey( 'homepage:0', $targets );
		$this->assertArrayHasKey( 'url:example.com/multi-hook', $targets );

		// Third explicit purge attempt for the same URL is deduplicated at gate
		$this->assertFalse( turbo_cache_purge_url( 'http://example.com/multi-hook/' ) );
		$this->assertFalse( turbo_cache_invalidate_homepage() );
	}
}
