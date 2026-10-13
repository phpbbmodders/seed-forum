# Wiki Fixtures

`bin/seed-wiki-fixtures.php` populates an installed disposable phpBB board with
the phpBB Modders Wiki enabled. It refuses a board that already has the
"Wiki Discussions" forum or any wiki article. Run it through the seed runner
rather than seeding the same database twice, and run `bin/seed-standard-fixtures.php`
first so the REGISTERED group has its baseline permissions.

```bash
bin/reset-board.sh --validate
bin/reset-board.sh --reset
```

or on an installed board of your own, 3.3.x or 4.0:

```bash
php bin/run-seed.php /path/to/board bin/seed-standard-fixtures.php
php bin/run-seed.php /path/to/board bin/seed-wiki-fixtures.php
```

The local configuration (see `config/local.yml.example`) selects the board root
and the wiki checkout. A reset destroys the configured disposable board, not a
production board. The script needs no PHP extension beyond what phpBB needs: the
fixture image and ZIP are built without GD or ZipArchive.

## Accounts

All four test users use the disposable password `KbTest1234!`, like the other
fixtures. Everyone, signed in or not, may read the wiki.

| Account | Wiki permissions |
| --- | --- |
| `wiki_reader` | Read only. No edit, no version history, no upload |
| `wiki_editor` | Edit and view version history. Edits wait for approval |
| `wiki_uploader` | Editor, plus link a discussion topic and upload files |
| `wiki_moderator` | Everything: approve and set active versions, sticky, redirects, delete versions and articles, see inactive articles |

The installer's `admin` is given the upload, discussion and set-active permissions
so the board can be tried without signing in as anyone else.

## Articles

Ninety-six versions are saved across these articles:

| Article | What it shows |
| --- | --- |
| Main Page | Sticky, three versions, an embedded image, links to existing and missing articles, a `[[link\|text]]` |
| Getting Started | Two versions, sources, a real discussion topic (the Discussion button works) |
| Markup Guide | An embedded image, a ZIP embedded as a link with its own text, a `[[File:missing.png]]` that was never uploaded |
| Release Process | A live version plus a newer version waiting for approval |
| Style Guide | A live version plus a pending edit older than it (the "older than the live version" warning) |
| Draft Notes | Never approved: no live version, hidden from everyone but moderators |
| Important Notice | Marked important, shown in its own list |
| Old Name | A redirect to Getting Started |
| Übersicht ✓ | A title with non-ASCII characters |
| Busy Article | Thirty versions from three editors, so the history and contributions pages page |
| Bulk Article 01 to 40 | Forty live articles, so the overview lists run to several entries |
| Template:Out of date | A notice template with two versions and a `<noinclude>` documentation part with a live example |
| Template:Extension infobox | An infobox (a table) whose rows appear only when a value is given, with `#if` |
| Template:Row | A table row for use between `<table>` tags |
| Template:Start Extension, Template:Start Person | Starting points offered by **Start from** when a new article is created |
| Example Extension | An article that uses the notice and the infobox, with `#expr` inside a parameter |
| Version Table | A table with a caption, `colspan` and `rowspan`, and a table whose rows come from `{{Row}}` |
| Parser Functions | A table of parser function calls (in `[code]`) next to what they produce |
| Headings and Contents | `==` headings, a contents box, and `[[Article#Section]]` and `[[#Section]]` links |

The template and markup articles are created only when the extension has templates
(it needs a build with `phpbbmodders\wiki\wiki\templates`); on an older build the
script says so and skips them.

Three files are stored in `store/phpbbmodders_wiki/` and recorded in the
`wiki_file` table: `logo.png` (Main Page), and `diagram.png` and `notes.zip`
(Markup Guide). The board's discussion forum, "Wiki Discussions", is set as the
wiki's discussion forum, so the Start discussion button shows on every article
that has no topic, to members who may use it.

## Useful pages

- `/wiki/`: the overview, with the sticky, popular, latest and all-articles lists. The Pending list shows to
  `wiki_moderator`.
- `/wiki/Busy%20Article?action=versions`: a version history of thirty rows.
- `/wiki/templates`: the list of five templates. Open `/wiki/Template:Extension infobox` for a template page
  (documentation, then the template text and how many articles use it).
- `/wiki/Brand%20New?action=edit`: the **Start from** choice on a new article, which offers the two Start templates.
- `/wiki/contributions/wiki_editor`, `wiki_uploader`, `wiki_moderator`: contributions pages. Sign in as
  `wiki_moderator` to see the pending versions among them.

## Checked on

The script was run on fresh boards on phpBB 3.3.19 with MySQL 8.0 and with
PostgreSQL 16, and on phpBB 4.0.0-a3-dev with SQLite, and a browser run confirmed
what guests, readers, editors, uploaders and moderators see on each.
