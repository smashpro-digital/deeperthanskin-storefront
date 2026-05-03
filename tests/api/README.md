# SmashPro API Tests

These tests hit the live API at `https://smashpro.app/api/v1/index.php` using Node's built-in `fetch`.

Setup:

1. Copy `tests/api/.env.example` to `tests/api/.env`
2. Fill in `API_KEY` locally
3. Run:

```sh
npm run test:api
```

Notes:

- No dependencies are required.
- Protected tests are skipped if `API_KEY` is missing.
- Do not commit `tests/api/.env` or real API keys.
- Checkout testing only sends an empty cart and expects a controlled JSON error.
