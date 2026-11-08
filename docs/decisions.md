# Documentator: decisions

Settled calls, with the reason. Check here before changing the schema or the rules. Source of the plan:
`Documentator-Handover.pdf` (7 Nov 2026), where a choice there is not overridden below.

## Stack and shape (8 Nov 2026)

- **Follow PMS (Bench), not PAS and not MyPortal.** Laravel 13 on PHP 8.3+, SQLite, PHPUnit, Pint. The page is hand-built
  in `resources/app/` (HTML, CSS, JS joined by `npm run build`) and talks to a JSON API. The look uses Bench's colour and
  type tokens. (The handover assumed Laravel + Nuxt like MyPortal; the user chose PMS.)
- **No PHP on Windows.** Everything runs through Docker (`composer:2` image): `scripts\dev.cmd` starts the server at
  http://localhost:8100, `scripts\make-account.cmd` makes accounts. Port 8000 is left to MyPortal.
- **The server is the security boundary.** The `X-Documentator` header plus a same-origin check replace a CSRF token.

## Sign-in and accounts

- Username and password, no e-mail, no sign-up page. The first account comes from `make-account.cmd`.
- Lockout: 5 wrong tries per username, 25 per address, 15 minutes. Sessions end when idle (12 h) and after 7 days.
- `users.auth_source` / `external_id` exist so accounts can be linked to OneHub later. A OneHub account cannot sign in
  with a local password.
- **Password changes follow PMS:** an admin sets every password, and people do not change their own. **One exception the
  user asked for:** a password an admin has just set must be changed by the person at their first sign-in, and an admin
  reset forces the same again. (Built 8 Nov 2026, see "Users and roles" below.)
- An admin creates users in the app. (Built 8 Nov 2026, see "Users and roles" below.)

## Documents

- **One code = one document**, like a stapled packet. A document has versions (**Ver** 0, 1, ...; the user preferred "Ver" to "Rev", 8 Nov 2026), and a version has one or more
  files. "Folder" means the physical place the paper is kept, never a document.
- **The code is free text.** The app only *suggests* `TSI-YY-MM-DD-NN` (today in Asia/Manila, next free number, two digits,
  more past 99), filled in on the form. The person may keep it, edit it, or type any code ("RandomCodeafe"). It is unique
  whatever its case; the unique index decides, not the suggestion. The prefix is `documentator.code_prefix`.
- **Physical location is a free-text note for now.** The Office > Cabinet > Shelf > Group > Folder tree comes with the masterfile.
- **Department and type are free text** with the values already used offered as suggestions. They become real tables when
  department-scoped permissions need them.
- Files: PDF, Word (doc, docx), Excel (xls, xlsx), PowerPoint (ppt, pptx), CSV, text, PNG, JPG. A name is never enough: the
  content must fit the extension (`documentator.uploads.types`), and a file is stored and served as what its extension says.
  Up to 10 files of 25 MB each; stored privately under made-up names with a SHA-256; never public. Every download goes
  through the server and is audited. The list was widened on 8 Nov 2026 at the user's request ("doc, docx, excel, xls,
  xlsx, especially pdf").
- The upload form order is Code, Files, Title, then the rest. The title is filled from the first file's name (underscores
  become spaces) until the person types one of their own. Issued on starts as today.
- **File viewer (8 Nov 2026):** a View button opens a file inside the app: a PDF in the browser's own PDF viewer (an
  `<iframe>` on a `blob:` address, so the page's policy allows `frame-src blob:` and `object-src blob:` and nothing more),
  a picture, plain text (first 1 MB) or a CSV as a table (first 1000 rows). The file is fetched with the app header, so it
  needs a sign-in, and the server audits it as `file.viewed` (a download is `file.downloaded`).
- **Word (.docx) and Excel (.xlsx) viewing (8 Nov 2026):** a plain preview, converted in the browser by two vendored
  libraries in `resources/app/vendor/` (mammoth 1.13.0 for Word, BSD-2; read-excel-file 5.8.5 for Excel, MIT; licences
  beside them). The build copies them to `public/build/documentator/vendor/`, and the viewer loads one only the first time
  such a file is opened (same-origin script, so the policy is unchanged). Word is read as a document and only plain tags are
  copied into the page (no links, styles, scripts; pictures only as data), never inserted as markup. Excel shows sheet tabs
  and the first 1000 rows, formulas as their last saved value. Fonts, headers, page layout, formatting and charts are not
  shown, and the page says so. **Still download-only:** the old .doc and .xls, and PowerPoint (.ppt, .pptx). An exact view
  or editing in the browser means an editor service (OnlyOffice, Collabora, Microsoft 365), a later decision; for now the
  way to change a file is download, edit, upload as a new version.
