<?php
/**
 * Run one PHP seed script with an installed board root as its first argument.
 *
 * Inputs: BOARD_ROOT and SEED_SCRIPT positional arguments.
 * Output: the child script's stdout/stderr and exit status. Record each attempt
 * in the board's JSON ledger; failed or interrupted attempts require a reset.
 */
if (PHP_SAPI !== 'cli' || count($argv) !== 3)
{
	fwrite(STDERR, "Usage: php run-seed.php BOARD_ROOT SEED_SCRIPT\n");
	exit(1);
}
$root = realpath($argv[1]);
$script = realpath($argv[2]);
if (!$root || !$script || !is_file($root . '/config.php'))
{
	fwrite(STDERR, "An installed board and existing seed script are required.\n");
	exit(1);
}
// Lock without truncating: another process's completed records must survive.
$handle = fopen($root . '/.seed-forum-seeds.json', 'c+');
if (!$handle || !flock($handle, LOCK_EX | LOCK_NB))
{
	fwrite(STDERR, "Unable to lock seed ledger; another seed may be running.\n");
	exit(1);
}
$contents = stream_get_contents($handle);
$ledger = $contents === '' ? [] : json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
if (isset($ledger[$script]))
{
	fwrite(STDERR, "Seed already attempted: {$script}. Reset before repeating.\n");
	exit(1);
}
/**
 * Replace ledger contents while retaining the caller's exclusive file lock.
 *
 * @param resource $handle Locked, writable ledger stream.
 * @param array<string, string> $ledger Absolute seed paths and attempt states.
 * @return void
 * @throws RuntimeException When writing or flushing the ledger fails.
 */
function save_seed_ledger($handle, array $ledger): void
{
	rewind($handle);
	ftruncate($handle, 0);
	if (fwrite($handle, json_encode($ledger, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)) === false || !fflush($handle))
	{
		throw new RuntimeException('Unable to write seed ledger');
	}
}
// Persist before launching: a crash cannot make a partial seed look unattempted.
$ledger[$script] = 'started';
save_seed_ledger($handle, $ledger);
// Keep execution in a separate PHP process because seeds bootstrap phpBB globals.
$process = proc_open([PHP_BINARY, $script, $root], [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes);
$status = is_resource($process) ? proc_close($process) : 1;
$ledger[$script] = $status === 0 ? 'complete' : 'failed';
save_seed_ledger($handle, $ledger);
fclose($handle);
exit($status);
