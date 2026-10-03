<?php
/**
 * Seed an installed board with the Knowledgebase extension enabled.
 *
 * Input: phpBB board root as the first argument. Create public comments and
 * private changelog forums, four users, two groups, three KB categories, and
 * eleven articles covering approvals, revisions, co-authorship, redirects,
 * tags, comments, and private drafts. Print created IDs and login guidance.
 * Reject existing fixture forums. Use the runner for seed-ledger protection.
 *
 * Usage: php seed-kb-fixtures.php /path/to/phpBB/root
 */

if (php_sapi_name() !== 'cli')
{
	fwrite(STDERR, "This script must be run from the command line.\n");
	exit(1);
}

$phpbb_root_path = rtrim($argv[1] ?? '', '/') . '/';
$phpEx = 'php';

if (!is_file($phpbb_root_path . 'config.php'))
{
	fwrite(STDERR, "Not an installed phpBB root (no config.php found): {$phpbb_root_path}\n");
	exit(1);
}

define('IN_PHPBB', true);
chdir($phpbb_root_path);

require($phpbb_root_path . 'includes/startup.' . $phpEx);
require($phpbb_root_path . 'phpbb/class_loader.' . $phpEx);

$phpbb_class_loader = new \phpbb\class_loader('phpbb\\', "{$phpbb_root_path}phpbb/", $phpEx);
$phpbb_class_loader->register();

$phpbb_config_php_file = new \phpbb\config_php_file($phpbb_root_path, $phpEx);
extract($phpbb_config_php_file->get_all());

if (!defined('PHPBB_ENVIRONMENT'))
{
	define('PHPBB_ENVIRONMENT', 'production');
}

require($phpbb_root_path . 'includes/constants.' . $phpEx);
require($phpbb_root_path . 'includes/functions.' . $phpEx);
require($phpbb_root_path . 'includes/functions_admin.' . $phpEx);
require($phpbb_root_path . 'includes/functions_user.' . $phpEx);
require($phpbb_root_path . 'includes/utf/utf_tools.' . $phpEx);
require($phpbb_root_path . 'includes/functions_compatibility.' . $phpEx);
require($phpbb_root_path . 'includes/acp/auth.' . $phpEx);
require($phpbb_root_path . 'includes/functions_acp.' . $phpEx);
require($phpbb_root_path . 'includes/functions_content.' . $phpEx);

$phpbb_class_loader_ext = new \phpbb\class_loader('\\', "{$phpbb_root_path}ext/", $phpEx);
$phpbb_class_loader_ext->register();

$phpbb_container_builder = new \phpbb\di\container_builder($phpbb_root_path, $phpEx);
$phpbb_container = $phpbb_container_builder->with_config($phpbb_config_php_file)->get_container();
$phpbb_container->get('request')->enable_super_globals();

require($phpbb_root_path . 'includes/compatibility_globals.' . $phpEx);
register_compatibility_globals();

/** @var \phpbb\config\config $config */
/** @var \phpbb\db\driver\driver_interface $db */
/** @var \phpbb\user $user */
/** @var \phpbb\auth\auth $auth */
/** @var \phpbb\log\log_interface $phpbb_log */
$language->set_default_language($config['default_lang']);
$language->add_lang(['common', 'acp/common', 'acp/forums', 'cli']);

$config_text = $phpbb_container->get('config_text');
$password_manager = $phpbb_container->get('passwords.manager');

// Run as the admin the installer created, so log entries and
// forum/category ownership resolve to a real, privileged user.
$sql = 'SELECT user_id, username FROM ' . USERS_TABLE . " WHERE username = 'admin'";
$result = $db->sql_query($sql);
$admin_row = $db->sql_fetchrow($result);
$db->sql_freeresult($result);

if (!$admin_row)
{
	fwrite(STDERR, "Couldn't find the 'admin' user - was the board actually installed?\n");
	exit(1);
}

$user->data['user_id'] = (int) $admin_row['user_id'];
$user->data['username'] = $admin_row['username'];
$user->data['user_permissions'] = '';
$user->data['user_ip'] = '127.0.0.1';
$user->ip = '127.0.0.1';
$user->lang = [];

