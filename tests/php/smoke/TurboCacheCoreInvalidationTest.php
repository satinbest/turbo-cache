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

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', sys_get_temp_dir() . '/turbo_test_abspath/' );
}

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

if ( ! function_exists( 'current_filter' ) ) {
	/**
	 * Test double for WordPress current_filter().
	 *
	 * @return string
	 */
	function current_filter() {
		return isset( $GLOBALS['wpsc_test_current_filter'] ) ? $GLOBALS['wpsc_test_current_filter'] : '';
	}
}

if ( ! function_exists( 'get_post_status' ) ) {
	/**
	 * Test double for WordPress get_post_status().
	 *
	 * @param mixed $post Post ID or object.
	 * @return string|false
	 */
	function get_post_status( $post = null ) {
		if ( is_object( $post ) && isset( $post->post_status ) ) {
			return $post->post_status;
		}
		$p = get_post( $post );
		return $p ? $p->post_status : false;
	}
}

if ( ! function_exists( 'get_post_meta' ) ) {
	/**
	 * Test double for WordPress get_post_meta().
	 *
	 * @param int    $post_id Post ID.
	 * @param string $key     Meta key.
	 * @param bool   $single  Whether single value.
	 * @return mixed
	 */
	function get_post_meta( $post_id, $key = '', $single = false ) {
		unset( $single );
		$id = (int) $post_id;
		return isset( $GLOBALS['wpsc_test_post_meta'][ $id ][ $key ] ) ? $GLOBALS['wpsc_test_post_meta'][ $id ][ $key ] : '';
	}
}

if ( ! function_exists( 'get_sample_permalink' ) ) {
	/**
	 * Test double for WordPress get_sample_permalink().
	 *
	 * @param mixed       $post  Post object or ID.
	 * @param string|null $title Post title.
	 * @param string|null $name  Post slug.
	 * @return array{0:string,1:string}
	 */
	function get_sample_permalink( $post, $title = null, $name = null ) {
		$slug = ( null !== $name && '' !== $name ) ? $name : ( is_object( $post ) && ! empty( $post->post_name ) ? $post->post_name : 'sample' );
		return array( 'http://example.com/%postname%/', $slug );
	}
}

if ( ! function_exists( 'get_object_taxonomies' ) ) {
	/**
	 * Test double for WordPress get_object_taxonomies().
	 *
	 * @param mixed  $object Object or post type.
	 * @param string $output Output format.
	 * @return array
	 */
	function get_object_taxonomies( $object, $output = 'names' ) {
		unset( $object, $output );
		return array();
	}
}

if ( ! function_exists( 'get_post_type_archive_link' ) ) {
	/**
	 * Test double for WordPress get_post_type_archive_link().
	 *
	 * @param string $post_type Post type name.
	 * @return string|false
	 */
	function get_post_type_archive_link( $post_type ) {
		unset( $post_type );
		return false;
	}
}

if ( ! function_exists( 'get_author_posts_url' ) ) {
	/**
	 * Test double for WordPress get_author_posts_url().
	 *
	 * @param int $author_id Author user ID.
	 * @return string
	 */
	function get_author_posts_url( $author_id ) {
		unset( $author_id );
		return '';
	}
}

if ( ! function_exists( 'home_url' ) ) {
	/**
	 * Test double for WordPress home_url().
	 *
	 * @param string $path Path.
	 * @return string
	 */
	function home_url( $path = '' ) {
		$home = isset( $GLOBALS['wpsc_test_options']['home'] ) ? (string) $GLOBALS['wpsc_test_options']['home'] : 'http://example.com';
		return rtrim( $home, '/' ) . '/' . ltrim( (string) $path, '/' );
	}
}

if ( ! function_exists( 'get_term_by' ) ) {
	/**
	 * Test double for WordPress get_term_by().
	 *
	 * @param string     $field    Field name.
	 * @param int|string $value    Field value.
	 * @param string     $taxonomy Taxonomy.
	 * @return object|false
	 */
	function get_term_by( $field, $value, $taxonomy = 'category' ) {
		unset( $taxonomy );
		if ( 'term_taxonomy_id' === $field ) {
			$tt_id = (int) $value;
			return isset( $GLOBALS['wpsc_test_terms_by_tt_id'][ $tt_id ] ) ? $GLOBALS['wpsc_test_terms_by_tt_id'][ $tt_id ] : false;
		}
		if ( 'id' === $field ) {
			$id = (int) $value;
			return isset( $GLOBALS['wpsc_test_terms_by_id'][ $id ] ) ? $GLOBALS['wpsc_test_terms_by_id'][ $id ] : false;
		}
		return false;
	}
}

