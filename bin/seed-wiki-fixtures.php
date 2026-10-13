<?php
/**
 * Seed an installed board with the phpBB Modders Wiki extension enabled.
 *
 * Input: phpBB board root as the first argument. Create a discussion forum, four
 * users, and articles in every state the wiki can show: live articles with
 * version histories (one with thirty versions), newer versions awaiting approval,
 * an article that was never approved, a redirect, a sticky article, a block of
 * forty bulk articles so the overview and the contributions pages run to several
 * pages, links between articles, links to articles that do not exist yet,
 * uploaded files (an image and a ZIP) embedded with [[File:name]], and one
 * article with a real discussion topic. When the extension has them: templates (a notice,
 * an infobox, a table row, two Start ... templates for new articles), articles that use
 * them, a table, parser functions, and headings with a contents box; and, when it has
 * categories, [[Category:...]] on those, one category page with a description and
 * one without. Choose the discussion forum, so the
 * Start discussion button shows on the articles that have no topic. Print the
 * created IDs and login guidance.
 * Reject a board that already has the fixture forum or any wiki article. Use the
 * runner for seed-ledger protection.
 *
 * Needs the standard fixtures (bin/seed-standard-fixtures.php) to have run first,
 * for the REGISTERED group's baseline permissions. Works on phpBB 3.3.x and 4.0.
 * Usage: php seed-wiki-fixtures.php /path/to/phpBB/root
 */
require(__DIR__ . '/seed-bootstrap.php');
require_once($phpbb_root_path . 'includes/acp/acp_forums.php');

const WIKI_SEED_PASSWORD = 'KbTest1234!';

/** @var \phpbb\extension\manager $ext_manager */
$ext_manager = $phpbb_container->get('ext.manager');
if (!$ext_manager->is_enabled('phpbbmodders/wiki'))
{
	fwrite(STDERR, "Enable phpbbmodders/wiki before seeding its fixtures.\n");
	exit(1);
}

$articles_table = $table_prefix . 'wiki_article';
$files_table = $table_prefix . 'wiki_file';

/**
 * Fetch the first row matching a trusted condition.
 *
 * @param string $table     Table name.
 * @param string $condition Trusted SQL condition; callers escape string values.
 * @return array Row fields, or an empty array when nothing matches.
 */
function wiki_row(string $table, string $condition): array
{
	global $db;
	$result = $db->sql_query('SELECT * FROM ' . $table . ' WHERE ' . $condition);
	$row = $db->sql_fetchrow($result);
	$db->sql_freeresult($result);
	return $row ?: [];
}

$existing_forum = wiki_row(FORUMS_TABLE, "forum_name = 'Wiki Discussions'");
$existing_article = wiki_row($articles_table, '1 = 1');
if ($existing_forum || $existing_article)
{
	fwrite(STDERR, "Wiki fixtures already exist (forum or articles found); reset before seeding again.\n");
	exit(1);
}

echo "Seeding as admin (user_id {$user->data['user_id']})...\n";

/**
 * Create a normal post forum at the board root and return its id.
 *
 * @param string $name Forum name.
 * @param string $desc Forum description.
 * @return int The new forum's id.
 */
