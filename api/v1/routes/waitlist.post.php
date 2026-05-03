<?php
declare(strict_types=1);

require_once __DIR__ . "/../../bootstrap/response.php";

global $pdo, $correlationId;

/* -------------------------------
   Helpers
-------------------------------- */
function getJsonBody(): array {
  $raw = file_get_contents("php://input");
  $data = json_decode($raw ?: "", true);
  return is_array($data) ? $data : [];
}

function str_or_null($v): ?string {
  if ($v === null) return null;
  $s = trim((string)$v);
  return $s === "" ? null : $s;
}

/**
 * Best-effort error logging into: spd_api_error_logs
 * Matches your table columns shown in phpMyAdmin.
 */
function log_api_error_best_effort(PDO $pdo, string $correlationId, Throwable $e, int $status = 500): void {
  try {
    $method = $_SERVER["REQUEST_METHOD"] ?? null;
    $path   = parse_url($_SERVER["REQUEST_URI"] ?? "", PHP_URL_PATH) ?? null;
    $qs     = $_SERVER["QUERY_STRING"] ?? null;
    $ip     = $_SERVER["REMOTE_ADDR"] ?? null;
    $ua     = $_SERVER["HTTP_USER_AGENT"] ?? null;

    $body = null;
    try { $body = file_get_contents("php://input") ?: null; } catch (Throwable $ignored) {}

    $hdrs = null;
    try {
      if (function_exists("getallheaders")) {
        $hdrs = json_encode(getallheaders(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
      }
    } catch (Throwable $ignored) {}

    $stmt = $pdo->prepare("
      INSERT INTO spd_api_error_logs
        (correlation_id, occurred_at, method, path, query_string, http_status,
         error_type, error_message, error_file, error_line, stack_trace,
         request_headers, request_body, ip_address, user_agent)
      VALUES
        (:cid, NOW(), :method, :path, :qs, :status,
         :etype, :emsg, :efile, :eline, :trace,
         :hdrs, :body, :ip, :ua)
    ");

    $stmt->execute([
      ":cid"    => $correlationId,
      ":method" => $method,
      ":path"   => $path,
      ":qs"     => $qs,
      ":status" => $status,
      ":etype"  => get_class($e),
      ":emsg"   => $e->getMessage(),
      ":efile"  => $e->getFile(),
      ":eline"  => $e->getLine(),
      ":trace"  => $e->getTraceAsString(),
      ":hdrs"   => $hdrs,
      ":body"   => $body,
      ":ip"     => $ip,
      ":ua"     => $ua,
    ]);
  } catch (Throwable $ignored) {
    // swallow (best-effort)
  }
}

/**
 * Lightweight rate limit (file-based). Best-effort; never throws.
 */
function rate_limit(string $key, int $max = 12, int $windowSeconds = 60): bool {
  try {
    $dir = sys_get_temp_dir() . "/spd_rl";
    if (!is_dir($dir)) @mkdir($dir, 0755, true);

    $file = $dir . "/" . md5($key) . ".json";
    $now  = time();
    $data = ["t" => $now, "c" => 0];

    if (file_exists($file)) {
      $stored = json_decode((string)file_get_contents($file), true);
      if (is_array($stored) && isset($stored["t"], $stored["c"])) $data = $stored;
    }

    if (($now - (int)$data["t"]) > $windowSeconds) {
      $data = ["t" => $now, "c" => 0];
    }

    $data["c"] = (int)$data["c"] + 1;
    @file_put_contents($file, json_encode($data));

    return (int)$data["c"] <= $max;
  } catch (Throwable $ignored) {
    return true; // fail-open
  }
}

/* -------------------------------
   CORS (GitHub Pages)
-------------------------------- */
$allowedOrigins = [
  "https://smashpro-digital.github.io",
  "https://smashpro.app",
  "http://localhost:3000",
  "http://localhost:5173",
];

$origin = $_SERVER["HTTP_ORIGIN"] ?? "";
if ($origin && in_array($origin, $allowedOrigins, true)) {
  header("Access-Control-Allow-Origin: {$origin}");
  header("Vary: Origin");
  header("Access-Control-Allow-Methods: POST, OPTIONS");
  header("Access-Control-Allow-Headers: Content-Type, X-Api-Key, X-Correlation-Id");
}

if (($_SERVER["REQUEST_METHOD"] ?? "") === "OPTIONS") {
  http_response_code(204);
  exit;
}

/* -------------------------------
   Main
-------------------------------- */
$stage = "start";

try {
  $stage = "pdo_check";
  if (!isset($pdo) || !($pdo instanceof PDO)) {
    throw new RuntimeException("PDO not initialized (check bootstrap order).");
  }

  // Rate limit per IP
  $stage = "rate_limit";
  $ip = $_SERVER["REMOTE_ADDR"] ?? "unknown";
  if (!rate_limit("waitlist_post_{$ip}", 12, 60)) {
    json_error("Too many requests", 429, ["correlation_id" => $correlationId], [
      "X-Correlation-Id" => $correlationId
    ]);
  }

  $stage = "parse_body";
  $body = getJsonBody();

  // Honeypot
  $stage = "honeypot";
  $company = str_or_null($body["company"] ?? null);
  if ($company) {
    json_ok([
      "status" => "waitlisted",
      "correlation_id" => $correlationId
    ], 200, ["X-Correlation-Id" => $correlationId]);
  }

  $stage = "validate";
  $appSlug = str_or_null($body["app_slug"] ?? $body["appSlug"] ?? null);
  $email   = strtolower((string)(str_or_null($body["email"] ?? null) ?? ""));
  $first   = str_or_null($body["first_name"] ?? $body["firstName"] ?? null);
  $last    = str_or_null($body["last_name"] ?? $body["lastName"] ?? null);
  $phone   = str_or_null($body["phone"] ?? null);
  $source  = str_or_null($body["source"] ?? null) ?? "public-site";

  if (!$appSlug) {
    json_error("app_slug is required", 400, ["correlation_id" => $correlationId], [
      "X-Correlation-Id" => $correlationId
    ]);
  }

  if ($email === "" || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    json_error("Valid email is required", 400, ["correlation_id" => $correlationId], [
      "X-Correlation-Id" => $correlationId
    ]);
  }

  $name = trim(((string)($first ?? "")) . " " . ((string)($last ?? "")));
  if ($name === "") $name = $email;

  $stage = "begin_tx";
  $pdo->beginTransaction();

  // Ensure app exists
  $stage = "ensure_app";
  $chk = $pdo->prepare("SELECT app_key FROM spd_apps WHERE app_key = :k LIMIT 1");
  $chk->execute([":k" => $appSlug]);
  if (!$chk->fetchColumn()) {
    $insApp = $pdo->prepare("
      INSERT INTO spd_apps (app_key, name, description, created_at)
      VALUES (:k, :n, :d, NOW())
    ");
    $insApp->execute([
      ":k" => $appSlug,
      ":n" => strtoupper($appSlug),
      ":d" => "Waitlist registration"
    ]);
  }

  // Find/create user
  $stage = "find_user";
  $u = $pdo->prepare("SELECT id FROM spd_users WHERE email = :email LIMIT 1");
  $u->execute([":email" => $email]);
  $userId = $u->fetchColumn();

  if (!$userId) {
    $stage = "insert_user";
    $insUser = $pdo->prepare("
      INSERT INTO spd_users (email, name, first_name, last_name, phone, app_source, created_at)
      VALUES (:email, :name, :first, :last, :phone, :src, NOW())
    ");
    $insUser->execute([
      ":email" => $email,
      ":name"  => $name,
      ":first" => $first,
      ":last"  => $last,
      ":phone" => $phone,
      ":src"   => $source
    ]);
    $userId = (int)$pdo->lastInsertId();
  } else {
    $userId = (int)$userId;

    // Gentle fill-in only if empty
    $stage = "update_user";
    $upd = $pdo->prepare("
      UPDATE spd_users
      SET
        first_name = CASE WHEN (first_name IS NULL OR first_name='') THEN :first ELSE first_name END,
        last_name  = CASE WHEN (last_name  IS NULL OR last_name ='') THEN :last  ELSE last_name  END,
        name       = CASE WHEN (name       IS NULL OR name      ='') THEN :name  ELSE name       END,
        phone      = CASE WHEN (phone      IS NULL OR phone     ='') THEN :phone ELSE phone      END,
        app_source = CASE WHEN (app_source IS NULL OR app_source='') THEN :src   ELSE app_source END
      WHERE id = :id
    ");
    $upd->execute([
      ":first" => $first,
      ":last"  => $last,
      ":name"  => $name,
      ":phone" => $phone,
      ":src"   => $source,
      ":id"    => $userId
    ]);
  }

  // Create waitlist entry (idempotent)
  $stage = "insert_waitlist";
  $ins = $pdo->prepare("
    INSERT INTO spd_user_applications (user_id, app_slug, role, status, created_at)
    VALUES (:uid, :app, 'waitlist', 'waitlisted', NOW())
    ON DUPLICATE KEY UPDATE status = status
  ");
  $ins->execute([":uid" => $userId, ":app" => $appSlug]);

  $stage = "commit";
  $pdo->commit();

  json_ok([
    "status" => "waitlisted",
    "user_id" => $userId,
    "app_slug" => $appSlug,
    "correlation_id" => $correlationId
  ], 200, [
    "X-Correlation-Id" => $correlationId
  ]);

} catch (Throwable $e) {
  // IMPORTANT: rollback first, otherwise your log insert gets rolled back too.
  try {
    if (isset($pdo) && ($pdo instanceof PDO) && $pdo->inTransaction()) {
      $pdo->rollBack();
    }
  } catch (Throwable $ignored) {}

  // Log (best-effort)
  if (isset($pdo) && ($pdo instanceof PDO)) {
    log_api_error_best_effort($pdo, (string)$correlationId, $e, 500);
  }

  json_error("Server error", 500, [
    "correlation_id" => $correlationId,
    "stage" => $stage
  ], [
    "X-Correlation-Id" => $correlationId,
    "X-Error-Stage" => $stage
  ]);
}
