# Deeper Than Skin — Storefront

**Wellness that begins within.**

This repository contains the official storefront and brand landing experience for **Deeper Than Skin**, a holistic wellness brand centered on intentional healing, community, and original provision.

The site is built as a **static, high‑performance frontend** and integrates with **Square** for secure checkout, service bookings, and subscriptions — allowing us to stay lean, flexible, and aligned with our event‑driven sales model.

---

## 🌱 Live Site
👉 https://smashpro-digital.github.io/deeperthanskin-storefront/

---

## 🧭 Project Purpose

Deeper Than Skin is evolving beyond traditional e‑commerce.

This storefront serves as:
- A **brand‑first landing experience**
- A gateway to curated wellness products
- A bridge between commerce and community
- The public-facing entry point ahead of our Genesis community launch

Commerce supports the mission — it does not define it.

---

## 🛍️ What This Site Supports

- Detox & cleanse kits  
- Cold‑pressed juices  
- Sea moss & herbal products  
- Ionic footbath service bookings  
- Event‑based and seasonal product drops  
- Square-powered checkout & subscriptions  

---

## 🧱 Tech Stack

- **HTML5 / CSS3 / Vanilla JavaScript**
- **GitHub Pages** (static hosting)
- **Square** (payments, bookings, subscriptions)
- No frameworks, no lock‑in, optimized for speed and clarity

---

## 🔐 Security & Architecture

- No secrets or private keys stored in this repository
- Payments and sensitive data are handled entirely by Square
- Safe to remain a public repository
- Designed for long-term maintainability

---

## 🌿 Brand Ecosystem

- **DeeperThanSkin.store** → Commerce & services  
- **ReturnToTheGarden.co** → Community, governance, and education  
- **Genesis (mobile app)** → Daily practice & accountability *(coming later)*  

Each layer has a distinct purpose and audience.

---

## 🚀 Deployment

This site is deployed via **GitHub Pages** directly from the `main` branch.

Updates go live automatically on commit.

### API Deploy Workflow

FileZilla remains a safe manual backup, but API deploys can be run from scripts.
Credentials are loaded from a local `.env.ftp` file and must never be committed.

1. Install `lftp`.
   - Git Bash/MSYS2: install the `lftp` package through MSYS2.
   - WSL Ubuntu: `sudo apt update && sudo apt install lftp`.
   - macOS: `brew install lftp`.
   - On this Windows workstation, MSYS2 is installed at `C:\msys64` and the npm
     scripts call `C:\msys64\usr\bin\bash.exe` directly.

2. Create local FTP credentials in `.env.ftp`.

   ```bash
   FTP_HOST=your-host
   FTP_USER=your-user
   FTP_PASS=your-password
   FTP_PORT=21
   FTP_REMOTE_ROOT=/public_html/smashpro.app
   ```

3. Deploy API routes only. This uploads changed files from `api/v1/routes/` to
   `$FTP_REMOTE_ROOT/api/v1/routes/` and does **not** delete remote files.

   ```bash
   npm run deploy:api-routes
   ```

   Only use remote deletion intentionally:

   ```bash
   npm run deploy:api-routes -- --delete
   ```

4. Deploy the full `api/` folder when router/bootstrap files changed.

   ```bash
   npm run deploy:api
   ```

   Full API deploys also avoid deletion unless `--delete` is explicitly passed.
   The scripts exclude `.env*`, config files, logs, tests, SQL, README/scratch
   files, `.git`, `.github`, and `node_modules`.

5. Verify with the health check or Postman:

   ```http
   GET {{api_base}}?path=health
   ```

   For Square customer sync updates, use a dry run first:

   ```http
   POST {{api_base}}?path=public/commerce/square-customers/sync&app_slug=deeper-than-skin&api_key={{api_key}}
   Content-Type: application/json

   {
     "limit": 25,
     "dry_run": true
   }
   ```

Frontend files do not deploy through FTP; the storefront still deploys through
the normal GitHub Pages build from `main`. Keep `.vscode/sftp.json` local only.

---

## ✨ Built With Intention

Created in partnership with **SmashPro Digital**  
Product Strategy • UX/UI • Web & Mobile Engineering

© Deeper Than Skin. All rights reserved.