function wiki_create_forum(string $name, string $desc): int
{
	$acp_forums = new acp_forums();
	$forum_data = [
		'parent_id' => 0, 'forum_type' => FORUM_POST, 'type_action' => '', 'forum_status' => ITEM_UNLOCKED,
		'forum_parents' => '', 'forum_name' => $name, 'forum_link' => '', 'forum_link_track' => false,
		'forum_desc' => $desc, 'forum_desc_uid' => '', 'forum_desc_options' => 7, 'forum_desc_bitfield' => '',
		'forum_rules' => '', 'forum_rules_uid' => '', 'forum_rules_options' => 7, 'forum_rules_bitfield' => '',
		'forum_rules_link' => '', 'forum_image' => '', 'forum_style' => 0, 'display_subforum_list' => true,
		'display_subforum_limit' => false, 'display_on_index' => true, 'forum_topics_per_page' => 0,
		'enable_indexing' => true, 'enable_icons' => true, 'enable_prune' => false, 'enable_post_review' => true,
		'enable_quick_reply' => false, 'enable_shadow_prune' => false, 'prune_days' => 7, 'prune_viewed' => 7,
		'prune_freq' => 1, 'prune_old_polls' => false, 'prune_announce' => false, 'prune_sticky' => false,
		'prune_shadow_days' => 7, 'prune_shadow_freq' => 1, 'forum_password' => '', 'forum_password_confirm' => '',
		'forum_password_unset' => false, 'forum_options' => 0, 'show_active' => true,
	];
	$errors = $acp_forums->update_forum_data($forum_data);
	if (!empty($errors))
	{
		fwrite(STDERR, "Failed to create forum '{$name}': " . implode(', ', $errors) . "\n");
		exit(1);
	}
	return (int) $forum_data['forum_id'];
}

/**
 * Create a registered user, or reuse the existing one with that name.
 *
 * @param string $username Account name.
 * @return int The user's id.
 */
function wiki_create_user(string $username): int
{
	global $db, $config, $password_manager;
	$existing = wiki_row(USERS_TABLE, "username = '" . $db->sql_escape($username) . "'");
	if ($existing)
	{
		return (int) $existing['user_id'];
	}
	$user_id = (int) user_add([
		'username' => $username, 'user_password' => $password_manager->hash(WIKI_SEED_PASSWORD),
		'user_email' => $username . '@example.org', 'group_id' => 2, 'user_timezone' => $config['board_timezone'],
		'user_lang' => $config['default_lang'], 'user_type' => USER_NORMAL, 'user_regdate' => time(),
	]);
	if (!$user_id)
	{
		fwrite(STDERR, "Failed to create user '{$username}'\n");
		exit(1);
	}
	return $user_id;
}

/**
 * The id of a built-in permission role, or 0 when the board lacks it.
 *
 * @param string $name Role name such as ROLE_FORUM_STANDARD.
 * @return int
 */
function wiki_role_id(string $name): int
{
	global $db;
	return (int) (wiki_row(ACL_ROLES_TABLE, "role_name = '" . $db->sql_escape($name) . "'")['role_id'] ?? 0);
}

/**
 * Give a user the named permissions that exist on this board.
 *
 * Options a version of the extension does not define are skipped, so the script
 * also seeds a board that runs an older build.
 *
 * @param \auth_admin $auth_admin Permission editor.
 * @param int         $user_id    Target user.
 * @param string[]    $options    Permission names.
 * @return void
 */
function wiki_grant(\auth_admin $auth_admin, int $user_id, array $options): void
{
	global $db;
	$result = $db->sql_query('SELECT auth_option FROM ' . ACL_OPTIONS_TABLE . ' WHERE ' . $db->sql_in_set('auth_option', $options));
	$known = [];
	while ($row = $db->sql_fetchrow($result))
	{
		$known[$row['auth_option']] = ACL_YES;
	}
	$db->sql_freeresult($result);
	if ($known)
	{
		$auth_admin->acl_set('user', 0, $user_id, $known);
	}
}

echo "==> Forum\n";
$forum_id = wiki_create_forum('Wiki Discussions', 'Discussion topics for wiki articles.');
echo "    Wiki Discussions forum_id={$forum_id}\n";

echo "==> Users\n";
$reader_id = wiki_create_user('wiki_reader');
$editor_id = wiki_create_user('wiki_editor');
$uploader_id = wiki_create_user('wiki_uploader');
$moderator_id = wiki_create_user('wiki_moderator');
echo "    wiki_reader={$reader_id} wiki_editor={$editor_id} wiki_uploader={$uploader_id} wiki_moderator={$moderator_id} (password for all: " . WIKI_SEED_PASSWORD . ")\n";

