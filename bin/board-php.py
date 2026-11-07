#!/usr/bin/env python3
"""Run PHP for a board, on the host or in a container with the board's database driver.

Usage: board-php.py BOARD_ROOT [php arguments...]

Boards on SQLite, MySQL, MariaDB or PostgreSQL use the host's `php`. A board
on MSSQL (see bin/db-container.py) needs the sqlsrv driver, which host PHP
packages don't ship, so its PHP runs in the image from docker/php-drivers/,
built on first use. That container uses the host's network, so it reaches
the database on 127.0.0.1 and `-S 127.0.0.1:PORT` serves the board there.
The board and seed-forum directories are mounted at their own paths, with the
board's extension bind mounts inside, and PHP runs as the calling user.

When a directory named after the board with `.outside` added exists next to it
(BOARD_ROOT.outside), it is mounted read-write at its own path too. Tests use it
for files that must live outside the board, such as an extension's private
backup directory; on the host the same path is used directly. Create and remove
it yourself; nothing here does.

Exit status: PHP's own, or 1 when the image can't be built.
"""
import hashlib
import json
import os
from pathlib import Path
import subprocess
import sys

REPO = Path(__file__).resolve().parent.parent
DOCKERFILE_DIR = REPO / "docker/php-drivers"
CONTAINER_ENGINES = {"mssql"}


def driver_image() -> str:
    """Return the driver image tag, building it when this Dockerfile's tag is missing."""
    digest = hashlib.sha256((DOCKERFILE_DIR / "Dockerfile").read_bytes()).hexdigest()[:12]
    tag = f"seed-forum-php-drivers:{digest}"
    found = subprocess.run(["docker", "image", "inspect", tag], capture_output=True, check=False)
    if found.returncode != 0:
        print(f"==> Building {tag} (PHP with the MSSQL driver); this happens once", file=sys.stderr, flush=True)
        subprocess.run(["docker", "build", "-t", tag, str(DOCKERFILE_DIR)], check=True,
                       stdout=sys.stderr, timeout=1800)
    return tag


def main() -> int:
    """Run PHP for the board named by the first argument."""
    if len(sys.argv) < 2 or sys.argv[1] in ("-h", "--help"):
        print(__doc__.strip())
        return 0
    root = Path(sys.argv[1]).resolve()
    php_args = sys.argv[2:]
    state_file = root / ".seed-forum-database.json"
    engine = json.loads(state_file.read_text())["engine"] if state_file.is_file() else "sqlite"
    if engine not in CONTAINER_ENGINES:
        os.execvp("php", ["php", *php_args])
    try:
        image = driver_image()
    except (subprocess.CalledProcessError, subprocess.TimeoutExpired) as error:
        print(f"Error: could not build the PHP driver image: {error}", file=sys.stderr)
        return 1
    cwd = Path.cwd()
    workdir = cwd if any(cwd.is_relative_to(path) for path in (root, REPO)) else root
    # --init: PHP must not be the container's PID 1, which ignores SIGTERM, or
    # stopping a `-S` server would leave the container serving.
    argv = ["docker", "run", "--rm", "-i", "--init", "--network", "host",
            "--user", f"{os.getuid()}:{os.getgid()}", "-e", "HOME=/tmp",
            "-v", f"{root}:{root}", "-v", f"{REPO}:{REPO}:ro"]
    outside = root.with_name(root.name + ".outside")
    if outside.is_dir() and not outside.is_symlink():
        argv += ["-v", f"{outside}:{outside}"]
    argv += ["-w", str(workdir), image, "php", *php_args]
    os.execvp("docker", argv)


if __name__ == "__main__":
    sys.exit(main())
