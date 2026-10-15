<?php
/**
 * Smoke tests for Turbo Cache lifecycle and storage maintenance layer.
 *
 * @package Turbo_Cache
 */

// phpcs:disable WordPress.WP.AlternativeFunctions
// phpcs:disable WordPress.PHP.NoSilencedErrors.Discouraged

require_once dirname( __DIR__, 3 ) . '/inc/cache-maintenance.php';

// phpcs:disable Universal.Files.SeparateFunctionsFromOO.Mixed

if ( ! function_exists( 'trailingslashit' ) ) {
	/**
	 * Polyfill trailingslashit for standalone test environment.
	 *
	 * @param string $path_or_url Input path.
	 * @return string Trailing-slashed path.
	 */
	function trailingslashit( $path_or_url ) {
		return rtrim( (string) $path_or_url, '/\\' ) . '/';
	}
}

use PHPUnit\Framework\TestCase;

/**
 * Class TurboCacheStorageMaintenanceTest
 *
 * @covers ::turbo_cache_is_safe_maintenance_target
 * @covers ::turbo_cache_is_dir_empty
 * @covers ::turbo_cache_prune_storage
 * @covers ::turbo_cache_run_maintenance
 */
class TurboCacheStorageMaintenanceTest extends TestCase {

	/**
	 * Temporary test directory serving as simulated cache root.
	 *
	 * @var string
	 */
	private $test_cache_root;

	/**
	 * Set up test directory and environment before each test.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->test_cache_root = sys_get_temp_dir() . '/tc_maint_test_' . uniqid() . '/';
		mkdir( $this->test_cache_root . 'supercache', 0777, true );
		$GLOBALS['cache_path'] = $this->test_cache_root;
	}

	/**
	 * Tear down test environment after each test.
	 */
	protected function tearDown(): void {
		if ( is_dir( $this->test_cache_root ) ) {
			$this->recursive_remove_dir( $this->test_cache_root );
		}
		unset( $GLOBALS['cache_path'] );
		parent::tearDown();
	}

	/**
	 * Helper to recursively remove a test directory.
	 *
	 * @param string $dir Directory to remove.
	 */
	private function recursive_remove_dir( $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$files = scandir( $dir );
		if ( is_array( $files ) ) {
			foreach ( $files as $file ) {
				if ( '.' === $file || '..' === $file ) {
					continue;
				}
				$path = $dir . '/' . $file;
				if ( is_dir( $path ) ) {
					$this->recursive_remove_dir( $path );
				} else {
					@unlink( $path );
				}
			}
		}
		@rmdir( $dir );
	}

	/**
	 * Test target validation within valid cache directory.
	 */
	public function test_is_safe_maintenance_target_within_cache(): void {
		$safe_file = $this->test_cache_root . 'supercache/localhost/sample-page/index.html';
		$safe_dir  = $this->test_cache_root . 'supercache/localhost/sample-page/';

		$this->assertTrue( turbo_cache_is_safe_maintenance_target( $safe_file, $this->test_cache_root ) );
		$this->assertTrue( turbo_cache_is_safe_maintenance_target( $safe_dir, $this->test_cache_root ) );
	}

	/**
	 * Test target validation rejects directory traversal and out-of-bounds targets.
	 */
	public function test_is_safe_maintenance_target_traversal_and_out_of_bounds(): void {
		$traversal = $this->test_cache_root . 'supercache/../outside.txt';
		$outside   = sys_get_temp_dir() . '/unauthorized_system_file.txt';

		$this->assertFalse( turbo_cache_is_safe_maintenance_target( $traversal, $this->test_cache_root ) );
		$this->assertFalse( turbo_cache_is_safe_maintenance_target( $outside, $this->test_cache_root ) );
		$this->assertFalse( turbo_cache_is_safe_maintenance_target( '', $this->test_cache_root ) );
	}