echo "==> Permissions and wiki settings\n";
$auth_admin = new auth_admin();
$registered = (int) wiki_row(GROUPS_TABLE, "group_name = 'REGISTERED'")['group_id'];
$guests = (int) wiki_row(GROUPS_TABLE, "group_name = 'GUESTS'")['group_id'];
$administrators = (int) wiki_row(GROUPS_TABLE, "group_name = 'ADMINISTRATORS'")['group_id'];

// Readers: everybody, signed in or not. Granted before any role so a role clean-up cannot remove it.
$auth_admin->acl_set('group', 0, $registered, ['u_wiki_view' => ACL_YES]);
$auth_admin->acl_set('group', 0, $guests, ['u_wiki_view' => ACL_YES]);

wiki_grant($auth_admin, $editor_id, ['u_wiki_edit', 'u_wiki_versions']);
wiki_grant($auth_admin, $uploader_id, ['u_wiki_edit', 'u_wiki_versions', 'u_wiki_edit_topic', 'u_wiki_upload']);
wiki_grant($auth_admin, $moderator_id, [
	'u_wiki_edit', 'u_wiki_versions', 'u_wiki_edit_topic', 'u_wiki_upload', 'u_wiki_set_active', 'u_wiki_set_sticky',
	'u_wiki_set_redirect', 'm_wiki_delete', 'm_wiki_delete_article', 'm_wiki_view_inactive',
]);
wiki_grant($auth_admin, (int) $user->data['user_id'], ['u_wiki_edit_topic', 'u_wiki_upload', 'u_wiki_set_active']);

// Registered members and administrators may post in the discussion forum.
$forum_standard = wiki_role_id('ROLE_FORUM_STANDARD');
if ($forum_standard)
{
	$auth_admin->acl_set('group', $forum_id, $registered, ['f_' => ACL_YES], $forum_standard);
	$auth_admin->acl_set('group', $forum_id, $administrators, ['f_' => ACL_YES], $forum_standard);
	$auth_admin->acl_set('group', $forum_id, $guests, ['f_' => ACL_YES], wiki_role_id('ROLE_FORUM_READONLY'));
}
$auth_admin->acl_clear_prefetch();

$config->set('wiki_discussion_forum_id', $forum_id);

/**
 * Store one version of an article, parsing its BBCode with the board's formatter so
 * [[links]] and [[File:...]] become real tags.
 *
 * @param array $v Version fields: title, text and user are required.
 * @return int The new version's article_id.
 */
function wiki_add_version(array $v): int
{
	global $db, $articles_table;
	$v += [
		'url' => $v['title'], 'approved' => 1, 'time' => time(), 'created' => null, 'reason' => '', 'topic' => 0,
		'sources' => '', 'sticky' => 0, 'views' => 0, 'redirect' => '', 'description' => '',
	];
	$text = $v['text'];
	$uid = $bitfield = '';
	$flags = 0;
	// Headings, tables and {{templates}} are read only while an article is saved, as the extension does
	$markup = '\\phpbbmodders\\wiki\\wiki\\markup';
	$wiki_markup = class_exists($markup);
	if ($wiki_markup)
	{
		$markup::set_active(true);
	}
	generate_text_for_storage($text, $uid, $bitfield, $flags, true, true, true);
	if ($wiki_markup)
	{
		$markup::set_active(false);
	}
	$db->sql_query('INSERT INTO ' . $articles_table . ' ' . $db->sql_build_array('INSERT', [
		'article_title' => $v['title'], 'article_url' => $v['url'], 'article_text' => $text,
		'bbcode_uid' => $uid, 'bbcode_bitfield' => $bitfield, 'article_approved' => (int) $v['approved'],
		'article_user_id' => (int) $v['user'], 'article_last_edit' => (int) $v['time'],
		'article_time_created' => (int) ($v['created'] ?? $v['time']), 'article_edit_reason' => $v['reason'],
		'article_topic_id' => (int) $v['topic'], 'article_sources' => $v['sources'], 'article_sticky' => (int) $v['sticky'],
		'article_views' => (int) $v['views'], 'article_redirect' => $v['redirect'],
		'article_description' => $v['description'], 'article_toc' => '',
	]));
	return (int) $db->sql_nextid();
}

