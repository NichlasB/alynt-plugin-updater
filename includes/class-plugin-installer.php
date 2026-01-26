<?php
/**
 * Plugin installer for GitHub updates.
 *
 * @package AlyntPluginUpdater
 */

namespace Alynt\PluginUpdater;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Plugin_Installer.
 */
class Plugin_Installer {
	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * Constructor.
	 *
	 * @param Logger $logger Logger.
	 */
	public function __construct( Logger $logger ) {
		$this->logger = $logger;
	}

	/**
	 * Install a plugin update from a download URL.
	 *
	 * @param string $plugin_file  Plugin file path.
	 * @param string $download_url Download URL.
	 * @return true|WP_Error True on success, WP_Error on failure.
	 */
	public function install_update( string $plugin_file, string $download_url ) {
		$plugin_slug = dirname( $plugin_file );
		if ( ! $this->acquire_lock( $plugin_slug ) ) {
			return new WP_Error( 'update_in_progress', __( 'Update already in progress for this plugin.', 'alynt-plugin-updater' ) );
		}

		$temp_file = null;
		$temp_dir  = null;
		$result    = true;

		do {
			$filesystem = $this->init_filesystem();
			if ( is_wp_error( $filesystem ) ) {
				$result = $filesystem;
				break;
			}

			if ( '' === $download_url ) {
				$result = new WP_Error( 'download_failed', __( 'Download URL is missing.', 'alynt-plugin-updater' ) );
				break;
			}

			$temp_file = download_url( $download_url );
			if ( is_wp_error( $temp_file ) ) {
				$result = new WP_Error( 'download_failed', $temp_file->get_error_message() );
				$this->logger->error( 'Download failed.', array( 'plugin' => $plugin_file, 'message' => $temp_file->get_error_message() ) );
				break;
			}

			$head = wp_remote_head( $download_url, array( 'timeout' => 15 ) );
			if ( ! is_wp_error( $head ) ) {
				$content_length = wp_remote_retrieve_header( $head, 'content-length' );
				if ( $content_length ) {
					$content_length = (int) $content_length;
					$filesize       = file_exists( $temp_file ) ? filesize( $temp_file ) : 0;
					if ( $filesize > 0 && $content_length > 0 && $filesize < $content_length ) {
						wp_delete_file( $temp_file );
						$result = new WP_Error( 'download_incomplete', __( 'Download incomplete. Please try again.', 'alynt-plugin-updater' ) );
						break;
					}
				}
			}

			$temp_dir = trailingslashit( get_temp_dir() ) . 'alynt_pu_' . uniqid();
			if ( ! $filesystem->mkdir( $temp_dir ) ) {
				$result = new WP_Error( 'filesystem_error', __( 'Could not create temporary directory.', 'alynt-plugin-updater' ) );
				break;
			}

			$unzipped = unzip_file( $temp_file, $temp_dir );
			if ( is_wp_error( $unzipped ) ) {
				$result = new WP_Error( 'extraction_failed', $unzipped->get_error_message() );
				$this->logger->error( 'Extraction failed.', array( 'plugin' => $plugin_file, 'message' => $unzipped->get_error_message() ) );
				break;
			}

			$extracted_folder = $this->find_extracted_folder( $temp_dir );
			if ( is_wp_error( $extracted_folder ) ) {
				$result = $extracted_folder;
				break;
			}

			$valid_structure = $this->validate_plugin_structure( $extracted_folder, $plugin_file );
			if ( is_wp_error( $valid_structure ) ) {
				$result = $valid_structure;
				break;
			}

			$plugin_dir = WP_PLUGIN_DIR . '/' . $plugin_slug;
			$backup_dir = '';

			if ( $filesystem->exists( $plugin_dir ) ) {
				$backup_dir = $plugin_dir . '-backup-' . time();
				if ( ! $filesystem->move( $plugin_dir, $backup_dir, true ) ) {
					$result = new WP_Error( 'move_failed', __( 'Could not backup existing plugin.', 'alynt-plugin-updater' ) );
					break;
				}
			}

			$move_result = $filesystem->move( $extracted_folder, $plugin_dir, true );
			if ( ! $move_result ) {
				if ( '' !== $backup_dir && $filesystem->exists( $backup_dir ) ) {
					$filesystem->move( $backup_dir, $plugin_dir, true );
				}

				$result = new WP_Error( 'move_failed', __( 'Could not move plugin to destination. Check file permissions.', 'alynt-plugin-updater' ) );
				$this->logger->error( 'Move failed.', array( 'plugin_dir' => $plugin_dir ) );
				break;
			}

			if ( '' !== $backup_dir && $filesystem->exists( $backup_dir ) ) {
				$filesystem->delete( $backup_dir, true );
			}
		} while ( false );

		$this->cleanup( $temp_file, $temp_dir );
		$this->release_lock( $plugin_slug );

		return $result;
	}