	/**
	 * Test root guard files (.htaccess, index.html) at root levels are protected from pruning.
	 */
	public function test_is_safe_maintenance_target_root_guard_files_protected(): void {
		$root_htaccess = $this->test_cache_root . '.htaccess';
		$root_index    = $this->test_cache_root . 'index.html';
		$super_index   = $this->test_cache_root . 'supercache/index.html';

		$this->assertFalse( turbo_cache_is_safe_maintenance_target( $root_htaccess, $this->test_cache_root ) );
		$this->assertFalse( turbo_cache_is_safe_maintenance_target( $root_index, $this->test_cache_root ) );
		$this->assertFalse( turbo_cache_is_safe_maintenance_target( $super_index, $this->test_cache_root ) );

		// Normal cached index.html under a page directory MUST be pruneable.
		$page_index = $this->test_cache_root . 'supercache/localhost/page-1/index.html';
		$this->assertTrue( turbo_cache_is_safe_maintenance_target( $page_index, $this->test_cache_root ) );
	}

	/**
	 * Test structural directories (root, supercache root) are protected from deletion.
	 */
	public function test_is_safe_maintenance_target_structural_roots_protected(): void {
		$this->assertFalse( turbo_cache_is_safe_maintenance_target( $this->test_cache_root, $this->test_cache_root ) );
		$this->assertFalse( turbo_cache_is_safe_maintenance_target( $this->test_cache_root . 'supercache', $this->test_cache_root ) );
	}

	/**
	 * Test turbo_cache_is_dir_empty correctly identifies empty vs populated directories.
	 */
	public function test_is_dir_empty(): void {
		$empty_dir = $this->test_cache_root . 'empty_sub';
		mkdir( $empty_dir );
		$this->assertTrue( turbo_cache_is_dir_empty( $empty_dir ) );

		$populated_dir = $this->test_cache_root . 'populated_sub';
		mkdir( $populated_dir );
		file_put_contents( $populated_dir . '/file.txt', 'data' );
		$this->assertFalse( turbo_cache_is_dir_empty( $populated_dir ) );

		$this->assertFalse( turbo_cache_is_dir_empty( $this->test_cache_root . 'non_existent' ) );
	}

	/**
	 * Test valid fresh cache files are preserved and NOT unlinked.
	 */
	public function test_prune_storage_preserves_fresh_files(): void {
		$page_dir = $this->test_cache_root . 'supercache/localhost/fresh-page/';
		mkdir( $page_dir, 0777, true );
		$file = $page_dir . 'index.html';
		file_put_contents( $file, '<html>Fresh Cache</html>' );
		touch( $file, time() ); // Fresh mtime.

		$metrics = turbo_cache_prune_storage(
			$this->test_cache_root . 'supercache',
			array(
				'now'     => time(),
				'max_age' => 3600,
			)
		);

		$this->assertSame( 0, $metrics['deleted_files'] );
		$this->assertSame( 0, $metrics['deleted_dirs'] );
		$this->assertFileExists( $file, 'Valid fresh cache file must be preserved.' );
		$this->assertDirectoryExists( $page_dir );
	}

	/**
	 * Test expired cache files are accurately identified and unlinked.
	 */
	public function test_prune_storage_removes_expired_files(): void {
		$page_dir = $this->test_cache_root . 'supercache/localhost/expired-page/';
		mkdir( $page_dir, 0777, true );
		$file = $page_dir . 'index.html';
		file_put_contents( $file, '<html>Expired Cache</html>' );
		touch( $file, time() - 7200 ); // 2 hours old

		$metrics = turbo_cache_prune_storage(
			$this->test_cache_root . 'supercache',
			array(
				'now'     => time(),
				'max_age' => 3600,
			)
		);

		$this->assertSame( 1, $metrics['deleted_files'] );
		$this->assertFileDoesNotExist( $file );
	}