/**
 * Store a whole history: every version but the last is older and replaced, the
 * last one is live unless $live is false.
 *
 * @param string $title    Article title (also its URL name).
 * @param array  $versions Each: text, user, reason; saved one day apart, oldest first.
 * @param array  $extra    Fields shared by every version (description, views, sticky, ...).
 * @param bool   $live     Whether the newest version is the live one.
 * @return void
 */
function wiki_add_history(string $title, array $versions, array $extra = [], bool $live = true): void
{
	$count = count($versions);
	$first = time() - ($count + 1) * 86400;
	foreach ($versions as $i => $version)
	{
		$is_last = ($i === $count - 1);
		wiki_add_version($version + [
			'title' => $title, 'time' => $first + ($i + 1) * 86400, 'created' => $first,
			'approved' => ($is_last && $live) ? 1 : 0,
		] + $extra);
	}
}

echo "==> Articles\n";
wiki_add_history('Main Page', [
	['user' => $moderator_id, 'reason' => 'First version', 'text' => "Welcome.\n\nSee [[Getting Started]]."],
	['user' => $editor_id, 'reason' => 'Add more links', 'text' => "Welcome to the wiki.\n\nSee [[Getting Started]] and [[Markup Guide]]."],
	['user' => $moderator_id, 'reason' => 'Tidy up and add the logo', 'text' => "Welcome to the [b]fixture wiki[/b].\n\n[[File:logo.png]]\n\n- [[Getting Started]]\n- [[Markup Guide]]\n- [[Release Process]]\n- [[A page nobody has written yet]]\n- [[Getting Started|The beginner's page]]"],
], ['description' => 'The front page of the fixture wiki.', 'views' => 120, 'sticky' => 1]);

wiki_add_history('Getting Started', [
	['user' => $editor_id, 'reason' => 'First version', 'text' => 'How to begin. Back to the [[Main Page]].'],
	['user' => $uploader_id, 'reason' => 'Explain editing', 'text' => "How to begin.\n\n[list][*]Read the [[Markup Guide]][*]Edit a page[*]Look at the version history[/list]\n\nBack to the [[Main Page]]."],
], ['description' => 'A first look at editing the wiki.', 'views' => 64, 'sources' => "https://example.org/docs\nhttps://example.org/faq"]);

wiki_add_history('Markup Guide', [
	['user' => $moderator_id, 'reason' => 'First version', 'text' => "[b]Bold[/b], [i]italic[/i], [url=https://example.org]links[/url].\n\nSee also [[Getting Started]]."],
	['user' => $uploader_id, 'reason' => 'Add the diagram and the download', 'text' => "[b]Bold[/b], [i]italic[/i], [url=https://example.org]links[/url] and [[wiki links]].\n\nThe diagram:\n\n[[File:diagram.png]]\n\nDownload [[File:notes.zip|the notes]]. A file that was never uploaded: [[File:missing.png]]\n\nSee also [[Getting Started]]."],
], ['description' => 'Formatting, links and files.', 'views' => 31]);

// Live version plus a newer one waiting for approval (shown in the Pending list).
wiki_add_history('Release Process', [
	['user' => $moderator_id, 'reason' => 'First version', 'text' => "1. Branch\n2. Test\n3. Tag"],
], ['description' => 'How a release is made.', 'views' => 12]);
wiki_add_version(['title' => 'Release Process', 'user' => $editor_id, 'reason' => 'Add the changelog step', 'approved' => 0, 'time' => time() - 3600, 'created' => time() - 3 * 86400, 'description' => 'How a release is made.', 'text' => "1. Branch\n2. Test\n3. Write the changelog\n4. Tag"]);

