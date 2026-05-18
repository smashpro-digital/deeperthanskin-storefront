<?php
declare(strict_types=1);

/**
 * /api/v1/routes/public.waitlist.post.php (FULL DROP-IN — MATCHES YOUR TABLE)
 *
 * POST /api/v1/index.php?path=public/waitlist
 *
 * Features:
 * - CORS + OPTIONS support
 * - Accepts JSON even when Content-Type is text/plain (GitHub Pages friendly)
 * - Honeypot: "company" => silently succeed
 * - Requires: app_slug + email
 * - Clamps field lengths to match DB columns
 * - UPSERT (requires UNIQUE KEY (app_slug, email))
 * - Sends confirmation email:
 *     - Prefer send_smtp_mail() if available (bootstrap/mail.php)
 *     - Else SendGrid Web API if SENDGRID_API_KEY is set
 *     - Else PHP mail()
 * - Multi-tenant config from spd_waitlist_apps:
 *     brand_name, notify_email, reply_to_email, contact_email, confirm_subject, confirm_body
 *
 * Returns:
 *   { ok:true, created:bool, app_slug:string, email_sent:bool }
 */

/* -------------------------------
   Robust bootstrap loader
-------------------------------- */
$API_ROOT = dirname(dirname(__DIR__)); // /api
$bootstrap = $API_ROOT . "/bootstrap/bootstrap.php";
if (!is_file($bootstrap)) {
  $dir = __DIR__;
  for ($i = 0; $i < 6; $i++) {
    $candidate = $dir . "/bootstrap/bootstrap.php";
    if (is_file($candidate)) { $bootstrap = $candidate; break; }
    $dir = dirname($dir);
  }
}
if (!is_file($bootstrap)) {
  http_response_code(500);
  header("Content-Type: application/json; charset=utf-8");
  echo json_encode(["ok" => false, "error" => "Bootstrap not found"]);
  exit;
}
require_once $bootstrap; // provides db(), json_ok/json_fail

// SMTP helper (mail.php defines send_smtp_mail())
$mailHelper = $API_ROOT . "/bootstrap/mail.php";
if (is_file($mailHelper)) {
  require_once $mailHelper;
}

/* -------------------------------
   CORS + OPTIONS
-------------------------------- */
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$method = $_SERVER['REQUEST_METHOD'] ?? 'POST';

$allowedOrigins = [
  'https://smashpro-digital.github.io',
  'https://smashpro.app',
];

$isLocal = (bool)preg_match('#^http://(localhost|127\.0\.0\.1)(:\d+)?$#', $origin);

$allow = false;
if ($origin && in_array($origin, $allowedOrigins, true)) $allow = true;
if ($origin && $isLocal) $allow = true;
if (!$origin) $allow = true; // server-to-server / curl

if ($allow) {
  if ($origin) {
    header("Access-Control-Allow-Origin: {$origin}");
    header("Vary: Origin");
  }
  header("Access-Control-Allow-Methods: POST, OPTIONS");
  header("Access-Control-Allow-Headers: Content-Type, Accept");
  header("Access-Control-Max-Age: 86400");
}

if ($method === 'OPTIONS') {
  http_response_code(204);
  exit;
}

/* -------------------------------
   Read JSON body safely
   (works even if Content-Type: text/plain)
-------------------------------- */
$raw  = file_get_contents("php://input");
$body = json_decode($raw ?: "{}", true);
if (!is_array($body)) $body = [];

/* -------------------------------
   Honeypot
-------------------------------- */
if (!empty($body["company"])) {
  json_ok(["ok" => true, "ignored" => true, "email_sent" => false]);
}

/* -------------------------------
   Helpers
-------------------------------- */
function clamp(?string $v, int $max): ?string {
  if ($v === null) return null;
  $s = trim((string)$v);
  if ($s === "") return null;
  return function_exists("mb_substr") ? mb_substr($s, 0, $max) : substr($s, 0, $max);
}

