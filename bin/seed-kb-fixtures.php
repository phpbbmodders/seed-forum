<?php
/**
 * Seed an installed board with the Knowledgebase extension enabled.
 *
 * Input: phpBB board root as the first argument. Create public comments and
 * private changelog forums, four users, three teams, a contributor group,
 * branching KB categories, and named articles covering approvals,
 * revisions, co-authorship,
 * redirects, tags, comments, Markdown, cross-listing, attachments, required
 * fields, and private drafts, plus a block of bulk articles so every listing
 * and every Administration queue choice runs to several pages. Print created
 * IDs and login guidance.
 * Reject existing fixture forums. Use the runner for seed-ledger protection.
 *
 * Requires PHP GD for the original/thumbnail image fixture.
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
$sql = 'SELECT * FROM ' . USERS_TABLE . " WHERE username = 'admin'";
$result = $db->sql_query($sql);
$admin_row = $db->sql_fetchrow($result);
$db->sql_freeresult($result);

if (!$admin_row)
{
	fwrite(STDERR, "Couldn't find the 'admin' user - was the board actually installed?\n");
	exit(1);
}

$user->data = $admin_row;
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
echo "    group_id={$team_group_id}, members: kb_moderator, kb_author2\n";

$other_team_ids = [];
foreach ([
	'Operations Team' => [$moderator_id, $author1_id],
	'Documentation Team' => [$moderator_id, $author1_id, $author2_id],
] as $team_name => $team_members)
{
	$other_team_id = 0;
	group_create($other_team_id, GROUP_OPEN, $team_name, 'Knowledgebase fixture team: ' . $team_name . '.', []);
	group_user_add($other_team_id, $team_members);
	$other_team_ids[$team_name] = (int) $other_team_id;
	echo "    group_id={$other_team_id} {$team_name}\n";
}
$operations_team_id = $other_team_ids['Operations Team'];
$documentation_team_id = $other_team_ids['Documentation Team'];
$team_ids = [$team_group_id, $operations_team_id, $documentation_team_id];
$config_text->set('kb_team_groups', json_encode($team_ids));

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
$auth->acl($user->data);

$config->set('kb_forum_id', $announce_forum_id);
$config->set('kb_anounce', 1);
$config->set('kb_changelog_forum_id', $changelog_forum_id);
$config->set('kb_allow_attachments', 1);
$config->set('kb_allow_thumbnail', 1);
$config->set('kb_articles_per_page', 10);
$config->set('kb_per_page_search', 10);
$config->set('kb_classic_look', 1);
$config->set('kb_contact_group', $documentation_team_id);
$config->set('kb_description_max_length', 80);

// phpBB only emits clean /knowledgebase/... links (instead of
// /app.php/knowledgebase/...) once this is on - a board-wide core
// setting, nothing the extension itself controls. Safe to default on
// here since reset-board.sh's own nginx config already has the
// @rewriteapp rewrite block clean URLs need.
$config->set('enable_mod_rewrite', 1);

echo "==> KB categories\n";
/** @var \phpbbmodders\knowledgebase\controller\acp_controller $acp */
$acp = $phpbb_container->get('phpbbmodders.knowledgebase.controller.acp');

$getting_started = ['category_name' => 'Getting Started', 'parent_id' => 0, 'category_parents' => ''];
$acp->update_category_data($getting_started, 0);
$getting_started_id = (int) $getting_started['category_id'];

// default_team_id set here (not on Getting Started) so the two root
// categories differ - Reference exercises a category with no default
// team (assigned_team_id must be set explicitly, or the queue's
// "effective team" falls through to none), Advanced Topics below
// exercises the fallback itself.
$reference = ['category_name' => 'Reference', 'parent_id' => 0, 'category_parents' => ''];
$acp->update_category_data($reference, 0);
$reference_id = (int) $reference['category_id'];

// A child with its own default team, beside roots with no default team.
$advanced_topics = ['category_name' => 'Advanced Topics', 'parent_id' => $getting_started_id, 'default_team_id' => $team_group_id, 'category_parents' => ''];
$acp->update_category_data($advanced_topics, 0);
$advanced_topics_id = (int) $advanced_topics['category_id'];
echo "    Getting Started (id={$getting_started_id}), Advanced Topics (id={$advanced_topics_id}, default team=KB Team), Reference (id={$reference_id})\n";

