<?php
/**
 * Seed a core phpBB forum without requiring any extension.
 *
 * Input: installed board root in $argv[1]. Create two categories, five forums,
 * five users, two groups, and twelve topics with thirty replies. Print a
 * summary on success; reject existing fixtures rather than duplicate them.
 * Run through run-seed.php to record attempts and prevent concurrent seeding.
 */
require(__DIR__ . '/seed-bootstrap.php');
require_once($phpbb_root_path . 'includes/acp/acp_forums.php');

/**
 * Fetch the first row matching a condition supplied by this fixture script.
 *
 * @param string $table phpBB table constant.
 * @param string $condition Trusted SQL condition; callers escape string values.
 * @return array Row fields, or an empty array when no row matches.
 */
function standard_row(string $table, string $condition): array
{
	global $db;
	$result = $db->sql_query('SELECT * FROM ' . $table . ' WHERE ' . $condition);
	$row = $db->sql_fetchrow($result);
	$db->sql_freeresult($result);
	return $row ?: [];
}

if (standard_row(FORUMS_TABLE, "forum_name = 'Community'"))
{
	throw new RuntimeException('Standard fixtures already exist; reset before seeding again');
}

/**
 * Create a forum/category through the ACP API so its tree indexes stay valid.
 *
 * @param string $name Display name.
 * @param int $parent Parent forum ID, or zero for a root category/forum.
 * @param int $type FORUM_POST or FORUM_CAT.
 * @return int Created forum ID. Permissions are assigned separately below.
 * @throws RuntimeException When ACP forum validation fails.
 */
function standard_forum(string $name, int $parent = 0, int $type = FORUM_POST): int
{
	$data = [
		'forum_name' => $name, 'parent_id' => $parent, 'forum_type' => $type,
		'type_action' => '', 'forum_status' => ITEM_UNLOCKED, 'forum_parents' => '',
		'forum_link' => '', 'forum_link_track' => false, 'forum_desc' => '',
		'forum_desc_uid' => '', 'forum_desc_options' => 7, 'forum_desc_bitfield' => '',
		'forum_rules' => '', 'forum_rules_uid' => '', 'forum_rules_options' => 7,
		'forum_rules_bitfield' => '', 'forum_rules_link' => '', 'forum_image' => '',
		'forum_style' => 0, 'display_subforum_list' => true, 'display_subforum_limit' => false,
		'display_on_index' => true, 'forum_topics_per_page' => 0, 'enable_indexing' => true,
		'enable_icons' => true, 'enable_prune' => false, 'enable_post_review' => true,
		'enable_quick_reply' => true, 'enable_shadow_prune' => false,
		'prune_days' => 7, 'prune_viewed' => 7, 'prune_freq' => 1,
		'prune_old_polls' => false, 'prune_announce' => false, 'prune_sticky' => false,
		'prune_shadow_days' => 7, 'prune_shadow_freq' => 1,
		'forum_password' => '', 'forum_password_confirm' => '', 'forum_password_unset' => false,
		'forum_options' => 0, 'show_active' => true,
	];
	$manager = new acp_forums();
	$errors = $manager->update_forum_data($data);
	if ($errors)
	{
		throw new RuntimeException(implode(', ', $errors));
	}
	return (int) $data['forum_id'];
}

$community = standard_forum('Community', 0, FORUM_CAT);
$resources = standard_forum('Resources', 0, FORUM_CAT);
$discussion = standard_forum('General Discussion', $community);
$introductions = standard_forum('Introductions', $community);
$reference = standard_forum('Reference Library', $resources);
$projects = standard_forum('Member Projects', $discussion);
$staff_forum = standard_forum('Staff Room', $resources);
$forums = [$community, $resources, $discussion, $introductions, $reference, $projects, $staff_forum];

