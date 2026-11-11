<?php
/**
 * Upgrade the seedforum/translatable fixture extension to 1.1.0 the way many
 * administrators do: delete the extension's folder, then upload the new one.
 *
 * Input: phpBB board root as the first argument. Needs
 * bin/seed-translationhelper-fixtures.php to have run first. The new
 * version's English changes one string (TRANSLATABLE_WELCOME), removes one
 * (TRANSLATABLE_REMOVED_LATER) and adds one (TRANSLATABLE_NEW); it ships no
 * Swedish files, so any translation in the old folder is gone. Translation
 * Helper should then put its saved copy back and show what changed.
 * Can be run again to repeat the test after re-seeding the 1.0.0 fixtures.
 *
 * Usage: php translationhelper-upgrade-fixture.php /path/to/phpBB/root [--readonly-language]
 */
if (PHP_SAPI !== 'cli')
{
	exit(1);
}

$root = rtrim($argv[1] ?? '', '/') . '/';
$ext_dir = $root . 'ext/seedforum/translatable/';
if (!is_file($root . 'config.php') || !is_file($ext_dir . 'composer.json'))
{
	fwrite(STDERR, "Run bin/seed-translationhelper-fixtures.php on an installed board first.\n");
	exit(1);
}

/**
 * Delete a folder tree that is a plain folder (never follows links).
 *
 * @param string $dir Folder.
 * @return void
 */
function upgrade_delete_tree(string $dir): void
{
	if (is_link($dir))
	{
		throw new RuntimeException('Refusing to delete through a link: ' . $dir);
	}
	$items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
	foreach ($items as $item)
	{
		$item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
	}
	rmdir($dir);
}

/**
 * Write a group-writable file, making its folders.
 *
 * @param string $path Full path.
 * @param string $content Contents.
 * @return void
 */
function upgrade_write(string $path, string $content): void
{
	if (!is_dir(dirname($path)))
	{
		mkdir(dirname($path), 0775, true);
		@chgrp(dirname($path), 'www-data');
	}
	file_put_contents($path, $content);
	@chgrp($path, 'www-data');
	chmod($path, 0664);
}

$header = "<?php\n/**\n *\n * Translatable fixture extension for seed-forum\n *\n * @license GNU General Public License, version 2 (GPL-2.0)\n *\n */\n\n"
	. "if (!defined('IN_PHPBB'))\n{\n\texit;\n}\n\nif (empty(\$lang) || !is_array(\$lang))\n{\n\t\$lang = [];\n}\n\n";

$composer = json_decode(file_get_contents($ext_dir . 'composer.json'), true);
$composer['version'] = '1.1.0';

upgrade_delete_tree($ext_dir);

upgrade_write($ext_dir . 'composer.json', json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
upgrade_write($ext_dir . 'ext.php', "<?php\nnamespace seedforum\\translatable;\n\nclass ext extends \\phpbb\\extension\\base\n{\n}\n");
upgrade_write($ext_dir . 'language/en/common.php', $header . "\$lang = array_merge(\$lang, [\n"
	. "\t'TRANSLATABLE_TITLE'\t\t=> 'Translatable fixture',\n"
	. "\t'TRANSLATABLE_WELCOME'\t\t=> 'Welcome back, %s!',\n"
	. "\t'TRANSLATABLE_QUOTE'\t\t=> 'It\\'s a \"quoted\" text',\n"
	. "\t'TRANSLATABLE_HTML'\t\t=> 'Read the <strong>rules</strong> first.',\n"
	. "\t'TRANSLATABLE_POSTS'\t\t=> [\n\t\t1\t=> '%d post',\n\t\t2\t=> '%d posts',\n\t],\n"
	. "\t'TRANSLATABLE_NEW'\t\t=> 'New in 1.1.0',\n"
	. "]);\n");
upgrade_write($ext_dir . 'language/en/info_acp_translatable.php', $header . "\$lang = array_merge(\$lang, [\n"
	. "\t'ACP_TRANSLATABLE'\t\t\t=> 'Translatable',\n"
	. "\t'ACP_TRANSLATABLE_SETTINGS'\t=> 'Settings',\n"
	. "]);\n");
// Writable for the web server, like seed-translationhelper-fixtures.php. Pass
// --readonly-language as the second argument to keep language/ unwritable and
// test the "could not be put back" warning instead.
$readonly = ($argv[2] ?? '') === '--readonly-language';
$items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($ext_dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
foreach (array_merge([new SplFileInfo($ext_dir)], iterator_to_array($items, false)) as $item)
{
	if ($readonly && $item->isDir() && $item->getPathname() === $ext_dir . 'language')
	{
		continue;
	}
	@chgrp($item->getPathname(), 'www-data');
	chmod($item->getPathname(), $item->isDir() ? 0775 : 0664);
}

echo "seedforum/translatable is now 1.1.0 (old folder deleted, no Swedish files).\n";