// An edit that is older than the live version: shows the "older than the live version" warning.
wiki_add_history('Style Guide', [
	['user' => $editor_id, 'reason' => 'Old draft that was never approved', 'text' => 'Old draft.'],
	['user' => $moderator_id, 'reason' => 'Rewritten', 'text' => 'The rewritten style guide.'],
], ['description' => 'House style.', 'views' => 8]);
wiki_add_version(['title' => 'Style Guide', 'user' => $editor_id, 'reason' => 'Stale pending edit', 'approved' => 0, 'time' => time() - 5 * 86400, 'created' => time() - 6 * 86400, 'description' => 'House style.', 'text' => 'A pending edit that predates the live version.']);

// Never approved: no live version, so only people who may view inactive articles see it.
wiki_add_history('Draft Notes', [
	['user' => $editor_id, 'reason' => 'Proposed article', 'text' => 'Notes that still need review.'],
], ['description' => 'Waiting for a moderator.'], false);

wiki_add_history('Important Notice', [
	['user' => $moderator_id, 'reason' => 'Posted', 'text' => 'This article is marked important, so it appears in its own list.'],
], ['description' => 'Marked important.', 'views' => 40, 'sticky' => 1]);

wiki_add_history('Old Name', [
	['user' => $moderator_id, 'reason' => 'Renamed', 'text' => 'This text is only shown to people who may set redirects.'],
], ['description' => 'Redirects to Getting Started.', 'redirect' => 'Getting Started']);

// Unicode in a title and name.
wiki_add_history('Übersicht ✓', [
	['user' => $editor_id, 'reason' => 'Anlegen', 'text' => 'Ein Artikel mit Umlauten im Titel. Zurück zur [[Main Page]].'],
], ['description' => 'Unicode title.', 'views' => 5]);

// Thirty versions, alternating editors: pages the version history and the contributions pages.
$long = [];
for ($i = 1; $i <= 30; $i++)
{
	$long[] = ['user' => [$editor_id, $uploader_id, $moderator_id][$i % 3], 'reason' => "Revision {$i} of 30", 'text' => "Revision {$i}.\n\nAn article that keeps being edited."];
}
wiki_add_history('Busy Article', $long, ['description' => 'Thirty saved versions.', 'views' => 77]);