$registered = (int) standard_row(GROUPS_TABLE, "group_name = 'REGISTERED'")['group_id'];
$guests = (int) standard_row(GROUPS_TABLE, "group_name = 'GUESTS'")['group_id'];
$administrators = (int) standard_row(GROUPS_TABLE, "group_name = 'ADMINISTRATORS'")['group_id'];
$members = $staff = 0;
group_create($members, GROUP_OPEN, 'Community Members', 'Standard seed members.', []);
group_create($staff, GROUP_CLOSED, 'Forum Staff', 'Standard seed staff.', []);
$users = [];
foreach (['forum_alex', 'forum_blair', 'forum_casey', 'forum_drew', 'forum_moderator'] as $name)
{
	if (standard_row(USERS_TABLE, "username = '" . $db->sql_escape($name) . "'"))
	{
		throw new RuntimeException('Fixture username already exists: ' . $name);
	}
	$id = user_add([
		'username' => $name, 'user_password' => $password_manager->hash('KbTest1234!'),
		'user_email' => $name . '@example.org', 'group_id' => $registered,
		'user_timezone' => $config['board_timezone'], 'user_lang' => $config['default_lang'],
		'user_type' => USER_NORMAL, 'user_regdate' => time(),
	]);
	if (!$id)
	{
		throw new RuntimeException('Failed to create ' . $name);
	}
	$users[] = (int) $id;
}
group_user_add($members, $users);
group_user_add($staff, [end($users)]);

$permissions = new auth_admin();
/**
 * Assign an existing phpBB permission role using its stored option settings.
 *
 * @param int $group Target group ID.
 * @param int $forum Forum ID, or zero for a global user role.
 * @param string $name Built-in role name such as ROLE_FORUM_STANDARD.
 * @return void
 * @throws RuntimeException When the requested role does not exist.
 */
function standard_role(int $group, int $forum, string $name): void
{
	global $permissions, $db;
	$role = standard_row(ACL_ROLES_TABLE, "role_name = '" . $db->sql_escape($name) . "'");
	if (!$role)
	{
		throw new RuntimeException('Missing permission role: ' . $name);
	}
	$result = $db->sql_query('SELECT ao.auth_option, ard.auth_setting FROM ' . ACL_ROLES_DATA_TABLE . ' ard JOIN ' . ACL_OPTIONS_TABLE . ' ao ON ao.auth_option_id = ard.auth_option_id WHERE ard.role_id = ' . (int) $role['role_id']);
	$options = [];
	while ($row = $db->sql_fetchrow($result))
	{
		$options[$row['auth_option']] = (int) $row['auth_setting'];
	}
	$db->sql_freeresult($result);
	$permissions->acl_set('group', $forum, $group, $options, (int) $role['role_id']);
}
// The headless installer can omit REGISTERED's baseline global user role.
standard_role($registered, 0, 'ROLE_USER_FULL');
foreach ($forums as $forum)
{
	standard_role($administrators, $forum, 'ROLE_FORUM_FULL');
	standard_role($staff, $forum, 'ROLE_FORUM_FULL');
	standard_role($staff, $forum, 'ROLE_MOD_FULL');
	if ($forum !== $staff_forum)
	{
		// Staff Room gets no public grants. Read-only forum roles are additive,
		// so members receive posting rights only outside Reference Library.
		standard_role($guests, $forum, 'ROLE_FORUM_READONLY');
		standard_role($registered, $forum, 'ROLE_FORUM_READONLY');
		if ($forum !== $reference)
		{
			standard_role($members, $forum, 'ROLE_FORUM_STANDARD');
		}
	}
}
$permissions->acl_clear_prefetch();
cache_moderators();

/**
 * Submit an approved fixture post through phpBB's parsing and posting APIs.
 *
 * @param int $author User ID used for author identity and permission checks.
 * @param int $forum Destination forum ID.
 * @param string $subject Topic or reply subject.
 * @param string $message Unparsed text/BBCode.
 * @param int $type POST_NORMAL, POST_STICKY, or POST_ANNOUNCE.
 * @param int $topic Existing topic ID for a reply, or zero for a new topic.
 * @param int $status Initial topic status when creating a topic.
 * @return int Topic ID assigned or reused by submit_post().
 */
