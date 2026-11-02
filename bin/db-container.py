#!/usr/bin/env python3
"""Start, stop or describe a board's disposable database container.

A board configured with board.database other than sqlite keeps its database
in a Docker container that belongs to that board alone. `start` removes any
earlier container for the board, starts a fresh one published on 127.0.0.1
only, waits until it accepts connections, creates the phpBB database and
writes the connection details to BOARD_ROOT/.seed-forum-database.json (mode
600). `stop` removes the container and that file. `status` prints the file.

Exit status: 0 on success, 1 when Docker or the database fails, 2 for bad
arguments.
"""
import argparse
import hashlib
import json
import os
from pathlib import Path
import secrets
import socket
import subprocess
import sys
import time

STATE_FILE = ".seed-forum-database.json"
LABEL = "seed-forum.board"
READY_TIMEOUT = 180

# Image, phpBB installer driver, port inside the container, and the
# environment that creates the server's admin account and phpBB database.
ENGINES = {
    "mysql": {
        "image": "mysql:8.4",
        "dbms": "mysqli",
        "port": 3306,
        "user": "root",
        "env": lambda password: {"MYSQL_ROOT_PASSWORD": password, "MYSQL_DATABASE": "phpbb"},
    },
    "mariadb": {
        "image": "mariadb:lts",
        "dbms": "mysqli",
        "port": 3306,
        "user": "root",
        "env": lambda password: {"MARIADB_ROOT_PASSWORD": password, "MARIADB_DATABASE": "phpbb"},
    },
    "postgres": {
        "image": "postgres:16-alpine",
        "dbms": "postgres",
        "port": 5432,
        "user": "postgres",
        "env": lambda password: {"POSTGRES_PASSWORD": password, "POSTGRES_DB": "phpbb"},
    },
    # ACCEPT_EULA accepts Microsoft's SQL Server Developer licence; only start
    # this engine when the person running the board has agreed to it.
    "mssql": {
        "image": "mcr.microsoft.com/mssql/server:2022-CU13-ubuntu-22.04",
        "dbms": "mssqlnative",
        "port": 1433,
        "user": "sa",
        "env": lambda password: {"ACCEPT_EULA": "Y", "MSSQL_PID": "Developer", "MSSQL_SA_PASSWORD": password},
    },
}


def container_name(root: Path) -> str:
    """Return the board's container name, derived from its root path."""
    return "seed-forum-db-" + hashlib.sha1(str(root).encode()).hexdigest()[:12]


def docker(*args: str, check: bool = True, capture: bool = True) -> subprocess.CompletedProcess:
    """Run a docker command with a time limit; raise on failure when check is set."""
    return subprocess.run(["docker", *args], check=check, text=True, timeout=300,
                          capture_output=capture)


def free_port() -> int:
    """Ask the kernel for an unused 127.0.0.1 port."""
    with socket.socket() as sock:
        sock.bind(("127.0.0.1", 0))
        return sock.getsockname()[1]


def new_password() -> str:
    """Return a random password that meets SQL Server's complexity rules."""
    return "Sf1!" + secrets.token_urlsafe(18)


def probe(engine: str, name: str, password: str) -> list:
    """Return a docker exec command that succeeds once the database accepts TCP logins.

    Every probe connects over TCP to 127.0.0.1: the MySQL, MariaDB and
    PostgreSQL images first run a temporary server without networking while
    they create the database, so a socket login would succeed too early.
    """
    if engine == "mysql":
        return ["exec", "-e", f"MYSQL_PWD={password}", name, "mysql", "-h127.0.0.1", "-uroot", "-e", "SELECT 1", "phpbb"]
    if engine == "mariadb":
        return ["exec", "-e", f"MYSQL_PWD={password}", name, "mariadb", "-h127.0.0.1", "-uroot", "-e", "SELECT 1", "phpbb"]
    if engine == "postgres":
        return ["exec", "-e", f"PGPASSWORD={password}", name, "psql", "-h", "127.0.0.1", "-U", "postgres", "-d", "phpbb", "-c", "SELECT 1"]
    return ["exec", name, "/opt/mssql-tools/bin/sqlcmd", "-S", "127.0.0.1", "-U", "sa", "-P", password, "-b", "-Q", "SELECT 1"]