for ($i = 1; $i <= 40; $i++)
{
	$n = sprintf('%02d', $i);
	wiki_add_history("Bulk Article {$n}", [
		['user' => [$editor_id, $uploader_id, $moderator_id][$i % 3], 'reason' => 'Bulk fixture', 'text' => "Bulk article {$n}.\n\nSee the [[Main Page]]."],
	], ['description' => "Bulk article number {$n}.", 'views' => ($i * 7) % 53]);
}
// Headings, tables, templates and parser functions need an extension build that has them.
if (class_exists('\\phpbbmodders\\wiki\\wiki\\templates'))
{
	echo "==> Templates and markup\n";
	// [[Category:...]] needs a build with categories; before that it would stay plain text
	$has_categories = class_exists('\\phpbbmodders\\wiki\\wiki\\categories');
	$category = static function (string ...$names) use ($has_categories): string {
		return $has_categories ? "\n\n" . implode(' ', array_map(static fn (string $name): string => "[[Category:{$name}]]", $names)) : '';
	};
	$call_demos = [
		'{{#if: x | yes | no}}', '{{#switch: b | a = one | b = two | #default = other}}', '{{#expr: (2 + 3) * 4}}',
		'{{#time: j F Y | 2026-10-12}}', '{{#ifexist: Version Table | there | missing}}', '{{#sub: hello world | 6}}',
		'{{#replace: a-b-c | - | +}}', '{{padleft: 42 | 5}}', '{{#iferror: {{#expr: 1/0}} | an error | fine}}',
		'{{SITENAME}}', '{{CURRENTYEAR}}',
	];
	$call_rows = '';
	foreach ($call_demos as $call)
	{
		$call_rows .= "<tr><td>[code]{$call}[/code]</td><td>{$call}</td></tr>\n";
	}

	// An older, plainer version first, so the template has a history
	wiki_add_history('Template:Out of date', [
		['user' => $moderator_id, 'reason' => 'First version', 'text' => '[b]This article is out of date.[/b]'],
		['user' => $moderator_id, 'reason' => 'Add the reason', 'text' => "<noinclude>Shows a notice that an article is out of date. Use it as {{Out of date|reason=...}}.\n\nExample:\n\n{{Out of date|reason=the 2.0 release changed this}}" . $category('Notice templates') . "</noinclude><table><tr><td>[b]This article is out of date.[/b] {{#if: {{{reason|}}} | Reason: {{{reason}}}. }}</td></tr></table>"],
	], ['description' => 'A notice for articles that need updating', 'views' => 9]);
	wiki_add_history('Template:Extension infobox', [
		['user' => $moderator_id, 'reason' => 'First version', 'text' => "<noinclude>A box of facts about an extension. Example:\n\n{{Extension infobox|name=Wiki|version=1.1.0|requires=phpBB 3.3.19|author=phpBB Modders}}" . $category('Infobox templates') . "</noinclude><table>\n<caption>{{{name}}}</caption>\n<tr><th>Version</th><td>{{{version|unknown}}}</td></tr>\n{{#if: {{{requires|}}} | <tr><th>Requires</th><td>{{{requires}}}</td></tr> }}\n{{#if: {{{author|}}} | <tr><th>Author</th><td>{{{author}}}</td></tr> }}\n</table>"],
	], ['description' => 'Facts about an extension in a box']);
	wiki_add_history('Template:Row', [
		['user' => $moderator_id, 'reason' => 'First version', 'text' => '<tr><th>{{{1}}}</th><td>{{{2|}}}</td></tr>'],
	], ['description' => 'One row of a table: {{Row|label|value}}']);
	// Templates called Start ... are offered when a new article is created
	wiki_add_history('Template:Start Extension', [
		['user' => $moderator_id, 'reason' => 'First version', 'text' => "<noinclude>A starting point for an article about an extension.</noinclude>{{Extension infobox|name=|version=|requires=|author=}}\n\n== What it does ==\n\n== Requirements ==\n\n== Installation ==\n\n== Settings ==\n"],
	], ['description' => 'An article about an extension']);
	wiki_add_history('Template:Start Person', [
		['user' => $moderator_id, 'reason' => 'First version', 'text' => "<noinclude>A starting point for an article about a person.</noinclude>== Life ==\n\n== Work ==\n\n== See also ==\n"],
	], ['description' => 'An article about a person']);

	wiki_add_history('Example Extension', [
		['user' => $editor_id, 'reason' => 'First version', 'text' => "{{Out of date|reason=the 2.0 release changed the settings}}\n\n{{Extension infobox|name=Example Extension|version=1.1.0|requires=phpBB {{#expr: 3 + 0.3 round 1}}.19 and PHP 8.2|author=phpBB Modders}}\n\nThe example extension adds a wiki to a board. See [[Version Table]] for the releases and [[Parser Functions]] for what templates can do." . $category('Extensions')],
	], ['description' => 'An article that uses two templates', 'views' => 21]);
	wiki_add_history('Version Table', [
		['user' => $editor_id, 'reason' => 'First version', 'text' => "Releases and what they changed:\n\n<table>\n<caption>Releases</caption>\n<tr><th>Version</th><th colspan=\"2\">Changes</th></tr>\n<tr><td>[b]1.0[/b]</td><td>First release</td><td>[[Example Extension]]</td></tr>\n<tr><td rowspan=\"2\">[b]1.1[/b]</td><td>Headings, tables and templates</td><td>[url=https://example.org]notes[/url]</td></tr>\n<tr><td>Parser functions</td><td>In use here</td></tr>\n</table>\n\nA table whose rows come from a template:\n\n<table>\n{{Row|Name|Wiki}}\n{{Row|Kind|Extension}}\n</table>" . $category('Extensions', 'Reference')],
	], ['description' => 'A table, and a table built from templates', 'views' => 14]);
	wiki_add_history('Parser Functions', [
		['user' => $uploader_id, 'reason' => 'First version', 'text' => "What templates can work out:\n\n<table>\n<tr><th>Written</th><th>Shows</th></tr>\n{$call_rows}</table>" . $category('Reference')],
	], ['description' => 'Parser functions with their results', 'views' => 17]);
	if ($has_categories)
	{
		// A category with a description page; Reference has none, to show the list without one
		wiki_add_history('Category:Extensions', [
			['user' => $moderator_id, 'reason' => 'First version', 'text' => 'Extensions for phpBB, and what they do.'],
		], ['description' => 'The category of extensions']);
	}
	wiki_add_history('Headings and Contents', [
		['user' => $moderator_id, 'reason' => 'First version', 'text' => "How a release is made, from branch to tag.\n\n== Preparing ==\nBranch from main and freeze the language files.\n=== Branch ===\nName it release-x.y.\n=== Language files ===\nRun the translation check.\n== Testing ==\nRun the unit tests, then the browser checks. See [[Getting Started#Editing]] and [[#Publishing]].\n== Publishing ==\nTag the release and attach the package.\n== See also ==\n[[Main Page]]"],
	], ['description' => 'Headings, a contents box and links to sections', 'views' => 11]);
}
else
{
	echo "    this build of the extension has no templates; the template articles were skipped\n";
}

