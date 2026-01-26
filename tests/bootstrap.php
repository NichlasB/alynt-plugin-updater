<?php
/**
 * PHPUnit bootstrap file.
 *
 * @package AlyntPluginUpdater
 */

require_once __DIR__ . '/../vendor/autoload.php';

require_once __DIR__ . '/../includes/class-loader.php';

Alynt\PluginUpdater\Loader::register();
