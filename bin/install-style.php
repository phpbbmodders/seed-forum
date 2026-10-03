<?php
/** Install a copied style using phpBB's ACP installer, without resetting data. */
if (PHP_SAPI !== 'cli')
{
	exit(1);
}
$directory = $argv[2] ?? '';
$make_default = ($argv[3] ?? '0') === '1';
if (!preg_match('/^[A-Za-z0-9_-]+$/D', $directory) || in_array(strtolower($directory), ['all', 'adm', 'admin', 'prosilver'], true))
{
	throw new RuntimeException('Invalid custom style directory');
}
require __DIR__ . '/seed-bootstrap.php';
require_once $phpbb_root_path . 'includes/acp/acp_styles.php';
$language->add_lang('acp/styles');

class seed_forum_style_installer extends acp_styles
{
	public function install_directory($directory)
	{
		global $db, $user, $phpbb_root_path;
		$this->db = $db;
		$this->user = $user;
		$this->styles_path = $phpbb_root_path . 'styles/';
		foreach ($this->get_styles() as $style)
		{
			if ($style['style_path'] === $directory)
			{
				return (int) $style['style_id'];
			}
		}
		foreach ($this->find_available(false) as $style)
		{
			if ($style['style_path'] === $directory)
			{
				$style['style_active'] = 1;
				return (int) $this->install_style($style);
			}
		}
		throw new RuntimeException('Style cannot be installed; check style.cfg and its installed parent: ' . $directory);
	}
}

$id = (new seed_forum_style_installer())->install_directory($directory);
$db->sql_query('UPDATE ' . STYLES_TABLE . ' SET style_active = 1 WHERE style_id = ' . $id);
if ($make_default)
{
	$old_default = (int) $config['default_style'];
	$config->set('default_style', $id);
	// Move users of the old board default; preserve other style choices.
	$db->sql_query('UPDATE ' . USERS_TABLE . ' SET user_style = ' . $id . ' WHERE user_style = ' . $old_default);
}
$cache->destroy('sql', STYLES_TABLE);
echo 'Installed style: ' . $directory . ($make_default ? ' (board default)' : '') . "\n";
