<?php
/**
 * Print JSON lists of enabled, disabled, and available phpBB extension names.
 *
 * Input: installed board root in $argv[1]. The shared CLI bootstrap may warm
 * phpBB's cache; this script does not enable, disable, or purge extensions.
 */
require(__DIR__ . '/seed-bootstrap.php');
$manager = $phpbb_container->get('ext.manager');
$manager->load_extensions();
echo json_encode([
	'enabled' => array_keys($manager->all_enabled()),
	'disabled' => array_keys($manager->all_disabled()),
	'available' => array_keys($manager->all_available()),
], JSON_THROW_ON_ERROR) . "\n";