	/**
	 * Test clean_empty_dirs removes parent directories after their child files expire.
	 */
	public function test_prune_storage_cleans_empty_directories(): void {
		$page_dir = $this->test_cache_root . 'supercache/localhost/clean-dir-page/';
		mkdir( $page_dir, 0777, true );
		$file1 = $page_dir . 'index.html';
		$file2 = $page_dir . 'index.html.gz';
		file_put_contents( $file1, '<html>Expired</html>' );
		file_put_contents( $file2, 'gzdata' );
		touch( $file1, time() - 5000 );
		touch( $file2, time() - 5000 );

		$metrics = turbo_cache_prune_storage(
			$this->test_cache_root . 'supercache',
			array(
				'now'              => time(),
				'max_age'          => 3600,
				'clean_empty_dirs' => true,
			)
		);

		$this->assertSame( 2, $metrics['deleted_files'] );
		$this->assertGreaterThanOrEqual( 1, $metrics['deleted_dirs'] );
		$this->assertDirectoryDoesNotExist( $page_dir, 'Empty directory must be removed after child files expire.' );
	}

	/**
	 * Test directories containing at least one fresh file are NOT removed.
	 */
	public function test_prune_storage_preserves_populated_directories(): void {
		$page_dir = $this->test_cache_root . 'supercache/localhost/mixed-page/';
		mkdir( $page_dir, 0777, true );
		$expired_file = $page_dir . 'index.html';
		$fresh_file   = $page_dir . 'index-https.html';
		file_put_contents( $expired_file, '<html>Expired</html>' );
		file_put_contents( $fresh_file, '<html>Fresh</html>' );
		touch( $expired_file, time() - 5000 );
		touch( $fresh_file, time() );

		$metrics = turbo_cache_prune_storage(
			$this->test_cache_root . 'supercache',
			array(
				'now'              => time(),
				'max_age'          => 3600,
				'clean_empty_dirs' => true,
			)
		);

		$this->assertSame( 1, $metrics['deleted_files'] );
		$this->assertFileDoesNotExist( $expired_file );
		$this->assertFileExists( $fresh_file );
		$this->assertDirectoryExists( $page_dir, 'Directory must remain because it contains an active fresh file.' );
	}

	/**
	 * Test abandoned temporary files (.tmp) older than threshold are cleaned.
	 */
	public function test_prune_storage_cleans_stale_temp_files(): void {
		$page_dir = $this->test_cache_root . 'supercache/localhost/tmp-page/';
		mkdir( $page_dir, 0777, true );
		$stale_tmp = $page_dir . 'abandoned_process.tmp';
		file_put_contents( $stale_tmp, 'partial write data' );
		touch( $stale_tmp, time() - 600 ); // 10 minutes old

		$metrics = turbo_cache_prune_storage(
			$this->test_cache_root . 'supercache',
			array(
				'now'             => time(),
				'tmp_max_age'     => 300,
				'clean_stale_tmp' => true,
			)
		);

		$this->assertSame( 1, $metrics['deleted_tmp'] );
		$this->assertFileDoesNotExist( $stale_tmp );
	}

	/**
	 * Test recently created temporary files (under active write) are preserved.
	 */
	public function test_prune_storage_preserves_recent_temp_files(): void {
		$page_dir = $this->test_cache_root . 'supercache/localhost/active-tmp-page/';
		mkdir( $page_dir, 0777, true );
		$recent_tmp = $page_dir . 'active_write.tmp';
		file_put_contents( $recent_tmp, 'writing now' );
		touch( $recent_tmp, time() - 10 ); // 10 seconds old

		$metrics = turbo_cache_prune_storage(
			$this->test_cache_root . 'supercache',
			array(
				'now'             => time(),
				'tmp_max_age'     => 300,
				'clean_stale_tmp' => true,
			)
		);

		$this->assertSame( 0, $metrics['deleted_tmp'] );
		$this->assertFileExists( $recent_tmp, 'Recent temp files must not be pruned while active.' );
	}

