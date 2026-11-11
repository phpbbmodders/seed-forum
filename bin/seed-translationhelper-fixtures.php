<?php
/**
 * Seed an installed board for the phpBB Modders Translation Helper extension.
 *
 * Input: phpBB board root as the first argument. Add what Translation Helper
 * needs to have something to translate:
 * - a test "Swedish" language (iso sv): a copy of the board's English
 *   language files with its own iso.txt, registered in the languages table;
 * - a small fixture extension, seedforum/translatable 1.0.0, made of plain
 *   files (not a bind mount, so Translation Helper can write its language
 *   folder without touching any source checkout). Its English files cover a
 *   plain string, quotes, HTML, a placeholder, a plural and a second file;
 *   language/sv/common.php translates part of them.
 * Folders are made group-writable for www-data, as the web server writes the
 * translations. Print what was created. Reject a board that already has the
 * fixture extension. Use the runner for seed-ledger protection.
 *
 * Works on phpBB 3.3.x and 4.0.
 * Usage: php seed-translationhelper-fixtures.php /path/to/phpBB/root
 */
require(__DIR__ . '/seed-bootstrap.php');

$ext_dir = $phpbb_root_path . 'ext/seedforum/translatable/';
if (is_dir($ext_dir))
{
	fwrite(STDERR, "Translation Helper fixtures already exist (ext/seedforum/translatable); reset before seeding again.\n");
	exit(1);
}

/**
 * Write a file, making its folders, group-writable for the web server.
 *
 * @param string $path Full path.
 * @param string $content File contents.
 * @return void
 */
function translationhelper_write(string $path, string $content): void
{
	$dir = dirname($path);
	if (!is_dir($dir) && !mkdir($dir, 0775, true))
	{
		throw new RuntimeException('Cannot create ' . $dir);
	}
	if (file_put_contents($path, $content) === false)
	{
		throw new RuntimeException('Cannot write ' . $path);
	}
	chmod($path, 0664);
}

/**
 * Give the web-server group write access to a folder tree.
 *
 * @param string $dir Folder.
 * @return void
 */
function translationhelper_web_writable(string $dir): void
{
	$items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
	foreach (array_merge([new SplFileInfo($dir)], iterator_to_array($items, false)) as $item)
	{
		@chgrp($item->getPathname(), 'www-data');
		@chmod($item->getPathname(), $item->isDir() ? 0775 : 0664);
	}
}

/**
 * A phpBB language file with the given entries, written as phpBB does.
 *
 * @param string $entries PHP array items, already formatted.
 * @return string File contents.
 */
function translationhelper_lang_file(string $entries): string
{
	return "<?php\n/**\n *\n * Translatable fixture extension for seed-forum\n *\n * @license GNU General Public License, version 2 (GPL-2.0)\n *\n */\n\n"
		. "if (!defined('IN_PHPBB'))\n{\n\texit;\n}\n\nif (empty(\$lang) || !is_array(\$lang))\n{\n\t\$lang = [];\n}\n\n"
		. "\$lang = array_merge(\$lang, [\n" . $entries . "]);\n";
}

// 1. The test language
$language_dir = $phpbb_root_path . 'language/sv/';
if (!is_dir($language_dir))
{
	$source = $phpbb_root_path . 'language/en/';
	$items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
	mkdir($language_dir, 0775);
	foreach ($items as $item)
	{
		$target = $language_dir . substr($item->getPathname(), strlen($source));
		if ($item->isDir())
		{
			mkdir($target, 0775, true);
		}
		else
		{
			copy($item->getPathname(), $target);
		}
	}
	file_put_contents($language_dir . 'iso.txt', "Swedish\nSvenska (test copy)\nseed-forum\n");
}

$result = $db->sql_query('SELECT lang_id FROM ' . LANG_TABLE . " WHERE lang_iso = 'sv'");
$lang_id = (int) $db->sql_fetchfield('lang_id');
$db->sql_freeresult($result);
if (!$lang_id)
{
	$db->sql_query('INSERT INTO ' . LANG_TABLE . ' ' . $db->sql_build_array('INSERT', [
		'lang_iso'			=> 'sv',
		'lang_dir'			=> 'sv',
		'lang_english_name'	=> 'Swedish',
		'lang_local_name'	=> 'Svenska (test copy)',
		'lang_author'		=> 'seed-forum',
	]));
}

// 2. The fixture extension, version 1.0.0
translationhelper_write($ext_dir . 'composer.json', json_encode([
	'name'			=> 'seedforum/translatable',
	'type'			=> 'phpbb-extension',
	'description'	=> 'Fixture extension with language files, for Translation Helper tests.',
	'version'		=> '1.0.0',
	'license'		=> 'GPL-2.0-only',
	'authors'		=> [['name' => 'seed-forum']],
	'require'		=> ['php' => '>=7.2'],
	'extra'			=> ['display-name' => 'Translatable Fixture', 'soft-require' => ['phpbb/phpbb' => '>=3.3.0']],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

translationhelper_write($ext_dir . 'ext.php', "<?php\nnamespace seedforum\\translatable;\n\nclass ext extends \\phpbb\\extension\\base\n{\n}\n");

translationhelper_write($ext_dir . 'language/en/common.php', translationhelper_lang_file(
	"\t'TRANSLATABLE_TITLE'\t\t=> 'Translatable fixture',\n"
	. "\t'TRANSLATABLE_WELCOME'\t\t=> 'Welcome, %s!',\n"
	. "\t'TRANSLATABLE_QUOTE'\t\t=> 'It\\'s a \"quoted\" text',\n"
	. "\t'TRANSLATABLE_HTML'\t\t=> 'Read the <strong>rules</strong> first.',\n"
	. "\t'TRANSLATABLE_POSTS'\t\t=> [\n\t\t1\t=> '%d post',\n\t\t2\t=> '%d posts',\n\t],\n"
	. "\t'TRANSLATABLE_REMOVED_LATER'\t=> 'This string goes away in 1.1.0',\n"
));

translationhelper_write($ext_dir . 'language/en/info_acp_translatable.php', translationhelper_lang_file(
	"\t'ACP_TRANSLATABLE'\t\t\t=> 'Translatable',\n"
	. "\t'ACP_TRANSLATABLE_SETTINGS'\t=> 'Settings',\n"
));

translationhelper_write($ext_dir . 'language/sv/common.php', translationhelper_lang_file(
	"\t'TRANSLATABLE_TITLE'\t\t=> 'Översättningsbar testdel',\n"
	. "\t'TRANSLATABLE_WELCOME'\t\t=> 'Välkommen, %s!',\n"
	. "\t'TRANSLATABLE_REMOVED_LATER'\t=> 'Den här texten försvinner i 1.1.0',\n"
));

translationhelper_web_writable($phpbb_root_path . 'ext/seedforum');

echo "Created test language sv and fixture extension seedforum/translatable 1.0.0 (English: 2 files, Swedish: common.php partly translated).\n";
