# Usage Guide

For requirements and initial setup, see the [README](../README.md).

Manage a disposable local phpBB 3.3.x board using SQLite. Configure local
extensions and their seed scripts in YAML. Mount extensions, change their
enabled state, seed fixtures, or purge cache independently of resetting.

## Quick Start

Only `--reset` wipes and reinstalls the board. Running without an operation
prints help.

```bash
bin/reset-board.sh --validate
bin/reset-board.sh --reset --dry-run
bin/reset-board.sh --reset
```

The default config is `config/local.yml`, ignored by Git and not supplied in
a fresh clone. Copy `config/local.yml.example`, edit its board and checkout
paths, and remove any extension or nginx configuration you do not need.
Select another config with `--config FILE`.

## Configuration

```yaml
variables:
  TEST_BOARD: /tmp/seed-forum-board
  KB_CHECKOUT: /path/to/knowledgebase
  SFS_CHECKOUT: /path/to/sfscompanion

board:
  root: ${TEST_BOARD}
  server_name: localhost
  server_port: '8092'
  seeds:
    - bin/seed-standard-fixtures.php

extensions:
  knowledgebase:
    source: ${KB_CHECKOUT}
    seeds:
      - bin/seed-kb-fixtures.php
  sfscompanion:
    source: ${SFS_CHECKOUT}
    seeds: []
```

Variable names and extension labels are chosen in the config. Environment
variables override the configured variable defaults. `${NAME}` references
can refer to other variables. Undefined references, cycles, duplicate
keys, and unknown fields are rejected. No shell commands are evaluated.

Each checkout's `composer.json` name determines its mount under
`board.root/ext/vendor/name` and its phpBB extension name. Add extensions
as config entries; positional checkout arguments are no longer supported.

Relative board/source paths resolve from the config directory. Seed paths
resolve from this tooling repository; absolute paths also work. Each seed
is a PHP CLI script receiving the board root as its first argument. Board
seeds run first, then extension seeds in config order.

The committed example runs both standard and Knowledgebase seeds. For a
standard-only board, use `extensions: {}`. For a KB-only board, use
`board.seeds: []` and keep the Knowledgebase entry.

The committed example contains paths from the original development machine;
replace them before use. Optional `board.nginx_site` selects a local nginx config.
When configured, reset creates a missing site, enables it with a
`sites-enabled` symlink, and sets its root, hostname, and listen port
from the board settings. Existing sites keep their other directives
and listener addresses. The site path must be under `sites-available`,
with one server block per board. Omit this field to leave nginx alone.

New sites listen on localhost IPv4 and IPv6. They use the detected
PHP-FPM socket; set the `PHP_FPM_SOCKET` environment variable if multiple
PHP versions are installed. Before reloading, the script runs `nginx -t`.
It restores the previous file and enabled-link state if validation or
reload fails. An inactive nginx service is started when setup succeeds.

`server_port` sets phpBB's generated URLs and, when `nginx_site` is
configured, the site's listen port. For example, this config creates
and serves the bare board on `localhost:8093` during reset:

```yaml
board:
  root: /tmp/seed-forum-board
  server_name: localhost
  server_port: '8093'
  nginx_site: /etc/nginx/sites-available/phpbb-bare.conf
  seeds:
    - bin/seed-standard-fixtures.php
extensions: {}
```

This is an example, not an already installed site. Reset installs the board
and configures nginx when `nginx_site` is present. Without that field, configure
your web server separately.

## Styles

Add local styles by directory name. Their parent style must already be installed
(prosilver is installed with the board). For example:

```yaml
styles:
  ProMinoDeux:
    source: /path/to/ProMinoDeux
    default: true
```

Use `styles: {}` when no custom styles are needed. Reset copies and installs
configured styles even with `--skip-seed`. To install or refresh them on an
existing board without resetting its data:

```bash
bin/reset-board.sh --config config/local.yml --install-styles
```

Only one entry may set `default: true`. Users using the previous board default
move to the new default; other style choices are preserved. Styles are copied,
so rerun `--install-styles` after editing the source. The command purges cache.

## Options

| Option | Behavior |
| --- | --- |
| `--config FILE` | Select YAML configuration; defaults to `config/local.yml`. |
| `--reset` | Wipe/reinstall, mount and enable selected extensions, then seed. |
| `--mount` | Add selected mounts while retaining existing managed mounts. Already mounted targets are rejected. |
| `--remount` | Replace the full managed mount set with the selected extensions. |
| `--unmount` | Remove all managed mounts, or only those selected by `--only`. |
| `--enable` | Enable selected extensions through phpBB CLI. |
| `--disable` | Disable selected extensions through phpBB CLI without purging their data. |
| `--seed-only` | Run seeds on an existing board, with repeat protection. |
| `--install-styles` | Copy/install configured styles and select the default without resetting data. |
| `--skip-seed` | Skip all seeds during reset. |
| `--purge-cache` | Purge phpBB cache independently or after other operations. |
| `--only LABELS` | Select comma-separated extension config labels; excludes board seeds. |
| `--dry-run` | Validate and print the selected operation without executing it. |
| `--status` | Show the board root, configured port/URL, sources, mount state, and enabled/disabled extensions. |
| `--validate` | Check config, sources, package names, and seed PHP syntax. No installed board needed. |
| `--help` | Show command help. |

Supply an operation with `--dry-run`. Choose only one of reset, mount,
remount, or unmount. Enable and disable cannot be combined. `--skip-seed`
requires reset. Status and validation cannot accompany changes.