function to_bool01($v): int {
  if (is_bool($v)) return $v ? 1 : 0;
  $s = strtolower(trim((string)$v));
  return in_array($s, ["1","true","yes","y","on"], true) ? 1 : 0;
}

function waitlist_columns(): array {
  static $columns = null;
  if (is_array($columns)) return $columns;

  $columns = [];
  try {
    $stmt = db()->query("SHOW COLUMNS FROM spd_waitlist");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
      $field = (string)($row["Field"] ?? "");
      if ($field !== "") $columns[$field] = true;
    }
  } catch (Throwable $e) {
    $columns = [];
  }

  return $columns;
}

function waitlist_has_column(string $column): bool {
  $columns = waitlist_columns();
  return isset($columns[$column]);
}

function render_template(string $tpl, array $vars): string {
  foreach ($vars as $k => $v) {
    $tpl = str_replace("{{{$k}}}", (string)$v, $tpl);
  }
  return $tpl;
}

/**
 * Pull app email settings from spd_waitlist_apps (your exact columns).
 */
function load_waitlist_app_config(string $appSlug): ?array {
  $stmt = db()->prepare("
    SELECT
      app_slug,
      is_enabled,
      brand_name,
      notify_email,
      reply_to_email,
      contact_email,
      confirm_subject,
      confirm_body
    FROM spd_waitlist_apps
    WHERE app_slug = :app
    LIMIT 1
  ");
  $stmt->execute([":app" => $appSlug]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$row) return null;
  if ((int)($row["is_enabled"] ?? 0) !== 1) return null;
  return $row;
}

/**
 * Send via SendGrid Web API.
 * Requires env: SENDGRID_API_KEY
 */
function send_sendgrid_mail(
  string $toEmail,
  string $subject,
  string $bodyText,
  string $fromEmail,
  string $fromName,
  string $replyToEmail = "",
  array $customHeaders = []
): bool {
  $apiKey = getenv("SENDGRID_API_KEY") ?: "";
  if ($apiKey === "") return false;

  // SendGrid will reject/soft-fail deliverability if From isn't verified
  if (trim($fromEmail) === "") return false;

  $payload = [
    "personalizations" => [[
      "to" => [[ "email" => $toEmail ]],
      "subject" => $subject,
    ]],
    "from" => [
      "email" => $fromEmail,
      "name"  => $fromName,
    ],
    "content" => [[
      "type"  => "text/plain",
      "value" => $bodyText,
    ]],
  ];

  if (trim($replyToEmail) !== "") {
    $payload["reply_to"] = ["email" => $replyToEmail];
  }

  if (!empty($customHeaders)) {
    $hdrs = [];
    foreach ($customHeaders as $h) {
      $parts = explode(":", $h, 2);
      if (count($parts) === 2) {
        $k = trim($parts[0]);
        $v = trim($parts[1]);
        if ($k !== "" && $v !== "") $hdrs[$k] = $v;
      }
    }
    if (!empty($hdrs)) $payload["headers"] = $hdrs;
  }

  if (!function_exists("curl_init")) {
    error_log("[sendgrid] cURL not available on server");
    return false;
  }

  $ch = curl_init("https://api.sendgrid.com/v3/mail/send");
  curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
      "Authorization: Bearer {$apiKey}",
      "Content-Type: application/json",
    ],
    CURLOPT_POSTFIELDS => json_encode($payload),
    CURLOPT_TIMEOUT => 12,
  ]);

  $resp = curl_exec($ch);
  $err  = curl_error($ch);
  $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  if ($err) {
    error_log("[sendgrid] curl error: " . $err);
    return false;
  }

  // Success is commonly 202 Accepted
  if ($code >= 200 && $code < 300) return true;

  error_log("[sendgrid] non-2xx ({$code}): " . substr((string)$resp, 0, 300));
  return false;
}