	/**
	 * Test bounding: max_files limit halts pruning when threshold is reached.
	 */
	public function test_prune_storage_respects_max_files_limit(): void {
		$page_dir = $this->test_cache_root . 'supercache/localhost/bulk-page/';
		mkdir( $page_dir, 0777, true );

		for ( $i = 1; $i <= 10; ++$i ) {
			$f = $page_dir . "index_{$i}.html";
			file_put_contents( $f, "data $i" );
			touch( $f, time() - 5000 );
		}

		$metrics = turbo_cache_prune_storage(
			$this->test_cache_root . 'supercache',
			array(
				'now'       => time(),
				'max_age'   => 3600,
				'max_files' => 4,
			)
		);

		$this->assertSame( 4, $metrics['deleted_files'] );
		$this->assertTrue( $metrics['hit_limit'] );

		// Remaining files check: 6 files must still exist.
		$remaining = glob( $page_dir . '*.html' );
		$this->assertCount( 6, $remaining );
	}

	/**
	 * Test force mode deletes cache files regardless of modification time.
	 */
	public function test_prune_storage_force_mode(): void {
		$page_dir = $this->test_cache_root . 'supercache/localhost/force-page/';
		mkdir( $page_dir, 0777, true );
		$fresh = $page_dir . 'index.html';
		file_put_contents( $fresh, 'Fresh content' );
		touch( $fresh, time() ); // Fresh.

		$metrics = turbo_cache_prune_storage(
			$this->test_cache_root . 'supercache',
			array(
				'now'   => time(),
				'force' => true,
			)
		);

		$this->assertSame( 1, $metrics['deleted_files'] );
		$this->assertFileDoesNotExist( $fresh );
	}

	/**
	 * Test prune_super_cache integrates cleanly with Turbo Cache maintenance.
	 */
	public function test_prune_super_cache_integration(): void {
		if ( ! function_exists( 'prune_super_cache' ) ) {
			require_once dirname( __DIR__, 3 ) . '/wp-cache-phase2.php';
		}

		$page_dir = $this->test_cache_root . 'supercache/localhost/phase2-page/';
		mkdir( $page_dir, 0777, true );
		$file = $page_dir . 'index.html';
		file_put_contents( $file, 'Expired' );
		touch( $file, time() - 7200 );

		$deleted_count = prune_super_cache( $this->test_cache_root . 'supercache', false, false );

		$this->assertGreaterThanOrEqual( 1, $deleted_count );
		$this->assertFileDoesNotExist( $file );
	}

	/**
	 * Test force=true removes fresh cache files as part of a full cache purge.
	 */
	public function test_prune_super_cache_force_true_removes_fresh_files(): void {
		if ( ! function_exists( 'prune_super_cache' ) ) {
			require_once dirname( __DIR__, 3 ) . '/wp-cache-phase2.php';
		}

		$page_dir = $this->test_cache_root . 'supercache/localhost/force-purge/';
		mkdir( $page_dir, 0777, true );

		$fresh_html = $page_dir . 'index.html';
		$fresh_gz   = $page_dir . 'index.html.gz';
		$fresh_meta = $page_dir . 'index.meta';
		$tmp_file   = $page_dir . 'write.tmp';

		file_put_contents( $fresh_html, 'Fresh HTML' );
		file_put_contents( $fresh_gz, 'Fresh GZ' );
		file_put_contents( $fresh_meta, 'Fresh META' );
		file_put_contents( $tmp_file, 'Temporary write' );

		touch( $fresh_html, time() );
		touch( $fresh_gz, time() );
		touch( $fresh_meta, time() );
		touch( $tmp_file, time() );

		$deleted_count = prune_super_cache( $page_dir, true, false );

		$this->assertGreaterThanOrEqual( 4, $deleted_count );
		$this->assertFileDoesNotExist( $fresh_html );
		$this->assertFileDoesNotExist( $fresh_gz );
		$this->assertFileDoesNotExist( $fresh_meta );
		$this->assertFileDoesNotExist( $tmp_file );
		$this->assertDirectoryDoesNotExist( $page_dir );
	}

