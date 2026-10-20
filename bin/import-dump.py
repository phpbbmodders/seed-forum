#!/usr/bin/env python3
"""Load a real board's MySQL/MariaDB dump into a disposable container for a board.

Internal reset backend for boards with board.import set; rebuild-board.sh runs
it in place of the phpBB installer. Read the import settings as JSON from
SEED_FORUM_IMPORT, plus PHPBB_ROOT, SERVER_NAME and SERVER_PORT, and the
Composer names of the extensions to enable from the positional arguments.

Steps: replace this board's container (only one seed-forum labelled for this
board), stream the dump into it (the dump file itself is only read), make the
copy safe to run locally, write config.php, update the database to the board's
phpBB code with db:migrate, enable the configured extensions, and give the
listed members the fixture password. Print progress to stdout; any failure
stops the reset with a nonzero exit.
"""
import json
import os
from pathlib import Path
import re
import secrets
import shlex
import shutil
import subprocess
import sys
import time

# The same disposable password as the seeded fixture accounts.
LOGIN_PASSWORD = 'KbTest1234!'
STATE_FILE = '.seed-forum-import.json'
CONTAINER_LABEL = 'seed-forum.board'
READY_TIMEOUT_SECONDS = 180
USE_STATEMENT = re.compile(r"^USE `([^`]+)`;", re.MULTILINE)
CREATE_DATABASE = re.compile(r"^CREATE DATABASE\b[^;]*?`([^`]+)`", re.MULTILINE)
# How much of the dump to scan for its USE/CREATE DATABASE statements.
DUMP_HEAD_BYTES = 64 * 1024


def run(argv, **kwargs):
    """Print an argv-based command (never through a shell) and run it, failing on error."""
    print('+ ' + shlex.join([str(arg) for arg in argv]), flush=True)
    return subprocess.run([str(arg) for arg in argv], check=True, **kwargs)


def dump_databases(sql_path):
    """Return the database names the dump's first statements create or select.

    mysqldump --databases writes CREATE DATABASE and USE lines; a dump of one
    database's tables has neither.
    """
    with open(sql_path, 'rb') as dump:
        head = dump.read(DUMP_HEAD_BYTES).decode('utf-8', 'replace')
    return sorted(set(USE_STATEMENT.findall(head)) | set(CREATE_DATABASE.findall(head)))


def choose_database(sql_path, configured):
    """Return the database the import ends up in, or raise if it can't be known.

    A dump that selects its own database always loads there, so a configured
    name must match it; a dump without one needs the name configured.
    """
    named = dump_databases(sql_path)
    if len(named) > 1:
        raise ValueError(f'The dump creates more than one database ({", ".join(named)}); import one at a time')
    if named and configured and configured != named[0]:
        raise ValueError(f'board.import.database is {configured!r}, but the dump loads into {named[0]!r}')
    database = configured or (named[0] if named else None)
    if not database:
        raise ValueError('The dump does not name its database; set board.import.database')
    if not re.fullmatch(r'[A-Za-z0-9_$]+', database):
        raise ValueError(f'Unsupported database name: {database!r}')
    return database


def safety_sql(prefix, server_name, server_port):
    """Return SQL that makes an imported copy safe and reachable on this machine.

    Mail and Jabber are switched off so no real member is contacted, the real
    board's extensions are disabled (their code isn't on this board), and the
    URL settings point at the local server.
    """
    if not re.fullmatch(r'[A-Za-z0-9_]+', prefix):
        raise ValueError(f'Unsupported table prefix: {prefix!r}')
    if not re.fullmatch(r'[A-Za-z0-9.-]+', server_name):
        raise ValueError(f'Unsupported server name: {server_name!r}')
    port = int(server_port)
    config = f'{prefix}config'
    settings = {
        'email_enable': '0',
        'jab_enable': '0',
        'smtp_delivery': '0',
        'board_email_form': '0',
        'board_disable': '0',
        'cookie_secure': '0',
        'force_server_vars': '0',
        'server_protocol': 'http://',
        'server_name': server_name,
        'server_port': str(port),
        'script_path': '/',
        'cookie_path': '/',
        'cookie_domain': '',
        'assets_version': str(int(time.time())),
    }
    statements = [f'UPDATE {prefix}ext SET ext_active = 0;']
    statements += [f"UPDATE {config} SET config_value = '{value}' WHERE config_name = '{name}';" for name, value in settings.items()]
    return '\n'.join(statements) + '\n'


