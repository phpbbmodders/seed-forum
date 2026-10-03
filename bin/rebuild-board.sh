#!/usr/bin/env bash
#
# Rebuilds a disposable local phpBB 3.3.x board using SQLite, with optional
# extensions enabled. Removes the board configuration, database, and cache.
# Positional arguments are extension checkout directories validated by the
# public runner. Prints install progress; any failing command stops the reset.
# Fixture seeding runs separately in the public runner after this succeeds.
#
# Internal reset backend. Use reset-board.sh --reset --config FILE.
# Configuration supplied by the public runner:
#   PHPBB_ROOT   phpBB source tree to install into; if missing or empty,
#                the current 3.3.x release is downloaded into it first
#   SERVER_NAME  hostname the installer records for generated URLs
#   SERVER_PORT  port the installer records for generated URLs
#   NGINX_SITE   nginx sites-available path; when enabled, create/update its
#                root, host and port, enable it, validate and reload nginx
#   PHP_FPM_SOCKET optional new-site socket override, otherwise auto-detected

set -Eeuo pipefail

if [ "${SEED_FORUM_CONFIGURED:-0}" != 1 ]; then
	echo "Use bin/reset-board.sh --reset --config FILE." >&2
	exit 1
fi

: "${PHPBB_ROOT:?}" "${SERVER_NAME:?}" "${SERVER_PORT:?}" "${NGINX_SITE:?}"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

MANAGED_EXTS_FILE="$PHPBB_ROOT/.seed-forum-mounted-extensions"
# Remove tracked mounts and the legacy KB mount before replacing the mount set.
unmount_extensions() {
	if [ -f "$MANAGED_EXTS_FILE" ]; then
		while IFS= read -r ext_target; do
			if [ -n "$ext_target" ] && mountpoint -q "$ext_target" 2>/dev/null; then
				sudo umount "$ext_target"
			fi
		done < "$MANAGED_EXTS_FILE"
	fi

	# Also handle mounts created by the original single-extension script.
	local legacy_kb_target="$PHPBB_ROOT/ext/phpbbmodders/knowledgebase"
	if mountpoint -q "$legacy_kb_target" 2>/dev/null; then
		sudo umount "$legacy_kb_target"
	fi
}

# Fresh machine or wiped folder: fetch the current phpBB 3.3.x release
# (as reported by phpBB's own version feed) and unpack it into
# PHPBB_ROOT. Only runs when PHPBB_ROOT is missing or completely empty,
# so an existing board's files are never touched.
if [ ! -d "$PHPBB_ROOT" ] || [ -z "$(ls -A "$PHPBB_ROOT")" ]; then
	echo "==> $PHPBB_ROOT is missing or empty; downloading the current phpBB 3.3.x release"
	phpbb_version="$(curl -fsSL --max-time 30 https://version.phpbb.com/phpbb/versions.json | jq -r '.stable["3.3"].current')"
	if ! [[ "$phpbb_version" =~ ^3\.3\.[0-9]+$ ]]; then
		echo "Couldn't read the current 3.3.x version from version.phpbb.com (got '$phpbb_version')." >&2
		exit 1
	fi
	release_url="https://download.phpbb.com/pub/release/3.3/$phpbb_version/phpBB-$phpbb_version.zip"
	DOWNLOAD_DIR="$(mktemp -d)"
	trap 'rm -rf "$DOWNLOAD_DIR"' EXIT
	curl -fsSL --max-time 300 -o "$DOWNLOAD_DIR/phpBB-$phpbb_version.zip" "$release_url"
	curl -fsSL --max-time 30 -o "$DOWNLOAD_DIR/phpBB-$phpbb_version.zip.sha256" "$release_url.sha256"
	(cd "$DOWNLOAD_DIR" && sha256sum -c --quiet "phpBB-$phpbb_version.zip.sha256")
	# The zip holds a single top-level phpBB3/ folder; move its contents
	# (dotfiles included) into PHPBB_ROOT.
	unzip -q "$DOWNLOAD_DIR/phpBB-$phpbb_version.zip" -d "$DOWNLOAD_DIR"
	mkdir -p "$PHPBB_ROOT"
	(shopt -s dotglob && mv "$DOWNLOAD_DIR/phpBB3/"* "$PHPBB_ROOT/")
	rm -rf "$DOWNLOAD_DIR"
	trap - EXIT
	echo "==> Extracted phpBB $phpbb_version into $PHPBB_ROOT"
fi

if [ -d "$PHPBB_ROOT/install.disabled" ] && [ ! -d "$PHPBB_ROOT/install" ]; then
	echo "==> Restoring install/ (was renamed aside after the last run)"
	mv "$PHPBB_ROOT/install.disabled" "$PHPBB_ROOT/install"
fi

if [ ! -d "$PHPBB_ROOT/install" ]; then
	echo "PHPBB_ROOT ($PHPBB_ROOT) doesn't look like a phpBB source tree (no install/ dir)." >&2
	exit 1
fi

INPUT_EXT_SOURCES=("$@")

