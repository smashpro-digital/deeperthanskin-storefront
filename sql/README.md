# SQLTools Workflow

Install these VS Code extensions:

- SQLTools
- SQLTools MySQL/MariaDB/TiDB

Setup:

- Copy `.vscode/settings.example.json` to `.vscode/settings.json`
- Fill in the DB password locally
- Do not commit real DB credentials
- Test the connection from the SQLTools sidebar

Notes:

- `.vscode/settings.json` is ignored by git
- API routes use the central `db()` helper through `api/bootstrap/bootstrap.php`
- Keep database credentials out of frontend Astro files and public JavaScript