	/**
	 * Test force=false preserves fresh cache files and only removes expired files.
	 */
	public function test_prune_super_cache_force_false_preserves_fresh_files(): void {
		if ( ! function_exists( 'prune_super_cache' ) ) {
			require_once dirname( __DIR__, 3 ) . '/wp-cache-phase2.php';
		}

		$page_dir = $this->test_cache_root . 'supercache/localhost/force-false-page/';
		mkdir( $page_dir, 0777, true );

		$fresh_file   = $page_dir . 'index.html';
		$expired_file = $page_dir . 'index-https.html';

		file_put_contents( $fresh_file, 'Fresh' );
		file_put_contents( $expired_file, 'Expired' );

		touch( $fresh_file, time() );
		touch( $expired_file, time() - 7200 );

		$deleted_count = prune_super_cache( $page_dir, false, false );

		$this->assertGreaterThanOrEqual( 1, $deleted_count );
		$this->assertFileExists( $fresh_file, 'Fresh cache file must not be removed when force=false.' );
		$this->assertFileDoesNotExist( $expired_file, 'Expired cache file must be removed when force=false.' );
		$this->assertDirectoryExists( $page_dir, 'Directory must remain because fresh file is still active.' );
	}

	/**
	 * Test rename=true falls through to legacy rebuild/rename behavior.
	 */
	public function test_prune_super_cache_rename_true_legacy_behavior(): void {
		if ( ! function_exists( 'prune_super_cache' ) ) {
			require_once dirname( __DIR__, 3 ) . '/wp-cache-phase2.php';
		}

		$GLOBALS['cache_rebuild_files'] = 1;
		$GLOBALS['file_prefix']         = 'wp-cache-';
		$GLOBALS['blog_cache_dir']      = $this->test_cache_root;

		$page_dir = $this->test_cache_root . 'supercache/localhost/rename-test/';
		mkdir( $page_dir, 0777, true );

		$file = $page_dir . 'index.html';
		file_put_contents( $file, 'Cache for rebuild' );
		touch( $file, time() - 7200 );

		$rebuild_file = $page_dir . 'index.html.needs-rebuild';

		prune_super_cache( $page_dir, false, true );

		$this->assertFileDoesNotExist( $file, 'Original index.html must be renamed.' );
		$this->assertFileExists( $rebuild_file, 'File must be renamed to .needs-rebuild under legacy rename=true.' );

		unset( $GLOBALS['cache_rebuild_files'], $GLOBALS['file_prefix'], $GLOBALS['blog_cache_dir'] );
	}

	/**
	 * Test bounded pruning processes more than 500 eligible files across multiple runs.
	 */
	public function test_bounded_pruning_processes_over_500_files_across_runs(): void {
		$target_dir = $this->test_cache_root . 'supercache/localhost/bulk-600/';
		mkdir( $target_dir . 'sub_a', 0777, true );
		mkdir( $target_dir . 'sub_b', 0777, true );

		$total_created = 600;
		for ( $i = 1; $i <= 300; ++$i ) {
			$fa = $target_dir . "sub_a/index_{$i}.html";
			$fb = $target_dir . "sub_b/index_{$i}.html";
			file_put_contents( $fa, 'test' );
			file_put_contents( $fb, 'test' );
			touch( $fa, time() - 7200 );
			touch( $fb, time() - 7200 );
		}

		// Run 1: bound at 400 deletions.
		$run1 = turbo_cache_prune_storage(
			$target_dir,
			array(
				'now'              => time(),
				'max_age'          => 3600,
				'max_files'        => 400,
				'clean_empty_dirs' => false,
			)
		);

		$this->assertSame( 400, $run1['deleted_files'] );
		$this->assertTrue( $run1['hit_limit'] );

		// Run 2: resume and bound at 400 deletions.
		$run2 = turbo_cache_prune_storage(
			$target_dir,
			array(
				'now'              => time(),
				'max_age'          => 3600,
				'max_files'        => 400,
				'clean_empty_dirs' => true,
			)
		);

		$this->assertSame( 200, $run2['deleted_files'] );
		$this->assertFalse( $run2['hit_limit'] );

		$total_deleted = $run1['deleted_files'] + $run2['deleted_files'];
		$this->assertSame( $total_created, $total_deleted, 'All 600 expired files must be deleted across runs.' );
		$this->assertDirectoryDoesNotExist( $target_dir . 'sub_a' );
		$this->assertDirectoryDoesNotExist( $target_dir . 'sub_b' );
	}

