#!/usr/bin/env python3
"""Serve a board with PHP's built-in web server, for boards without an nginx site.

MSSQL boards (board.database: mssql) can't be served by nginx and the host's
PHP-FPM, which has no sqlsrv driver; this runs the server through
bin/board-php.py instead, in the driver container for those boards. It also
works for any other board left without board.nginx_site.

The server listens on 127.0.0.1 only, with opcache off and four workers
(PHP_CLI_SERVER_WORKERS, when not already set), and runs until it is
stopped (Ctrl+C). Requests are logged to stderr.

Exit status: the PHP server's own, 2 for bad arguments.
"""
import argparse
import os
from pathlib import Path
import sys

REPO = Path(__file__).resolve().parent.parent


def main() -> int:
    """Parse arguments and replace this process with the PHP server."""
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("root", type=Path, help="the board's root directory (board.root)")
    parser.add_argument("port", type=int, help="127.0.0.1 port to listen on; use the board's server_port so its links work")
    args = parser.parse_args()
    root = args.root.resolve()
    if not (root / "config.php").is_file():
        parser.error(f"no installed board at {root}")
    if not 1 <= args.port <= 65535:
        parser.error("port must be between 1 and 65535")
    os.chdir(root)
    # Several workers: with one, a browser's speculative connection that sends
    # no request holds the server and the real page waits until it times out.
    os.environ.setdefault("PHP_CLI_SERVER_WORKERS", "4")
    argv = ["python3", str(REPO / "bin/board-php.py"), str(root),
            "-d", "opcache.enable=0", "-S", f"127.0.0.1:{args.port}", "-t", str(root)]
    os.execvp("python3", argv)


if __name__ == "__main__":
    sys.exit(main())