Commands run in this order: disable, unmount/remount, mount, enable,
seed, style installation, then purge cache. Reset performs its own mount and enable steps.
Disabling precedes unmounting regardless of argument order.

```bash
bin/reset-board.sh --config config/local.yml --mount --enable --only sfscompanion
bin/reset-board.sh --remount --purge-cache
bin/reset-board.sh --purge-cache
bin/reset-board.sh --disable --unmount --only sfscompanion
bin/reset-board.sh --seed-only --only knowledgebase
bin/reset-board.sh --reset --skip-seed
bin/reset-board.sh --status
```

The sfscompanion examples require that entry in your config. `--only`
uses config labels, not Composer names. With remount it replaces the
entire managed mount set with the selection. With unmount it removes
only the selection. Mounting does not enable extensions; combine with
`--enable` when needed. Disable extensions before unmounting if you plan
to continue using the board without them.

Status reports the configured port and URL; it does not test whether a web
server is listening there. Apply nginx setting changes with `--reset`.

phpBB's cache purge clears cached data, templates, the container, and
routes, and increments the asset version. It does not empty a separate
extension cache directory or restart PHP to clear OPcache.

## Seed Protection And Mount Records

Seed attempts are recorded in `.seed-forum-seeds.json` under the board
root. A lock prevents simultaneous seed execution. Completed, failed,
or interrupted seed attempts cannot be repeated; reset starts a new
ledger. Failed seeds may leave partial data and are not rolled back.
Extensions with seeds must be enabled before using `--seed-only`, or
enabled in the same command with `--enable`.

Calling a seed script directly bypasses the ledger. Boards seeded before
the ledger existed should be reset before using `--seed-only`. The
standard and KB scripts also reject their existing fixture forums.

Mounts use bind mounts because symlinks can break phpBB asset URLs.
`.seed-forum-mounted-extensions` records managed targets under the board
root. `.seed-forum-extension-names.json` remembers label/package mappings
so selected unmounts can work after a checkout disappears. The legacy
Knowledgebase mount is also recognized. Unmounting preserves source
checkouts and board data. Failed unmounts retain remaining mount records
for retry.

## Fixtures

Standard fixtures use phpBB's forum, user, group, permission, and posting
APIs. They include two categories, three public forums, a nested project
forum, a private staff forum, two custom groups, 12 topics, and 30 replies.
Reference Library is read-only for ordinary members. Topic states include
sticky, announcement, and locked; content includes quotes, lists, links,
and code blocks.

Standard users are `forum_alex`, `forum_blair`, `forum_casey`, `forum_drew`,
and `forum_moderator`. The first four are members; the last has staff
access and moderation permissions.

Knowledgebase fixtures include public comments and private changelog
forums; `kb_author1`, `kb_author2`, `kb_moderator`, and `kb_reader`; KB Team
and KB Contributors groups; three categories; and 11 articles covering
approvals, revisions, co-authorship, redirects, tags, comments, and private
drafts. `kb_reader` has view-only KB access. KB seeding preserves unrelated
forum permissions.

All fixture users use `KbTest1234!`. Installer admin credentials are
`admin` / `KbTest1234!`, intended for disposable local boards.
These credentials are public. Keep the board local; do not expose it to the internet.

## Requirements And Checks

Requires Bash, Python 3.9+ with PyYAML (`python3-yaml`), and PHP compatible
with the board. Reset also uses `jq`, `sqlite3`, `curl`, `unzip`, and
`sha256sum`. Mount/unmount and web-group permission repairs use `sudo`.
The permission setup assumes the local web group is `www-data`; cache
permissions are repaired after enable, disable, seed, and purge commands.

If the board directory is missing or empty, reset downloads the current
phpBB 3.3.x release and verifies its published SHA-256. Existing phpBB
source trees are reused. Nonempty directories that are not phpBB trees
are rejected. Mount, remount, enable, disable, seed, and cache commands
require an installed board. Unmount requires an existing board directory.

```bash
python3 -m unittest discover -s tests -v
bash -n bin/reset-board.sh
bash -n bin/rebuild-board.sh
bin/reset-board.sh --validate
```

## Layout

- `bin/reset-board.sh`: public command entry point.
- `bin/seed-forum.py`: config parsing, validation, selection, and operations.
- `bin/rebuild-board.sh`: internal reset backend.
- `bin/build-install-config.py`: builds installer YAML from the template.
- `bin/nginx-site.py`: creates/enables or updates a board site with rollback.
- `bin/run-seed.php`: seed ledger and execution lock.
- `bin/seed-bootstrap.php`: shared phpBB bootstrap for standard fixtures and state checks.
- `bin/seed-standard-fixtures.php`: core forum fixtures, independent of KB.
- `bin/seed-kb-fixtures.php`: Knowledgebase-specific fixtures.
- `bin/extension-state.php`: reads enabled/disabled extension state.
- `config/local.yml.example`: example runner configuration; edit its local paths.
- `config/bare.yml`: original development-board profile; edit paths and style choices before use.
- `config/install.yml.example`: phpBB installer settings template.
- `config/nginx-site.conf.example`: template for new nginx board sites.
- `config/phpbb-bare.conf`: concrete localhost:8093 bare-board site example.
- `tests/test_runner.py`: command and config regression tests.
- `tests/test_nginx_site.py`: site creation, updates, and rollback checks.