def tree_version(root):
    """Read the phpBB version of the board's code from includes/constants.php."""
    match = re.search(r"@define\('PHPBB_VERSION', '([^']+)'\);", (root / 'includes/constants.php').read_text())
    if not match:
        raise ValueError(f'Cannot read PHPBB_VERSION from {root}')
    return match[1]


def version_key(version):
    """Return a sortable key for phpBB versions such as 3.3.17 or 4.0.0-a3-dev."""
    numbers = re.match(r'(\d+)\.(\d+)\.(\d+)', version)
    if not numbers:
        raise ValueError(f'Unrecognized phpBB version: {version!r}')
    return tuple(int(part) for part in numbers.groups())


class Container:
    """The board's disposable MariaDB container, reached with `docker exec`."""

    def __init__(self, name, password):
        self.name = name
        self.password = password

    def sql(self, database, statements=None, stdin=None, capture=False):
        """Run SQL from a string or an open file; the password goes in MYSQL_PWD, not argv."""
        argv = ['docker', 'exec', '-i', '-e', 'MYSQL_PWD', self.name, 'mariadb', '-uroot', '--batch', '--skip-column-names', database]
        env = dict(os.environ, MYSQL_PWD=self.password)
        print('+ ' + shlex.join(argv), flush=True)
        if stdin is not None:
            return subprocess.run(argv, stdin=stdin, env=env, check=True)
        result = subprocess.run(argv, input=statements, env=env, check=True, text=True, capture_output=capture)
        return result.stdout if capture else None


def replace_container(name, root):
    """Remove the board's previous container; refuse to touch any other container."""
    found = subprocess.run(['docker', 'inspect', '--format', '{{ index .Config.Labels "' + CONTAINER_LABEL + '" }}', name],
                           capture_output=True, text=True, check=False)
    if found.returncode != 0:
        return
    if found.stdout.strip() != str(root):
        raise ValueError(f'Container {name} already exists and does not belong to this board; choose another board.import.container')
    run(['docker', 'rm', '-f', name], stdout=subprocess.DEVNULL)


def wait_until_ready(port, password):
    """Wait for MariaDB to accept TCP logins; its first-start server listens on no port."""
    probe = ('$c = @mysqli_connect("127.0.0.1", "root", getenv("MYSQL_PWD"), "", (int) $argv[1]);'
             'exit($c ? 0 : 1);')
    env = dict(os.environ, MYSQL_PWD=password)
    deadline = time.monotonic() + READY_TIMEOUT_SECONDS
    while time.monotonic() < deadline:
        if subprocess.run(['php', '-r', probe, str(port)], env=env, check=False,
                          stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL).returncode == 0:
            return
        time.sleep(1)
    raise TimeoutError(f'MariaDB did not accept connections on 127.0.0.1:{port} within {READY_TIMEOUT_SECONDS} seconds')


def write_config(root, settings, database, password):
    """Write config.php for the container; readable by the web-server group only."""
    def php_string(value):
        return "'" + str(value).replace('\\', '\\\\').replace("'", "\\'") + "'"

    lines = [
        '<?php',
        '// Written by seed-forum: a disposable copy of a real board in a local container.',
        "$dbms = 'phpbb\\\\db\\\\driver\\\\mysqli';",
        "$dbhost = '127.0.0.1';",
        f"$dbport = {php_string(settings['db_port'])};",
        f'$dbname = {php_string(database)};',
        "$dbuser = 'root';",
        f'$dbpasswd = {php_string(password)};',
        f"$table_prefix = {php_string(settings['table_prefix'])};",
        "$phpbb_adm_relative_path = 'adm/';",
        "$acm_type = 'phpbb\\\\cache\\\\driver\\\\file';",
        '',
        "@define('PHPBB_INSTALLED', true);",
        "@define('PHPBB_ENVIRONMENT', 'production');",
        '',
    ]
    target = root / 'config.php'
    target.write_text('\n'.join(lines))
    os.chmod(target, 0o640)
    try:
        # The web server reads config.php through its group, like the board's other files.
        shutil.chown(target, group='www-data')
    except LookupError:
        pass  # No www-data group on this machine, so there is no web server to grant.


