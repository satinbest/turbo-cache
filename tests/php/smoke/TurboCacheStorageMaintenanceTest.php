<?php
/**
 * Smoke tests for Turbo Cache lifecycle and storage maintenance layer.
 *
 * @package Turbo_Cache
 */

require_once dirname( __DIR__, 3 ) . '/inc/cache-maintenance.php';

if ( ! function_exists( 'trailingslashit' ) ) {
	function trailingslashit( $string ) {
		return rtrim( (string) $string, '/\\' ) . '/';
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
		touch( $file, time() ); // fresh mtime

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
		touch( $fresh, time() ); // fresh

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
}