- What a person may do depends on their role ("Users and roles" below).
- **Uploading a new version (8 Nov 2026):** "Upload new version" in the drawer adds Ver 1, 2, ... to the same document (same
  code and details). The new version is the whole set of files for that version (not only the changed ones), with an optional
  change note (up to 500 characters). Earlier versions and their files are kept and stay downloadable. The number is the
  highest plus one, and a clash with someone uploading at the same moment is refused as `version_conflict`. Audited as
  `version.created` with the note "Ver N". Internally the column and the API field are still named `rev_no` / `rev`; only the
  words on screen and in the audit notes say Ver.
- Not built yet: the duplicate-file warning, a per-document audit timeline, editing a document's
  details (title, dates, location) after registering.

## Audit

- `audit_logs` is append-only; on SQLite triggers refuse UPDATE and DELETE. Sign-in, failed sign-in, sign-out, user creation,
  document registration, new versions and file views and downloads are recorded.

## Saving space and archiving (8 Nov 2026, asked for by the user, not built yet)

- **Zipping saves little on most files.** PDF, Word, Excel, PowerPoint, PNG and JPG are already compressed inside, so zipping
  them saves about 0-5%; only text and CSV shrink a lot. **The user's note:** zipping the files of old versions, which are
  rarely opened, would probably still be best, accepting that viewing, downloading and retrieving them is slower, for the
  disk space. (Kept as the user's call; the cheaper wins are sharing one stored copy of byte-identical files across versions,
  using the SHA-256 already kept, and compressing at disk level. Neither was chosen yet.)
- **Last viewed and last downloaded.** Each file shows when it was last viewed and last downloaded, and by whom, as small text
  beside its View and Download buttons, and the documents table shows the latest of each. "Accessed" means viewed or
  downloaded. This is also what the archive rules read.
- **Audit like KVNsys** (read from `Desktop/KVNsys/Phoenix`: `KVNsysCore/shared/audit-trail.ts`, `audits/audit-log-book.ts`,
  `docs/schema.md`): one row **per changed column** (table, record, column, value before, value after, Created / Updated /
  Decommissioned, Manual / Automatic, who, where, when), written **in the same transaction as the save** so a change is never
  kept without its record and a refused save leaves none; secret columns (password hash) logged as "(hidden)"; an unchanged
  value (null vs empty) is not a change; the rows are append-only and the database refuses to change them. Today's
  `audit_logs` is one line of text per event; it stays for sign-ins and file access, and a column-level table is added beside
  it for changes to documents, versions, files, users and archive rules.
- **Panels:** Users, Documents, Archiving and Audits along the top.
- **Archiving screen:** a table sorted with the most recently accessed first, a checkbox per version and a "select all", an
  "Archive selected" button (the manual way), and a way to take a version back out of the archive. **A current version can
  never be archived.** Rules, set on the same screen: archive a version automatically when it has not been accessed for X
  days / months / years, and/or when it is an old (not current) version.
- **Documents page:** each version shows whether it is archived.

- **Built (8 Nov 2026), step 1:** `audit_changes` (one row per changed column, written in the same transaction as the save,
  append-only by trigger; `App\Support\ChangeLog`, rules in its unit test) now records the creation of documents, versions,
  files and accounts, a new version's change to its document, and a password change (value hidden). `true`/`false` columns are
  logged in words. Other tables join as screens that edit them are built. Files carry `last_viewed_at/by` and
  `last_downloaded_at/by`; the drawer shows them under each file, the table shows the latest of each per document. **Not
  column-audited on purpose:** the access dates themselves, since each press is already a line in `audit_logs`.
  The step-2 Audits screen reads `audit_changes`.

- **Built (8 Nov 2026), step 2:** four panels along the top, Documents, Users, Archiving and Audits (the panel is kept in the
  address, `#audits`, so a refresh stays put). **Users and Archiving are placeholders** that say what will come. **Audits**
  (`GET /api/audits`, `App\Audits\ChangeReader`) shows `audit_changes` newest save first, each save's columns in the order
  written, the newest 500 that match with a note when there are more. Filters: table, person (including "the command line"),
  from and to day by the office clock (Asia/Manila), and one record's history by choosing its name. Names stand in for ids
  (people, document codes, "CODE · Ver N", file names) and `true`/`false` read Yes/No; a record that is gone shows as "(gone)
  id". Changes made from the command line are credited to "The command line", not to the account they touch. Until roles
  exist any signed-in person can read Audits; it will need its own right, as in KVNsys (`audits.view`). Not in Audits: sign-ins
  and file views/downloads, which stay in the older `audit_logs` (no screen yet).

