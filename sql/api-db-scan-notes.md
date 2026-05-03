# API DB Scan Notes

Scan scope:

- `api/`
- `bootstrap/` if present at repo root
- `config/` if present at repo root

Findings:

- API routes load shared bootstrap files from `api/bootstrap/`.
- The central connection implementation is `api/bootstrap/db.php`.
- `api/bootstrap/bootstrap.php` exposes the shared `db(): PDO` helper after loading config.
- Route files that query the database use `db()` from the central helper.
- No route-level `new PDO`, `new mysqli`, or `mysqli_connect` calls were found.

Expected central connection:

- `api/bootstrap/db.php` contains the single `new PDO(...)` call.
- This is not duplicate route logic; it is the shared connection factory used by all API routes.

Local secrets note:

- `api/bootstrap/config.php` is local runtime config and should not be committed.
- `.vscode/settings.json` is local SQLTools config and should not be committed.

TODO:

- If future route files add direct database connections, replace them with the central `db()` helper.
- Keep real DB passwords out of tracked files.