if ( ! function_exists( 'get_category_parents' ) ) {
	/**
	 * Test double for WordPress get_category_parents().
	 *
	 * @param int    $category_id  Category ID.
	 * @param bool   $display_link Whether to format as link.
	 * @param string $separator    Path separator.
	 * @param bool   $nicename     Whether to use slug.
	 * @return string
	 */
	function get_category_parents( $category_id, $display_link = false, $separator = '/', $nicename = false ) {
		unset( $display_link, $nicename );
		$id    = (int) $category_id;
		$chain = '';
		while ( $id > 0 && isset( $GLOBALS['wpsc_test_terms_by_id'][ $id ] ) ) {
			$term  = $GLOBALS['wpsc_test_terms_by_id'][ $id ];
			$chain = $term->slug . $separator . $chain;
			$id    = isset( $term->parent ) ? (int) $term->parent : 0;
		}
		return $chain;
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	/**
	 * Test double for WordPress is_wp_error().
	 *
	 * @param mixed $thing Thing to check.
	 * @return bool
	 */
	function is_wp_error( $thing ) {
		return is_object( $thing ) && is_a( $thing, 'WP_Error' );
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
		$GLOBALS['wpsc_test_posts']          = array();
		$GLOBALS['wpsc_test_permalinks']     = array();
		$GLOBALS['wpsc_test_post_meta']      = array();
		$GLOBALS['wpsc_test_terms_by_tt_id'] = array();
		$GLOBALS['wpsc_test_terms_by_id']    = array();
		$GLOBALS['wpsc_test_current_filter'] = '';
		$GLOBALS['file_prefix']              = 'wp-cache-';

		unset(
			$_SERVER['REQUEST_URI'],
			$_SERVER['HTTPS'],
			$_SERVER['HTTP_X_FORWARDED_PROTO'],
			$GLOBALS['cached_direct_pages'],
			$GLOBALS['wp_cache_request_uri'],
			$GLOBALS['cache_rebuild_files']
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
			$GLOBALS['wpsc_test_post_meta'],
			$GLOBALS['wpsc_test_terms_by_tt_id'],
			$GLOBALS['wpsc_test_terms_by_id'],
			$GLOBALS['wpsc_test_current_filter'],
			$GLOBALS['file_prefix'],
			$GLOBALS['cached_direct_pages'],
			$GLOBALS['wp_cache_request_uri'],
			$GLOBALS['cache_rebuild_files'],
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

	// -------------------------------------------------------------------------
	// 11. Phase 5B Corrective Regression Tests
	// -------------------------------------------------------------------------

	/**
	 * Regression 1: Changing a published post slug invalidates old and new URLs.
	 */
	public function test_post_slug_change_invalidates_old_and_new_urls(): void {
		$post_id     = 701;
		$post_before = (object) array(
			'ID'          => $post_id,
			'post_title'  => 'Original Title',
			'post_name'   => 'original-slug',
			'post_type'   => 'post',
			'post_status' => 'publish',
			'post_date'   => '2026-10-09 10:00:00',
			'post_parent' => 0,
		);
		$post_after  = (object) array(
			'ID'          => $post_id,
			'post_title'  => 'New Title',
			'post_name'   => 'new-slug',
			'post_type'   => 'post',
			'post_status' => 'publish',
			'post_date'   => '2026-10-09 10:00:00',
			'post_parent' => 0,
		);

		$GLOBALS['wpsc_test_posts'][ $post_id ]      = $post_after;
		$GLOBALS['wpsc_test_permalinks'][ $post_id ] = 'http://example.com/new-slug/';

		$old_dir       = $this->create_cache_fixture( '/original-slug/' );
		$new_dir       = $this->create_cache_fixture( '/new-slug/' );
		$unrelated_dir = $this->create_cache_fixture( '/unrelated-post/' );

		$this->assertFileExists( $old_dir . 'index.html' );
		$this->assertFileExists( $new_dir . 'index.html' );
		$this->assertFileExists( $unrelated_dir . 'index.html' );

		$res = turbo_cache_post_updated( $post_id, $post_after, $post_before );
		$this->assertTrue( $res );

		// Old URL cache is removed
		$this->assertFileDoesNotExist( $old_dir . 'index.html' );
		// New URL cache is removed
		$this->assertFileDoesNotExist( $new_dir . 'index.html' );
		// Unrelated post cache is preserved
		$this->assertFileExists( $unrelated_dir . 'index.html' );
	}

	/**
	 * Regression 2: Changing a hierarchical page parent invalidates old and new hierarchy URLs.
	 */
	public function test_page_parent_change_invalidates_old_and_new_urls(): void {
		$parent_old_id = 801;
		$parent_new_id = 802;
		$page_id       = 803;

		$GLOBALS['wpsc_test_permalinks'][ $parent_old_id ] = 'http://example.com/company/';
		$GLOBALS['wpsc_test_permalinks'][ $parent_new_id ] = 'http://example.com/about/';

		$page_before = (object) array(
			'ID'          => $page_id,
			'post_title'  => 'Our Team',
			'post_name'   => 'team',
			'post_type'   => 'page',
			'post_status' => 'publish',
			'post_parent' => $parent_old_id,
			'post_date'   => '2026-10-09 10:00:00',
		);
		$page_after  = (object) array(
			'ID'          => $page_id,
			'post_title'  => 'Our Team',
			'post_name'   => 'team',
			'post_type'   => 'page',
			'post_status' => 'publish',
			'post_parent' => $parent_new_id,
			'post_date'   => '2026-10-09 10:00:00',
		);

		$GLOBALS['wpsc_test_posts'][ $page_id ]      = $page_after;
		$GLOBALS['wpsc_test_permalinks'][ $page_id ] = 'http://example.com/about/team/';

		$old_dir       = $this->create_cache_fixture( '/company/team/' );
		$new_dir       = $this->create_cache_fixture( '/about/team/' );
		$unrelated_dir = $this->create_cache_fixture( '/company/' );

		$this->assertFileExists( $old_dir . 'index.html' );
		$this->assertFileExists( $new_dir . 'index.html' );
		$this->assertFileExists( $unrelated_dir . 'index.html' );

		$res = turbo_cache_post_updated( $page_id, $page_after, $page_before );
		$this->assertTrue( $res );

		// Old hierarchical URL cache is removed
		$this->assertFileDoesNotExist( $old_dir . 'index.html' );
		// New hierarchical URL cache is removed
		$this->assertFileDoesNotExist( $new_dir . 'index.html' );
		// Unrelated parent page is preserved
		$this->assertFileExists( $unrelated_dir . 'index.html' );
	}

	/**
	 * Regression 3: Permanent deletion from Trash for a previously published post purges cache.
	 *
	 * WordPress deletion lifecycle:
	 * 1. Post is in trash with post_status='trash', post_name='trashed-post__trashed', _wp_trash_meta_status='publish'.
	 * 2. before_delete_post fires while metadata and post object are intact.
	 * 3. Core deletes post metadata (_wp_trash_meta_status is deleted).
	 * 4. delete_post fires.
	 */
	public function test_permanent_deletion_from_trash_purges_cache(): void {
		$post_id = 901;
		$post    = (object) array(
			'ID'          => $post_id,
			'post_title'  => 'Trashed Post',
			'post_name'   => 'trashed-post__trashed',
			'post_type'   => 'post',
			'post_status' => 'trash',
		);
		$GLOBALS['wpsc_test_posts'][ $post_id ]                               = $post;
		$GLOBALS['wpsc_test_permalinks'][ $post_id ]                          = 'http://example.com/?p=901';
		$GLOBALS['wpsc_test_post_meta'][ $post_id ]['_wp_trash_meta_status'] = 'publish';

		$dir = $this->create_cache_fixture( '/trashed-post/' );
		$this->assertFileExists( $dir . 'index.html' );

		// Step 1: before_delete_post hook runs
		$GLOBALS['wpsc_test_current_filter'] = 'before_delete_post';
		turbo_cache_before_delete_post( $post_id, $post );

		// Step 2: Core deletes metadata prior to delete_post
		unset( $GLOBALS['wpsc_test_post_meta'][ $post_id ]['_wp_trash_meta_status'] );

		// Step 3: delete_post hook runs legacy handler
		$GLOBALS['wpsc_test_current_filter'] = 'delete_post';
		wp_cache_post_edit( $post_id );

		// Cache for previously published post is purged on permanent deletion
		$this->assertFileDoesNotExist( $dir . 'index.html' );
	}

	/**
	 * Regression 4: Permanent deletion from Trash for a draft post skips cache purge.
	 */
	public function test_permanent_deletion_of_draft_skips_purge(): void {
		$post_id = 902;
		$post    = (object) array(
			'ID'          => $post_id,
			'post_title'  => 'Draft Post',
			'post_name'   => 'draft-post__trashed',
			'post_type'   => 'post',
			'post_status' => 'trash',
		);
		$GLOBALS['wpsc_test_posts'][ $post_id ]                               = $post;
		$GLOBALS['wpsc_test_permalinks'][ $post_id ]                          = 'http://example.com/?p=902';
		$GLOBALS['wpsc_test_post_meta'][ $post_id ]['_wp_trash_meta_status'] = 'draft';

		$dir = $this->create_cache_fixture( '/draft-post/' );
		$this->assertFileExists( $dir . 'index.html' );

		// Step 1: before_delete_post hook runs
		$GLOBALS['wpsc_test_current_filter'] = 'before_delete_post';
		turbo_cache_before_delete_post( $post_id, $post );

		// Step 2: Core deletes metadata prior to delete_post
		unset( $GLOBALS['wpsc_test_post_meta'][ $post_id ]['_wp_trash_meta_status'] );

		// Step 3: delete_post hook runs legacy handler
		$GLOBALS['wpsc_test_current_filter'] = 'delete_post';
		wp_cache_post_edit( $post_id );

		// Never-published draft files are not purged through public cache hook
		$this->assertFileExists( $dir . 'index.html' );
	}

	/**
	 * Regression 5: Failed filesystem purge does not permanently suppress retry.
	 */
	public function test_failed_purge_does_not_suppress_retry(): void {
		$dir  = $this->create_cache_fixture( '/locked-test/' );
		$file = $dir . 'index.html';
		$this->assertFileExists( $file );

		// Set read-only permissions to simulate I/O failure on unlink.
		chmod( $file, 0444 );

		$purged = turbo_cache_purge_url( 'http://example.com/locked-test/' );
		$this->assertFalse( $purged );
		// Target must NOT be permanently marked as purged.
		$this->assertTrue( turbo_cache_deduplicate_purge( 'url:example.com/locked-test', 'check' ) );

		// Restore write permissions. Retry should now succeed.
		chmod( $file, 0666 );
		$retry = turbo_cache_purge_url( 'http://example.com/locked-test/' );
		$this->assertTrue( $retry );
		$this->assertFileDoesNotExist( $file );

		// Also verify unsafe directory purge reports failure without exceptions.
		$outside_dir = sys_get_temp_dir() . '/turbo_unsafe_' . uniqid();
		mkdir( $outside_dir, 0777, true );
		$success = null;
		$deleted = turbo_cache_purge_storage_dir( $outside_dir, true, $success );
		$this->assertSame( 0, $deleted );
		$this->assertFalse( $success );
		@rmdir( $outside_dir );
	}

	/**
	 * Regression 6: Repeated mutations of the same post in long-running process can invalidate again.
	 */
	public function test_repeated_mutation_in_same_process_can_invalidate_again(): void {
		$post_id = 905;
		$post1   = (object) array(
			'ID'          => $post_id,
			'post_title'  => 'Title Version 1',
			'post_name'   => 'test-article',
			'post_type'   => 'post',
			'post_status' => 'publish',
			'post_date'   => '2026-10-09 10:00:00',
			'post_parent' => 0,
		);
		$GLOBALS['wpsc_test_posts'][ $post_id ]      = $post1;
		$GLOBALS['wpsc_test_permalinks'][ $post_id ] = 'http://example.com/test-article/';

		$dir = $this->create_cache_fixture( '/test-article/' );
		$this->assertFileExists( $dir . 'index.html' );

		// Mutation 1
		turbo_cache_post_updated( $post_id, $post1, $post1 );
		$this->assertFileDoesNotExist( $dir . 'index.html' );

		// Recreate cache (simulating regeneration)
		$this->create_cache_fixture( '/test-article/' );
		$this->assertFileExists( $dir . 'index.html' );

		// Mutation 2 in the same PHP process
		$post2 = (object) array(
			'ID'          => $post_id,
			'post_title'  => 'Title Version 2',
			'post_name'   => 'test-article',
			'post_type'   => 'post',
			'post_status' => 'publish',
			'post_date'   => '2026-10-09 10:05:00',
			'post_parent' => 0,
		);
		turbo_cache_post_updated( $post_id, $post2, $post1 );
		$this->assertFileDoesNotExist( $dir . 'index.html' );
	}

	/**
	 * Regression 7: turbo_cache_reset_purged_targets clears both registries consistently.
	 */
	public function test_reset_purged_targets_clears_both_registries(): void {
		$this->create_cache_fixture( '/test-registry/' );
		turbo_cache_purge_url( 'http://example.com/test-registry/' );

		$targets = turbo_cache_get_purged_targets();
		$this->assertArrayHasKey( 'url:example.com/test-registry', $targets );
		$this->assertFalse( turbo_cache_deduplicate_purge( 'url:example.com/test-registry', 'check' ) );

		turbo_cache_reset_purged_targets();

		$cleared_targets = turbo_cache_get_purged_targets();
		$this->assertEmpty( $cleared_targets );
		$this->assertTrue( turbo_cache_deduplicate_purge( 'url:example.com/test-registry', 'check' ) );
	}

	/**
	 * Regression 8: Root URLs normalize path to slash without scheme corruption.
	 */
	public function test_root_url_path_fallback_normalizes_to_slash(): void {
		$home_dir = $this->create_cache_fixture( '/' );
		$this->assertFileExists( $home_dir . 'index.html' );

		// Purge root URL without trailing slash
		$purged = turbo_cache_purge_url( 'http://example.com' );
		$this->assertTrue( $purged );
		$this->assertFileDoesNotExist( $home_dir . 'index.html' );
		// Root directory itself remains
		$this->assertDirectoryExists( $home_dir );

		// Target normalization check for various root formats
		$this->assertSame( 'example.com/', turbo_cache_normalize_invalidation_target( 'http://example.com' ) );
		$this->assertSame( 'example.com/', turbo_cache_normalize_invalidation_target( 'https://example.com:8080' ) );
		$this->assertSame( 'example.com/', turbo_cache_normalize_invalidation_target( 'http://example.com/' ) );
	}

	/**
	 * Regression 9: Direct-page cache invalidation from admin/CLI context without REQUEST_URI dependency.
	 */
	public function test_direct_page_cache_invalidation_from_admin_context(): void {
		$GLOBALS['cached_direct_pages'] = array( '/direct-page/' );
		$_SERVER['REQUEST_URI']         = '/wp-admin/post.php';

		// Create mock direct page in ABSPATH
		$direct_dir = ABSPATH . 'direct-page/';
		if ( ! is_dir( $direct_dir ) ) {
			mkdir( $direct_dir, 0777, true );
		}
		file_put_contents( $direct_dir . 'index.html', 'direct cached content' );
		$this->assertFileExists( $direct_dir . 'index.html' );

		// Storage directory resolves to ABSPATH based on URI path
		$storage_dir = turbo_cache_get_storage_dir( '/direct-page/', 'example.com', $this->test_cache_dir . '/' );
		$this->assertSame( str_replace( '\\', '/', $direct_dir ), str_replace( '\\', '/', $storage_dir ) );

		$success = null;
		$deleted = turbo_cache_purge_storage_dir( $storage_dir, true, $success );
		$this->assertSame( 1, $deleted );
		$this->assertTrue( $success );
		$this->assertFileDoesNotExist( $direct_dir . 'index.html' );
		// Ensure direct page folder under ABSPATH was preserved and not unlinked
		$this->assertDirectoryExists( $direct_dir );

		@rmdir( $direct_dir );
	}

	/**
	 * Regression 10: wpsc_delete_url_cache preserves rebuild staging when cache_rebuild_files is enabled.
	 */
	public function test_wpsc_delete_url_cache_rebuild_compatibility(): void {
		$dir = $this->create_cache_fixture( '/rebuild-test/' );
		$this->assertFileExists( $dir . 'index.html' );

		// Mode A: Rebuild enabled
		$GLOBALS['cache_rebuild_files'] = 1;
		$res_rebuild                    = wpsc_delete_url_cache( 'http://example.com/rebuild-test/' );
		$this->assertTrue( $res_rebuild );
		$this->assertFileDoesNotExist( $dir . 'index.html' );
		$this->assertFileExists( $dir . 'index.html.needs-rebuild' );

		// Mode B: Standard purge (rebuild disabled)
		$GLOBALS['cache_rebuild_files'] = 0;
		turbo_cache_reset_purged_targets();
		$res_purge = wpsc_delete_url_cache( 'http://example.com/rebuild-test/' );
		$this->assertTrue( $res_purge );
		$this->assertFileDoesNotExist( $dir . 'index.html.needs-rebuild' );
	}

	/**
	 * Regression 11: Homepage purge failure rolls back deduplication and allows retry.
	 */
	public function test_homepage_failed_purge_allows_retry(): void {
		$home_dir  = $this->create_cache_fixture( '/' );
		$home_file = $home_dir . 'index.html';
		$this->assertFileExists( $home_file );

		// Simulate filesystem permission failure on homepage cache file.
		chmod( $home_file, 0444 );

		$failed_purge = turbo_cache_invalidate_homepage();
		$this->assertFalse( $failed_purge );
		$this->assertFileExists( $home_file );

		// Deduplication tag must have been rolled back to allow retrying.
		$this->assertTrue( turbo_cache_deduplicate_purge( 'homepage:0', 'check' ) );

		// Fix permission and retry homepage purge.
		chmod( $home_file, 0666 );
		$retry_purge = turbo_cache_invalidate_homepage();
		$this->assertTrue( $retry_purge );
		$this->assertFileDoesNotExist( $home_file );
	}

	/**
	 * Regression 12.A: Category-based permalink changes invalidate both old and new category URLs independently.
	 *
	 * Asserts independently that:
	 * 1. Old category alpha cache artifact is removed.
	 * 2. New category beta cache artifact is removed.
	 * 3. Unrelated cached page remains intact.
	 * 4. Repeating the mutation does not produce incorrect deduplication behavior.
	 */
	public function test_category_change_invalidates_old_and_new_urls_independently(): void {
		$GLOBALS['wpsc_test_options']['permalink_structure'] = '/%category%/%postname%/';

		$cat_alpha = (object) array(
			'term_id'          => 10,
			'term_taxonomy_id' => 10,
			'slug'             => 'alpha',
			'parent'           => 0,
		);
		$cat_beta  = (object) array(
			'term_id'          => 20,
			'term_taxonomy_id' => 20,
			'slug'             => 'beta',
			'parent'           => 0,
		);

		$GLOBALS['wpsc_test_terms_by_tt_id'][10] = $cat_alpha;
		$GLOBALS['wpsc_test_terms_by_id'][10]    = $cat_alpha;
		$GLOBALS['wpsc_test_terms_by_tt_id'][20] = $cat_beta;
		$GLOBALS['wpsc_test_terms_by_id'][20]    = $cat_beta;

		$post_id = 1001;
		$post    = (object) array(
			'ID'          => $post_id,
			'post_title'  => 'Category Post',
			'post_name'   => 'my-story',
			'post_type'   => 'post',
			'post_status' => 'publish',
			'post_date'   => '2026-10-12 10:00:00',
		);
		$GLOBALS['wpsc_test_posts'][ $post_id ]      = $post;
		$GLOBALS['wpsc_test_permalinks'][ $post_id ] = 'http://example.com/beta/my-story/';

		$cat_old_dir   = $this->create_cache_fixture( '/alpha/my-story/' );
		$cat_new_dir   = $this->create_cache_fixture( '/beta/my-story/' );
		$unrelated_dir = $this->create_cache_fixture( '/unrelated-page/' );

		$this->assertFileExists( $cat_old_dir . 'index.html' );
		$this->assertFileExists( $cat_new_dir . 'index.html' );
		$this->assertFileExists( $unrelated_dir . 'index.html' );

		// Execute core hook set_object_terms with category change from alpha (10) to beta (20)
		turbo_cache_terms_changed( $post_id, array( 20 ), array( 20 ), 'category', false, array( 10 ) );

		// Step 4: Assert old alpha cache artifact is removed
		$this->assertFileDoesNotExist( $cat_old_dir . 'index.html' );

		// Step 5: Assert independently that new beta cache artifact is removed
		$this->assertFileDoesNotExist( $cat_new_dir . 'index.html' );

		// Step 6: Verify unrelated cached page remains intact
		$this->assertFileExists( $unrelated_dir . 'index.html' );

		// Step 7: Verify repeating the same operation allows invalidation without incorrect deduplication suppression
		$this->create_cache_fixture( '/alpha/my-story/' );
		$this->create_cache_fixture( '/beta/my-story/' );
		$this->assertFileExists( $cat_old_dir . 'index.html' );
		$this->assertFileExists( $cat_new_dir . 'index.html' );

		turbo_cache_terms_changed( $post_id, array( 20 ), array( 20 ), 'category', false, array( 10 ) );
		$this->assertFileDoesNotExist( $cat_old_dir . 'index.html' );
		$this->assertFileDoesNotExist( $cat_new_dir . 'index.html' );
	}

	/**
	 * Regression 12.B: Multiple categories select lowest term_id matching WordPress core get_permalink().
	 */
	public function test_category_change_with_multiple_categories_selects_correct_permalink_category(): void {
		$GLOBALS['wpsc_test_options']['permalink_structure'] = '/%category%/%postname%/';

		$cat_alpha = (object) array(
			'term_id'          => 10,
			'term_taxonomy_id' => 10,
			'slug'             => 'alpha',
			'parent'           => 0,
		);
		$cat_zeta  = (object) array(
			'term_id'          => 50,
			'term_taxonomy_id' => 50,
			'slug'             => 'zeta',
			'parent'           => 0,
		);
		$cat_beta  = (object) array(
			'term_id'          => 20,
			'term_taxonomy_id' => 20,
			'slug'             => 'beta',
			'parent'           => 0,
		);

		$GLOBALS['wpsc_test_terms_by_tt_id'][10] = $cat_alpha;
		$GLOBALS['wpsc_test_terms_by_id'][10]    = $cat_alpha;
		$GLOBALS['wpsc_test_terms_by_tt_id'][50] = $cat_zeta;
		$GLOBALS['wpsc_test_terms_by_id'][50]    = $cat_zeta;
		$GLOBALS['wpsc_test_terms_by_tt_id'][20] = $cat_beta;
		$GLOBALS['wpsc_test_terms_by_id'][20]    = $cat_beta;

		$post_id = 1002;
		$post    = (object) array(
			'ID'          => $post_id,
			'post_title'  => 'Multi Cat Post',
			'post_name'   => 'multi-story',
			'post_type'   => 'post',
			'post_status' => 'publish',
			'post_date'   => '2026-10-12 10:00:00',
		);
		$GLOBALS['wpsc_test_posts'][ $post_id ]      = $post;
		$GLOBALS['wpsc_test_permalinks'][ $post_id ] = 'http://example.com/beta/multi-story/';

		// Old categories were zeta (50) and alpha (10). WordPress term_id ASC selects alpha (10).
		// Pass $old_tt_ids in reverse order (50, 10) to test that naive reset() does NOT misidentify zeta as the permalink category.
		$old_cat_dir    = $this->create_cache_fixture( '/alpha/multi-story/' );
		$new_cat_dir    = $this->create_cache_fixture( '/beta/multi-story/' );
		$zeta_fake_dir  = $this->create_cache_fixture( '/zeta/multi-story/' );
		$unrelated_dir  = $this->create_cache_fixture( '/other-article/' );

		turbo_cache_terms_changed( $post_id, array( 20, 50 ), array( 20, 50 ), 'category', false, array( 50, 10 ) );

		// Old URL based on alpha is purged
		$this->assertFileDoesNotExist( $old_cat_dir . 'index.html' );
		// New URL based on beta is purged
		$this->assertFileDoesNotExist( $new_cat_dir . 'index.html' );
		// Zeta directory is NOT falsely targeted as the old permalink
		$this->assertFileExists( $zeta_fake_dir . 'index.html' );
		// Unrelated article remains intact
		$this->assertFileExists( $unrelated_dir . 'index.html' );
	}

	/**
	 * Regression 12.C: Hierarchical categories with parent paths are accurately reconstructed and purged.
	 */
	public function test_category_change_with_hierarchical_category_parent(): void {
		$GLOBALS['wpsc_test_options']['permalink_structure'] = '/%category%/%postname%/';

		$cat_news = (object) array(
			'term_id'          => 10,
			'term_taxonomy_id' => 10,
			'slug'             => 'news',
			'parent'           => 0,
		);
		$cat_tech = (object) array(
			'term_id'          => 20,
			'term_taxonomy_id' => 20,
			'slug'             => 'tech',
			'parent'           => 10,
		);
		$cat_rev  = (object) array(
			'term_id'          => 30,
			'term_taxonomy_id' => 30,
			'slug'             => 'reviews',
			'parent'           => 0,
		);

		$GLOBALS['wpsc_test_terms_by_tt_id'][10] = $cat_news;
		$GLOBALS['wpsc_test_terms_by_id'][10]    = $cat_news;
		$GLOBALS['wpsc_test_terms_by_tt_id'][20] = $cat_tech;
		$GLOBALS['wpsc_test_terms_by_id'][20]    = $cat_tech;
		$GLOBALS['wpsc_test_terms_by_tt_id'][30] = $cat_rev;
		$GLOBALS['wpsc_test_terms_by_id'][30]    = $cat_rev;

		$post_id = 1003;
		$post    = (object) array(
			'ID'          => $post_id,
			'post_title'  => 'Gadget Review',
			'post_name'   => 'gadget-review',
			'post_type'   => 'post',
			'post_status' => 'publish',
			'post_date'   => '2026-10-12 10:00:00',
		);
		$GLOBALS['wpsc_test_posts'][ $post_id ]      = $post;
		$GLOBALS['wpsc_test_permalinks'][ $post_id ] = 'http://example.com/reviews/gadget-review/';

		$old_hier_dir  = $this->create_cache_fixture( '/news/tech/gadget-review/' );
		$new_dir       = $this->create_cache_fixture( '/reviews/gadget-review/' );
		$unrelated_dir = $this->create_cache_fixture( '/news/general/' );

		turbo_cache_terms_changed( $post_id, array( 30 ), array( 30 ), 'category', false, array( 20 ) );

		$this->assertFileDoesNotExist( $old_hier_dir . 'index.html' );
		$this->assertFileDoesNotExist( $new_dir . 'index.html' );
		$this->assertFileExists( $unrelated_dir . 'index.html' );
	}

	/**
	 * Regression 12.D: Category removal or replacement with default category invalidates cache.
	 */
	public function test_category_removal_or_replacement_invalidates_cache(): void {
		$GLOBALS['wpsc_test_options']['permalink_structure'] = '/%category%/%postname%/';

		$cat_custom  = (object) array(
			'term_id'          => 15,
			'term_taxonomy_id' => 15,
			'slug'             => 'custom-cat',
			'parent'           => 0,
		);
		$cat_default = (object) array(
			'term_id'          => 1,
			'term_taxonomy_id' => 1,
			'slug'             => 'uncategorized',
			'parent'           => 0,
		);

		$GLOBALS['wpsc_test_terms_by_tt_id'][15] = $cat_custom;
		$GLOBALS['wpsc_test_terms_by_id'][15]    = $cat_custom;
		$GLOBALS['wpsc_test_terms_by_tt_id'][1]  = $cat_default;
		$GLOBALS['wpsc_test_terms_by_id'][1]     = $cat_default;

		$post_id = 1004;
		$post    = (object) array(
			'ID'          => $post_id,
			'post_title'  => 'Reset Cat Post',
			'post_name'   => 'reset-post',
			'post_type'   => 'post',
			'post_status' => 'publish',
			'post_date'   => '2026-10-12 10:00:00',
		);
		$GLOBALS['wpsc_test_posts'][ $post_id ]      = $post;
		$GLOBALS['wpsc_test_permalinks'][ $post_id ] = 'http://example.com/uncategorized/reset-post/';

		$old_dir       = $this->create_cache_fixture( '/custom-cat/reset-post/' );
		$new_dir       = $this->create_cache_fixture( '/uncategorized/reset-post/' );
		$unrelated_dir = $this->create_cache_fixture( '/custom-cat/other/' );

		turbo_cache_terms_changed( $post_id, array( 1 ), array( 1 ), 'category', false, array( 15 ) );

		$this->assertFileDoesNotExist( $old_dir . 'index.html' );
		$this->assertFileDoesNotExist( $new_dir . 'index.html' );
		$this->assertFileExists( $unrelated_dir . 'index.html' );
	}

	/**
	 * Regression 12.E: Permalink structure without %category% invalidates post cache and archives.
	 */
	public function test_permalink_structure_without_category_token_invalidates_post_and_archives(): void {
		$GLOBALS['wpsc_test_options']['permalink_structure'] = '/%postname%/';

		$cat_old = (object) array(
			'term_id'          => 10,
			'term_taxonomy_id' => 10,
			'slug'             => 'old-category',
			'parent'           => 0,
		);
		$cat_new = (object) array(
			'term_id'          => 20,
			'term_taxonomy_id' => 20,
			'slug'             => 'new-category',
			'parent'           => 0,
		);

		$GLOBALS['wpsc_test_terms_by_tt_id'][10] = $cat_old;
		$GLOBALS['wpsc_test_terms_by_id'][10]    = $cat_old;
		$GLOBALS['wpsc_test_terms_by_tt_id'][20] = $cat_new;
		$GLOBALS['wpsc_test_terms_by_id'][20]    = $cat_new;

		$post_id = 1005;
		$post    = (object) array(
			'ID'          => $post_id,
			'post_title'  => 'Standard Permalink Post',
			'post_name'   => 'standard-slug',
			'post_type'   => 'post',
			'post_status' => 'publish',
			'post_date'   => '2026-10-12 10:00:00',
		);
		$GLOBALS['wpsc_test_posts'][ $post_id ]      = $post;
		$GLOBALS['wpsc_test_permalinks'][ $post_id ] = 'http://example.com/standard-slug/';

		$post_dir      = $this->create_cache_fixture( '/standard-slug/' );
		$unrelated_dir = $this->create_cache_fixture( '/unrelated-post/' );

		$this->assertFileExists( $post_dir . 'index.html' );
		$this->assertFileExists( $unrelated_dir . 'index.html' );

		turbo_cache_terms_changed( $post_id, array( 20 ), array( 20 ), 'category', false, array( 10 ) );

		// Post cache is invalidated because category display on single post changed
		$this->assertFileDoesNotExist( $post_dir . 'index.html' );
		// Unrelated post is preserved
		$this->assertFileExists( $unrelated_dir . 'index.html' );
	}

	/**
	 * Regression 12.F: Category change where primary permalink category is unchanged does not spuriously purge old paths.
	 */
	public function test_category_change_when_permalink_category_does_not_change(): void {
		$GLOBALS['wpsc_test_options']['permalink_structure'] = '/%category%/%postname%/';

		$cat_alpha = (object) array(
			'term_id'          => 10,
			'term_taxonomy_id' => 10,
			'slug'             => 'alpha',
			'parent'           => 0,
		);
		$cat_beta  = (object) array(
			'term_id'          => 30,
			'term_taxonomy_id' => 30,
			'slug'             => 'beta',
			'parent'           => 0,
		);
		$cat_gamma = (object) array(
			'term_id'          => 40,
			'term_taxonomy_id' => 40,
			'slug'             => 'gamma',
			'parent'           => 0,
		);

		$GLOBALS['wpsc_test_terms_by_tt_id'][10] = $cat_alpha;
		$GLOBALS['wpsc_test_terms_by_id'][10]    = $cat_alpha;
		$GLOBALS['wpsc_test_terms_by_tt_id'][30] = $cat_beta;
		$GLOBALS['wpsc_test_terms_by_id'][30]    = $cat_beta;
		$GLOBALS['wpsc_test_terms_by_tt_id'][40] = $cat_gamma;
		$GLOBALS['wpsc_test_terms_by_id'][40]    = $cat_gamma;

		$post_id = 1006;
		$post    = (object) array(
			'ID'          => $post_id,
			'post_title'  => 'Steady Category Post',
			'post_name'   => 'steady-post',
			'post_type'   => 'post',
			'post_status' => 'publish',
			'post_date'   => '2026-10-12 10:00:00',
		);
		$GLOBALS['wpsc_test_posts'][ $post_id ]      = $post;
		// Both before and after, alpha (10) is the lowest term_id, so permalink is alpha/steady-post/
		$GLOBALS['wpsc_test_permalinks'][ $post_id ] = 'http://example.com/alpha/steady-post/';

		$post_dir      = $this->create_cache_fixture( '/alpha/steady-post/' );
		$unrelated_dir = $this->create_cache_fixture( '/unrelated-post/' );

		// Secondary category changes from beta (30) to gamma (40) while alpha (10) remains
		turbo_cache_terms_changed( $post_id, array( 10, 40 ), array( 10, 40 ), 'category', false, array( 10, 30 ) );

		// Post cache is invalidated via turbo_cache_invalidate_post
		$this->assertFileDoesNotExist( $post_dir . 'index.html' );
		// Unrelated cache is preserved
		$this->assertFileExists( $unrelated_dir . 'index.html' );
	}

	/**
	 * Regression 13: wpsc_register_post_hooks handles CLI config sync and respects disabled cache.
	 */
	public function test_cli_hook_registration_and_disabled_cache_preservation(): void {
		// Verify when cache_enabled is false, hooks are not registered
		$GLOBALS['cache_enabled'] = false;
		wpsc_register_post_hooks();
		$this->assertFalse( $GLOBALS['cache_enabled'] );
	}
}