/**
 * Send confirmation:
 * - Prefer SMTP helper
 * - Else SendGrid API
 * - Else mail()
 *
 * IMPORTANT: No hardcoded From in code.
 * We derive:
 *   From email = contact_email (per app)
 *   From name  = brand_name (per app)
 *   Reply-To   = reply_to_email (per app)
 */
function send_waitlist_confirmation(array $cfg, string $toEmail, array $vars, ?string $endpoint = null): bool {
  $brandName = trim((string)($cfg["brand_name"] ?? "")) ?: "SmashPro";

  // Subject/body from DB with fallbacks
  $subject = trim((string)($cfg["confirm_subject"] ?? ""));
  if ($subject === "") $subject = "You are on the early access list";

  $body = trim((string)($cfg["confirm_body"] ?? ""));
  if ($body === "") {
    $contact = trim((string)($cfg["contact_email"] ?? $cfg["notify_email"] ?? "")) ?: "support@smashpro.app";
    $body = implode("\n", [
      "You're officially added to early access.",
      "",
      "We’ll email you when:",
      "• the relaunch goes live",
      "• limited drops open",
      "• best sellers restock",
      "",
      "If you didn’t request this, you can ignore this email.",
      "",
      "— {$brandName}",
      $contact,
    ]);
  }

  $subject = render_template($subject, $vars);
  $body    = render_template($body, $vars);

  // Dynamic From (NO hardcoding):
  $fromEmail = trim((string)($cfg["contact_email"] ?? "")); // per app
  $fromName  = $brandName;                                  // per app
  $replyTo   = trim((string)($cfg["reply_to_email"] ?? "")) ?: trim((string)($cfg["notify_email"] ?? ""));

  $extraHeaders = [
    "X-App-Slug: " . (string)($vars["app_slug"] ?? ""),
    "From: {$fromName} <{$fromEmail}>",
  ];
  if ($replyTo !== "") $extraHeaders[] = "Reply-To: {$replyTo}";

  // Prefer SMTP-first helper (logs to spd_email_logs)
  if (function_exists("send_smtp_mail")) {
    return send_smtp_mail(
      $toEmail,
      $subject,
      $body,
      $extraHeaders,
      db(),
      $GLOBALS["correlationId"] ?? null,
      $endpoint ?? "public.waitlist.post"
    );
  }

  // Next: SendGrid API (requires SENDGRID_API_KEY)
  $sent = send_sendgrid_mail(
    $toEmail,
    $subject,
    $body,
    $fromEmail,
    $fromName,
    $replyTo,
    $extraHeaders
  );
  if ($sent) return true;

  // Last resort: PHP mail()
  $headers = [
    "MIME-Version: 1.0",
    "Content-Type: text/plain; charset=utf-8",
  ];
  foreach ($extraHeaders as $h) $headers[] = $h;

  return @mail($toEmail, $subject, $body, implode("\r\n", $headers));
}

/* -------------------------------
   Required fields
-------------------------------- */
$appSlug = clamp($body["app_slug"] ?? null, 50);
$email   = clamp($body["email"] ?? null, 190);

if (!$appSlug) json_fail("app_slug is required", 400);
if (!$email)   json_fail("email is required", 400);

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
  json_fail("email is invalid", 400);
}

/* -------------------------------
   Optional allowlist (keep if you want strict)
-------------------------------- */
$allowedApps = ["deeper-than-skin", "hodos"];
if (!in_array($appSlug, $allowedApps, true)) {
  json_fail("Invalid app", 400, ["app_slug" => $appSlug]);
}

/* -------------------------------
   Optional fields
-------------------------------- */
$first   = clamp($body["first_name"] ?? null, 80);
$last    = clamp($body["last_name"] ?? null, 80);
$phone   = clamp($body["phone"] ?? null, 30);
$source  = clamp($body["source"] ?? "landing-page", 80);
$consent = to_bool01($body["consent"] ?? 1);
$sourceDevice = clamp($body["source_device"] ?? $body["device"] ?? null, 80);
$eventSlug = clamp($body["event_slug"] ?? $body["event"] ?? null, 120);
$eventDate = clamp($body["event_date"] ?? null, 10);
$campaign = clamp($body["campaign"] ?? null, 120);
$interest = clamp($body["interest"] ?? null, 120);
$smsOptIn = to_bool01($body["sms_opt_in"] ?? $body["sms_consent"] ?? 0);

