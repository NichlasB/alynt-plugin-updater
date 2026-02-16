<?php
/**
 * Shared plugin configuration constants.
 *
 * @package AlyntPluginUpdater
 */

namespace Alynt\PluginUpdater;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Config.
 *
 * @since 1.0.0
 */
class Config {
	/**
	 * Cache duration option key.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public const CACHE_DURATION_OPTION = 'alynt_pu_cache_duration';

	/**
	 * Default cache duration in seconds.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	public const CACHE_DURATION_DEFAULT = 3600;

	/**
	 * Minimum allowed cache duration in seconds.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	public const CACHE_DURATION_MIN = 300;

	/**
	 * Maximum allowed cache duration in seconds.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	public const CACHE_DURATION_MAX = 86400;
}
