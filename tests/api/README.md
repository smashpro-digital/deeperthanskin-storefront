# SmashPro API Tests

These tests hit the live API at `https://smashpro.app/api/v1/index.php`.

## Automated Tests

1. Copy `tests/api/.env.example` to `tests/api/.env`
2. Fill in `API_KEY` locally
3. Run:

```sh
npm run test:api
```

The automated suite uses Node's built-in `fetch`; no dependencies are required.

## Manual VS Code Requests

Thunder Client's free version has limited collections/environment support. For free manual API testing in VS Code, install the extension **REST Client** by Huachao Mao.

Manual workflow:

1. Open `tests/api/requests.http`
2. Replace `YOUR_API_KEY` at the top of the file
3. Click **Send Request** above any request block

Notes:

- Protected tests are skipped if `API_KEY` is missing.
- Do not commit `tests/api/.env` or real API keys.
- Checkout testing only sends an empty cart and expects a controlled JSON error.
