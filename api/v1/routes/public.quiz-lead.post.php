<?php
declare(strict_types=1);

/**
 * /api/v1/routes/public.quiz-lead.post.php
 *
 * POST /api/v1/index.php?path=public/quiz-lead&app_slug=deeper-than-skin
 *
 * Purpose:
 * - Receives Starter Kit Quiz submissions from /trial
 * - Emails business owner with customer answers + recommended kit
 *
 * Features:
 * - CORS + OPTIONS support
 * - Accepts JSON even when Content-Type is text/plain
 * - Honeypot: company => silently succeed
 * - Requires app_slug + kit_slug + kit_title + at least one contact field
 * - Uses spd_waitlist_apps for brand/email config
 * - Prefers send_smtp_mail() from bootstrap/mail.php
 * - Falls back to SendGrid API if SENDGRID_API_KEY exists
 * - Falls back to PHP mail()
 *
 * Returns:
 *   { ok:true, email_sent:bool }
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
    if (is_file($candidate)) {
      $bootstrap = $candidate;
      break;
    }
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

$mailHelper = $API_ROOT . "/bootstrap/mail.php";
if (is_file($mailHelper)) {
  require_once $mailHelper;
}

/* -------------------------------
   CORS + OPTIONS
-------------------------------- */
$origin = $_SERVER["HTTP_ORIGIN"] ?? "";
$method = $_SERVER["REQUEST_METHOD"] ?? "POST";

$allowedOrigins = [
  "https://smashpro-digital.github.io",
  "https://smashpro.app",
  "https://shop.deeperthanskin.store",
];

$isLocal = (bool)preg_match("#^http://(localhost|127\.0\.0\.1)(:\d+)?$#", $origin);

$allow = false;
if ($origin && in_array($origin, $allowedOrigins, true)) $allow = true;
if ($origin && $isLocal) $allow = true;
if (!$origin) $allow = true;

if ($allow) {
  if ($origin) {
    header("Access-Control-Allow-Origin: {$origin}");
    header("Vary: Origin");
  }
  header("Access-Control-Allow-Methods: POST, OPTIONS");
  header("Access-Control-Allow-Headers: Content-Type, Accept");
  header("Access-Control-Max-Age: 86400");
}

if ($method === "OPTIONS") {
  http_response_code(204);
  exit;
}

if (strtoupper((string)$method) !== "POST") {
  json_fail("Method Not Allowed", 405, ["method" => $method]);
}

/* -------------------------------
   Read JSON body safely
-------------------------------- */
$raw = file_get_contents("php://input");
$body = json_decode($raw ?: "{}", true);
if (!is_array($body)) $body = [];

/* -------------------------------
   Honeypot
-------------------------------- */
if (!empty($body["company"])) {
  json_ok([
    "ok" => true,
    "ignored" => true,
    "email_sent" => false,
  ]);
}

/* -------------------------------
   Helpers
-------------------------------- */
function ql_clamp($v, int $max): ?string {
  if ($v === null) return null;
  $s = trim((string)$v);
  if ($s === "") return null;
  return function_exists("mb_substr") ? mb_substr($s, 0, $max) : substr($s, 0, $max);
}

function ql_arr($v): array {
  if (!is_array($v)) return [];
  $out = [];
  foreach ($v as $item) {
    $s = trim((string)$item);
    if ($s !== "") $out[] = $s;
  }
  return array_values(array_unique($out));
}

function ql_lines(array $items): string {
  if (empty($items)) return "None selected";
  return "• " . implode("\n• ", $items);
}

function ql_render_template(string $tpl, array $vars): string {
  foreach ($vars as $k => $v) {
    $tpl = str_replace("{{{$k}}}", (string)$v, $tpl);
  }
  return $tpl;
}