def main():
    """Run the import for the board described by the environment; see the module docstring."""
    settings = json.loads(os.environ['SEED_FORUM_IMPORT'])
    root = Path(os.environ['PHPBB_ROOT']).resolve()
    extensions = sys.argv[1:]
    sql_path = Path(settings['sql'])
    prefix = settings['table_prefix']
    database = choose_database(sql_path, settings.get('database'))
    statements = safety_sql(prefix, os.environ['SERVER_NAME'], os.environ['SERVER_PORT'])
    password = secrets.token_hex(16)
    container = Container(settings['container'], password)

    print(f"==> Starting MariaDB container {settings['container']} ({settings['image']}) on 127.0.0.1:{settings['db_port']}")
    replace_container(settings['container'], root)
    run(['docker', 'run', '-d', '--name', settings['container'], '--label', f'{CONTAINER_LABEL}={root}',
         '-p', f"127.0.0.1:{settings['db_port']}:3306", '-e', 'MARIADB_ROOT_PASSWORD',
         '-e', f'MARIADB_DATABASE={database}', settings['image']],
        env=dict(os.environ, MARIADB_ROOT_PASSWORD=password), stdout=subprocess.DEVNULL)
    (root / STATE_FILE).write_text(json.dumps({'container': settings['container'], 'image': settings['image'],
                                                'db_port': settings['db_port'], 'database': database, 'sql': str(sql_path)}, indent=2))
    wait_until_ready(settings['db_port'], password)

    print(f'==> Importing {sql_path} (read only) into {database}')
    with open(sql_path, 'rb') as dump:
        container.sql(database, stdin=dump)
    found = container.sql(database, f"SHOW TABLES LIKE '{prefix}config';", capture=True).strip()
    if found != f'{prefix}config':
        raise ValueError(f'No {prefix}config table after the import; check board.import.table_prefix')

    dump_version = container.sql(database, f"SELECT config_value FROM {prefix}config WHERE config_name = 'version';", capture=True).strip()
    code_version = tree_version(root)
    if version_key(dump_version) > version_key(code_version):
        raise ValueError(f'The dump is phpBB {dump_version}, newer than the board code ({code_version})')

    print('==> Making the copy safe: mail and Jabber off, its extensions off, local URLs')
    container.sql(database, statements)
    write_config(root, settings, database, password)

    print(f'==> Updating the database from phpBB {dump_version} to {code_version}')
    run(['php', 'bin/phpbbcli.php', 'db:migrate', '--no-interaction'], cwd=root)
    for name in extensions:
        run(['php', 'bin/phpbbcli.php', 'extension:enable', name, '--no-interaction'], cwd=root)

    users = [int(user_id) for user_id in settings.get('login_users', [])]
    if users:
        print(f"==> Giving members {', '.join(map(str, users))} the fixture password")
        password_hash = subprocess.run(['php', '-r', 'echo password_hash($argv[1], PASSWORD_BCRYPT);', LOGIN_PASSWORD],
                                       capture_output=True, text=True, check=True).stdout
        if not password_hash.startswith('$2y$'):
            raise ValueError('Could not hash the fixture password')
        container.sql(database, f"UPDATE {prefix}users SET user_password = '{password_hash}', user_login_attempts = 0 "
                                f"WHERE user_id IN ({', '.join(map(str, users))});")


if __name__ == '__main__':
    try:
        main()
    except (ValueError, OSError, TimeoutError, subprocess.CalledProcessError) as error:
        print(f'Error: {error}', file=sys.stderr)
        sys.exit(1)