	/**
	 * Acquire update lock for a plugin.
	 *
	 * @param string $plugin_slug Plugin slug.
	 * @return bool True if lock acquired.
	 */
	private function acquire_lock( string $plugin_slug ): bool {
		$lock_key = 'alynt_pu_updating_' . sanitize_key( $plugin_slug );
		if ( get_transient( $lock_key ) ) {
			return false;
		}

		set_transient( $lock_key, time(), 5 * MINUTE_IN_SECONDS );

		return true;
	}

	/**
	 * Release update lock for a plugin.
	 *
	 * @param string $plugin_slug Plugin slug.
	 * @return void
	 */
	private function release_lock( string $plugin_slug ): void {
		delete_transient( 'alynt_pu_updating_' . sanitize_key( $plugin_slug ) );
	}

	/**
	 * Initialize WordPress Filesystem API.
	 *
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	private function init_filesystem() {
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		if ( ! WP_Filesystem() ) {
			return new WP_Error( 'filesystem_error', __( 'Could not initialize filesystem.', 'alynt-plugin-updater' ) );
		}

		global $wp_filesystem;

		return $wp_filesystem;
	}

	/**
	 * Find the main extracted folder.
	 *
	 * @param string $temp_dir Temporary directory.
	 * @return string|WP_Error Path or WP_Error.
	 */
	private function find_extracted_folder( string $temp_dir ) {
		global $wp_filesystem;

		$dirs = glob( $temp_dir . '/*', GLOB_ONLYDIR );
		if ( empty( $dirs ) ) {
			$files = glob( $temp_dir . '/*' );
			if ( empty( $files ) ) {
				return new WP_Error( 'empty_archive', __( 'No files found in the archive.', 'alynt-plugin-updater' ) );
			}

			$flat_dir = trailingslashit( $temp_dir ) . 'flat';
			$wp_filesystem->mkdir( $flat_dir );

			foreach ( $files as $file ) {
				$wp_filesystem->move( $file, trailingslashit( $flat_dir ) . basename( $file ), true );
			}

			return $flat_dir;
		}

		sort( $dirs );
		if ( 1 < count( $dirs ) ) {
			$this->logger->warning( 'Multiple directories found in archive.', array( 'directory' => $dirs[0] ) );
		}

		return $dirs[0];
	}

	/**
	 * Validate that extracted folder contains expected plugin structure.
	 *
	 * @param string $folder      Extracted folder path.
	 * @param string $plugin_file Plugin file path.
	 * @return bool|WP_Error True if valid.
	 */
	private function validate_plugin_structure( string $folder, string $plugin_file ) {
		$expected = trailingslashit( $folder ) . basename( $plugin_file );
		if ( ! file_exists( $expected ) ) {
			return new WP_Error( 'invalid_plugin_structure', __( 'Plugin main file not found in archive.', 'alynt-plugin-updater' ) );
		}

		return true;
	}

	/**
	 * Cleanup temporary files and directories.
	 *
	 * @param string|null $temp_file Temp file path.
	 * @param string|null $temp_dir  Temp directory path.
	 * @return void
	 */
	private function cleanup( ?string $temp_file, ?string $temp_dir ): void {
		global $wp_filesystem;

		if ( is_string( $temp_file ) && '' !== $temp_file && file_exists( $temp_file ) ) {
			wp_delete_file( $temp_file );
		}

		if ( $temp_dir && $wp_filesystem && $wp_filesystem->exists( $temp_dir ) ) {
			$wp_filesystem->delete( $temp_dir, true );
		}
	}
}