- **Navigation is a left side bar, like PMS (8 Nov 2026, the user's call):** it replaced the tabs along the top. Groups
  "Documents" (Documents, Archiving) and "Administration" (Users, Audits); the signed-in person and Sign out at the bottom. On
  a wide screen the toggle in the top strip folds it to icons and back, and the choice is kept in this browser
  (`documentator.rail.v1`, applied before first paint by `head.js`). Under 1024 px it slides over the page from the menu
  button and closes on picking a panel, on Esc, or on tapping outside it. The CSS follows PMS's `04-app-shell.css` and
  `24-responsive-layout.css`.

- **Built (8 Nov 2026), step 3, manual archiving:** the unit is a **version**. Archiving packs all of a version's files into
  one zip (`archive/<document id>/ver-N-<uuid>.zip` on the private disk, each file named by its id inside) and removes the
  loose copies, **only after** the zip is written and every file in it hashes to the SHA-256 recorded at upload; the
  `document_files` rows stay. **A current version is refused** (`current_version`, 409). An archived file still views and
  downloads, read out of the zip (slower), audited as before. Taking a version out unpacks to the original paths, checks the
  hashes again, then deletes the zip. Each archive or restore is a column change on `document_versions` (`archived_at`,
  `archived_by`, shown in Audits as "Archived on / by") and a `version.archived` / `version.restored` line in `audit_logs`.
  The Archiving panel lists only old versions, the most recently opened first and never-opened last (tie: newer first), with
  tick boxes, select all (what is shown), "Archive selected" and "Take out of archive", a filter (all / not archived /
  archived) and a search; one request per version so a long run shows progress and one failure stops no other. The
  Documents list shows "N archived" beside a document's Ver, and the drawer marks each archived version. **Honest note:**
  zipping already-compressed files saves little, and a tiny file gets bigger in a zip (fixed overhead); the panel prints the
  real before and after. The list has no paging yet; it comes with volume. Step 4 (automatic rules) is not built.

- **Built (8 Nov 2026), step 4, archive rules:** two rules, set in the card at the top of the Archiving panel (table
  `archive_rules`, one row per kind, off until first saved; each save is a column change shown in Audits as "Archive rules"):
  **idle**, an old version nobody has viewed or downloaded for N days / months / years (1 to 999; never opened counts from the
  day it was uploaded), and **old**, an old version N days / months / years after a newer one replaced it (0 to 999; 0 means as
  soon as it is replaced). A version matching both is archived once. **Never** a current or an already archived version. A
  version someone took out of the archive (`document_versions.restored_at`) is skipped by "old" for good, and "idle" counts it
  from the restore, so a restore is not undone the same night. The panel shows how many versions each rule would archive right
  now, "Save rules", "Run the rules now" (asks first; same code as the night run) and what the last run did. The nightly run is
  `php artisan documentator:archive-due` (`--limit=500`, `--dry-run`), scheduled at **02:00 office time** (Asia/Manila). It acts as
  "the system": Source "Automatic", shown as "The system (archive rules)" in Audits, `archived_by` empty and shown as "an
  archive rule". One version failing (for example a missing file) is reported, noted as `version.archive-failed` in the activity
  log, and the run carries on; a version that keeps failing is retried and reported every night. **It only runs if something runs
  the scheduler:** `scripts\dev.cmd` now starts `php artisan schedule:work` beside the server (restart the dev server once to
  get it); a real server needs the one cron line `* * * * * php artisan schedule:run`. Until roles exist anyone signed in can
  edit the rules. All four steps of the archive plan are built.

## Users and roles (8 Nov 2026)

- **(Superseded the same day by "Roles per department" below: the three roles are now records and the bundles are per department.)**
  **Roles are bundles of namespaced permissions**, the names OneHub will use (`documentator.document.view`, `.file.download`,
  `.document.create`, `.version.create`, `.archive.manage`, `.audit.view`, `.user.manage`). Three roles, in
  `config/documentator.php`: **Viewer** (view documents, view and download files), **Editor** (also register documents and
  upload new versions), **Administrator** (also archive and its rules, read Audits, manage users). `AppAuthAccess` answers
  "may this person do that", and **the server is the rule**: each route names its permission
  (`documentator.can:<permission>`, a 403 `forbidden` otherwise, checked before a record is looked up so a person cannot
  tell from a 404 what exists). The screen only hides what would be refused. Editing the bundles from the screen, and
  per-department permissions, are later decisions.
- **Accounts that existed before roles became administrators** (they could do everything before); new ones are made as a
  viewer unless the administrator chooses another role. `make-account.cmd` makes administrators (`--role=viewer|editor|admin`).
- **Users screen** (right `user.manage`): list, search, **New user** (full name, username, role, first password typed by the
  administrator, who tells the person), **Edit** (name, role), **Set password**, **Switch off / on**. The username is not
  changed later. Passwords follow the existing policy (8 to 128 characters, not the username).
- **Forced change at first sign-in:** an account made by an administrator, and one whose password an administrator sets, has
  `must_change_password`. That person can sign in but the server refuses everything except session, sign-out and
  `POST /api/password` (403 `password_change_required`), and the app shows a "Choose a new password" screen. It needs the
  password they were given and a different one that passes the policy; their other sessions end, this one carries on.
  **That is the only time anyone changes their own password** (PMS: an administrator sets every other one; refused otherwise).
  An account made from the command line is not forced, since the person at the server knows the password.
- **Guards:** an administrator cannot switch off or change the role of **their own** account or set their own password here
  (`own_account`); the last active administrator can never be removed (`last_admin`, a second guard behind the first); a
  OneHub account has no password here (`not_local`). Switching an account off ends its sessions at once and keeps its
  documents and history.
- **Audited:** every account change is a column change in Audits (password always "(hidden)", role shown in words, who made it)
  and `user.created`, `user.password-set`, `user.password-changed`, `user.switched-off/on` are in the activity log.
- **Not built:** password reset by e-mail (there is no e-mail, by design), OneHub linking, editing roles, departments as
  permissions, and a screen for the activity log (sign-ins, views and downloads).

## Departments (8 Nov 2026)

- **Departments are records an administrator keeps** (Administration > Departments, right `documentator.department.manage`, held
  by the Administrator role): add, rename, switch off / on. **Never deleted**: documents keep pointing at a switched-off
  department, it is just not offered for new documents (the upload form lists only those in use, and the server refuses a
  switched-off or unknown one with `department_id`). Names are unique whatever their case and spacing, up to 100 characters.
  A document may have **no department**. Each change is a column change in Audits (table "Departments") and
  `department.created / switched-on / switched-off` are in the activity log.
- **Documents now hold `department_id`** (the typed `department` text is gone). The migration turned every distinct department
  already typed on a document (ignoring case and surrounding spaces, first spelling wins, non-ASCII included) into a
  department and pointed its documents at it, blank ones became "no department"; rolling back restores the text. Rehearsed on
  messy data before running. Audit rows written before this keep the old column name `department` (shown as "Department").
- **(Built the same day, see "Roles per department" below.)** Departments as a dimension of roles. The user's wish: a role carries, per department, tags such as
  view / download / update / archive / delete, so a department's documents can only be reached by someone whose role holds
  the matching tag for that department. Open questions for that step are in the conversation (what "delete" is, documents with
  no department, one role per person or several, and what stays global such as Users and Audits).

## Roles per department (8 Nov 2026, the user's call after a simpler option was offered)

- **Roles are records** (Administration > Roles, right `documentator.role.manage`): a name, a description and a **grid**: a row
  per department (switched-off ones marked) plus **"No department"** (documents with no department), a column per tag, and a
  checkbox for each. A person has **one role** (chosen on the Users screen); someone in two departments gets a role that
  ticks both. Roles can be made, renamed, described and deleted (only when nobody has them). Role keys never change, so a
  rename loses no one their role.
- **The tags** (namespaced, shown as the grid's columns): **View** `documentator.document.view` (see the document, its details,
  versions and file names), **Download** `documentator.file.download` (open and download its files), **Register**
  `documentator.document.create` (register documents in that department), **Update** `documentator.version.create` (upload
  new versions), **Archive** `documentator.version.archive` (archive and take out its old versions), **Delete**
  `documentator.document.delete` (**reserved**: shown greyed, does nothing, there is no delete yet). Ticking anything brings
  **View** with it (the screen does it and the server insists on it); unticking View clears the row.
- **The server decides, per document.** Each route needs the tag *somewhere* (403 `forbidden` otherwise, before any record is
  looked up), then the controller checks the **document's own department**: the Documents list and the types offered are
  filtered to what the person may view; opening a document, downloading or viewing a file, registering (in that department
  only; "no department" only if ticked), uploading a version, and archiving or restoring a version each check their tag for
  that department (403 otherwise). A document says what the signed-in person may do with it (`can.download / update /
  archive`) so the screen shows or hides buttons; the Archiving panel lists only versions the person may archive.
- **Stays the Administrator's alone, whatever a role ticks:** Users, Roles, Departments, Audits, and the archive rules and "run
  now" (they work on every department at once). The **Administrator** role is the one fixed system role (key `admin`): everything
  in every department, **including departments made later**, never edited or deleted, so someone can always sign in and fix the
  others. An administrator still cannot switch off or demote their own account.
- **A new department starts with nothing ticked** in every role, so nobody but an administrator reaches its documents until it is
  granted. The migration carried the old Viewer and Editor roles over with their old rights (Viewer: view and download; Editor:
  also register and update) for the departments that existed **and for documents with no department**; existing accounts keep
  their role.
- **Audited:** each role is a record in Audits (table "Roles"); the grid is logged as one value, "HR: view, download; No
  department: view" (the way KVNsys logs rights), before and after each save; a deleted role is logged as "Deleted" with what
  it held. `role.created / updated / deleted` are in the activity log.
- **Not built:** per-person extra departments on top of a role, several roles per person, a delete feature, editing roles'
  global abilities (they are the Administrator's), and OneHub linking.

## To do later (kept here so it is not forgotten)

- **Reminders for expiring documents** (asked for 9 Nov 2026, put off by the user): a daily e-mail or Teams message listing what
  expires soon. It needs IT to give us SMTP details or a Teams incoming-webhook address. Build it after "Expiry and renewal"
  below, as one more scheduled command next to `documentator:archive-due`.
- Running for real: the one-line cron job (`* * * * * php artisan schedule:run`) and backups of the database and stored files.
- Paging for long lists (Documents, Audits, Archiving).
- Not started from the handover plan: physical-location tree, document status workflow, per-document history timeline and
  activity-log screen, duplicate-file warning, request forms, credentials vault, corporate-document generation, OneHub linking,
  a document editor, viewing old .doc / .xls / PowerPoint files, deleting a document.

## Editing details, expiry and renewal (9 Nov 2026, the user's call)

- **Edit details** (drawer, Details section): title, type, department, description, issued / expires dates and physical
  location can be corrected. **The code and the files stay** (the code is what is written on the paper; a new file is a new
  version). Needs the **Update** tag in the document's department, and in the new department when it is moved ("no department"
  is its own row; a switched-off department cannot be moved into). `PATCH /api/documents/{id}`; only fields sent change; an
  empty one clears it; a save with no difference writes no change rows. Audited column by column (`document.updated`).
- **Dates live on the version too** (`document_versions.issued_on / expires_on`): each version keeps the dates it was valid for,
  so the timeline shows "Valid 12 Jan 2026 to 3 Nov 2026" on Ver 0 and the new dates on Ver 1. Correcting the document's dates
  by hand fixes them on the **current** version as well (a typo fix, not a renewal). The migration copied each document's dates
  onto its current version; older versions have none.
- **Renew** (drawer, next to "Upload new version", and in the Expiring panel): a new version with a new file, new issued date
  (today by default) and a **required new expiry date**, plus a note. It is `POST /api/documents/{id}/versions` with the dates
  added; the document moves on to the new dates, the old ones stay with the old version and in Audits (`document.renewed`).
  A plain new version without dates leaves the dates as they are. Dates the wrong way round are refused and nothing is saved.
- **Status**, worked out by the server in the office's calendar (`documentator.timezone`): `none` (no expiry date), `active`,
  `expiring` (today up to **30 days** ahead, `documentator.expiry_warning_days`) and `expired` (the date has passed). Each
  document says `expiryStatus` and `daysLeft`; the screens mark "In 12 days" / "Expired 5 days ago".
- **Screens:** an expiry filter on Documents (any, expiring soon or expired, expiring soon, expired, no expiry date); a new
  **Expiring** panel (Documents group; anyone who can view documents) listing expired and expiring documents, soonest first, with a
  Renew button where the role may update; a red count badge on its sidebar item (a dot in the folded rail).
- **Not built:** the daily e-mail / Teams reminders (see "To do later"), a per-department warning period, and a "does not
  expire" marker (a document without a date is simply never listed).
