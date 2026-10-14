# Knowledgebase Fixtures

`bin/seed-kb-fixtures.php` populates an installed disposable phpBB board with
the Knowledgebase extension enabled. It refuses boards that already contain
the fixture forums. Run it through the existing reset runner rather than
repeatedly seeding the same database. PHP GD is required for the image fixture.

```bash
bin/reset-board.sh --validate
bin/reset-board.sh --reset
```

The local configuration selects the board root and extension checkout. A reset
destroys the configured disposable board, not a production board. Review the
configuration before running it.

## Accounts And Teams

All four KB test users use the disposable password `KbTest1234!`.

| Account | Purpose |
| --- | --- |
| `kb_reader` | Read published content; no posting or private-draft access |
| `kb_author1` | Contributor; Operations and Documentation teams |
| `kb_author2` | Contributor; KB and Documentation teams |
| `kb_moderator` | Global moderation; all three teams |

The three configured review teams are **KB Team**, **Operations Team**, and
**Documentation Team**. Each has group-authored articles and assigned queue
entries. Other entries are deliberately unassigned. Team membership is not the
same as global moderation: category-scoped moderator permissions are present
for KB Team in Events and Listeners and Operations Team in Team Runbooks.

## Category Trees

There are 37 categories under four independent roots, with branching siblings,
two intentionally empty categories, and a six-level path in every root:

```text
Getting Started > Installation > Local Development > Linux Workstations
  > Nginx and PHP-FPM > Clean URL Troubleshooting
Reference > Command Reference > phpBB CLI > Maintenance Commands
  > Cache Operations > Cache Recovery Recipes
Board Operations > Backups and Recovery > Restore Drills > Database Recovery
  > SQLite Recovery > Post-Restore Verification
Community Handbook > Moderation > Reports and Escalation > Appeals
  > Review Process > Decision Templates
```

Archived Release Notes is read-only for contributors. Team Runbooks allows
Operations Team contributions. Routine Maintenance permits contributor
autoapproval. Defaults vary between unassigned and all three review teams.
One deep guide is cross-listed into the other main trees and a scoped
moderation category.

## Article And File Cases

The clean fixture set contains 265 articles, including 216 bulk entries for
pagination. Cases include new, reviewed, active, inactive, redirects, pending
revisions, private drafts and private edits of published articles. Inactive
fixtures have `approved=0`, matching the extension's deactivation operation.

Named content covers individual authors, group authors, user and group
co-authors, BBCode, Markdown headings and anchors, multilingual titles,
cross-listing, comments, and a moderator-only changelog forum. A
`Help and Questions` forum holds three member questions with a reply each
(backing up before an upgrade, clean URLs on nginx, the installation guide),
so a board search for `backup`, `troubleshooting` or `installation` shows forum
posts with the Knowledge Base block beside them. Searchable
branch guides share the keyword `branchwalk`. The seed builds the configured
search backend through its public indexer.

All three custom-field widgets are represented: checkbox lists, dropdowns,
and radio buttons. Required, optional, inactive-field and inactive-value cases
are present. Submitted articles have required values; the private draft
intentionally lacks them.

Eight attachment rows cover public text, a PNG with a thumbnail, private
draft, pending, reviewed and inactive files, an aged orphan, and a deliberate
missing-file orphan. A separate `kb_fixture_extra-file.txt` has no database row.
Orphans are dated one day earlier so they appear in the ACP's aged-upload list.
Only the fixture-created missing file is removed by the seed. Stable filenames
avoid accumulating new files on every reset. The files go in the folder the
extension keeps attachments in: `store/phpbbmodders_knowledgebase/files/` for
current versions (the seed uses the extension's own folder helper), or the
extension's `files/` folder for older ones.

## Runtime Coverage

Browser validation was performed on the local SQLite board with visible
Chromium, desktop, tablet, and phone viewports. Screenshots and individual test
results accompany the local walkthrough report. Seed coverage is not proof
that every feature works.

Verified workflows include all 37 category pages and ancestor breadcrumbs,
reader/author/moderator permission boundaries, all team queue filters,
pagination, Markdown, thumbnails and download gates; posting required-field
errors, upload, private draft, submission, assignment, team review, reason
validation, approval, private edit, revision comparison and approval,
deactivation/reactivation, group and co-authorship, rejection and deletion;
copy-link and print; approval/rejection bell notifications, private messages,
and email delivery to a loopback-only mail catcher.

ACP coverage includes opening all 13 KB screens, configuration saves, classic
and non-classic/flat layouts, invalid-CSRF rejection, category creation with
copied permissions/default team, rename/move/delete, custom-field add/toggle/
delete, value add/disable/delete, attachment-extension toggles, native search
index deletion/recreation, and deliberately broken-attachment cleanup. The
cleanup preserved every real-file row. Group/category permission editor and
mask details were inspected without changing the fixture permissions.

### Defects Found And Fixed

Preview previously cleared newly selected custom-field values and cross-listed
categories on the posting form. A subsequent submit failed the required-field
checks until the author reselected the values. The original test records this failure;
the remaining publication workflow was tested after reselecting them.

The source cause was in `knowledgebase/controller/posting.php`: selection
redisplay used the submitted values only for `$submit`, not `$preview`.
Advanced Search previously disabled category choices for a read-only member
who could browse those categories. `knowledgebase/controller/search.php` called
`make_category_select(0, [], false)` with its default `kb_u_add` permission,
so the selector checks posting rights instead of read/search access.

Both defects were fixed in the extension's posting/search controllers and
passed seven focused browser regressions, including existing-article changes,
cleared values, real category/author search, and unchanged access boundaries.
The original failed test records remain as historical evidence. A separately
requested article-metadata fix
puts each displayed custom field on its own line and omits empty fields.

### Boundaries

Bulk extra-file deletion is deliberately not executed against the mounted
source checkout: it could remove unrelated files. The extra-file diagnostic
fixture is available for a separately isolated upload-directory test.
Not every permission write combination, ACL_NEVER inheritance combination,
upload type/size boundary, stale-edit conflict, notification preference
combination, alternate search backend, database engine or browser engine has
been validated. Extension install/upgrade/purge compatibility is separate from
this fixture and browser run. Pending-review email delivery is not confirmed.

The final reset restores clean fixture content and disables the temporary
mail setup. Workflow screenshots show the test run, not permanent additions
to the demo board.