$result = $db->sql_query('SELECT forum_id FROM ' . FORUMS_TABLE . " WHERE forum_name IN ('Knowledge Base Comments', 'Knowledge Base Changelog')");
$existing_fixture = $db->sql_fetchrow($result);
$db->sql_freeresult($result);
if ($existing_fixture)
{
	fwrite(STDERR, "Knowledgebase fixture forums already exist; reset before seeding again.\n");
	exit(1);
}

echo "Seeding as admin (user_id {$user->data['user_id']})...\n";

/**
 * Creates a normal post forum at the board root and returns its id.
 */
function seed_create_forum(string $name, string $desc): int
{
	global $db, $phpbb_root_path, $phpEx;

	require_once($phpbb_root_path . 'includes/acp/acp_forums.' . $phpEx);

	$acp_forums = new acp_forums();
	$forum_data = [
		'parent_id'              => 0,
		'forum_type'              => FORUM_POST,
		'type_action'             => '',
		'forum_status'            => ITEM_UNLOCKED,
		'forum_parents'           => '',
		'forum_name'              => $name,
		'forum_link'              => '',
		'forum_link_track'        => false,
		'forum_desc'              => $desc,
		'forum_desc_uid'          => '',
		'forum_desc_options'      => 7,
		'forum_desc_bitfield'     => '',
		'forum_rules'             => '',
		'forum_rules_uid'         => '',
		'forum_rules_options'     => 7,
		'forum_rules_bitfield'    => '',
		'forum_rules_link'        => '',
		'forum_image'             => '',
		'forum_style'             => 0,
		'display_subforum_list'   => true,
		'display_subforum_limit'  => false,
		'display_on_index'        => true,
		'forum_topics_per_page'   => 0,
		'enable_indexing'         => true,
		'enable_icons'            => true,
		'enable_prune'            => false,
		'enable_post_review'      => true,
		'enable_quick_reply'      => false,
		'enable_shadow_prune'     => false,
		'prune_days'              => 7,
		'prune_viewed'            => 7,
		'prune_freq'              => 1,
		'prune_old_polls'         => false,
		'prune_announce'          => false,
		'prune_sticky'            => false,
		'prune_shadow_days'       => 7,
		'prune_shadow_freq'       => 1,
		'forum_password'          => '',
		'forum_password_confirm'  => '',
		'forum_password_unset'    => false,
		'forum_options'           => 0,
		'show_active'             => true,
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
 * Creates a registered user with the given username and returns its id,
 * or reuses one that already exists (so re-seeding without a full
 * reset-board.sh run doesn't fail on a duplicate username).
 */
function seed_create_user(string $username, string $email): int
{
	global $db, $config, $password_manager;

	$sql = 'SELECT user_id FROM ' . USERS_TABLE . ' WHERE username = \'' . $db->sql_escape($username) . "'";
	$result = $db->sql_query($sql);
	$row = $db->sql_fetchrow($result);
	$db->sql_freeresult($result);

	if ($row)
	{
		return (int) $row['user_id'];
	}

	$user_row = [
		'username'      => $username,
		'user_password' => $password_manager->hash('KbTest1234!'),
		'user_email'    => $email,
		'group_id'      => 2, // GROUP_REGISTERED
		'user_timezone' => $config['board_timezone'],
		'user_lang'     => $config['default_lang'],
		'user_type'     => USER_NORMAL,
		'user_regdate'  => time(),
	];

	$user_id = (int) user_add($user_row);
	if (!$user_id)
	{
		fwrite(STDERR, "Failed to create user '{$username}'\n");
		exit(1);
	}

	return $user_id;
}

echo "==> Forums\n";
$announce_forum_id = seed_create_forum('Knowledge Base Comments', 'Public comment threads for KB articles.');
$changelog_forum_id = seed_create_forum('Knowledge Base Changelog', 'Moderator-only per-article changelog threads.');
echo "    comments forum_id={$announce_forum_id}, changelog forum_id={$changelog_forum_id}\n";

echo "==> Users\n";
$author1_id = seed_create_user('kb_author1', 'kb_author1@example.org');
$author2_id = seed_create_user('kb_author2', 'kb_author2@example.org');
$moderator_id = seed_create_user('kb_moderator', 'kb_moderator@example.org');
$reader_id = seed_create_user('kb_reader', 'kb_reader@example.org');
echo "    kb_author1={$author1_id} kb_author2={$author2_id} kb_moderator={$moderator_id} kb_reader={$reader_id} (password for all: KbTest1234!)\n";

echo "==> Team group\n";
$sql = "SELECT group_id FROM " . GROUPS_TABLE . " WHERE group_name = 'KB Team'";
$result = $db->sql_query($sql);
$row = $db->sql_fetchrow($result);
$db->sql_freeresult($result);

if ($row)
{
	$team_group_id = (int) $row['group_id'];
}
else
{
	$team_group_id = 0;
	group_create($team_group_id, GROUP_OPEN, 'KB Team', 'Seed fixture team group for KB group-authored articles.', []);
}

group_user_add($team_group_id, [$moderator_id, $author2_id]);
$config_text->set('kb_team_groups', json_encode([$team_group_id]));
echo "    group_id={$team_group_id}, members: kb_moderator, kb_author2\n";

echo "==> Contributors group\n";
// kb_u_add/edit/delete get granted to this group below, not to
// REGISTERED as a whole - kb_reader needs to stay REGISTERED (for
// u_kb_view) without also picking up submit rights, so those two have
// to live on different groups rather than both riding on REGISTERED.
$sql = "SELECT group_id FROM " . GROUPS_TABLE . " WHERE group_name = 'KB Contributors'";
$result = $db->sql_query($sql);
$row = $db->sql_fetchrow($result);
$db->sql_freeresult($result);

if ($row)
{
	$contributors_group_id = (int) $row['group_id'];
}
else
{
	$contributors_group_id = 0;
	group_create($contributors_group_id, GROUP_OPEN, 'KB Contributors', 'Seed fixture group: can submit/edit/delete their own KB articles.', []);
}

group_user_add($contributors_group_id, [$author1_id, $author2_id, $moderator_id]);
echo "    group_id={$contributors_group_id}, members: kb_author1, kb_author2, kb_moderator (kb_reader deliberately excluded)\n";

echo "==> Permissions and KB config\n";
$auth_admin = new auth_admin();
$auth_admin->acl_set('user', 0, $moderator_id, ['a_manage_kb' => ACL_YES]);

// u_kb_view (a standard phpBB global permission, registered by the
// extension's own migration) gates the whole KB - version_1_0_7.php
// adds the option but grants it to nobody by default, so without this,
// only a_manage_kb holders (i.e. only kb_moderator) could see the KB
// at all. REGISTERED covers all four seeded users alike, including
// kb_reader, who should be able to see the KB without being able to
// submit anything into it.
//
// Granted BEFORE the ROLE_USER_FULL assignment below, not after -
// acl_set() deletes any existing role of the same auth-flag type
// ('u_' here) for the group/forum every time it runs, as a "replace
// what this flag-type currently is" operation. Granting u_kb_view
// first (an individual permission, not part of any role) means it
// isn't touched by that cleanup; granting it after the role would
// silently wipe the role right back out.
$sql = "SELECT group_id FROM " . GROUPS_TABLE . " WHERE group_name = 'REGISTERED'";
$result = $db->sql_query($sql);
$registered_group_id = (int) $db->sql_fetchfield('group_id');
$db->sql_freeresult($result);

$auth_admin->acl_set('group', 0, $registered_group_id, ['u_kb_view' => ACL_YES]);

// The interactive/web phpBB installer assigns ROLE_USER_FULL to
// REGISTERED as one of its final install steps - the headless CLI
// installer (install/phpbbcli.php, used by reset-board.sh) never does,
// leaving REGISTERED with no baseline permissions at all: no search,
// no PM, nothing under Quick Links that even a guest gets by default.
// The role itself still exists post-install; only the assignment to
// REGISTERED is missing. Assigning it here, by its own real permission
// set, is a phpBB-CLI-installer gap this seed script papers over -
// nothing to do with the extension.
$sql = "SELECT role_id FROM " . ACL_ROLES_TABLE . " WHERE role_name = 'ROLE_USER_FULL'";
$result = $db->sql_query($sql);
$user_full_role_id = (int) $db->sql_fetchfield('role_id');
$db->sql_freeresult($result);

if ($user_full_role_id)
{
	$sql = 'SELECT ao.auth_option, ard.auth_setting
		FROM ' . ACL_ROLES_DATA_TABLE . ' ard, ' . ACL_OPTIONS_TABLE . ' ao
		WHERE ard.role_id = ' . $user_full_role_id . '
			AND ard.auth_option_id = ao.auth_option_id';
	$result = $db->sql_query($sql);
	$role_auth = [];
	while ($row = $db->sql_fetchrow($result))
	{
		$role_auth[$row['auth_option']] = (int) $row['auth_setting'];
	}
	$db->sql_freeresult($result);

	$auth_admin->acl_set('group', 0, $registered_group_id, $role_auth, $user_full_role_id);
}

// update_forum_data() creates a forum with zero ACL rows - nobody, not
// even admin, can read it until something explicitly grants access
// (the real ACP "Add forum" form prompts for a "copy permissions from"
// forum; calling update_forum_data() directly skips that entirely).
$sql = "SELECT group_id, group_name FROM " . GROUPS_TABLE . " WHERE group_name IN ('ADMINISTRATORS', 'REGISTERED')";
$result = $db->sql_query($sql);
$standard_groups = [];
while ($row = $db->sql_fetchrow($result))
{
	$standard_groups[$row['group_name']] = (int) $row['group_id'];
}
$db->sql_freeresult($result);

// ROLE_FORUM_STANDARD, not a hand-picked permission list - it already
// covers read/list/post/reply/noapprove/subscribe plus the rest of a
// normal member's per-forum rights (f_search, f_download, f_bbcode,
// editing/deleting their own posts, ...). Hand-picking a shorter list
// here previously left f_search ungranted, which - combined with the
// ROLE_USER_FULL gap above - meant Quick Links' Search/Active/
// Unanswered topics entries never appeared for any REGISTERED member,
// not even in forums that have nothing to do with the KB.
$sql = "SELECT role_id FROM " . ACL_ROLES_TABLE . " WHERE role_name = 'ROLE_FORUM_STANDARD'";
$result = $db->sql_query($sql);
$forum_standard_role_id = (int) $db->sql_fetchfield('role_id');
$db->sql_freeresult($result);

// Comments forum: every registered member, same as anyone who can view
// the KB itself. Changelog forum: moderator-only, so just the KB team.
$auth_admin->acl_set('group', $announce_forum_id, $standard_groups['REGISTERED'], ['f_' => ACL_YES], $forum_standard_role_id);
$auth_admin->acl_set('group', $announce_forum_id, $standard_groups['ADMINISTRATORS'], ['f_' => ACL_YES], $forum_standard_role_id);
$auth_admin->acl_set('group', $changelog_forum_id, $team_group_id, ['f_' => ACL_YES], $forum_standard_role_id);
$auth_admin->acl_set('group', $changelog_forum_id, $standard_groups['ADMINISTRATORS'], ['f_' => ACL_YES], $forum_standard_role_id);

// Same CLI-installer gap as ROLE_USER_FULL above, but per-forum this
// time: the interactive installer assigns ROLE_FORUM_STANDARD to
// REGISTERED for every default forum it creates - the CLI installer
// creates "Your first forum" but never grants REGISTERED anything in
// it. Limit this repair to the installer defaults and KB comments:
// other seed scripts may have created private or read-only forums.
$sql = 'SELECT forum_id FROM ' . FORUMS_TABLE . " WHERE forum_name IN ('Your first category', 'Your first forum') OR forum_id = " . (int) $announce_forum_id;
$result = $db->sql_query($sql);
$all_forum_ids = [];
while ($row = $db->sql_fetchrow($result))
{
	$all_forum_ids[] = (int) $row['forum_id'];
}
$db->sql_freeresult($result);

if (!empty($all_forum_ids))
{
	$auth_admin->acl_set('group', $all_forum_ids, $standard_groups['REGISTERED'], ['f_' => ACL_YES], $forum_standard_role_id);
}

$auth_admin->acl_clear_prefetch();

$config->set('kb_forum_id', $announce_forum_id);
$config->set('kb_anounce', 1);
$config->set('kb_changelog_forum_id', $changelog_forum_id);

// phpBB only emits clean /knowledgebase/... links (instead of
// /app.php/knowledgebase/...) once this is on - a board-wide core
// setting, nothing the extension itself controls. Safe to default on
// here since reset-board.sh's own nginx config already has the
// @rewriteapp rewrite block clean URLs need.
$config->set('enable_mod_rewrite', 1);

echo "==> KB categories\n";
/** @var \phpbbmodders\knowledgebase\controller\acp_controller $acp */
$acp = $phpbb_container->get('phpbbmodders.knowledgebase.controller.acp');

$getting_started = ['category_name' => 'Getting Started', 'parent_id' => 0];
$acp->update_category_data($getting_started, 0);
$getting_started_id = (int) $getting_started['category_id'];

// default_team_id set here (not on Getting Started) so the two root
// categories differ - Reference exercises a category with no default
// team (assigned_team_id must be set explicitly, or the queue's
// "effective team" falls through to none), Advanced Topics below
// exercises the fallback itself.
$reference = ['category_name' => 'Reference', 'parent_id' => 0];
$acp->update_category_data($reference, 0);
$reference_id = (int) $reference['category_id'];

// Second root-level category (a sibling of Getting Started, not nested
// under it) - both to have somewhere else to test "move to category"
// with, and because the root-level "new category always nested under
// the first one" bug this fixture setup would have silently matched
// before it was fixed.
$advanced_topics = ['category_name' => 'Advanced Topics', 'parent_id' => $getting_started_id, 'default_team_id' => $team_group_id];
$acp->update_category_data($advanced_topics, 0);
$advanced_topics_id = (int) $advanced_topics['category_id'];
echo "    Getting Started (id={$getting_started_id}), Advanced Topics (id={$advanced_topics_id}, default team=KB Team), Reference (id={$reference_id})\n";

// update_category_data() with copy_perm_from_id=0 (nothing to copy
// from - these are the first categories on the board) leaves these
// category-scoped permissions (this extension's own kb_groups table,
// entirely separate from phpBB's standard ACL tables acl_kb_get()
// doesn't touch) completely empty, same underlying gap as the forum
// ACL fix above. Granted to KB Contributors, not REGISTERED - kb_reader
// is REGISTERED too (for u_kb_view) but must stay view-only, and
// acl_kb_get() has no per-user override that can revoke a group grant,
// only add on top of one - so "can view but not submit" only works if
// submit rights live on a narrower group kb_reader isn't in.
$sql = "SELECT auth_option_id, auth_option FROM " . $phpbb_container->getParameter('tables.kb_options_table');
$result = $db->sql_query($sql);
$kb_auth_option_ids = [];
while ($row = $db->sql_fetchrow($result))
{
	$kb_auth_option_ids[$row['auth_option']] = (int) $row['auth_option_id'];
}
$db->sql_freeresult($result);

$kb_groups_table = $phpbb_container->getParameter('tables.kb_groups_table');
foreach ([$getting_started_id, $advanced_topics_id, $reference_id] as $category_id)
{
	foreach (['kb_u_add', 'kb_u_edit', 'kb_u_delete'] as $option_name)
	{
		$sql = 'INSERT INTO ' . $kb_groups_table . ' (group_id, category_id, auth_option_id, auth_setting)
			VALUES (' . $contributors_group_id . ', ' . $category_id . ', ' . $kb_auth_option_ids[$option_name] . ', ' . ACL_YES . ')';
		$db->sql_query($sql);
	}
}

echo "==> Articles\n";
/** @var \phpbbmodders\knowledgebase\inc\functions_kb $kb */
$kb = $phpbb_container->get('phpbbmodders.knowledgebase.inc');
$articles_table = $phpbb_container->getParameter('tables.articles_table');

/**
 * Direct insert into kb_articles - post_article() itself is an
 * HTTP-request-shaped controller method, not something worth faking a
 * request for just to seed fixture rows.
 */
function seed_create_article(string $table, array $data): int
{
	global $db;

	$data += [
		'article_date' => time(),
		'edit_date'    => time(),
		'bbcode_uid'   => '',
		'views'        => 0,
	];

	$sql = 'INSERT INTO ' . $table . ' ' . $db->sql_build_array('INSERT', $data);
	$db->sql_query($sql);

	return (int) $db->sql_nextid();
}

// 1. Plain single-author article.
$article1_id = seed_create_article($articles_table, [
	'article_category_id' => $getting_started_id,
	'approved'             => 1,
	'status'               => \phpbbmodders\knowledgebase\inc\functions_kb::STATUS_ACTIVE,
	'article_title'        => 'Welcome to the Knowledge Base',
	'article_description'  => 'Start here.',
	'article_body'         => 'This is a seeded article with a single author, used for baseline smoke-testing.',
	'author_id'             => $author1_id,
	'author'                => 'kb_author1',
	'author_type'           => \phpbbmodders\knowledgebase\inc\functions_kb::AUTHOR_TYPE_USER,
]);

// 2. Multi-author article - a user co-author plus the KB Team group.
$article2_id = seed_create_article($articles_table, [
	'article_category_id' => $getting_started_id,
	'approved'             => 1,
	'status'               => \phpbbmodders\knowledgebase\inc\functions_kb::STATUS_ACTIVE,
	'article_title'        => 'Installing the Board',
	'article_description'  => 'Step-by-step installation notes.',
	'article_body'         => 'This is a seeded article with co-authors, used to exercise the multi-author display.',
	'author_id'             => $author1_id,
	'author'                => 'kb_author1',
	'author_type'           => \phpbbmodders\knowledgebase\inc\functions_kb::AUTHOR_TYPE_USER,
]);
$kb->set_co_authors($article2_id, [$author2_id], [$team_group_id], $author1_id, \phpbbmodders\knowledgebase\inc\functions_kb::AUTHOR_TYPE_USER);

// 3. Group-authored, unapproved article - exercises the moderation queue.
$article3_id = seed_create_article($articles_table, [
	'article_category_id' => $advanced_topics_id,
	'approved'             => 0,
	'status'               => \phpbbmodders\knowledgebase\inc\functions_kb::STATUS_NEW,
	'article_title'        => 'Extending via Events',
	'article_description'  => 'Draft, pending approval.',
	'article_body'         => 'This is a seeded article credited to the KB Team group, awaiting moderator approval.',
	'author_id'             => $team_group_id,
	'author'                => 'KB Team',
	'author_type'           => \phpbbmodders\knowledgebase\inc\functions_kb::AUTHOR_TYPE_GROUP,
]);

// 4. Live article with a pending revision - exercises optimistic
// locking and the changelog thread.
$article4_id = seed_create_article($articles_table, [
	'article_category_id' => $advanced_topics_id,
	'approved'             => 1,
	'status'               => \phpbbmodders\knowledgebase\inc\functions_kb::STATUS_ACTIVE,
	'article_title'        => 'Performance Tuning',
	'article_description'  => 'Caching and query tips.',
	'article_body'         => 'This is the currently-live body of a seeded article that also has a pending revision.',
	'author_id'             => $author2_id,
	'author'                => 'kb_author2',
	'author_type'           => \phpbbmodders\knowledgebase\inc\functions_kb::AUTHOR_TYPE_USER,
]);
$kb->save_article_revision($article4_id, [
	'article_title'       => 'Performance Tuning',
	'article_description' => 'Caching and query tips (revised).',
	'article_body'         => 'This is a pending revision of the article body, awaiting moderator approval - used to test edit-conflict locking.',
	'bbcode_uid'           => '',
	'bbcode_bitfield'      => '',
], $author2_id);

// 5. Second plain unapproved article - lets the Administration queue show
// more than one row needing an initial approve/deny decision.
$article5_id = seed_create_article($articles_table, [
	'article_category_id' => $getting_started_id,
	'approved'             => 0,
	'status'               => \phpbbmodders\knowledgebase\inc\functions_kb::STATUS_NEW,
	'article_title'        => 'Troubleshooting Common Errors',
	'article_description'  => 'Draft, pending approval.',
	'article_body'         => 'This is a seeded article awaiting its first moderator approval.',
	'author_id'             => $author1_id,
	'author'                => 'kb_author1',
	'author_type'           => \phpbbmodders\knowledgebase\inc\functions_kb::AUTHOR_TYPE_USER,
]);

// 6. Second live article with a pending revision.
$article6_id = seed_create_article($articles_table, [
	'article_category_id' => $getting_started_id,
	'approved'             => 1,
	'status'               => \phpbbmodders\knowledgebase\inc\functions_kb::STATUS_ACTIVE,
	'article_title'        => 'Backup and Restore',
	'article_description'  => 'Keeping your data safe.',
	'article_body'         => 'This is the currently-live body of a second seeded article that also has a pending revision.',
	'author_id'             => $author1_id,
	'author'                => 'kb_author1',
	'author_type'           => \phpbbmodders\knowledgebase\inc\functions_kb::AUTHOR_TYPE_USER,
]);
$kb->save_article_revision($article6_id, [
	'article_title'       => 'Backup and Restore',
	'article_description' => 'Keeping your data safe (revised).',
	'article_body'         => 'This is a pending revision of the second article body, awaiting moderator approval.',
	'bbcode_uid'           => '',
	'bbcode_bitfield'      => '',
], $author1_id);

// 7. Third live article with a pending revision, in the other category.
$article7_id = seed_create_article($articles_table, [
	'article_category_id' => $advanced_topics_id,
	'approved'             => 1,
	'status'               => \phpbbmodders\knowledgebase\inc\functions_kb::STATUS_ACTIVE,
	'article_title'        => 'Securing Your Installation',
	'article_description'  => 'Hardening checklist.',
	'article_body'         => 'This is the currently-live body of a third seeded article that also has a pending revision.',
	'author_id'             => $author2_id,
	'author'                => 'kb_author2',
	'author_type'           => \phpbbmodders\knowledgebase\inc\functions_kb::AUTHOR_TYPE_USER,
]);
$kb->save_article_revision($article7_id, [
	'article_title'       => 'Securing Your Installation',
	'article_description' => 'Hardening checklist (revised).',
	'article_body'         => 'This is a pending revision of the third article body, awaiting moderator approval.',
	'bbcode_uid'           => '',
	'bbcode_bitfield'      => '',
], $author2_id);

// 8. Redirect entry - approved and active, so it shows up in the public
// listing immediately (a redirect never needs a pending-revision cycle
// the way a text article does).
$article8_id = seed_create_article($articles_table, [
	'article_category_id' => $getting_started_id,
	'approved'             => 1,
	'status'               => \phpbbmodders\knowledgebase\inc\functions_kb::STATUS_ACTIVE,
	'article_title'        => 'phpBB Official Site',
	'article_description'  => 'External link, used to exercise redirect entries.',
	'article_body'         => '',
	'is_redirect'          => 1,
	'redirect_url'         => 'https://www.phpbb.com/',
	'author_id'             => $author1_id,
	'author'                => 'kb_author1',
	'author_type'           => \phpbbmodders\knowledgebase\inc\functions_kb::AUTHOR_TYPE_USER,
]);

// 9. Tagged with the "phpBB Version: 3.3.x" custom field seeded by the
// extension's own version_2_0_5 migration - exercises the field filter
// on the category listing page without this script needing to define a
// field of its own.
$article9_id = seed_create_article($articles_table, [
	'article_category_id' => $getting_started_id,
	'approved'             => 1,
	'status'               => \phpbbmodders\knowledgebase\inc\functions_kb::STATUS_ACTIVE,
	'article_title'        => 'Upgrading Between Versions',
	'article_description'  => 'Tagged with a phpBB Version custom field value.',
	'article_body'         => 'This is a seeded article tagged with a custom field value, used to exercise the field filter.',
	'author_id'             => $author2_id,
	'author'                => 'kb_author2',
	'author_type'           => \phpbbmodders\knowledgebase\inc\functions_kb::AUTHOR_TYPE_USER,
]);
$sql = 'SELECT value_id FROM ' . $kb->get_field_values_table() . " WHERE value_label = '3.3.x'";
$result = $db->sql_query($sql);
$phpbb_version_value_id = (int) $db->sql_fetchfield('value_id');
$db->sql_freeresult($result);
if ($phpbb_version_value_id)
{
	$sql = 'INSERT INTO ' . $kb->get_article_field_values_table() . ' (article_id, value_id)
		VALUES (' . $article9_id . ', ' . $phpbb_version_value_id . ')';
	$db->sql_query($sql);
}

// 10. Real topic_id article - exercises the "View topic" moderator
// action, which only appears once an article actually has a comments
// topic. submit_post() (called by submit_article() below) needs a
// fully-loaded $user->data, not the pared-down one this script sets up
// for everything above it - it reads is_registered/user_colour/etc, and
// a NOT NULL constraint fails on topic_first_poster_colour without a
// real user_colour. Loaded here, right before the one call that needs
// it, rather than for the whole script.
require_once($phpbb_root_path . 'includes/functions_posting.' . $phpEx);
$sql = 'SELECT * FROM ' . USERS_TABLE . ' WHERE user_id = ' . (int) $admin_row['user_id'];
$result = $db->sql_query($sql);
$full_admin_row = $db->sql_fetchrow($result);
$db->sql_freeresult($result);
$user->data = array_merge($user->data, $full_admin_row, ['is_registered' => true]);

$article10_id = seed_create_article($articles_table, [
	'article_category_id' => $getting_started_id,
	'approved'             => 1,
	'status'               => \phpbbmodders\knowledgebase\inc\functions_kb::STATUS_ACTIVE,
	'article_title'        => 'Article With a Real Comments Topic',
	'article_description'  => 'Has a real topic_id, for the View Topic action.',
	'article_body'         => 'This is a seeded article with a real comments topic, used to exercise the View Topic moderator action.',
	'author_id'             => $author1_id,
	'author'                => 'kb_author1',
	'author_type'           => \phpbbmodders\knowledgebase\inc\functions_kb::AUTHOR_TYPE_USER,
]);
$kb->submit_article($getting_started_id, $announce_forum_id, 'Article With a Real Comments Topic', 'Has a real topic_id, for the View Topic action.', 'kb_author1', 'Getting Started', $article10_id);

// 11. Draft - never submitted, visible only to its author. Credited to
// kb_author1 (the account these fixtures are meant to be explored as)
// specifically so it's immediately visible on the Manage Your Articles
// tab in the web UI after logging in as kb_author1, rather than
// something that only shows up if you already know to look for it.
$article11_id = seed_create_article($articles_table, [
	'article_category_id' => $getting_started_id,
	'approved'             => 0,
	'status'               => \phpbbmodders\knowledgebase\inc\functions_kb::STATUS_DRAFT,
	'article_title'        => 'Unfinished Draft Article',
	'article_description'  => 'Still being written.',
	'article_body'         => 'This is a seeded draft, never submitted - visible only to kb_author1 on Manage Your Articles, not to moderators or in any public listing.',
	'author_id'             => $author1_id,
	'author'                => 'kb_author1',
	'author_type'           => \phpbbmodders\knowledgebase\inc\functions_kb::AUTHOR_TYPE_USER,
]);

// 12. Draft-of-an-edit on an already-live article - kept entirely
// separate from a real pending revision (see the Performance Tuning
// article above, which already has one), exercised via the extension's
// own API rather than a hand-built row, same as save_article_revision()
// above.
$kb->save_article_draft_edit($article1_id, [
	'article_title'       => 'Welcome to the Knowledge Base',
	'article_description' => 'Start here.',
	'article_body'         => 'This is a seeded draft-of-an-edit for the Welcome article, awaiting kb_author1 to either finish and submit it or leave it as-is.',
	'bbcode_uid'           => '',
	'bbcode_bitfield'      => '',
	'redirect_url'         => '',
], $author1_id);

echo "    article_id={$article1_id} Welcome to the Knowledge Base (active, has a private draft-of-an-edit)\n";
echo "    article_id={$article2_id} Installing the Board (active, co-authored)\n";
echo "    article_id={$article3_id} Extending via Events (pending approval, group-authored)\n";
echo "    article_id={$article4_id} Performance Tuning (active, pending revision)\n";
echo "    article_id={$article5_id} Troubleshooting Common Errors (pending approval)\n";
echo "    article_id={$article6_id} Backup and Restore (active, pending revision)\n";
echo "    article_id={$article7_id} Securing Your Installation (active, pending revision)\n";
echo "    article_id={$article8_id} phpBB Official Site (active, redirect entry)\n";
echo "    article_id={$article9_id} Upgrading Between Versions (active, tagged phpBB Version: 3.3.x)\n";
echo "    article_id={$article10_id} Article With a Real Comments Topic (active, real topic_id)\n";
echo "    article_id={$article11_id} Unfinished Draft Article (DRAFT - private to kb_author1)\n";

echo "\nDone. Log in as kb_author1 / KbTest1234! and open the \"Manage Your Articles\" tab to see the\n";
echo "seeded draft (and the Welcome article's separate draft-of-an-edit, visible when you edit it) in\n";
echo "the actual web UI - both are deliberately invisible to kb_moderator and everywhere else. Log in\n";
echo "as kb_moderator / KbTest1234! to see the Administration queue, kb_author1/kb_author2 for the\n";
echo "author side, or kb_reader (same password) to check view-only access.\n";
