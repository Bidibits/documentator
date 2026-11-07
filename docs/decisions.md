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

- **One code = one document**, like a stapled packet. A document has versions (Rev 0, 1, ...), and a version has one or more
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
- In-app viewing of files (a PDF/image preview) and editing Office files in the browser are **not built**. Editing in the
  browser would mean an editor service (OnlyOffice, Collabora or Microsoft 365) and is a later decision; for now the way
  to change a file is download, edit, upload as a new version.
- Until roles exist, every signed-in person can register, read and download. Permissions come with the roles step.
- Not built yet: uploading a new version (Rev 1...) with a change note, restoring a version, the duplicate-file warning,
  a per-document audit timeline.

## Audit

- `audit_logs` is append-only; on SQLite triggers refuse UPDATE and DELETE. Sign-in, failed sign-in, sign-out, user creation,
  document registration and file downloads are recorded.