function load_quiz_app_config(string $appSlug): ?array {
  $stmt = db()->prepare("
    SELECT
      app_slug,
      is_enabled,
      brand_name,
      notify_email,
      reply_to_email,
      contact_email
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

function ql_send_sendgrid_mail(
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
  if (trim($fromEmail) === "") return false;

  $payload = [
    "personalizations" => [[
      "to" => [[ "email" => $toEmail ]],
      "subject" => $subject,
    ]],
    "from" => [
      "email" => $fromEmail,
      "name" => $fromName,
    ],
    "content" => [[
      "type" => "text/plain",
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
    error_log("[quiz-lead.sendgrid] cURL not available");
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
  $err = curl_error($ch);
  $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  if ($err) {
    error_log("[quiz-lead.sendgrid] curl error: " . $err);
    return false;
  }

  if ($code >= 200 && $code < 300) return true;

  error_log("[quiz-lead.sendgrid] non-2xx ({$code}): " . substr((string)$resp, 0, 300));
  return false;
}

function send_quiz_owner_notification(array $cfg, array $lead): bool {
  $brandName = trim((string)($cfg["brand_name"] ?? "")) ?: "Deeper Than Skin";

  $notifyEmail = trim((string)($cfg["notify_email"] ?? ""));
  if ($notifyEmail === "") {
    error_log("[quiz-lead.mail] Missing notify_email");
    return false;
  }

  $fromEmail = trim((string)($cfg["contact_email"] ?? ""));
  if ($fromEmail === "") $fromEmail = trim((string)($cfg["reply_to_email"] ?? ""));
  if ($fromEmail === "") $fromEmail = $notifyEmail;

  $customerEmail = trim((string)($lead["email"] ?? ""));
  $replyTo = $customerEmail !== ""
    ? $customerEmail
    : (trim((string)($cfg["reply_to_email"] ?? "")) ?: $fromEmail);

  $kitTitle = trim((string)($lead["kit_title"] ?? "Starter Kit"));
  $subject = "New Starter Kit Quiz Lead — {$kitTitle}";

  $bodyText = implode("\n", [
    "New Starter Kit Quiz Lead",
    "",
    "Brand: {$brandName}",
    "Timestamp: " . date("Y-m-d H:i:s T"),
    "",
    "Customer",
    "--------",
    "Name: " . (($lead["name"] ?? "") ?: "Not provided"),
    "Email: " . (($lead["email"] ?? "") ?: "Not provided"),
    "Phone: " . (($lead["phone"] ?? "") ?: "Not provided"),
    "",
    "Recommended Kit",
    "---------------",
    "Kit Title: " . (($lead["kit_title"] ?? "") ?: "Not provided"),
    "Kit Slug: " . (($lead["kit_slug"] ?? "") ?: "Not provided"),
    "",
    "Goals Selected",
    "--------------",
    ql_lines($lead["goals"] ?? []),
    "",
    "Preferences Selected",
    "--------------------",
    ql_lines($lead["prefs"] ?? []),
    "",
    "Considerations Selected",
    "-----------------------",
    ql_lines($lead["notes"] ?? []),
    "",
    "Customer Message",
    "----------------",
    (($lead["message"] ?? "") ?: "None provided"),
    "",
    "Source: " . (($lead["source"] ?? "") ?: "starter-kit-quiz"),
    "App Slug: " . (($lead["app_slug"] ?? "") ?: ""),
  ]);

  $extraHeaders = [
    "X-App-Slug: " . (string)($lead["app_slug"] ?? ""),
    "X-Lead-Source: " . (string)($lead["source"] ?? "starter-kit-quiz"),
    "From: {$brandName} <{$fromEmail}>",
  ];

  if ($replyTo !== "") {
    $extraHeaders[] = "Reply-To: {$replyTo}";
  }

  if (function_exists("send_smtp_mail")) {
    return send_smtp_mail(
      $notifyEmail,
      $subject,
      $bodyText,
      $extraHeaders,
      db(),
      $GLOBALS["correlationId"] ?? null,
      "public.quiz-lead.post"
    );
  }

  $sent = ql_send_sendgrid_mail(
    $notifyEmail,
    $subject,
    $bodyText,
    $fromEmail,
    $brandName,
    $replyTo,
    $extraHeaders
  );

  if ($sent) return true;

  $headers = [
    "MIME-Version: 1.0",
    "Content-Type: text/plain; charset=utf-8",
  ];

  foreach ($extraHeaders as $h) $headers[] = $h;

  return @mail($notifyEmail, $subject, $bodyText, implode("\r\n", $headers));
}

/* -------------------------------
   Parse + validate
-------------------------------- */
$appSlug = ql_clamp($body["app_slug"] ?? ($_GET["app_slug"] ?? null), 50);
$name = ql_clamp($body["name"] ?? null, 120);
$email = ql_clamp($body["email"] ?? null, 190);
$phone = ql_clamp($body["phone"] ?? null, 40);
$message = ql_clamp($body["message"] ?? null, 1200);
$source = ql_clamp($body["source"] ?? "starter-kit-quiz", 80);

$kitSlug = ql_clamp($body["kit_slug"] ?? null, 120);
$kitTitle = ql_clamp($body["kit_title"] ?? null, 180);

$goals = ql_arr($body["goals"] ?? []);
$prefs = ql_arr($body["prefs"] ?? []);
$notes = ql_arr($body["notes"] ?? []);

if (!$appSlug) json_fail("app_slug is required", 400);
if ($appSlug !== "deeper-than-skin") {
  json_fail("Invalid app", 400, ["app_slug" => $appSlug]);
}

if (!$kitSlug) json_fail("kit_slug is required", 400);
if (!$kitTitle) json_fail("kit_title is required", 400);

if (!$name && !$email && !$phone) {
  json_fail("name, email, or phone is required", 400);
}

if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
  json_fail("email is invalid", 400);
}

/* -------------------------------
   Load config + send
-------------------------------- */
try {
  $cfg = load_quiz_app_config($appSlug);

  if (!$cfg) {
    error_log("[quiz-lead] App config missing or disabled for app_slug={$appSlug}");
    json_ok([
      "ok" => true,
      "email_sent" => false,
    ]);
  }

  $lead = [
    "app_slug" => $appSlug,
    "source" => $source ?: "starter-kit-quiz",
    "name" => $name ?: "",
    "email" => $email ?: "",
    "phone" => $phone ?: "",
    "message" => $message ?: "",
    "kit_slug" => $kitSlug,
    "kit_title" => $kitTitle,
    "goals" => $goals,
    "prefs" => $prefs,
    "notes" => $notes,
  ];

  $emailSent = false;

  try {
    $emailSent = send_quiz_owner_notification($cfg, $lead);
  } catch (Throwable $e) {
    error_log("[quiz-lead.mail] " . $e->getMessage());
    $emailSent = false;
  }

  json_ok([
    "ok" => true,
    "email_sent" => $emailSent,
  ]);

} catch (Throwable $e) {
  error_log("[quiz-lead] " . $e->getMessage());
  json_fail("Server error", 500, ["stage" => "quiz-lead"]);
}