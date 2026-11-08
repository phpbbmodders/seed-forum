<?php
/**
 * Seed an installed board with the phpBB Modders See Notes extension enabled.
 *
 * Input: phpBB board root as the first argument. Give the standard fixture
 * members reasons and notes in every state See Notes can show: the seven
 * starter reasons the extension adds when it is enabled, plus a switched-off
 * one, with "General" emptied of its description; a
 * member with a current reason, an older second reason, a note quoting a
 * private message and an edited note; a member whose note was written by a
 * staff member who has since been deleted (the saved name shows as plain
 * text); a member who only has a switched-off reason and no current one (no
 * reason line, but history behind the notes button); and a member with nothing
 * at all.
 * Also give members phpBB warnings and user notes, so the read-only "phpBB user
 * notes" box on the notes pages has content, and a post by each member so the
 * reason line shows next to posts. Print what was created.
 * Reject a board where members already have See Notes entries. Use the runner
 * for seed-ledger protection.
 *
 * Needs the standard fixtures (bin/seed-standard-fixtures.php) to have run
 * first, for the forum_* members and their posts. Works on phpBB 3.3.x and 4.0.
 * Usage: php seed-seenotes-fixtures.php /path/to/phpBB/root
 */
require(__DIR__ . '/seed-bootstrap.php');
require_once($phpbb_root_path . 'includes/mcp/mcp_warn.php');

/** @var \phpbb\extension\manager $ext_manager */
$ext_manager = $phpbb_container->get('ext.manager');
if (!$ext_manager->is_enabled('phpbbmodders/seenotes'))
{
	fwrite(STDERR, "Enable phpbbmodders/seenotes before seeding its fixtures.\n");
	exit(1);
}

$reasons_table = $table_prefix . 'seenotes_reasons';
$entries_table = $table_prefix . 'seenotes_entries';
$notes_table = $table_prefix . 'seenotes_notes';

$result = $db->sql_query_limit('SELECT entry_id FROM ' . $entries_table, 1);
$existing = $db->sql_fetchrow($result);
$db->sql_freeresult($result);
if ($existing)
{
	fwrite(STDERR, "See Notes fixtures already exist (an entry was found); reset before seeding again.\n");
	exit(1);
}

/**
 * The user row of a standard fixture member.
 *
 * @param string $username Fixture username.
 * @return array Users table row.
 */
function seenotes_member(string $username): array
{
	global $db;
	$result = $db->sql_query('SELECT * FROM ' . USERS_TABLE . " WHERE username = '" . $db->sql_escape($username) . "'");
	$row = $db->sql_fetchrow($result);
	$db->sql_freeresult($result);
	if (!$row)
	{
		throw new RuntimeException('Run the standard fixtures first: ' . $username . ' is missing');
	}
	return $row;
}

/**
 * The newest visible post of a member, or 0 when they have none.
 *
 * @param int $user_id Member's user id.
 * @return int Post id.
 */
function seenotes_post_of(int $user_id): int
{
	global $db;
	$result = $db->sql_query_limit('SELECT post_id FROM ' . POSTS_TABLE . ' WHERE poster_id = ' . $user_id
		. ' AND post_visibility = ' . ITEM_APPROVED . ' ORDER BY post_time DESC', 1);
	$post_id = (int) $db->sql_fetchfield('post_id');
	$db->sql_freeresult($result);
	return $post_id;
}

/** @var \phpbbmodders\seenotes\manager $manager */
$manager = $phpbb_container->get('phpbbmodders.seenotes.manager');
$day = 86400;
$now = time();

/**
 * Add a note as the current user, then date it.
 *
 * @param int $entry_id Entry the note belongs to.
 * @param string $text Note text, with BBCode.
 * @param int $time When the note was written.
 * @return int Note id.
 */
function seenotes_note(int $entry_id, string $text, int $time): int
{
	global $manager, $db, $notes_table;
	$parsed = $manager->parse_note($text);
	if ($parsed['errors'])
	{
		throw new RuntimeException('Note not accepted: ' . implode(', ', $parsed['errors']));
	}
	$note_id = $manager->add_note($entry_id, $parsed);
	$db->sql_query('UPDATE ' . $notes_table . ' SET note_time = ' . $time . ' WHERE note_id = ' . $note_id);
	return $note_id;
}

/**
 * Give a member a reason as the current user, then date the entry.
 *
 * @param int $user_id Member's user id.
 * @param int $reason_id Reason id.
 * @param bool $current Make it the member's current reason.
 * @param int $time When the reason was given.
 * @return int Entry id.
 */
function seenotes_entry(int $user_id, int $reason_id, bool $current, int $time): int
{
	global $manager, $db, $entries_table;
	$entry_id = $manager->add_entry($user_id, $reason_id, $current);
	$db->sql_query('UPDATE ' . $entries_table . ' SET entry_time = ' . $time . ' WHERE entry_id = ' . $entry_id);
	return $entry_id;
}