$result = $db->sql_query('SELECT COUNT(*) AS total FROM ' . $articles_table);
$version_total = (int) $db->sql_fetchfield('total');
$db->sql_freeresult($result);
echo "    {$version_total} versions saved\n";

/**
 * A small gradient PNG, built without the GD extension.
 *
 * @param int $width  Image width in pixels.
 * @param int $height Image height in pixels.
 * @param int $red    Red channel at the left edge.
 * @return string PNG bytes.
 */
function wiki_png(int $width, int $height, int $red): string
{
	$raw = '';
	for ($y = 0; $y < $height; $y++)
	{
		$raw .= "\x00";
		for ($x = 0; $x < $width; $x++)
		{
			$raw .= chr(($red + intdiv($x * 80, $width)) % 256) . chr(60 + intdiv($y * 120, $height)) . chr(150);
		}
	}
	$chunk = static function (string $type, string $data): string {
		return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
	};
	return "\x89PNG\r\n\x1a\n" . $chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0)) . $chunk('IDAT', gzcompress($raw)) . $chunk('IEND', '');
}

/**
 * A ZIP archive holding one stored (uncompressed) text file, built without ZipArchive.
 *
 * @param string $name     File name inside the archive.
 * @param string $contents File contents.
 * @return string ZIP bytes.
 */
function wiki_zip(string $name, string $contents): string
{
	$crc = crc32($contents);
	$size = strlen($contents);
	$local = pack('VvvvvvVVVvv', 0x04034b50, 20, 0, 0, 0, 0x21, $crc, $size, $size, strlen($name), 0) . $name . $contents;
	$central = pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0, 0, 0, 0x21, $crc, $size, $size, strlen($name), 0, 0, 0, 0, 0, 0) . $name;
	$end = pack('VvvvvVVv', 0x06054b50, 0, 0, 1, 1, strlen($central), strlen($local), 0);
	return $local . $central . $end;
}