function standard_post(int $author, int $forum, string $subject, string $message, int $type = POST_NORMAL, int $topic = 0, int $status = ITEM_UNLOCKED): int
{
	global $db, $user, $auth;
	$user->data = standard_row(USERS_TABLE, 'user_id = ' . $author);
	$user->data['user_ip'] = '127.0.0.1';
	$user->data['is_registered'] = true;
	$user->data['is_bot'] = false;
	// Rebuild ACLs whenever the author changes; no web session runs this step.
	$auth->acl($user->data);
	$uid = $bitfield = '';
	$options = 0;
	generate_text_for_storage($message, $uid, $bitfield, $options, true, true, true);
	$data = [
		'forum_id' => $forum, 'topic_id' => $topic, 'topic_title' => $subject,
		'icon_id' => 0, 'poster_id' => $author, 'enable_bbcode' => true,
		'enable_smilies' => true, 'enable_urls' => true, 'enable_sig' => false,
		'message' => $message, 'message_md5' => md5($message),
		'bbcode_uid' => $uid, 'bbcode_bitfield' => $bitfield,
		'enable_indexing' => true, 'notify' => false, 'notify_set' => false,
		'post_edit_locked' => false, 'topic_time_limit' => 0,
		'topic_status' => $status, 'forum_name' => '', 'force_approved_state' => ITEM_APPROVED,
	];
	$poll = [];
	submit_post($topic ? 'reply' : 'post', $subject, '', $type, $poll, $data);
	return (int) $data['topic_id'];
}

$subjects = ['Welcome to the community', 'Introduce yourself', 'A useful reference', 'Weekend project', 'Community guidelines', 'Reading list', 'Share your workspace', 'Project progress', 'Frequently asked questions', 'Archived discussion', 'Staff planning', 'Next community meetup'];
$destinations = [$discussion, $introductions, $reference, $projects, $discussion, $reference, $discussion, $projects, $reference, $discussion, $staff_forum, $introductions];
$messages = [
	'A plain message to start a conversation.',
	'[quote="Alex"]Small steps make progress.[/quote] What are you working on?',
	'[list][*]Read the reference[*]Try an example[*]Share your results[/list]',
	'[url=https://www.phpbb.com/]phpBB[/url] is the software behind this board.',
	'[code]echo "Hello, forum!";[/code]',
];
foreach ($subjects as $index => $subject)
{
	$forum = $destinations[$index];
	// Post as staff where the seeded member roles intentionally lack access.
	$author = ($forum === $staff_forum || $forum === $reference) ? end($users) : $users[$index % 4];
	$type = $index === 0 ? POST_STICKY : ($index === 4 ? POST_ANNOUNCE : POST_NORMAL);
	$topic = standard_post($author, $forum, $subject, $messages[$index % count($messages)], $type);
	// Six topics with three replies and six with two give thirty replies total.
	$replies = $index < 6 ? 3 : 2;
	for ($reply = 0; $reply < $replies; $reply++)
	{
		$reply_author = ($forum === $staff_forum || $forum === $reference) ? end($users) : $users[($index + $reply + 1) % 4];
		standard_post($reply_author, $forum, 'Re: ' . $subject, $messages[($index + $reply + 1) % count($messages)], $type, $topic);
	}
	if ($index === 9)
	{
		// Lock after adding the fixture replies.
		$db->sql_query('UPDATE ' . TOPICS_TABLE . ' SET topic_status = ' . ITEM_LOCKED . ' WHERE topic_id = ' . $topic);
	}
}
$permissions->acl_clear_prefetch();
echo "Standard fixtures: 2 categories, 5 forums, 5 users, 2 groups, 12 topics, 30 replies.\n";