$category_ids = ['getting_started' => $getting_started_id, 'advanced' => $advanced_topics_id, 'reference' => $reference_id];
$category_specs = [
	['installation', 'getting_started', 'Installation', 'Choose an installation path.'],
	['local', 'installation', 'Local Development', 'Build and inspect a local board.'],
	['linux', 'local', 'Linux Workstations', 'Desktop and server development environments.'],
	['nginx', 'linux', 'Nginx and PHP-FPM', 'Web server setup and request routing.'],
	['routing', 'nginx', 'Clean URL Troubleshooting', 'Deepest installation branch: rewrite rules and route checks.'],
	['hosted', 'installation', 'Hosted Boards', 'Shared hosting and control panels.'],
	['first_steps', 'getting_started', 'First Steps', 'Accounts, forums, and your first article.'],
	['extensions', 'advanced', 'Extension Development', 'Services, events, and migrations.'],
	['events', 'extensions', 'Events and Listeners', 'Connect extension behavior to phpBB events.'],
	['empty_start', 'getting_started', 'Planned Tutorials', 'An intentionally empty section.'],
	['commands', 'reference', 'Command Reference', 'Commands organized by tool.'],
	['phpbb_cli', 'commands', 'phpBB CLI', 'Install, maintain, and inspect a board.'],
	['maintenance', 'phpbb_cli', 'Maintenance Commands', 'Cache, database, and extension maintenance.'],
	['cache', 'maintenance', 'Cache Operations', 'Cache invalidation and ownership checks.'],
	['cache_recipes', 'cache', 'Cache Recovery Recipes', 'Deepest reference branch: practical recovery steps.'],
	['glossary', 'reference', 'Glossary', 'Terminology and short definitions.'],
	['read_only', 'reference', 'Archived Release Notes', 'Published reference material; contributor posting is disabled.'],
	['operations', '', 'Board Operations', 'Run, protect, and recover a live community.'],
	['backups', 'operations', 'Backups and Recovery', 'Backup plans and restoration drills.'],
	['restore', 'backups', 'Restore Drills', 'Practice restoration before an incident.'],
	['database_restore', 'restore', 'Database Recovery', 'Recover a consistent database.'],
	['sqlite_restore', 'database_restore', 'SQLite Recovery', 'Local database restoration.'],
	['verify_restore', 'sqlite_restore', 'Post-Restore Verification', 'Deepest operations branch: verify the recovered board.'],
	['security', 'operations', 'Security', 'Permissions, updates, and access control.'],
	['team_only', 'security', 'Team Runbooks', 'Everyone can read; only Operations Team members can contribute.'],
	['autoapprove', 'operations', 'Routine Maintenance', 'Contributor submissions publish without approval.'],
	['community', '', 'Community Handbook', 'Help people participate and keep discussions useful.'],
	['moderation', 'community', 'Moderation', 'Everyday moderation procedures.'],
	['reports', 'moderation', 'Reports and Escalation', 'Review and route member reports.'],
	['appeals', 'reports', 'Appeals', 'Review a moderation decision.'],
	['review', 'appeals', 'Review Process', 'Collect evidence and communicate a decision.'],
	['templates', 'review', 'Decision Templates', 'Deepest community branch: reusable decision wording.'],
	['accessibility', 'community', 'Accessibility and Translation', 'Readable, accessible, multilingual content.'],
	['empty_community', 'community', 'Upcoming Community Guides', 'A second empty section in a different main category.'],
];
foreach ($category_specs as [$key, $parent, $name, $details])
{
	$category = [
		'category_name' => $name,
		'category_details' => $details,
		'parent_id' => $parent === '' ? 0 : $category_ids[$parent],
		'category_parents' => '',
		'default_team_id' => in_array($key, ['operations', 'team_only'], true) ? $operations_team_id : ($key === 'community' ? $documentation_team_id : 0),
	];
	$errors = $acp->update_category_data($category, 0);
	if ($errors)
	{
		throw new RuntimeException('Failed to create category ' . $name . ': ' . implode(', ', $errors));
	}
	$category_ids[$key] = (int) $category['category_id'];
	echo "    category_id={$category_ids[$key]} {$name} (parent_id={$category['parent_id']})\n";
}

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
foreach ($category_ids as $category_key => $category_id)
{
	if ($category_key === 'read_only')
	{
		continue;
	}
	$posting_group_id = $category_key === 'team_only' ? $operations_team_id : $contributors_group_id;
	foreach (['kb_u_add', 'kb_u_edit', 'kb_u_delete'] as $option_name)
	{
		$sql = 'INSERT INTO ' . $kb_groups_table . ' (group_id, category_id, auth_option_id, auth_setting)
			VALUES (' . $posting_group_id . ', ' . $category_id . ', ' . $kb_auth_option_ids[$option_name] . ', ' . ACL_YES . ')';
		$db->sql_query($sql);
	}
	if ($category_key === 'autoapprove')
	{
		$db->sql_query('INSERT INTO ' . $kb_groups_table . ' (group_id, category_id, auth_option_id, auth_setting)
			VALUES (' . $contributors_group_id . ', ' . $category_id . ', ' . $kb_auth_option_ids['kb_u_add_noapprove'] . ', ' . ACL_YES . ')');
	}
	if (in_array($category_key, ['events', 'team_only'], true))
	{
		$scoped_team_id = $category_key === 'events' ? $team_group_id : $operations_team_id;
		foreach (['kb_m_approve', 'kb_m_edit', 'kb_m_delete'] as $option_name)
		{
			$db->sql_query('INSERT INTO ' . $kb_groups_table . ' (group_id, category_id, auth_option_id, auth_setting)
				VALUES (' . $scoped_team_id . ', ' . $category_id . ', ' . $kb_auth_option_ids[$option_name] . ', ' . ACL_YES . ')');
		}
	}
}

echo "==> Articles\n";
/** @var \phpbbmodders\knowledgebase\inc\functions_kb $kb */
$kb = $phpbb_container->get('phpbbmodders.knowledgebase.inc');
/** @var \phpbbmodders\knowledgebase\textformatter\article_text $article_text */
$article_text = $phpbb_container->get('phpbbmodders.knowledgebase.article_text');
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

/**
 * Stores article text through the extension's real formatter.
 */
function seed_format_article_text(string $text, bool $markdown = false, bool $include_bitfield = false): array
{
	global $article_text;

	$uid = $bitfield = $options = '';
	$article_text->store($text, $markdown, $uid, $bitfield, $options);

	$fields = [
		'article_body'     => $text,
		'bbcode_uid'       => $uid,
		'article_markdown' => $markdown ? 1 : 0,
	];
	if ($include_bitfield)
	{
		$fields['bbcode_bitfield'] = $bitfield;
	}

	return $fields;
}

/**
 * Creates a small KB attachment row and matching file.
 */
function seed_create_attachment(int $article_id, int $poster_id, string $real_filename, string $contents, string $extension, string $mimetype, string $comment = '', bool $is_orphan = false, string $thumbnail = ''): int
{
	global $db, $phpbb_container;

	$upload_dir = $phpbb_container->get('ext.manager')->get_extension_path('phpbbmodders/knowledgebase', true) . 'files/';
	if (!is_dir($upload_dir) && !mkdir($upload_dir, 0775, true) && !is_dir($upload_dir))
	{
		fwrite(STDERR, "Failed to create Knowledgebase attachment directory: {$upload_dir}\n");
		exit(1);
	}

	// Stable names keep repeat resets from accumulating unreferenced files.
	$physical_filename = 'kb_fixture_' . sha1($real_filename . ':' . $article_id) . '.' . $extension;
	if (file_put_contents($upload_dir . $physical_filename, $contents) === false)
	{
		fwrite(STDERR, "Failed to write Knowledgebase attachment file: {$upload_dir}{$physical_filename}\n");
		exit(1);
	}
	if ($thumbnail !== '' && file_put_contents($upload_dir . 'thumb_' . $physical_filename, $thumbnail) === false)
	{
		throw new RuntimeException('Failed to write attachment thumbnail.');
	}

	$sql = 'INSERT INTO ' . $phpbb_container->getParameter('tables.kb_attachments_table') . ' ' . $db->sql_build_array('INSERT', [
		'article_id'        => $article_id,
		'poster_id'         => $poster_id,
		'is_orphan'         => $is_orphan ? 1 : 0,
		'physical_filename' => $physical_filename,
		'real_filename'     => $real_filename,
		'download_count'    => 0,
		'attach_comment'    => $comment,
		'extension'         => $extension,
		'mimetype'          => $mimetype,
		'filesize'          => strlen($contents),
		// ACP only lists orphan uploads once they are more than three hours old.
		'filetime'          => $is_orphan ? time() - 86400 : time(),
		'thumbnail'         => $thumbnail !== '' ? 1 : 0,
	]);
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
	$sql = 'UPDATE ' . $kb->get_fields_table() . '
		SET field_required = 1
		WHERE field_key = \'phpbb_version\'';
	$db->sql_query($sql);

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

// Private draft-of-an-edit on an already-live article - kept entirely
// separate from a real pending revision (see the Performance Tuning article
// above, which already has one), exercised via the extension's own API rather
// than a hand-built row, same as save_article_revision() above.
$kb->save_article_draft_edit($article1_id, [
	'article_title'       => 'Welcome to the Knowledge Base',
	'article_description' => 'Start here.',
	'article_body'         => 'This is a seeded draft-of-an-edit for the Welcome article, awaiting kb_author1 to either finish and submit it or leave it as-is.',
	'bbcode_uid'           => '',
	'bbcode_bitfield'      => '',
	'redirect_url'         => '',
], $author1_id);

// 12. Markdown article - stored through the extension's Markdown parser,
// cross-listed, tagged with the required phpBB Version field and carrying
// both a Markdown pending revision and a Markdown private draft-of-an-edit.
$markdown_article = seed_format_article_text("# Writing a useful board guide\n\n[Jump to the checklist](#before-you-publish)\n\nA guide should explain one task clearly. This paragraph is wrapped\nacross source lines but should read as a continuous paragraph.\n\n## Before you publish\n\n- Give the article a specific title.\n- Link to the relevant category.\n- Ask a teammate to check the steps.\n\n### Example command\n\n```bash\nphp bin/phpbbcli.php cache:purge\n```\n\n> Keep a backup before changing a live board.\n\n**Bold**, *emphasis*, and [phpBB documentation](https://www.phpbb.com/support/docs/) are included here.\n\nA deliberate hard break follows.  \nThis is its second line.\n\n## Checklist\n\n1. Preview the article.\n2. Check the links.\n3. Submit for review.", true);
$article12_id = seed_create_article($articles_table, array_merge([
	'article_category_id' => $advanced_topics_id,
	'approved'             => 1,
	'status'               => \phpbbmodders\knowledgebase\inc\functions_kb::STATUS_ACTIVE,
	'article_title'        => 'Markdown Authoring Guide',
	'article_description'  => 'Markdown format, required field, and cross-listing.',
	'author_id'             => $author2_id,
	'author'                => 'kb_author2',
	'author_type'           => \phpbbmodders\knowledgebase\inc\functions_kb::AUTHOR_TYPE_USER,
], $markdown_article));
$kb->set_cross_listed_categories($article12_id, [$reference_id]);
if ($phpbb_version_value_id)
{
	$sql = 'INSERT INTO ' . $kb->get_article_field_values_table() . ' (article_id, value_id)
		VALUES (' . $article12_id . ', ' . $phpbb_version_value_id . ')';
	$db->sql_query($sql);
}
$kb->save_article_revision($article12_id, array_merge([
	'article_title'       => 'Markdown Authoring Guide',
	'article_description' => 'Markdown format, required field, and cross-listing (revised).',
], seed_format_article_text("## Pending Markdown revision\n\nThis revision stays Markdown while moderators compare it with the live article.", true, true)), $author2_id);
$kb->save_article_draft_edit($article12_id, array_merge([
	'article_title'       => 'Markdown Authoring Guide',
	'article_description' => 'Markdown format, required field, and cross-listing.',
	'redirect_url'        => '',
], seed_format_article_text("## Private Markdown draft\n\nThis is separate from the pending revision and visible only to the author side.", true, true)), $author2_id);

// 13. Attachment article - exercises KB file delivery and attachment-box
// rendering. A separate orphan attachment lets uploader-only preview checks
// hit the article_id=0 path in kb_file.php.
$article13_id = seed_create_article($articles_table, array_merge([
	'article_category_id' => $reference_id,
	'approved'             => 1,
	'status'               => \phpbbmodders\knowledgebase\inc\functions_kb::STATUS_ACTIVE,
	'article_title'        => 'Attachment Handling Examples',
	'article_description'  => 'Published attachments plus orphan preview data.',
	'author_id'             => $author1_id,
	'author'                => 'kb_author1',
	'author_type'           => \phpbbmodders\knowledgebase\inc\functions_kb::AUTHOR_TYPE_USER,
], seed_format_article_text('This published article has KB-owned attachments so file delivery and ACP attachment listings have data to inspect.')));
$attachment1_id = seed_create_attachment($article13_id, $author1_id, 'kb-fixture-note.txt', "Knowledgebase attachment fixture.\n", 'txt', 'text/plain', 'Plain text attachment');
$orphan_attachment_id = seed_create_attachment(0, $author1_id, 'kb-fixture-orphan.txt', "Orphan preview attachment fixture.\n", 'txt', 'text/plain', 'Orphan preview attachment', true);

echo "==> Branch articles and custom fields\n";
$field_specs = [
	'audience' => ['Audience', \phpbbmodders\knowledgebase\inc\field\kb_single_select_field_type::class, 1, 0, ['Administrators', 'Moderators', 'Members', 'Retired audience']],
	'difficulty' => ['Difficulty', \phpbbmodders\knowledgebase\inc\field\kb_radio_select_field_type::class, 1, 1, ['Beginner', 'Intermediate', 'Advanced']],
	'topics' => ['Topics', \phpbbmodders\knowledgebase\inc\field\kb_multi_select_field_type::class, 1, 0, ['Installation', 'Maintenance', 'Permissions', 'Accessibility & translation']],
	'retired_field' => ['Retired internal label', \phpbbmodders\knowledgebase\inc\field\kb_single_select_field_type::class, 0, 0, ['Legacy']],
];
$fixture_fields = [];
foreach ($field_specs as $field_key => [$field_name, $field_type, $enabled, $required, $labels])
{
	$db->sql_query('INSERT INTO ' . $kb->get_fields_table() . ' ' . $db->sql_build_array('INSERT', [
		'field_key' => $field_key, 'field_name' => $field_name, 'field_type' => $field_type,
		'enabled' => $enabled, 'field_required' => $required,
	]));
	$field_id = (int) $db->sql_nextid();
	$value_ids = [];
	foreach ($labels as $order => $label)
	{
		$db->sql_query('INSERT INTO ' . $kb->get_field_values_table() . ' ' . $db->sql_build_array('INSERT', [
			'field_id' => $field_id, 'value_label' => $label,
			'enabled' => $label === 'Retired audience' ? 0 : 1, 'display_order' => $order,
		]));
		$value_ids[] = (int) $db->sql_nextid();
	}
	$fixture_fields[$field_key] = ['id' => $field_id, 'values' => $value_ids];
}

$branch_article_ids = [];
foreach ($category_specs as $index => [$key, $parent, $name, $details])
{
	if (in_array($key, ['empty_start', 'empty_community'], true))
	{
		continue;
	}
	$is_markdown = $index % 2 === 0;
	$body = $is_markdown
		? '# ' . $name . "\n\n" . $details . "\n\n## Before you begin\n\nRead the parent category, record the current settings, and keep a backup.\n\n## Walkthrough\n\n1. Open the relevant control panel.\n2. Make one change at a time.\n3. Check the result as a regular member.\n\n## Verify the result\n\nSearch for this guide using **branchwalk**. The category breadcrumb should show the complete path back to Knowledge base.\n\n[Back to the walkthrough](#walkthrough)"
		: '[b]' . $name . "[/b]\n\n" . $details . "\n\n[list][*]Record the current settings.[*]Check the result as a regular member.[*]Keep a backup.[/list]\n\n[quote]A repeatable procedure is easier to review.[/quote]\n\nSearch for this guide using branchwalk.\n[code]php bin/phpbbcli.php cache:purge[/code]";
	$title = $name . ': a practical guide';
	if ($key === 'accessibility')
	{
		// UTF-8 content checks title/search escaping and non-English rendering.
		$title = 'Accessible guides: caf' . "\u{00E9}" . ', ' . "\u{65E5}\u{672C}\u{8A9E}" . ', and clear link labels';
	}
	$branch_id = seed_create_article($articles_table, array_merge([
		'article_category_id' => $category_ids[$key], 'approved' => 1,
		'status' => \phpbbmodders\knowledgebase\inc\functions_kb::STATUS_ACTIVE,
		'article_title' => $title, 'article_description' => $details,
		'author_id' => $index % 2 === 0 ? $author1_id : $author2_id,
		'author' => $index % 2 === 0 ? 'kb_author1' : 'kb_author2',
		'author_type' => \phpbbmodders\knowledgebase\inc\functions_kb::AUTHOR_TYPE_USER,
		'assigned_team_id' => $index % 4 === 3 ? 0 : $team_ids[$index % 3],
		'display_order' => $index + 1,
	], seed_format_article_text($body, $is_markdown)));
	$branch_article_ids[$key] = $branch_id;
	echo "    article_id={$branch_id} {$title} (category_id={$category_ids[$key]})\n";
}
$kb->set_cross_listed_categories($branch_article_ids['routing'], [$category_ids['cache_recipes'], $category_ids['verify_restore'], $category_ids['templates'], $category_ids['events']]);
$kb->save_article_revision($branch_article_ids['routing'], array_merge([
	'article_title' => 'Clean URL Troubleshooting: a practical guide',
	'article_description' => 'A deep article reviewed through a cross-listed category.',
], seed_format_article_text("# Revised clean URL checks\n\nCheck the request as a reader, then confirm the rewrite rule with the team.", true, true)), $author1_id);

foreach (['KB Team' => $team_group_id, 'Operations Team' => $operations_team_id, 'Documentation Team' => $documentation_team_id] as $team_name => $group_id)
{
	seed_create_article($articles_table, array_merge([
		'article_category_id' => $category_ids['community'], 'approved' => 1,
		'status' => \phpbbmodders\knowledgebase\inc\functions_kb::STATUS_ACTIVE,
		'article_title' => $team_name . ': shared publishing checklist',
		'article_description' => 'A published guide owned by ' . $team_name . '.',
		'author_id' => $group_id, 'author' => $team_name,
		'author_type' => \phpbbmodders\knowledgebase\inc\functions_kb::AUTHOR_TYPE_GROUP,
		'assigned_team_id' => $group_id,
], seed_format_article_text("[b]Team publishing checklist[/b]\nCheck links, review wording, and test as a reader.")));
}

// Files on draft, pending, and inactive articles exercise different access rules.
seed_create_attachment($article11_id, $author1_id, 'private-draft.txt', "Private draft attachment.\n", 'txt', 'text/plain');
seed_create_attachment($article3_id, $author2_id, 'pending-review.txt', "Pending article attachment.\n", 'txt', 'text/plain');
$inactive_attachment_article_id = seed_create_article($articles_table, [
	'article_category_id' => $category_ids['read_only'], 'approved' => 0,
	'status' => \phpbbmodders\knowledgebase\inc\functions_kb::STATUS_INACTIVE,
	'article_title' => 'Retired maintenance procedure', 'article_description' => 'Inactive article with a retained attachment.',
	'article_body' => 'This retired procedure should not appear in published listings.',
	'author_id' => $author1_id, 'author' => 'kb_author1',
]);
seed_create_attachment($inactive_attachment_article_id, $author1_id, 'inactive-procedure.txt', "Inactive article attachment.\n", 'txt', 'text/plain');

// A visible diagram and a smaller preview exercise original/thumbnail delivery.
if (!extension_loaded('gd'))
{
	throw new RuntimeException('The image attachment fixture requires the PHP GD extension.');
}
$image = imagecreatetruecolor(800, 440);
$background = imagecolorallocate($image, 246, 249, 252);
$ink = imagecolorallocate($image, 32, 43, 55);
$blue = imagecolorallocate($image, 48, 114, 165);
$green = imagecolorallocate($image, 38, 129, 88);
imagefill($image, 0, 0, $background);
imagestring($image, 5, 28, 22, 'BOARD RECOVERY CHECKLIST', $ink);
foreach (['1. BACK UP', '2. RESTORE', '3. VERIFY'] as $step => $label)
{
	$x = 30 + $step * 255;
	imagefilledrectangle($image, $x, 95, $x + 225, 285, $step === 2 ? $green : $blue);
	imagestring($image, 5, $x + 18, 125, $label, $background);
	imagestring($image, 3, $x + 18, 180, ['Database and files', 'Use a separate board', 'Login, search, files'][$step], $background);
}
imagestring($image, 4, 28, 350, 'Keep the original backup until the verification is complete.', $ink);
ob_start();
imagepng($image);
$image_contents = ob_get_clean();
$thumbnail_image = imagescale($image, 400, 220);
ob_start();
imagepng($thumbnail_image);
$thumbnail_contents = ob_get_clean();
seed_create_attachment($article13_id, $author1_id, 'board-recovery.png', $image_contents, 'png', 'image/png', 'Recovery checklist diagram', false, $thumbnail_contents);
imagedestroy($thumbnail_image);
imagedestroy($image);

// 14+. Bulk articles - enough of every kind that each Administration queue
// choice (needs attention, everything, redirects, inactive, one team, one
// team including active) and each category listing runs past one page of ten.
// They are generated in blocks of six, one block per category and team
// assignment, cycling through six kinds so every kind lands in every
// category with and without the KB Team assigned:
//   0 new (pending approval)      3 active with a pending revision
//   1 reviewed (awaiting approval) 4 inactive
//   2 active                       5 active redirect
// The ids and titles are deterministic ("Bulk NN - kind"), newest last.
echo "==> Bulk articles\n";
$bulk_count = 216;
$bulk_kinds = ['pending approval', 'reviewed', 'active', 'pending revision', 'inactive', 'redirect'];
$bulk_categories = [
	$getting_started_id, $advanced_topics_id, $reference_id,
	$category_ids['routing'], $category_ids['cache_recipes'],
	$category_ids['verify_restore'], $category_ids['templates'],
	$category_ids['operations'], $category_ids['community'],
];
$bulk_base_time = time() - ($bulk_count * 3600);
$bulk_first_id = 0;
$bulk_last_id = 0;

for ($i = 0; $i < $bulk_count; $i++)
{
	$block = intdiv($i, 6);
	$kind = $i % 6;
	$is_user_one = ($i % 2 === 0);
	$bulk_title = sprintf('Bulk %02d - %s', $i + 1, $bulk_kinds[$kind]);
	$bulk_time = $bulk_base_time + ($i * 3600);

	$bulk_row = [
		'article_category_id' => $bulk_categories[$block % count($bulk_categories)],
		'approved'            => in_array($kind, [2, 3, 5], true) ? 1 : 0,
		'status'              => [
			\phpbbmodders\knowledgebase\inc\functions_kb::STATUS_NEW,
			\phpbbmodders\knowledgebase\inc\functions_kb::STATUS_REVIEWED,
			\phpbbmodders\knowledgebase\inc\functions_kb::STATUS_ACTIVE,
			\phpbbmodders\knowledgebase\inc\functions_kb::STATUS_ACTIVE,
			\phpbbmodders\knowledgebase\inc\functions_kb::STATUS_INACTIVE,
			\phpbbmodders\knowledgebase\inc\functions_kb::STATUS_ACTIVE,
		][$kind],
		'article_title'       => $bulk_title,
		'article_description' => 'Generated for paging tests.',
		'article_body'        => ($kind === 5) ? '' : 'Generated article ' . ($i + 1) . ' of ' . $bulk_count . ', a ' . $bulk_kinds[$kind] . ' entry used to fill listings past one page.',
		'author_id'           => $is_user_one ? $author1_id : $author2_id,
		'author'              => $is_user_one ? 'kb_author1' : 'kb_author2',
		'author_type'         => \phpbbmodders\knowledgebase\inc\functions_kb::AUTHOR_TYPE_USER,
		'assigned_team_id'    => ($block % 4 === 3) ? 0 : $team_ids[$block % 3],
		'article_date'        => $bulk_time,
		'edit_date'           => $bulk_time,
	];
	if ($kind === 5)
	{
		$bulk_row['is_redirect'] = 1;
		$bulk_row['redirect_url'] = 'https://example.org/bulk-' . ($i + 1);
	}

	$bulk_id = seed_create_article($articles_table, $bulk_row);
	$bulk_first_id = $bulk_first_id ?: $bulk_id;
	$bulk_last_id = $bulk_id;

	if ($kind === 3)
	{
		$kb->save_article_revision($bulk_id, [
			'article_title'       => $bulk_title,
			'article_description' => 'Generated for paging tests (revised).',
			'article_body'        => 'Pending revision of generated article ' . ($i + 1) . '.',
			'bbcode_uid'          => '',
			'bbcode_bitfield'     => '',
		], $is_user_one ? $author1_id : $author2_id);
	}
}

seed_create_attachment($bulk_first_id + 1, $author2_id, 'reviewed-checklist.txt', "Reviewed article attachment.\n", 'txt', 'text/plain');

// Deliberate maintenance cases: one file with no row, and one orphan row
// whose file is absent. Only the fixture-created file is removed here.
$missing_attachment_id = seed_create_attachment(0, $author1_id, 'missing-file-demo.txt', "Deliberately removed fixture file.\n", 'txt', 'text/plain', 'Lost-file cleanup example', true);
$result = $db->sql_query('SELECT physical_filename FROM ' . $phpbb_container->getParameter('tables.kb_attachments_table') . ' WHERE attach_id = ' . $missing_attachment_id);
$missing_filename = $db->sql_fetchfield('physical_filename');
$db->sql_freeresult($result);
$fixture_upload_dir = $phpbb_container->get('ext.manager')->get_extension_path('phpbbmodders/knowledgebase', true) . 'files/';
if (!unlink($fixture_upload_dir . $missing_filename))
{
	throw new RuntimeException('Failed to prepare the lost-file fixture.');
}
if (file_put_contents($fixture_upload_dir . 'kb_fixture_extra-file.txt', "Extra-file cleanup example: deliberately has no database row.\n") === false)
{
	throw new RuntimeException('Failed to prepare the extra-file fixture.');
}

// Required values belong on every submitted article, including pending rows.
// Optional fields vary so their filters have both matches and non-matches.
$result = $db->sql_query('SELECT article_id, status FROM ' . $articles_table . ' ORDER BY article_id');
$seeded_articles = $db->sql_fetchrowset($result);
$db->sql_freeresult($result);
foreach ($seeded_articles as $row)
{
	$id = (int) $row['article_id'];
	if ((int) $row['status'] === \phpbbmodders\knowledgebase\inc\functions_kb::STATUS_DRAFT)
	{
		continue;
	}
	if ($phpbb_version_value_id)
	{
		$result = $db->sql_query('SELECT field_id FROM ' . $kb->get_fields_table() . " WHERE field_key = 'phpbb_version'");
		$version_field_id = (int) $db->sql_fetchfield('field_id');
		$db->sql_freeresult($result);
		$kb->set_article_field_values($id, $version_field_id, [$phpbb_version_value_id]);
	}
	$kb->set_article_field_values($id, $fixture_fields['difficulty']['id'], [$fixture_fields['difficulty']['values'][$id % 3]]);
	if ($id % 2 === 0)
	{
		$kb->set_article_field_values($id, $fixture_fields['audience']['id'], [$fixture_fields['audience']['values'][$id % 3]]);
	}
	$kb->set_article_field_values($id, $fixture_fields['topics']['id'], [$fixture_fields['topics']['values'][$id % 4], $fixture_fields['topics']['values'][($id + 1) % 4]]);
}

// The old direct-insert fixtures were invisible to keyword search. Build the
// configured backend through its public indexer, as posting does.
echo "==> Search index\n";
$kb_search = $phpbb_container->get('phpbbmodders.knowledgebase.search.backend_factory')->get_active();
$result = $db->sql_query('SELECT article_id, article_body, article_title, article_description, author_id FROM ' . $articles_table . ' WHERE status <> ' . \phpbbmodders\knowledgebase\inc\functions_kb::STATUS_DRAFT);
$search_articles = $db->sql_fetchrowset($result);
$db->sql_freeresult($result);
foreach ($search_articles as $row)
{
	$kb_search->index('add', (int) $row['article_id'], $row['article_body'], $row['article_title'], $row['article_description'], (int) $row['author_id']);
}

// The rows above are inserted directly, so bring the counters the extension
// normally maintains in line with what is now published (active articles and
// redirects per category; every submitted article for the board-wide total,
// matching the ACP's own definition).
foreach ($category_ids as $bulk_category_id)
{
	$sql = 'SELECT COUNT(article_id) AS published
		FROM ' . $articles_table . '
		WHERE article_category_id = ' . (int) $bulk_category_id . '
			AND approved = 1
			AND status = ' . \phpbbmodders\knowledgebase\inc\functions_kb::STATUS_ACTIVE;
	$result = $db->sql_query($sql);
	$published = (int) $db->sql_fetchfield('published');
	$db->sql_freeresult($result);

	$db->sql_query('UPDATE ' . $phpbb_container->getParameter('tables.categories_table') . ' SET number_articles = ' . $published . ' WHERE category_id = ' . (int) $bulk_category_id);
}
$sql = 'SELECT COUNT(article_id) AS submitted FROM ' . $articles_table . ' WHERE status <> ' . \phpbbmodders\knowledgebase\inc\functions_kb::STATUS_DRAFT;
$result = $db->sql_query($sql);
$config->set('kb_num_articles', (int) $db->sql_fetchfield('submitted'));
$db->sql_freeresult($result);

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
echo "    article_id={$article12_id} Markdown Authoring Guide (active Markdown, cross-listed, required field, pending revision, draft-of-an-edit)\n";
echo "    article_id={$article13_id} Attachment Handling Examples (active, attachment_id={$attachment1_id}, orphan_attachment_id={$orphan_attachment_id})\n";
echo "    article_id={$bulk_first_id}..{$bulk_last_id} {$bulk_count} bulk articles (\"Bulk NN - kind\": pending approval, reviewed, active, pending revision, inactive, redirect; three teams and unassigned)\n";
echo '    ' . count($category_ids) . " categories in four main trees, six levels deep; three team groups; all three field widgets; searchable content.\n";

echo "\nDone. Log in as kb_author1 / KbTest1234! and open the \"Manage Your Articles\" tab to see the\n";
echo "seeded draft (and the Welcome article's separate draft-of-an-edit, visible when you edit it) in\n";
echo "the actual web UI - both are deliberately invisible to kb_moderator and everywhere else. Log in\n";
echo "as kb_moderator / KbTest1234! to see the Administration queue, kb_author1/kb_author2 for the\n";
echo "author side, or kb_reader (same password) to check view-only access.\n";
