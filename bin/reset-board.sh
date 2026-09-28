#!/usr/bin/env bash
#
# Rebuilds a disposable local phpBB 3.3.x board with the knowledgebase
# extension enabled, using SQLite so there's no external database
# service to manage. Safe to re-run - previous install state under
# PHPBB_ROOT is wiped first.
#
# Usage: bin/reset-board.sh
# Config (env vars, all optional):
#   PHPBB_ROOT   phpBB source tree to install into; if missing or empty,
#                the current 3.3.x release is downloaded into it first
#   KB_EXT_SRC   knowledgebase extension checkout to symlink in
#   SERVER_NAME  hostname the installer records for generated URLs
#   SERVER_PORT  port the installer records for generated URLs
#   NGINX_SITE   nginx site config serving the board; if its root isn't
#                PHPBB_ROOT, it's updated and nginx is restarted

set -Eeuo pipefail

#PHPBB_ROOT="${PHPBB_ROOT:-/home/william/Desktop/bak/phpBB3}"
PHPBB_ROOT="${PHPBB_ROOT:-/home/william/Desktop/repos/seeded-board/kb}"
KB_EXT_SRC="${KB_EXT_SRC:-/home/william/Desktop/repos/knowledgebase}"
SERVER_NAME="${SERVER_NAME:-localhost}"
SERVER_PORT="${SERVER_PORT:-8092}"
NGINX_SITE="${NGINX_SITE:-/etc/nginx/sites-available/phpbb-kb-test.conf}"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"

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

if [ ! -d "$KB_EXT_SRC/controller" ]; then
	echo "KB_EXT_SRC ($KB_EXT_SRC) doesn't look like the knowledgebase extension checkout." >&2
	exit 1
fi

echo "==> Wiping previous install state under $PHPBB_ROOT"
# sudo, not a plain rm, because a previous run's web requests leave
# some cache/ files owned by the php-fpm user (www-data), not us.
sudo rm -f "$PHPBB_ROOT/config.php"
sudo rm -f "$PHPBB_ROOT/store/sqlite.db"
sudo rm -f "$PHPBB_ROOT/store/install_config.php"
sudo find "$PHPBB_ROOT/cache" -mindepth 1 ! -name '.htaccess' ! -name 'index.htm' -exec rm -rf {} +
sudo chown -R "$(id -u):$(id -g)" "$PHPBB_ROOT/cache" "$PHPBB_ROOT/store"

echo "==> Mounting knowledgebase extension"
# A bind mount, not a symlink. phpBB resolves the extension's install
# path with realpath() when it builds asset URLs (INCLUDECSS, etc.) -
# realpath() follows a symlink straight through to KB_EXT_SRC, which
# sits outside PHPBB_ROOT, so the "relative" URL it computes ends up
# being the raw filesystem path instead (a 404, and everything the
# extension styles goes unstyled). A bind mount is the same directory
# reachable at two paths, not a symlink, so realpath() resolves it as
# living under PHPBB_ROOT like any other extension.
EXT_TARGET="$PHPBB_ROOT/ext/phpbbmodders/knowledgebase"
mkdir -p "$PHPBB_ROOT/ext/phpbbmodders"
if [ -L "$EXT_TARGET" ]; then
	rm -f "$EXT_TARGET"
fi
if mountpoint -q "$EXT_TARGET" 2>/dev/null; then
	sudo umount "$EXT_TARGET"
fi
mkdir -p "$EXT_TARGET"
sudo mount --bind "$KB_EXT_SRC" "$EXT_TARGET"

echo "==> Generating install config"
INSTALL_YML="$(mktemp)"
trap 'rm -f "$INSTALL_YML"' EXIT
sed \
	-e "s#{{PHPBB_ROOT}}#$PHPBB_ROOT#g" \
	-e "s#{{SERVER_NAME}}#$SERVER_NAME#g" \
	-e "s#{{SERVER_PORT}}#$SERVER_PORT#g" \
	"$REPO_ROOT/config/install.yml.example" > "$INSTALL_YML"

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

# Not the same directory as $PHPBB_ROOT/files above - this is the
# extension's OWN attachment dir, reached through the ext/ symlink into
# KB_EXT_SRC. acp_controller.php unconditionally chmod()s it to 0777 on
# every config-page load; group-write alone doesn't let www-data do
# that (chmod requires being the owner), so it needs real ownership,
# not just group access, or every ACP load throws a PHP warning.
if [ -d "$KB_EXT_SRC/files" ]; then
	sudo chown -R www-data:www-data "$KB_EXT_SRC/files"
	sudo chmod -R u+rwX,g+rwX "$KB_EXT_SRC/files"
fi

echo "==> Seeding fixtures"
php "$SCRIPT_DIR/seed-kb-fixtures.php" "$PHPBB_ROOT"

# The CLI seeding run above (running as this user, not www-data) writes
# fresh container/SQL/data cache files of its own - undoing the group
# ownership the fix above just set on anything created after that point.
# Repeat it now so www-data can still write new cache entries (e.g. a
# template it hasn't compiled yet) on the first real page request.
echo "==> Re-fixing cache permissions after seeding wrote its own cache files"
sudo chgrp -R www-data "$PHPBB_ROOT/cache"
sudo chmod -R g+rwX "$PHPBB_ROOT/cache"

# phpBB refuses to serve normal pages to anonymous users while install/
# is present (it shows the board-disabled notice instead) - rename it
# aside like a real deployment would, and restore it next time this
# script runs so the installer can use it again.
echo "==> Disabling install/ so the board actually serves pages"
mv "$PHPBB_ROOT/install" "$PHPBB_ROOT/install.disabled"

# Point nginx at this board if it's still serving a different folder
# (for example after PHPBB_ROOT moved). The old config is kept and put
# back if nginx rejects the new one, so a bad edit never takes the
# other sites down.
if [ -f "$NGINX_SITE" ]; then
	PHPBB_ROOT="$(cd "$PHPBB_ROOT" && pwd)"
	nginx_root="$(awk '$1 == "root" { sub(/;$/, "", $2); print $2; exit }' "$NGINX_SITE")"
	if [ "${nginx_root%/}" != "${PHPBB_ROOT%/}" ]; then
		case "$PHPBB_ROOT" in
			*[\#\;\ ]*)
				echo "PHPBB_ROOT contains a character that can't go in the nginx root line; update $NGINX_SITE yourself." >&2
				exit 1
				;;
		esac
		echo "==> Pointing nginx at $PHPBB_ROOT (was $nginx_root) and restarting it"
		sudo cp "$NGINX_SITE" "$NGINX_SITE.bak"
		sudo sed -i "s#^\([[:space:]]*root[[:space:]]\+\).*;#\1${PHPBB_ROOT%/}/;#" "$NGINX_SITE"
		if ! sudo nginx -t -q; then
			sudo mv "$NGINX_SITE.bak" "$NGINX_SITE"
			echo "nginx rejected the new root; $NGINX_SITE was restored unchanged." >&2
			exit 1
		fi
		sudo rm -f "$NGINX_SITE.bak"
		sudo systemctl restart nginx
	fi
else
	echo "==> $NGINX_SITE not found; point your web server at $PHPBB_ROOT yourself"
fi

echo "==> Done."
echo "    Board root:   $PHPBB_ROOT"
echo "    Admin login:  admin / KbTest1234!"
echo "    KB front end: /kb (once a web server is pointed at $PHPBB_ROOT)"
