# Exact Online PHP API Tester

A lightweight PHP app to test Exact Online OAuth2 connection and API calls.

## What this gives you

- OAuth2 connect flow with Exact Online
- Access/refresh token storage in `storage/token.json`
- API test call to `GET /api/v1/current/Me`
- Webhook endpoint with optional HMAC verification
- Connection failure/success logs in `storage/connection.log`
- Simple browser UI for non-technical testing

## 1) Configure

1. Copy `.env.example` to `.env`.
2. Fill in your app values:
   - `EXACT_CLIENT_ID`
   - `EXACT_CLIENT_SECRET`
   - `EXACT_REDIRECT_URI` (must match your Exact app registration)
   - `WEBHOOK_SECRET` (optional but recommended)

Example redirect URI for local testing:

`http://localhost:8000/oauth_callback.php`

## 2) Start locally

From project root:

```bash
php -S localhost:8000 -t public
```

Open:

`http://localhost:8000/index.php`

## 3) Test flow

1. Open the dashboard and fill all values in **Connection Settings**.
2. Click **Save Settings** (or **Save and Test Exact Connection**).
3. Click **Connect to Exact Online**.
4. Complete login and consent.
5. Back on dashboard, click **Test API Connection**.
6. Review **Last API Result** and **Connection Logs** in the UI.

Connection logs are written to `storage/connection.log` and include explicit error messages when the test fails.

## Webhook test endpoint

- URL: `http://localhost:8000/webhook.php`
- Incoming payloads are appended to `storage/webhook.log`
- Signature headers checked (if provided):
  - `X-Exact-Signature`
  - `Exact-Signature`

Signature verification currently expects:

- Algorithm: `HMAC-SHA256`
- Value: hex digest of raw request body using `WEBHOOK_SECRET`

Adjust header/algorithm if your Exact webhook format differs.

## Important security notes

- Never commit `.env` with real client secrets.
- Rotate credentials if they were shared in plain chat.
- For production, store tokens in an encrypted database instead of local JSON.
