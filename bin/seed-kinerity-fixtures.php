<?php
/**
 * Seed an installed board with the kinerity/knowledgebase extension enabled.
 *
 * Input: phpBB board root as the first argument. Build a large kinerity
 * knowledge base to test moving a board from kinerity/knowledgebase to
 * phpbbmodders/knowledgebase: a category tree several levels deep with
 * BBCode descriptions (some longer than 255 characters), and articles in
 * every kinerity state (approved, waiting for approval, deleted, denied),
 * filed in one or several categories, in none at all, or only in a category
 * that no longer exists, with BBCode, smilies, links, Unicode and HTML
 * special characters, by current and deleted members. Also give a new group
 * and two members board-wide kinerity permissions of their own (a Yes and a
 * Never), and leave a kinerity notification and notification setting behind.
 * Print what was created.
 *
 * Sizes default to 2,000 categories and 50,000 articles; set
 * SEED_KINERITY_CATEGORIES and SEED_KINERITY_ARTICLES to change them. The
 * content is the same on every run (fixed random seed).
 * Set SEED_KINERITY_LEFTOVERS=0 to leave out the articles in no category or
 * linked to a deleted one: kinerity never leaves those behind itself, and its
 * own article list stops with an error on them. kinerity's article list also
 * loads every article at once, so it only displays boards of a few thousand.
 * Reject a board that already has more than kinerity's own sample article.
 * Use the runner for seed-ledger protection.
 *
 * Needs the standard fixtures (bin/seed-standard-fixtures.php) to have run
 * first, for the forum_* members. Works on phpBB 3.3.x and 4.0.
 * Usage: php seed-kinerity-fixtures.php /path/to/phpBB/root
 */
require(__DIR__ . '/seed-bootstrap.php');

/** @var \phpbb\extension\manager $ext_manager */
$ext_manager = $phpbb_container->get('ext.manager');
if (!$ext_manager->is_enabled('kinerity/knowledgebase'))
{
	fwrite(STDERR, "Enable kinerity/knowledgebase before seeding its fixtures.\n");
	exit(1);
}

$category_total = (int) (getenv('SEED_KINERITY_CATEGORIES') ?: 2000);
$leftovers = getenv('SEED_KINERITY_LEFTOVERS') !== '0';
$article_total = (int) (getenv('SEED_KINERITY_ARTICLES') ?: 50000);
if ($category_total < 10 || $article_total < 20)
{
	fwrite(STDERR, "SEED_KINERITY_CATEGORIES must be at least 10 and SEED_KINERITY_ARTICLES at least 20.\n");
	exit(1);
}

$articles_table = $table_prefix . 'kb_articles';
$categories_table = $table_prefix . 'kb_categories';
$links_table = $table_prefix . 'kb_article_category';

$existing = 0;
foreach ([$articles_table => 'article_id', $categories_table => 'category_id'] as $table => $column)
{
	$result = $db->sql_query('SELECT COUNT(' . $column . ') AS total FROM ' . $table);
	$existing = max($existing, (int) $db->sql_fetchfield('total'));
	$db->sql_freeresult($result);
}
if ($existing > 1)
{
	fwrite(STDERR, "kinerity fixtures already exist; reset before seeding again.\n");
	exit(1);
}

mt_srand(20261111);

// kinerity's migration inserts its sample category and article with id 1
// without moving PostgreSQL's sequences on, so the next insert would reuse 1
if ($db->get_sql_layer() === 'postgres')
{
	foreach (['kb_categories' => 'category_id', 'kb_articles' => 'article_id'] as $table => $column)
	{
		$db->sql_query("SELECT setval('" . $table_prefix . $table . "_seq', GREATEST((SELECT MAX(" . $column . ') FROM ' . $table_prefix . $table . '), 1))');
	}
}

/**
 * The user row of a standard fixture member, or the installer's admin.
 */
