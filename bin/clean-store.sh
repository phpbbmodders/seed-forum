#!/usr/bin/env bash
#
# Empties a board's store/ folder for a reset, keeping only the files phpBB's
# own package ships there (.htaccess and index.htm). The SQLite database,
# install_config.php and every extension's folder (uploads, attachments,
# starter templates) are removed, so a reset board starts with a clean store/.
#
# Usage: clean-store.sh PHPBB_ROOT
#
# Refuses, without deleting anything, when store/ is a symlink, resolves to
# somewhere other than PHPBB_ROOT/store, or has a mount point inside it.
# Symlinks inside store/ are removed themselves; their targets are never
# followed. A missing store/ is left alone. Exits nonzero on refusal or error.

set -Eeuo pipefail

if [ "$#" -ne 1 ] || [ -z "$1" ]; then
	echo "Usage: clean-store.sh PHPBB_ROOT" >&2
	exit 2
fi

root="$(realpath -e -- "$1")"
store="$root/store"

if [ -L "$store" ]; then
	echo "Refusing to clean $store: it is a symlink." >&2
	exit 1
fi
if [ ! -e "$store" ]; then
	exit 0
fi
if [ ! -d "$store" ] || [ "$(realpath -e -- "$store")" != "$store" ]; then
	echo "Refusing to clean $store: it does not resolve to a folder inside $root." >&2
	exit 1
fi

# rm -rf never follows symlinks, but it does descend into a bind mount, which
# would delete files outside the board.
while IFS= read -r -d '' dir; do
	if mountpoint -q -- "$dir"; then
		echo "Refusing to clean $store: $dir is a mount point." >&2
		exit 1
	fi
done < <(find "$store" -mindepth 1 -type d -print0)

find "$store" -mindepth 1 -maxdepth 1 ! -name '.htaccess' ! -name 'index.htm' -exec rm -rf -- {} +