/* -------------------------------
   Determine created flag (reliable)
-------------------------------- */
$created = false;
try {
  $chk = db()->prepare("SELECT 1 FROM spd_waitlist WHERE app_slug = :app_slug AND email = :email LIMIT 1");
  $chk->execute([":app_slug" => $appSlug, ":email" => $email]);
  $exists = (bool)$chk->fetchColumn();
  $created = !$exists;
} catch (Throwable $e) {
  $created = false;
}

/* -------------------------------
   UPSERT
-------------------------------- */
$insertValues = [
  "app_slug" => $appSlug,
  "email" => $email,
  "first_name" => $first,
  "last_name" => $last,
  "phone" => $phone,
  "source" => $source,
  "consent" => $consent,
];

$optionalValues = [
  "source_device" => $sourceDevice,
  "event_slug" => $eventSlug,
  "event_date" => $eventDate,
  "campaign" => $campaign,
  "interest" => $interest,
  "sms_opt_in" => $smsOptIn,
];

foreach ($optionalValues as $column => $value) {
  if (waitlist_has_column($column)) {
    $insertValues[$column] = $value;
  }
}

$columns = array_keys($insertValues);
$columnSql = implode(", ", $columns);
$valueSql = implode(", ", array_map(function ($column) {
  return ":" . $column;
}, $columns));
$updates = [
  "first_name = COALESCE(VALUES(first_name), first_name)",
  "last_name  = COALESCE(VALUES(last_name), last_name)",
  "phone      = COALESCE(VALUES(phone), phone)",
  "source     = VALUES(source)",
  "consent    = GREATEST(consent, VALUES(consent))",
];

foreach (["source_device", "event_slug", "event_date", "campaign", "interest"] as $column) {
  if (isset($insertValues[$column])) {
    $updates[] = "{$column} = COALESCE(VALUES({$column}), {$column})";
  }
}

if (isset($insertValues["sms_opt_in"])) {
  $updates[] = "sms_opt_in = GREATEST(COALESCE(sms_opt_in, 0), VALUES(sms_opt_in))";
}

$updates[] = "updated_at = CURRENT_TIMESTAMP";

$sql = "
  INSERT INTO spd_waitlist
    ({$columnSql})
  VALUES
    ({$valueSql})
  ON DUPLICATE KEY UPDATE
    " . implode(",\n    ", $updates) . "
";

try {
  $stmt = db()->prepare($sql);
  $params = [];
  foreach ($insertValues as $column => $value) {
    $params[":" . $column] = $value;
  }
  $stmt->execute($params);

  // Send confirmation email best-effort
  $emailSent = false;
  try {
    $cfg = load_waitlist_app_config($appSlug);
    if ($cfg) {
      $vars = [
        "app_slug" => $appSlug,
        "email" => $email,
        "first_name" => $first ?: "",
        "last_name" => $last ?: "",
        "brand_name" => trim((string)($cfg["brand_name"] ?? "")) ?: "SmashPro",
        "contact_email" => trim((string)($cfg["contact_email"] ?? $cfg["notify_email"] ?? "")),
      ];
      $emailSent = send_waitlist_confirmation($cfg, $email, $vars, "public.waitlist.post");
    }
  } catch (Throwable $e) {
    error_log("[waitlist.confirm] " . $e->getMessage());
    $emailSent = false;
  }

  json_ok([
    "ok" => true,
    "created" => $created,
    "app_slug" => $appSlug,
    "email_sent" => $emailSent,
  ]);

} catch (Throwable $e) {
  json_fail("Server error", 500, ["stage" => "waitlist.upsert"]);
}