/**
 * The id of a reason by name, adding it at the end of the list when the board
 * does not have it (the starter reasons are added when the extension is
 * enabled, in the board's default language).
 *
 * @param string $title Reason name.
 * @param string $description Description, used when the reason is added.
 * @param string $color Six-digit hex color, used when the reason is added.
 * @return int Reason id.
 */
function seenotes_reason(string $title, string $description, string $color): int
{
	global $manager;
	foreach ($manager->get_reasons() as $reason_id => $reason)
	{
		if ($reason['reason_title'] === $title)
		{
			return (int) $reason_id;
		}
	}
	return $manager->save_reason(['reason_title' => $title, 'reason_description' => $description, 'reason_color' => $color]);
}

// The starter reasons, by their English names
$queue = seenotes_reason('Moderation queue', 'Posts must be approved before they appear', 'AA0000');
$spam = seenotes_reason('Spam watch', 'Check links and signatures in new posts', 'A04800');
seenotes_reason('Conduct', 'Earlier problems with how they treat other members', '8C5A00');
$general = seenotes_reason('General', '', '555555');
// A reason without a description, to show the line without a hover text
$manager->save_reason(['reason_title' => 'General', 'reason_description' => '', 'reason_color' => '555555'], $general);
// A switched-off reason that a member still has
$retired = seenotes_reason('Signature links', 'Old rule, replaced by Spam watch', '2F6F3E');
$manager->set_reason_active($retired, false);

$alex = seenotes_member('forum_alex');
$blair = seenotes_member('forum_blair');
$casey = seenotes_member('forum_casey');
$drew = seenotes_member('forum_drew');

// forum_alex: an older Spam watch entry, then a current Moderation queue entry
// with a quoted PM and an edited note
$old = seenotes_entry((int) $alex['user_id'], $spam, false, $now - 40 * $day);
seenotes_note($old, 'Three link-only posts removed from Introductions.', $now - 40 * $day);
$entry = seenotes_entry((int) $alex['user_id'], $queue, true, $now - 6 * $day);
seenotes_note($entry, "[quote=\"admin\"]Hi, your last few posts were mostly links to the same shop. Please read the board rules before posting again. Until then your posts will be approved by a moderator first.[/quote]\nSent as a PM after the report on the sales thread.", $now - 6 * $day);
$edited = seenotes_note($entry, 'Member replied and apologised. Keeping the queue on for two weeks.', $now - 5 * $day);
$manager->edit_note($edited, $manager->parse_note("Member replied and apologised. Keeping the queue on for two weeks, until [b]the 20th[/b]."));
$db->sql_query('UPDATE ' . $notes_table . ' SET note_edit_time = ' . ($now - 4 * $day) . ' WHERE note_id = ' . $edited);

// forum_blair: current Spam watch, with a note by a staff member who was deleted since
$entry = seenotes_entry((int) $blair['user_id'], $spam, true, $now - 12 * $day);
$note = seenotes_note($entry, 'Posted the same affiliate link in two forums. Watch for new accounts from the same address.', $now - 12 * $day);
$db->sql_query('UPDATE ' . $notes_table . " SET note_author_id = 999999, note_author_name = 'former_moderator' WHERE note_id = " . $note);
seenotes_note($entry, 'Quiet for a week now.', $now - 2 * $day);

// forum_casey: only a switched-off reason, none current: no reason line, but history behind the notes button
$entry = seenotes_entry((int) $casey['user_id'], $retired, false, $now - 90 * $day);
seenotes_note($entry, 'Removed a signature with six shop links.', $now - 90 * $day);

// forum_drew: no reasons at all

// phpBB warnings and user notes for the read-only box
add_warning($alex, 'Advertising: links to the same shop in several posts.', false, seenotes_post_of((int) $alex['user_id']));
add_warning($blair, 'Spamming affiliate links.', false, seenotes_post_of((int) $blair['user_id']));
$phpbb_log->add('user', $user->data['user_id'], $user->ip, 'LOG_USER_GENERAL', false, ['reportee_id' => (int) $alex['user_id'], 'Asked by email why posts need approval; pointed to the PM.']);
$phpbb_log->add('user', $user->data['user_id'], $user->ip, 'LOG_USER_GENERAL', false, ['reportee_id' => (int) $drew['user_id'], 'Helpful in the support forum, no issues.']);

echo "See Notes fixtures: the starter reasons plus 1 switched off, forum_alex (current reason, quoted PM, edited note, older entry, warning), "
	. "forum_blair (current reason, note by a deleted moderator, warning), forum_casey (switched-off reason only, none current), "
	. "forum_drew (no reasons).\n";
echo "Log in as admin / " . 'KbTest1234!' . " to see them; members never see See Notes.\n";