	/**
	 * Test time-budget termination and subsequent recovery without skipping files.
	 */
	public function test_time_budget_termination_and_subsequent_recovery(): void {
		$target_dir = $this->test_cache_root . 'supercache/localhost/timeout-recovery/';
		mkdir( $target_dir, 0777, true );

		for ( $i = 1; $i <= 30; ++$i ) {
			$f = $target_dir . "item_{$i}.html";
			file_put_contents( $f, "data {$i}" );
			touch( $f, time() - 7200 );
		}

		// Run 1: extremely tiny time limit to force timeout.
		$run1 = turbo_cache_prune_storage(
			$target_dir,
			array(
				'now'              => time(),
				'max_age'          => 3600,
				'time_limit'       => 0.000001,
				'clean_empty_dirs' => false,
			)
		);

		$this->assertTrue( $run1['timed_out'], 'Run 1 must report timed_out when budget is exhausted.' );

		// Run 2: standard time limit to complete remaining work.
		$run2 = turbo_cache_prune_storage(
			$target_dir,
			array(
				'now'              => time(),
				'max_age'          => 3600,
				'time_limit'       => 15.0,
				'clean_empty_dirs' => true,
			)
		);

		$this->assertFalse( $run2['timed_out'], 'Run 2 must complete without timing out.' );
		$total_deleted = $run1['deleted_files'] + $run2['deleted_files'];
		$this->assertSame( 30, $total_deleted, 'All 30 items must be deleted across runs.' );
		$this->assertDirectoryDoesNotExist( $target_dir );
	}

	/**
	 * Test active temporary files are preserved even with aggressive cache max_age.
	 */
	public function test_active_temp_files_preserved_under_aggressive_cache_max_age(): void {
		$page_dir = $this->test_cache_root . 'supercache/localhost/temp-guard/';
		mkdir( $page_dir, 0777, true );

		$active_tmp = $page_dir . 'writing_now.tmp';
		$stale_tmp  = $page_dir . 'abandoned.tmp';

		file_put_contents( $active_tmp, 'in-flight data' );
		file_put_contents( $stale_tmp, 'stale partial data' );

		touch( $active_tmp, time() - 15 );  // 15 seconds old
		touch( $stale_tmp, time() - 700 );   // ~11 minutes old

		$metrics = turbo_cache_prune_storage(
			$page_dir,
			array(
				'now'             => time(),
				'max_age'         => 5,     // Very small cache TTL: 5 seconds.
				'tmp_max_age'     => 300,   // Temp threshold: 5 minutes.
				'clean_stale_tmp' => true,
			)
		);

		$this->assertSame( 1, $metrics['deleted_tmp'] );
		$this->assertFileDoesNotExist( $stale_tmp, 'Stale temp file must be deleted.' );
		$this->assertFileExists( $active_tmp, 'Active temp file must NOT be deleted even when cache max_age is 5s.' );
	}

