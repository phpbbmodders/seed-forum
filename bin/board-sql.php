<?php
/**
 * Run one SQL statement on an installed board's database and print its rows as JSON.
 *
 * Usage: php board-sql.php BOARD_ROOT SQL [PARAMS_JSON]
 *
 * The statement goes through phpBB's own database layer, so the same query
 * works whichever database the board uses (see bin/db-container.py); run it
 * through bin/board-php.py so an MSSQL board gets its driver. Each `?` in SQL
 * is replaced, in order, by the matching value of the PARAMS_JSON array:
 * numbers as they are, strings quoted with the database's own escaping, null
 * as NULL. A SELECT prints a JSON array of rows (each row an array of column
 * values in order); any other statement prints the number of affected rows.
 * A failing statement exits with status 1 and the database error on stderr.
 * For disposable test boards only: the statement runs exactly as given.
 */
require(__DIR__ . '/seed-bootstrap.php');

$sql = $argv[2] ?? '';
$params = isset($argv[3]) ? json_decode($argv[3], true, 16, JSON_THROW_ON_ERROR) : [];
if ($sql === '' || !is_array($params))
{
	fwrite(STDERR, "Usage: php board-sql.php BOARD_ROOT SQL [PARAMS_JSON]\n");
	exit(2);
}

$parts = explode('?', $sql);
if (count($parts) - 1 !== count($params))
{
	fwrite(STDERR, 'Expected ' . (count($parts) - 1) . ' parameters, got ' . count($params) . "\n");
	exit(2);
}
$query = array_shift($parts);
foreach ($params as $i => $value)
{
	if ($value === null)
	{
		$query .= 'NULL';
	}
	else if (is_int($value) || is_float($value))
	{
		$query .= $value;
	}
	else
	{
		$query .= "'" . $db->sql_escape((string) $value) . "'";
	}
	$query .= $parts[$i];
}

$db->sql_return_on_error(true);
$result = $db->sql_query($query);
if ($result === false)
{
	$error = $db->get_sql_error_returned();
	fwrite(STDERR, ($error['message'] ?? 'SQL error') . "\n");
	exit(1);
}
if (preg_match('/^\s*SELECT\b/i', $query))
{
	$rows = [];
	while ($row = $db->sql_fetchrow($result))
	{
		$rows[] = array_values($row);
	}
	$db->sql_freeresult($result);
	echo json_encode($rows, JSON_THROW_ON_ERROR) . "\n";
}
else
{
	echo json_encode($db->sql_affectedrows(), JSON_THROW_ON_ERROR) . "\n";
}
