#!/usr/bin/env bash
# Public CLI entry point. Forward options unchanged to the Python runner.
# With no operation the runner prints help; only --reset reinstalls the board.
set -Eeuo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
exec python3 "$SCRIPT_DIR/seed-forum.py" "$@"
