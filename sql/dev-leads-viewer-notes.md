# Dev Leads Viewer

This endpoint exists because remote MySQL access is blocked on shared hosting, so VS Code and SQLTools may not be able to connect directly to the database.

Call it with:

```text
https://smashpro.app/api/v1/index.php?path=dev/leads&app_slug=deeper-than-skin&key=DEV_SECRET_KEY
```

Replace `DEV_SECRET_KEY` with the server-side `DEV_DASHBOARD_KEY` value. Do not expose this key publicly, paste it into frontend code, or commit it to git.

The endpoint checks recent rows from:

- `spd_waitlist`
- `quiz_leads`
- `pending_orders`
- `square_events`

It masks email and phone fields, omits obvious secret/token/password fields, and only queries tables that exist.

Disable or remove this route in production if it is no longer needed.

