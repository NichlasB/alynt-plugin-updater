<?php
/**
 * PHPUnit bootstrap file.
 *
 * @package AlyntPluginUpdater
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/../vendor/autoload.php';

require_once __DIR__ . '/../includes/class-loader.php';

Alynt\PluginUpdater\Loader::register();
