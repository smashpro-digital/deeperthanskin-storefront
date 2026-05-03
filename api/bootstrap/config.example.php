<?php
declare(strict_types=1);

/**
 * config.example.php
 * Copy to config.php (NOT committed) and fill real values.
 *
 * This file documents ALL supported config keys.
 * config.php may safely omit sections you don’t use.
 */

return [

  /* ===============================
     App
     =============================== */
  "app" => [
    "name" => "SmashPro API",
    "env"  => "production", // local | staging | production
  ],

  /* ===============================
     API (shared/public access)
     =============================== */
  "api" => [
    /**
     * Used by require_api_key() or public endpoints
     * NOT admin access
     */
    "key" => "PUT_YOUR_PUBLIC_API_KEY_HERE",

    /**
     * Optional CORS allow-list
     * Example:
     * ["https://smashpro.app", "https://smashpro-digital.github.io"]
     */
    "allowed_origins" => [],
  ],

  /* ===============================
     Admin (secure access)
     =============================== */
  "admin" => [
    /**
     * Used by admin endpoints (Postman, CI, internal tools)
     * Sent via header: X-Admin-Key
     *
     * DO NOT reuse API key here.
     */
    "key" => "PUT_YOUR_ADMIN_KEY_HERE",
  ],

  /* ===============================
     Database
     =============================== */
  "db" => [
    /**
     * Shared hosting notes:
     * - host may be "localhost" or provider-specific
     * - db/user names often include account prefix
     */
    "host" => "localhost",
    "port" => 3306,

    /**
     * Optional: use instead of host/port if required
     * Example: "/var/lib/mysql/mysql.sock"
     */
    "unix_socket" => "",

    "name" => "database_name",
    "user" => "database_user",
    "pass" => "database_password",

    "charset" => "utf8mb4",
  ],

  /* ===============================
     Mail (SMTP)
     =============================== */
  "mail" => [
    "smtp_host" => "smtp.example.com",
    "smtp_port" => 587,
    "smtp_user" => "smtp_user@example.com",
    "smtp_pass" => "smtp_password",

    "from_email" => "no-reply@smashpro.app",
    "from_name"  => "SmashPro",

    /**
     * Internal notifications, quote requests, errors
     */
    "admin_email" => "appdev@smashpro.app",
  ],

  /* ===============================
     HODOS (app-specific config)
     =============================== */
  "hodos" => [
    /**
     * Feature flags (safe defaults)
     */
    "features" => [
      "story_mode" => true,
      "quests"     => true,
      "ai_tools"   => true,
    ],

    /**
     * AI prompt defaults
     * (used by admin/ai/prompts endpoints)
     */
    "ai" => [
      "default_theme" => "sunlit ancient-gold HODOS realm",
      "image_aspect"  => "16:9",
    ],

    /* ===============================
       Integrations: Strava
       =============================== */
    "strava" => [
      "client_id" => getenv("STRAVA_CLIENT_ID") ?: "",
      "client_secret" => getenv("STRAVA_CLIENT_SECRET") ?: "",
      "redirect_uri" => getenv("STRAVA_REDIRECT_URI") ?: "",
      "webhook_callback_url" => getenv("STRAVA_WEBHOOK_CALLBACK_URL") ?: "",
      "webhook_verify_token" => getenv("STRAVA_WEBHOOK_VERIFY_TOKEN") ?: "",
      "scope" => "read,activity:read_all,profile:read_all",
    ],

    /* ===============================
       Integrations: Fitbit
       =============================== */
    "fitbit" => [
      /**
       * Fitbit uses Authorization Code flow (OAuth2).
       *
       * You can keep these in env like Strava.
       * If you later add PKCE for mobile, the server still needs client_id and (often) client_secret
       * for the token exchange/refresh, unless you go full public client + PKCE design.
       */
      "client_id" => getenv("FITBIT_CLIENT_ID") ?: "",
      "client_secret" => getenv("FITBIT_CLIENT_SECRET") ?: "",

      /**
       * Must EXACTLY match Fitbit app settings (https), usually:
       * https://smashpro.app/dashboard/fitbit/callback
       * or your providers endpoint callback page if you’re using that pattern.
       */
      "redirect_uri" => getenv("FITBIT_REDIRECT_URI") ?: "",

      /**
       * Fitbit endpoints (overrideable for testing)
       */
      "authorize_url" => getenv("FITBIT_AUTHORIZE_URL") ?: "https://www.fitbit.com/oauth2/authorize",
      "token_url"     => getenv("FITBIT_TOKEN_URL") ?: "https://api.fitbit.com/oauth2/token",
      "api_base"      => getenv("FITBIT_API_BASE") ?: "https://api.fitbit.com",

      /**
       * Scopes are space-delimited for Fitbit (NOT comma-delimited).
       * Keep minimal for MVP; add more as you implement them.
       *
       * Common MVP: activity + profile
       * - activity: read activity logs / summaries
       * - profile: user profile basics
       *
       * (If you add steps/time series, you may need: "activity" is usually enough,
       * but some datasets might require additional scopes depending on endpoint.)
       */
      "scope" => getenv("FITBIT_SCOPE") ?: "activity profile",

      /**
       * Optional: state prefix for your app
       */
      "state_prefix" => getenv("FITBIT_STATE_PREFIX") ?: "hodos",

      /**
       * Optional: if you add webhook support later
       */
      "webhook_verify_token" => getenv("FITBIT_WEBHOOK_VERIFY_TOKEN") ?: "",
      "webhook_callback_url" => getenv("FITBIT_WEBHOOK_CALLBACK_URL") ?: "",
    ],
  ],

  /* ===============================
     Debug / Dev tools
     =============================== */
  "debug" => [
    /**
     * show_exceptions:
     *   Include exception messages in JSON (DEV ONLY)
     *
     * show_db_errors:
     *   Include PDO connection errors (DEV ONLY)
     *
     * create_log_tables:
     *   Allow logger to auto-create tables if missing
     */
    "show_exceptions"   => false,
    "show_db_errors"    => false,
    "create_log_tables" => false,
  ],

];
