<?php
/**
 * Version utility helpers.
 *
 * @package AlyntPluginUpdater
 */

namespace Alynt\PluginUpdater;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Version_Util.
 */
class Version_Util {
	/**
	 * Normalize a version string by stripping a leading "v".
	 *
	 * @param string $version Version string.
	 * @return string Normalized version.
	 */
	public function normalize( string $version ): string {
		return ltrim( $version, "vV" );
	}

	/**
	 * Compare two versions after normalization.
	 *
	 * @param string $version1 First version.
	 * @param string $version2 Second version.
	 * @param string $operator Comparison operator.
	 * @return bool Comparison result.
	 */
	public function compare( string $version1, string $version2, string $operator = '>' ): bool {
		$version1 = $this->normalize( $version1 );
		$version2 = $this->normalize( $version2 );

		return version_compare( $version1, $version2, $operator );
	}

	/**
	 * Check if a remote version is newer.
	 *
	 * @param string $current_version Current version.
	 * @param string $remote_version  Remote version.
	 * @return bool True if update is available.
	 */
	public function is_update_available( string $current_version, string $remote_version ): bool {
		return $this->compare( $remote_version, $current_version, '>' );
	}
}