function kinerity_member(string $username): array
{
	global $db;

	$result = $db->sql_query('SELECT user_id, username, user_colour FROM ' . USERS_TABLE . "
		WHERE username_clean = '" . $db->sql_escape(utf8_clean_string($username)) . "'");
	$row = $db->sql_fetchrow($result);
	$db->sql_freeresult($result);
	if (!$row)
	{
		throw new RuntimeException("Fixture member {$username} was not found; run the standard fixtures first");
	}

	return $row;
}

/**
 * Text as kinerity stores it: parsed with phpBB's text formatter.
 *
 * @return array{text: string, uid: string, bitfield: string}
 */
function kinerity_parse(string $text): array
{
	$uid = $bitfield = '';
	if ($text === '')
	{
		return ['text' => '', 'uid' => '', 'bitfield' => ''];
	}

	// Escaped first, as phpBB's request object hands posted text to the parser
	$text = htmlspecialchars($text, ENT_COMPAT, 'UTF-8');
	$flags = 0;
	generate_text_for_storage($text, $uid, $bitfield, $flags, true, true, true);

	return ['text' => $text, 'uid' => $uid, 'bitfield' => $bitfield];
}

/**
 * Plain text as phpBB stores user input (HTML-escaped).
 */
function kinerity_escape(string $text): string
{
	// Characters outside the BMP as entities, as phpBB stores them: MySQL's
	// utf8 tables on phpBB 3.3 cannot hold them
	return utf8_encode_ucr(htmlspecialchars($text, ENT_COMPAT, 'UTF-8'));
}

$posters = [];
foreach (['admin', 'forum_alex', 'forum_blair', 'forum_casey', 'forum_drew', 'forum_moderator'] as $name)
{
	$posters[] = kinerity_member($name);
}
// An author whose account has since been deleted: kinerity keeps the name
$posters[] = ['user_id' => 999999, 'username' => 'Departed member', 'user_colour' => ''];

// Category tree: top-level categories, each with a random subtree up to five levels deep
echo "Building {$category_total} categories...\n";

$result = $db->sql_query('SELECT MAX(right_id) AS max_right FROM ' . $categories_table);
$next_left = (int) $db->sql_fetchfield('max_right') + 1;
$db->sql_freeresult($result);

$category_descriptions = [
	'',
	'Guides & how-tos',
	'[b]Bold[/b] and [i]italic[/i] notes with a [url=https://www.phpbb.com/]link[/url] :)',
	'Line one' . "\n" . 'Line two "quoted" <not a tag>',
	str_repeat('Long description & more text. ', 14),
	'Ünïcödé Ελληνικά 日本語 🚀',
];
$parsed_category_descriptions = array_map('kinerity_parse', $category_descriptions);

$tree = [];
$remaining = $category_total;
$build = function (int $depth, int $budget) use (&$build, &$remaining): array {
	$node = ['children' => []];
	$remaining--;
	$budget--;
	while ($budget > 0 && $remaining > 0 && $depth < 5)
	{
		$share = min($budget, mt_rand(1, max(1, intdiv($budget, 2))));
		$node['children'][] = $build($depth + 1, $share);
		$budget -= $share;
	}

	return $node;
};
while ($remaining > 0)
{
	$tree[] = $build(1, min($remaining, mt_rand(20, 200)));
}

$category_ids = [];
$category_number = 0;
$db->sql_transaction('begin');
$insert_category = function (array $node, int $depth) use (&$insert_category, &$next_left, &$category_ids, &$category_number, $db, $categories_table, $parsed_category_descriptions): void {
	$category_number++;
	$left = $next_left++;
	$name = ($depth === 1 ? 'Section ' : 'Topic ') . $category_number . ($category_number % 7 === 0 ? ' & "friends"' : '');
	$description = $parsed_category_descriptions[$category_number % count($parsed_category_descriptions)];

	$db->sql_query('INSERT INTO ' . $categories_table . ' ' . $db->sql_build_array('INSERT', [
		'left_id'              => $left,
		'right_id'             => 0,
		'category_name'        => kinerity_escape($name),
		'category_description' => $description['text'],
		'bbcode_bitfield'      => $description['bitfield'],
		'bbcode_options'       => 7,
		'bbcode_uid'           => $description['uid'],
	]));
	$category_id = (int) $db->sql_nextid();
	$category_ids[] = $category_id;

	foreach ($node['children'] as $child)
	{
		$insert_category($child, $depth + 1);
	}

	$db->sql_query('UPDATE ' . $categories_table . ' SET right_id = ' . $next_left++ . ' WHERE category_id = ' . $category_id);
};
foreach ($tree as $node)
{
	$insert_category($node, 1);
}
$db->sql_transaction('commit');

// A category id that no longer exists, for links kinerity left behind
$missing_category = max($category_ids) + 1000;

// Article bodies: a set of templates, parsed once, reused with different titles
$bodies = [
	"[b]Getting started[/b]\n\nFollow these steps:\n[list=1][*]Download[*]Upload[*]Enable[/list]\nSee [url=https://www.phpbb.com/]phpBB.com[/url] :)",
	"[quote=\"Kailey\"]Quoted advice[/quote]\nThen run:\n[code]php bin/phpbbcli.php cache:purge[/code]",
	"Plain text with <angle brackets> & ampersands, \"quotes\" and 'apostrophes'.",
	"[color=#AA0000]Red text[/color], [size=150]bigger text[/size], [u]underlined[/u] and [i]italic[/i].",
	"Ünïcödé text: Ελληνικά, Русский, 日本語, עברית, emoji 🎉🚀 and a smiley :D",
	"[img]https://www.phpbb.com/theme/images/logos/blue/160x52.png[/img]\nAn image above, a link below: https://example.com/path?a=1&b=2",
	str_repeat("A long paragraph of text that goes on for a while. ", 120),
];
$parsed_bodies = array_map('kinerity_parse', $bodies);
$descriptions = [
	'',
	'A short description',
	'Tips & tricks for "experts"',
	str_repeat('This description is far longer than 255 characters & has entities. ', 6),
];

// What each kinerity article_visibility value is called, for the summary
$visibility_names = [0 => 'waiting', 1 => 'approved', 2 => 'deleted', 3 => 'denied'];
$summary = [
	'categories'         => count($category_ids),
	'articles'           => 0,
	'by visibility'      => array_fill_keys(array_values($visibility_names), 0),
	'one category'       => 0,
	'several categories' => 0,
	'no category'        => 0,
	'only a missing one' => 0,
	'links'              => 0,
];

echo "Building {$article_total} articles...\n";
$time = 1500000000;
$links = [];
$db->sql_transaction('begin');
for ($number = 1; $number <= $article_total; $number++)
{
	$roll = mt_rand(1, 100);
	$visibility = $roll <= 80 ? 1 : ($roll <= 88 ? 0 : ($roll <= 94 ? 2 : 3));
	$body = $parsed_bodies[$number % count($parsed_bodies)];
	$poster = $posters[$number % count($posters)];
	$time += mt_rand(60, 3600);

	$title = 'Article ' . $number;
	if ($number % 11 === 0)
	{
		$title .= ' & "special" <chars>';
	}
	else if ($number % 13 === 0)
	{
		$title .= ' — Ünïcödé 日本語 🚀';
	}

	$db->sql_query('INSERT INTO ' . $articles_table . ' ' . $db->sql_build_array('INSERT', [
		'article_poster_id'     => (int) $poster['user_id'],
		'article_poster_name'   => $poster['username'],
		'article_poster_colour' => (string) $poster['user_colour'],
		'article_time'          => $time,
		'article_edit_time'     => $number % 5 === 0 ? $time + 86400 : 0,
		'enable_bbcode'         => 1,
		'enable_smilies'        => 1,
		'enable_magic_url'      => 1,
		'article_title'         => kinerity_escape($title),
		'article_description'   => kinerity_escape($descriptions[$number % count($descriptions)]),
		'article_text'          => $body['text'],
		'bbcode_bitfield'       => $body['bitfield'],
		'bbcode_uid'            => $body['uid'],
		'article_visibility'    => $visibility,
		'article_views'         => mt_rand(0, 100000),
		'article_edit_name'     => $number % 5 === 0 ? 'forum_moderator' : '',
	]));
	$article_id = (int) $db->sql_nextid();

	$summary['articles']++;
	$summary['by visibility'][$visibility_names[$visibility]]++;

	$roll = mt_rand(1, 100);
	if (!$leftovers && $roll <= 2)
	{
		// Keeps the random sequence the same, so the rest of the board matches
		$roll += 2;
	}
	if ($roll === 1)
	{
		$summary['no category']++;
	}
	else if ($roll === 2)
	{
		$links[] = ['category_id' => $missing_category, 'article_id' => $article_id];
		$summary['only a missing one']++;
	}
	else
	{
		$how_many = $roll <= 72 ? 1 : ($roll <= 94 ? 2 : mt_rand(3, 4));
		$chosen = [];
		while (count($chosen) < $how_many)
		{
			$chosen[$category_ids[mt_rand(0, count($category_ids) - 1)]] = true;
		}
		foreach (array_keys($chosen) as $category_id)
		{
			$links[] = ['category_id' => $category_id, 'article_id' => $article_id];
		}
		// Now and then a leftover link to a deleted category as well
		if ($leftovers && $roll % 25 === 0)
		{
			$links[] = ['category_id' => $missing_category, 'article_id' => $article_id];
		}
		$summary[$how_many === 1 ? 'one category' : 'several categories']++;
	}

	if (count($links) >= 1000 || $number === $article_total)
	{
		$summary['links'] += count($links);
		$db->sql_multi_insert($links_table, $links);
		$links = [];
	}
	if ($number % 1000 === 0)
	{
		$db->sql_transaction('commit');
		$db->sql_transaction('begin');
		echo "  {$number} articles\n";
	}
}
$db->sql_transaction('commit');

// Board-wide kinerity permissions beyond the roles kinerity set up itself
$option_ids = [];
$result = $db->sql_query('SELECT auth_option_id, auth_option FROM ' . ACL_OPTIONS_TABLE . "
	WHERE auth_option IN ('u_kb_post', 'm_kb_approve', 'm_kb_chgposter')");
while ($row = $db->sql_fetchrow($result))
{
	$option_ids[$row['auth_option']] = (int) $row['auth_option_id'];
}
$db->sql_freeresult($result);

$group_id = 0;
$group_error = group_create($group_id, GROUP_CLOSED, 'KB Reviewers', 'Reviews knowledge base articles', []);
if ($group_error)
{
	throw new RuntimeException('Could not create the KB Reviewers group: ' . implode(', ', (array) $group_error));
}
group_user_add($group_id, [(int) kinerity_member('forum_blair')['user_id']]);

$db->sql_multi_insert(ACL_GROUPS_TABLE, [
	['group_id' => $group_id, 'forum_id' => 0, 'auth_option_id' => $option_ids['m_kb_approve'], 'auth_role_id' => 0, 'auth_setting' => ACL_YES],
]);
$db->sql_multi_insert(ACL_USERS_TABLE, [
	['user_id' => (int) kinerity_member('forum_drew')['user_id'], 'forum_id' => 0, 'auth_option_id' => $option_ids['u_kb_post'], 'auth_role_id' => 0, 'auth_setting' => ACL_NEVER],
	['user_id' => (int) kinerity_member('forum_casey')['user_id'], 'forum_id' => 0, 'auth_option_id' => $option_ids['m_kb_chgposter'], 'auth_role_id' => 0, 'auth_setting' => ACL_YES],
]);
$auth->acl_clear_prefetch();

// kinerity installs switched off; a board that uses it has it on, with its header link
$config->set('knowledgebase_enable', 1);
$config->set('knowledgebase_header_link', 1);

// A kinerity notification and a member's setting for it
$type_name = 'kinerity.knowledgebase.notification.type.approve_article';
$result = $db->sql_query('SELECT notification_type_id FROM ' . NOTIFICATION_TYPES_TABLE . "
	WHERE notification_type_name = '" . $db->sql_escape($type_name) . "'");
$type_id = (int) $db->sql_fetchfield('notification_type_id');
$db->sql_freeresult($result);
if (!$type_id)
{
	$db->sql_query('INSERT INTO ' . NOTIFICATION_TYPES_TABLE . ' ' . $db->sql_build_array('INSERT', ['notification_type_name' => $type_name, 'notification_type_enabled' => 1]));
	$type_id = (int) $db->sql_nextid();
}
$db->sql_query('INSERT INTO ' . NOTIFICATIONS_TABLE . ' ' . $db->sql_build_array('INSERT', [
	'notification_type_id' => $type_id,
	'item_id'              => 1,
	'item_parent_id'       => 0,
	'user_id'              => (int) $admin['user_id'],
	'notification_read'    => 0,
	'notification_time'    => time(),
	'notification_data'    => serialize(['article_title' => 'Article 1']),
]));
$db->sql_query('INSERT INTO ' . USER_NOTIFICATIONS_TABLE . ' ' . $db->sql_build_array('INSERT', [
	'item_type' => $type_name,
	'item_id'   => 0,
	'user_id'   => (int) kinerity_member('forum_alex')['user_id'],
	'method'    => 'notification.method.board',
	'notify'    => 1,
]));

echo "kinerity fixtures created:\n";
foreach ($summary as $label => $value)
{
	echo '  ' . $label . ': ' . (is_array($value) ? json_encode($value) : $value) . "\n";
}
echo "  KB Reviewers group: {$group_id} (m_kb_approve Yes, member forum_blair)\n";
echo "  forum_drew: u_kb_post Never; forum_casey: m_kb_chgposter Yes\n";
echo "  kinerity's own sample category and article (from its migration) are kept as they are\n";
