<?php
/**
 * Bootstrap an installed phpBB board for CLI fixture and state scripts.
 *
 * Read the board root from $argv[1], set the working directory to that root,
 * and expose phpBB services/compatibility globals to the including script.
 * Use the installer's admin identity for ownership, logs, and permission setup.
 * This does not create fixtures or register a web session.
 */
if (PHP_SAPI !== 'cli')
{
	exit(1);
}
$phpbb_root_path = rtrim($argv[1] ?? '', '/') . '/';
$phpEx = 'php';
if (!is_file($phpbb_root_path . 'config.php'))
{
	throw new RuntimeException('Not an installed phpBB board');
}
define('IN_PHPBB', true);
chdir($phpbb_root_path);
require($phpbb_root_path . 'includes/startup.php');
require($phpbb_root_path . 'phpbb/class_loader.php');
$phpbb_class_loader = new \phpbb\class_loader('phpbb\\', "{$phpbb_root_path}phpbb/", $phpEx);
$phpbb_class_loader->register();
$phpbb_config_php_file = new \phpbb\config_php_file($phpbb_root_path, $phpEx);
extract($phpbb_config_php_file->get_all());
if (!defined('PHPBB_ENVIRONMENT'))
{
	define('PHPBB_ENVIRONMENT', 'production');
}
foreach (['constants', 'functions', 'functions_admin', 'functions_user', 'utf/utf_tools', 'functions_compatibility', 'acp/auth', 'functions_acp', 'functions_content', 'functions_posting'] as $include)
{
	require_once($phpbb_root_path . 'includes/' . $include . '.php');
}
$phpbb_class_loader_ext = new \phpbb\class_loader('\\', "{$phpbb_root_path}ext/", $phpEx);
$phpbb_class_loader_ext->register();
$phpbb_container_builder = new \phpbb\di\container_builder($phpbb_root_path, $phpEx);
$phpbb_container = $phpbb_container_builder->with_config($phpbb_config_php_file)->get_container();
$phpbb_container->get('request')->enable_super_globals();
require($phpbb_root_path . 'includes/compatibility_globals.php');
register_compatibility_globals();
/** @var \phpbb\db\driver\driver_interface $db */
/** @var \phpbb\user $user */
/** @var \phpbb\auth\auth $auth */
/** @var \phpbb\language\language $language */
$language->set_default_language($config['default_lang']);
$language->add_lang(['common', 'posting', 'acp/common', 'acp/forums', 'cli']);
$password_manager = $phpbb_container->get('passwords.manager');
$result = $db->sql_query('SELECT * FROM ' . USERS_TABLE . " WHERE username = 'admin'");
$admin = $db->sql_fetchrow($result);
$db->sql_freeresult($result);
if (!$admin)
{
	throw new RuntimeException('Installer admin user was not found');
}
// Assign the CLI actor directly rather than creating a browser login/session.
$user->data = $admin;
$user->ip = '127.0.0.1';
$user->data['user_ip'] = $user->ip;
$user->lang = [];
$auth->acl($user->data);