	/**
	 * Test root guard files and structural directories are preserved under forced purge.
	 */
	public function test_root_guard_files_and_structural_roots_preserved_under_force(): void {
		$root_htaccess = $this->test_cache_root . '.htaccess';
		$root_index    = $this->test_cache_root . 'index.html';
		$super_index   = $this->test_cache_root . 'supercache/index.html';
		$meta_dir      = $this->test_cache_root . 'meta/';
		$meta_index    = $meta_dir . 'index.html';
		$blogs_dir     = $this->test_cache_root . 'blogs/';

		mkdir( $meta_dir, 0777, true );
		mkdir( $blogs_dir, 0777, true );

		file_put_contents( $root_htaccess, 'Deny from all' );
		file_put_contents( $root_index, '<!-- root guard -->' );
		file_put_contents( $super_index, '<!-- supercache guard -->' );
		file_put_contents( $meta_index, '<!-- meta guard -->' );

		// Add a deletable child cache file under supercache.
		$page_dir   = $this->test_cache_root . 'supercache/localhost/some-page/';
		$page_cache = $page_dir . 'index.html';
		mkdir( $page_dir, 0777, true );
		file_put_contents( $page_cache, 'Page data' );

		// Run forced purge on root.
		$metrics = turbo_cache_prune_storage(
			$this->test_cache_root,
			array(
				'force' => true,
				'now'   => time(),
			)
		);

		// Structural files and directories MUST remain intact.
		$this->assertFileExists( $root_htaccess, 'Root .htaccess must NEVER be deleted.' );
		$this->assertFileExists( $root_index, 'Root index.html must NEVER be deleted.' );
		$this->assertFileExists( $super_index, 'supercache/index.html must NEVER be deleted.' );
		$this->assertFileExists( $meta_index, 'meta/index.html must NEVER be deleted.' );
		$this->assertDirectoryExists( $this->test_cache_root . 'supercache' );
		$this->assertDirectoryExists( $meta_dir );
		$this->assertDirectoryExists( $blogs_dir );

		// The child page cache must be deleted.
		$this->assertFileDoesNotExist( $page_cache );
	}

	/**
	 * Test multisite layout and direct-page cache safety validation.
	 */
	public function test_multisite_and_direct_pages_safety(): void {
		// 1. Multisite subsite target validation.
		$subsite_dir = $this->test_cache_root . 'blogs/2/sample-page/';
		mkdir( $subsite_dir, 0777, true );
		$subsite_cache = $subsite_dir . 'index.html';
		file_put_contents( $subsite_cache, 'Subsite data' );
		touch( $subsite_cache, time() - 7200 );

		$this->assertTrue( turbo_cache_is_safe_maintenance_target( $subsite_cache, $this->test_cache_root ) );
		$this->assertTrue( turbo_cache_is_safe_maintenance_target( $subsite_dir, $this->test_cache_root ) );

		// Structural blogs root is protected.
		$this->assertFalse( turbo_cache_is_safe_maintenance_target( $this->test_cache_root . 'blogs', $this->test_cache_root ) );

		// 2. Direct-page cache validation.
		if ( ! defined( 'ABSPATH' ) ) {
			define( 'ABSPATH', sys_get_temp_dir() . '/tc_simulated_abspath_' . uniqid() . '/' );
			mkdir( ABSPATH, 0777, true );
		}

		$GLOBALS['cached_direct_pages'] = array( '/landing-page/', '/promo/' );

		$safe_direct_page   = ABSPATH . 'landing-page/index.html';
		$unsafe_system_file = ABSPATH . 'wp-config.php';
		$unsafe_secret_file = ABSPATH . 'wp-content/secret.txt';

		$this->assertTrue( turbo_cache_is_safe_maintenance_target( $safe_direct_page, $this->test_cache_root ) );
		$this->assertFalse( turbo_cache_is_safe_maintenance_target( $unsafe_system_file, $this->test_cache_root ) );
		$this->assertFalse( turbo_cache_is_safe_maintenance_target( $unsafe_secret_file, $this->test_cache_root ) );
		$this->assertFalse( turbo_cache_is_safe_maintenance_target( ABSPATH, $this->test_cache_root ) );

		unset( $GLOBALS['cached_direct_pages'] );
	}