declare -A EXT_NAME_SOURCES=()
EXT_SOURCES=()
EXT_NAMES=()
for ext_src in "${INPUT_EXT_SOURCES[@]}"; do
	if [ ! -d "$ext_src" ]; then
		echo "Extension source ($ext_src) does not exist or is not a directory." >&2
		exit 1
	fi
	if [ ! -f "$ext_src/composer.json" ]; then
		echo "Extension source ($ext_src) is missing composer.json." >&2
		exit 1
	fi
	ext_name="$(jq -r '.name // empty' "$ext_src/composer.json")"
	if ! [[ "$ext_name" =~ ^[a-z0-9_.-]+/[a-z0-9_.-]+$ ]]; then
		echo "Extension source ($ext_src) has an invalid composer package name ('$ext_name')." >&2
		exit 1
	fi
	if [ -n "${EXT_NAME_SOURCES[$ext_name]:-}" ]; then
		if [ "${EXT_NAME_SOURCES[$ext_name]}" = "$ext_src" ]; then
			continue
		fi
		echo "Extension '$ext_name' was provided more than once:" >&2
		echo "  ${EXT_NAME_SOURCES[$ext_name]}" >&2
		echo "  $ext_src" >&2
		exit 1
	fi
	EXT_NAME_SOURCES[$ext_name]="$ext_src"
	EXT_SOURCES+=("$ext_src")
	EXT_NAMES+=("$ext_name")
done

echo "==> Wiping previous install state under $PHPBB_ROOT"
# Web requests can leave cache files owned by www-data.
sudo rm -f "$PHPBB_ROOT/config.php"
sudo rm -f "$PHPBB_ROOT/store/sqlite.db"
sudo rm -f "$PHPBB_ROOT/store/install_config.php"
sudo find "$PHPBB_ROOT/cache" -mindepth 1 ! -name '.htaccess' ! -name 'index.htm' -exec rm -rf {} +
sudo chown -R "$(id -u):$(id -g)" "$PHPBB_ROOT/cache" "$PHPBB_ROOT/store"

echo "==> Mounting extensions"
# A bind mount, not a symlink. phpBB resolves the extension's install
# path with realpath() when it builds asset URLs (INCLUDECSS, etc.) -
# realpath() follows a symlink straight through to the source checkout, which
# sits outside PHPBB_ROOT, so the "relative" URL it computes ends up
# being the raw filesystem path instead (a 404, and everything the
# extension styles goes unstyled). A bind mount is the same directory
# reachable at two paths, not a symlink, so realpath() resolves it as
# living under PHPBB_ROOT like any other extension.
unmount_extensions

: > "$MANAGED_EXTS_FILE"
for i in "${!EXT_SOURCES[@]}"; do
	ext_src="${EXT_SOURCES[$i]}"
	ext_name="${EXT_NAMES[$i]}"
	ext_target="$PHPBB_ROOT/ext/$ext_name"
	mkdir -p "$(dirname "$ext_target")"
	if [ -L "$ext_target" ]; then
		rm -f "$ext_target"
	fi
	if mountpoint -q "$ext_target" 2>/dev/null; then
		sudo umount "$ext_target"
	fi
	mkdir -p "$ext_target"
	echo "    $ext_name <= $ext_src"
	sudo mount --bind "$ext_src" "$ext_target"
	printf '%s\n' "$ext_target" >> "$MANAGED_EXTS_FILE"
done

echo "==> Generating install config"
INSTALL_YML="$(mktemp)"
trap 'rm -f "$INSTALL_YML"' EXIT
PHPBB_ROOT="$PHPBB_ROOT" SERVER_NAME="$SERVER_NAME" SERVER_PORT="$SERVER_PORT" \
	python3 "$SCRIPT_DIR/build-install-config.py" "${EXT_NAMES[@]}" > "$INSTALL_YML"

echo "==> Running the phpBB CLI installer"
(cd "$PHPBB_ROOT" && php install/phpbbcli.php install "$INSTALL_YML" -n)

# A fresh install always resets assets_version back to a low number
# (currently 2), which a browser from an earlier session/board on this
# same origin may already have cached CSS/JS under - it'd keep serving
# that stale response since the cache key (URL + query string) matches.
# A timestamp is guaranteed higher than anything previously cached.
sqlite3 "$PHPBB_ROOT/store/sqlite.db" "UPDATE phpbb_config SET config_value = $(date +%s) WHERE config_name = 'assets_version';"

echo "==> Fixing permissions for the web server group (www-data)"
for d in cache store files config images/avatars/upload; do
	if [ -d "$PHPBB_ROOT/$d" ]; then
		chgrp -R www-data "$PHPBB_ROOT/$d"
		chmod -R g+rwX "$PHPBB_ROOT/$d"
	fi
done
chgrp www-data "$PHPBB_ROOT"
chmod g+rwx "$PHPBB_ROOT"

# Not the same directory as $PHPBB_ROOT/files above - this is an
# extension's OWN attachment dir in its source checkout. Knowledgebase's
# ACP controller unconditionally chmod()s it to 0777 on every config-page
# load; group-write alone doesn't let www-data do that (chmod requires
# being the owner), so it needs real ownership, not just group access.
for ext_src in "${EXT_SOURCES[@]}"; do
	if [ -d "$ext_src/files" ]; then
		sudo chown -R www-data:www-data "$ext_src/files"
		sudo chmod -R u+rwX,g+rwX "$ext_src/files"
	fi
done

# phpBB refuses to serve normal pages to anonymous users while install/
# is present (it shows the board-disabled notice instead) - rename it
# aside like a real deployment would, and restore it next time this
# script runs so the installer can use it again.
echo "==> Disabling install/ so the board actually serves pages"
mv "$PHPBB_ROOT/install" "$PHPBB_ROOT/install.disabled"

# Configure this board's site without changing any other site's directives.
# The helper creates missing sites, updates existing listeners, validates nginx,
# and restores the previous file/link if setup or service reload fails.
if [ "${SEED_FORUM_NGINX_ENABLED:-0}" = 1 ]; then
	python3 "$SCRIPT_DIR/nginx-site.py"
else
	echo "==> No nginx site configured; point your web server at $PHPBB_ROOT"
fi

echo "==> Done."
echo "    Board root:   $PHPBB_ROOT"
echo "    Admin login:  admin / KbTest1234!"
