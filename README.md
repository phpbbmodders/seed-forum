# seed-forum

Rebuilds a disposable local phpBB 3.3.x test board (SQLite, no external
DB service) with the [`phpbbmodders/knowledgebase`](https://github.com/phpbbmodders/knowledgebase)
extension enabled and seeded with fixture data, so the extension's
front end can be clicked through by hand after a change instead of
relying on lint/read-through alone.

## Usage

```bash
bin/reset-board.sh
```

This wipes and reinstalls the board pointed at by `PHPBB_ROOT`
(default `/home/william/Desktop/repos/seeded-board/kb`), bind-mounts in the
extension checkout at `KB_EXT_SRC` (default
`/home/william/Desktop/repos/knowledgebase` - a bind mount, not a
symlink, since phpBB's asset URLs break under a symlinked extension
path; see the comment in `reset-board.sh`), and runs
`bin/seed-kb-fixtures.php` against the fresh install. Safe to re-run
any time a clean slate is wanted.

If `PHPBB_ROOT` doesn't exist or is empty, the script first downloads
the current phpBB 3.3.x release (version taken from
`version.phpbb.com`, zip checked against its published SHA-256) and
unpacks it there. A folder that already has files in it is left alone.

The board is served by the desktop's nginx site config
`/etc/nginx/sites-available/phpbb-kb-test.conf` (port `:8092`), which
isn't part of this repo. If its `root` isn't `PHPBB_ROOT` (for example
after the board moved to a different folder), `reset-board.sh` updates
it and restarts nginx; if `nginx -t` rejects the change, the old config
is put back. Set `NGINX_SITE` to use a different site config.

### What gets seeded

- Two forums: a public "Knowledge Base Comments" forum and a
  moderator-only "Knowledge Base Changelog" forum, wired up via the
  extension's own config keys.
- Four users, all with password `KbTest1234!`: `kb_author1`,
  `kb_author2`, `kb_moderator` (granted `a_manage_kb`), and `kb_reader`
  - a plain REGISTERED member with `u_kb_view` but no submit/edit
  rights, for checking view-only access actually stays view-only.
- Two groups: `KB Team` (`kb_moderator` + `kb_author2` - assignable
  reviewer/group-author status) and `KB Contributors` (`kb_author1` +
  `kb_author2` + `kb_moderator` - holds the category-level
  `kb_u_add`/`kb_u_edit`/`kb_u_delete` grants). `kb_reader` is
  deliberately in neither.
- Two nested KB categories (`Getting Started` > `Advanced Topics`).
- Four articles covering: a plain single-author article, a
  multi-author article (user co-author + group co-author), an
  unapproved group-authored article (for the moderation queue), and an
  active article with a pending revision (for edit-conflict locking).

Admin login: `admin` / `KbTest1234!`.

## Layout

- `bin/reset-board.sh` - the entry point; wipes, reinstalls, seeds.
- `bin/seed-kb-fixtures.php` - bootstraps phpBB's container directly
  (same pattern as `bin/phpbbcli.php`) and creates the fixtures above
  using phpBB's and the extension's own APIs (`update_forum_data()`,
  `user_add()`, `group_create()`, `update_category_data()`,
  `set_co_authors()`, `save_article_revision()`) rather than hand-built
  SQL, so seeded data goes through the same validation/side-effects a
  real submission would.
- `config/install.yml.example` - the phpBB CLI installer config
  template (`{{PHPBB_ROOT}}`/`{{SERVER_NAME}}`/`{{SERVER_PORT}}`
  placeholders filled in by `reset-board.sh`).

## Future direction

Seeding is currently scoped to what the knowledgebase extension's
authorship/revision features need to be exercised by hand. A more
general "standard forum" seed - multiple plain phpBB forums, several
users/groups, topics and replies, independent of the KB extension - is
a planned follow-up, not yet built.

## License

Local tooling, not published; no license file.