	/**
	 * Test symlink pointing outside authorized cache root is rejected and not traversed.
	 */
	public function test_symlink_safety_and_out_of_bounds_rejection(): void {
		$outside_dir = sys_get_temp_dir() . '/tc_outside_target_' . uniqid() . '/';
		mkdir( $outside_dir, 0777, true );
		$outside_file = $outside_dir . 'critical_data.txt';
		file_put_contents( $outside_file, 'Must not be touched' );

		$link_path = $this->test_cache_root . 'supercache/escape_link';

		// Test target validation rejects the outside target directly.
		$this->assertFalse( turbo_cache_is_safe_maintenance_target( $outside_file, $this->test_cache_root ) );
		$this->assertFalse( turbo_cache_is_safe_maintenance_target( $outside_dir, $this->test_cache_root ) );

		// If symlink creation succeeds, test pruning never traverses it.
		$symlink_created = false;
		if ( function_exists( 'symlink' ) ) {
			$symlink_created = @symlink( $outside_dir, $link_path );
		}

		if ( $symlink_created ) {
			$this->assertFalse( turbo_cache_is_safe_maintenance_target( $link_path, $this->test_cache_root ) );

			// Pruning cache root must not touch the outside target file.
			turbo_cache_prune_storage(
				$this->test_cache_root . 'supercache',
				array(
					'force' => true,
					'now'   => time(),
				)
			);

			$this->assertFileExists( $outside_file, 'File outside cache root must NEVER be deleted via symlink traversal.' );
			@unlink( $link_path );
		}

		$this->recursive_remove_dir( $outside_dir );
	}

	/**
	 * Test that a large nested directory tree respects the time budget throughout traversal.
	 */
	public function test_large_nested_directory_tree_stops_within_time_budget(): void {
		$target_dir = $this->test_cache_root . 'supercache/localhost/nested-budget-tree/';
		mkdir( $target_dir, 0777, true );

		// Create a branched nested hierarchy: 4 levels deep = 84+ directories with files.
		$dirs = array( $target_dir );
		for ( $level = 1; $level <= 4; ++$level ) {
			$new_dirs = array();
			foreach ( $dirs as $parent ) {
				for ( $b = 1; $b <= 3; ++$b ) {
					$d = $parent . "b{$b}/";
					mkdir( $d, 0777, true );
					$file = $d . 'index.html';
					file_put_contents( $file, 'test data' );
					touch( $file, time() - 7200 );
					$new_dirs[] = $d;
				}
			}
			$dirs = $new_dirs;
		}

		// Run with a tight time budget of 50ms (0.05s).
		$budget = 0.05;
		$run1   = turbo_cache_prune_storage(
			$target_dir,
			array(
				'now'              => time(),
				'max_age'          => 3600,
				'time_limit'       => $budget,
				'clean_empty_dirs' => false,
			)
		);

		$this->assertTrue( $run1['timed_out'], 'Traversal must terminate and set timed_out=true when budget is reached.' );
		$this->assertLessThan( 1.0, $run1['duration'], 'Duration must stop within reasonable tolerance of budget.' );
		$this->assertGreaterThan( 0, $run1['scanned_dirs'], 'Must have traversed at least some directories.' );

		// Subsequent run with normal budget resumes and completes cleaning the rest.
		$run2 = turbo_cache_prune_storage(
			$target_dir,
			array(
				'now'              => time(),
				'max_age'          => 3600,
				'time_limit'       => 15.0,
				'clean_empty_dirs' => true,
			)
		);

		$this->assertFalse( $run2['timed_out'], 'Subsequent recovery run must complete.' );
		$this->assertDirectoryDoesNotExist( $target_dir, 'Complete directory tree must be pruned after recovery run.' );
	}
}
