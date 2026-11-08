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
  reset forces the same again. (Not built yet.)
- An admin creates users in the app. (Not built yet; it needs roles first.)

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
- Until roles exist, every signed-in person can register, read and download. Permissions come with the roles step.
- **Uploading a new version (8 Nov 2026):** "Upload new version" in the drawer adds Ver 1, 2, ... to the same document (same
  code and details). The new version is the whole set of files for that version (not only the changed ones), with an optional
  change note (up to 500 characters). Earlier versions and their files are kept and stay downloadable. The number is the
  highest plus one, and a clash with someone uploading at the same moment is refused as `version_conflict`. Audited as
  `version.created` with the note "Ver N". Internally the column and the API field are still named `rev_no` / `rev`; only the
  words on screen and in the audit notes say Ver.
- Not built yet: restoring an old version, the duplicate-file warning, a per-document audit timeline, editing a document's
  details (title, dates, location) after registering.

## Audit

- `audit_logs` is append-only; on SQLite triggers refuse UPDATE and DELETE. Sign-in, failed sign-in, sign-out, user creation,
  document registration, new versions and file views and downloads are recorded.