echo "==> Files\n";
// Older builds of the extension have no files table; probing it keeps the script portable across databases.
$db->sql_return_on_error(true);
$probe = $db->sql_query('SELECT 1 FROM ' . $files_table . ' WHERE 1 = 0');
$db->sql_return_on_error(false);
if ($probe !== false)
{
	$db->sql_freeresult($probe);
	$store = $phpbb_root_path . 'store/phpbbmodders_wiki/';
	if (!is_dir($store))
	{
		mkdir($store, 0755, true);
		file_put_contents($store . '.htaccess', "# Files are only read through the wiki.\n<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tOrder allow,deny\n\tDeny from all\n</IfModule>\n");
		file_put_contents($store . 'index.htm', '');
	}
	$files = [
		['Main Page', 'logo.png', wiki_png(160, 80, 40), 'image/png', 1, $moderator_id],
		['Markup Guide', 'diagram.png', wiki_png(240, 140, 120), 'image/png', 1, $uploader_id],
		['Markup Guide', 'notes.zip', wiki_zip('notes.txt', "Notes for the fixture wiki.\n"), 'application/octet-stream', 0, $uploader_id],
	];
	foreach ($files as [$article, $name, $bytes, $mime, $is_image, $owner])
	{
		$physical = $owner . '_' . bin2hex(random_bytes(16));
		file_put_contents($store . $physical, $bytes);
		$db->sql_query('INSERT INTO ' . $files_table . ' ' . $db->sql_build_array('INSERT', [
			'article_url' => $article, 'file_name' => $name, 'physical_name' => $physical, 'mimetype' => $mime,
			'file_size' => strlen($bytes), 'is_image' => $is_image, 'user_id' => $owner, 'file_time' => time() - 86400,
		]));
	}
	echo '    ' . count($files) . " files stored in store/phpbbmodders_wiki/\n";
}
else
{
	echo "    this build of the extension has no file uploads; skipped\n";
}

echo "==> Discussion topic\n";
// submit_post() needs a fully loaded user row (is_registered, colours, ...), which this
// script otherwise does not set up.
$full_admin = wiki_row(USERS_TABLE, 'user_id = ' . (int) $admin['user_id']);
$user->data = array_merge($user->data, $full_admin, ['is_registered' => true]);
$topic_text = "A real topic, so the article's Discussion button works.\n\n[b][url=" . generate_board_url() . '/wiki/Getting%20Started]Read the article[/url][/b]';
$uid = $bitfield = '';
$flags = 0;
generate_text_for_storage($topic_text, $uid, $bitfield, $flags, true, true, true);
$poll = [];
$data = [
	'topic_title' => 'Getting Started', 'forum_id' => $forum_id, 'forum_name' => 'Wiki Discussions', 'icon_id' => 0,
	'poster_id' => (int) $admin['user_id'], 'enable_bbcode' => true, 'enable_smilies' => true, 'enable_urls' => true,
	'enable_sig' => true, 'notify' => 0, 'notify_set' => '', 'enable_indexing' => true, 'message' => $topic_text,
	'message_md5' => md5($topic_text), 'bbcode_bitfield' => $bitfield, 'bbcode_uid' => $uid, 'post_edit_locked' => 0,
];
submit_post('post', 'Getting Started', $admin['username'], POST_NORMAL, $poll, $data, false);
$topic_id = (int) $data['topic_id'];
$db->sql_query('UPDATE ' . $articles_table . " SET article_topic_id = {$topic_id} WHERE article_url = 'Getting Started'");
echo "    topic_id={$topic_id} linked to \"Getting Started\"; the other articles show Start discussion to people who may link topics\n";

$cache->purge();

echo "\nDone. Open /wiki/ . Sign in as:\n";
echo "  wiki_reader / " . WIKI_SEED_PASSWORD . "     reads only (no edit, no history)\n";
echo "  wiki_editor / " . WIKI_SEED_PASSWORD . "     edits and sees version history; edits wait for approval\n";
echo "  wiki_uploader / " . WIKI_SEED_PASSWORD . "   editor who can also upload files and start discussions\n";
echo "  wiki_moderator / " . WIKI_SEED_PASSWORD . "  approves and removes versions, sets redirects, sees inactive articles\n";
echo "Pending list: Release Process and Style Guide (stale) and Draft Notes (never approved).\n";
echo "Categories: /wiki/categories lists Extensions (with a description page), Reference (without one), Infobox templates and Notice templates, when the extension has categories.\n";
echo "Templates: /wiki/templates lists five; open a new article (for example /wiki/Brand%20New?action=edit) to try Start from. Example Extension, Version Table, Parser Functions and Headings and Contents use them.\n";
echo "Contributions: /wiki/contributions/wiki_editor, wiki_uploader, wiki_moderator (Busy Article has thirty versions).\n";