def remove(root: Path) -> None:
    """Remove the board's container, if any, and its connection file."""
    name = container_name(root)
    existing = docker("ps", "-a", "--filter", f"name=^{name}$", "--format", "{{.Label \"" + LABEL + "\"}}")
    owner = existing.stdout.strip()
    if owner and owner != str(root):
        raise RuntimeError(f"Container {name} belongs to another board ({owner}); not removing it")
    if owner:
        docker("rm", "-f", "-v", name)
        print(f"==> Removed database container {name}", flush=True)
    (root / STATE_FILE).unlink(missing_ok=True)


def start(root: Path, engine: str) -> dict:
    """Start a fresh database for the board and return its connection details."""
    spec = ENGINES[engine]
    remove(root)
    name = container_name(root)
    password = new_password()
    port = free_port()
    argv = ["run", "-d", "--name", name, "--label", f"{LABEL}={root}",
            "-p", f"127.0.0.1:{port}:{spec['port']}"]
    # The password is passed by environment name so it stays out of process lists.
    env = dict(os.environ)
    for key, value in spec["env"](password).items():
        env[key] = value
        argv += ["-e", key]
    print(f"==> Starting {engine} database container {name} on 127.0.0.1:{port}", flush=True)
    subprocess.run(["docker", *argv, spec["image"]], check=True, text=True, timeout=600,
                   env=env, stdout=subprocess.DEVNULL)
    deadline = time.monotonic() + READY_TIMEOUT
    while True:
        if docker(*probe(engine, name, password), check=False).returncode == 0:
            break
        state = docker("inspect", "-f", "{{.State.Running}}", name, check=False).stdout.strip()
        if state != "true" or time.monotonic() > deadline:
            logs = docker("logs", "--tail", "30", name, check=False)
            raise RuntimeError(f"{engine} did not start within {READY_TIMEOUT}s:\n{logs.stdout}{logs.stderr}")
        time.sleep(2)
    if engine == "mssql":
        # The SQL Server image has no setting that creates a database.
        docker("exec", name, "/opt/mssql-tools/bin/sqlcmd", "-S", "127.0.0.1", "-U", "sa",
               "-P", password, "-b", "-Q", "CREATE DATABASE phpbb")
    # phpBB's mssqlnative driver joins host and port with ":" on anything but
    # Windows, which Microsoft's Linux driver reads as part of the host name; it
    # wants "host,port". So for MSSQL the port goes into dbhost, the Windows way,
    # and dbport stays empty.
    host, db_port = ("127.0.0.1", port) if engine != "mssql" else (f"127.0.0.1,{port}", "")
    details = {
        "engine": engine,
        "dbms": spec["dbms"],
        "dbhost": host,
        "dbport": db_port,
        "port": port,
        "dbuser": spec["user"],
        "dbpasswd": password,
        "dbname": "phpbb",
        "container": name,
        "image": spec["image"],
    }
    state_file = root / STATE_FILE
    state_file.touch(mode=0o600)
    state_file.chmod(0o600)
    state_file.write_text(json.dumps(details, indent=2) + "\n")
    print(f"==> {engine} is ready; connection details in {state_file}", flush=True)
    return details


def main() -> int:
    """Parse arguments and run the chosen action."""
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("action", choices=("start", "stop", "status"),
                        help="start a fresh database, stop and remove it, or print its connection details")
    parser.add_argument("root", type=Path, help="the board's root directory (board.root)")
    parser.add_argument("engine", nargs="?", choices=sorted(ENGINES),
                        help="database engine to start; required with start")
    args = parser.parse_args()
    root = args.root.resolve()
    if not root.is_dir():
        parser.error(f"board root does not exist: {root}")
    if args.action == "start" and not args.engine:
        parser.error("start needs an engine")
    try:
        if args.action == "start":
            start(root, args.engine)
        elif args.action == "stop":
            remove(root)
        else:
            state_file = root / STATE_FILE
            if not state_file.is_file():
                print("No database container; the board uses SQLite or is not installed.")
                return 0
            details = json.loads(state_file.read_text())
            running = docker("inspect", "-f", "{{.State.Running}}", details["container"], check=False).stdout.strip()
            print(f"{details['engine']}: container {details['container']} ({details['image']}), "
                  f"127.0.0.1:{details['port']}, running={running == 'true'}")
    except (RuntimeError, subprocess.CalledProcessError, subprocess.TimeoutExpired) as error:
        detail = getattr(error, "stderr", "") or ""
        print(f"Error: {error}\n{detail}".rstrip(), file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main())
