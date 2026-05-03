<?php
declare(strict_types=1);

/**
 * /api/bootstrap/bootstrap.php (FULL DROP-IN)
 *
 * Compatible with:
 * - response.php: json_ok/json_error (both exit)
 * - db.php: db_connect(array $config): PDO
 * - config_loader.php: load_local_config()
 * - logger.php: log_api_error(PDO $pdo, array $payload) + (optional) redact_headers(array $headers)
 *
 * Adds:
 * - PHP 7.x polyfills for str_starts_with / str_ends_with (only if missing)
 */

if (!headers_sent()) {
  // Only default to JSON if no other content type has been set yet
  $already = false;
  foreach (headers_list() as $h) {
    if (stripos($h, "Content-Type:") === 0) { $already = true; break; }
  }
  if (!$already) header("Content-Type: application/json; charset=utf-8");
}

ini_set("display_errors", "0");
error_reporting(E_ALL);

/* -------------------------------
   PHP 7.x Polyfills (safe)
   - Only defined if missing (PHP < 8)
   - No side effects
-------------------------------- */
if (!function_exists('str_starts_with')) {
  function str_starts_with($haystack, $needle): bool {
    $haystack = (string)$haystack;
    $needle   = (string)$needle;
    if ($needle === '') return true;
    return strncmp($haystack, $needle, strlen($needle)) === 0;
  }
}

if (!function_exists('str_ends_with')) {
  function str_ends_with($haystack, $needle): bool {
    $haystack = (string)$haystack;
    $needle   = (string)$needle;
    if ($needle === '') return true;
    $len = strlen($needle);
    return $len === 0 ? true : substr($haystack, -$len) === $needle;
  }
}

require_once __DIR__ . "/response.php";
require_once __DIR__ . "/config_loader.php";
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/logger.php";

global $pdo, $correlationId;

/**
 * json_fail() compatibility wrapper
 * Accepts:
 *   json_fail("msg")                           -> 500
 *   json_fail("msg", 400)                      -> 400
 *   json_fail("msg", ["k"=>"v"])               -> 400 + data
 *   json_fail("msg", 401, ["k"=>"v"])          -> 401 + data
 *   json_fail("msg", 401, ["k"=>"v"], ["H"=>"x"]) -> headers
 */
if (!function_exists("json_fail")) {
  function json_fail(string $message, $statusOrData = 500, array $data = [], array $headers = []): void {
    $status = 500;

    if (is_int($statusOrData)) {
      $status = $statusOrData;
    } elseif (is_array($statusOrData)) {
      // common call style: json_fail("err", ["meta"=>...])
      $status = 400;
      $data = $statusOrData;
    }

    json_error($message, $status, $data, $headers);
  }
}

/* -------------------------------
   Correlation Id
-------------------------------- */
function get_correlation_id(): string {
  $incoming = $_SERVER["HTTP_X_CORRELATION_ID"] ?? "";
  $incoming = is_string($incoming) ? trim($incoming) : "";
  if ($incoming !== "") return $incoming;

  try {
    return bin2hex(random_bytes(16));
  } catch (Throwable $e) {
    return bin2hex(pack("N", time())) . bin2hex(pack("N", mt_rand()));
  }
}

$correlationId = get_correlation_id();
$GLOBALS["correlationId"] = $correlationId;
$GLOBALS["correlation_id"] = $correlationId;

/* -------------------------------
   Helpers
-------------------------------- */
function safe_get_headers(): array {
  try {
    if (function_exists("getallheaders")) {
      $h = getallheaders();
      return is_array($h) ? $h : [];
    }
  } catch (Throwable $e) {}
  return [];
}

function redact_headers_safe(array $headers): array {
  if (function_exists("redact_headers")) {
    try {
      $out = redact_headers($headers);
      return is_array($out) ? $out : $headers;
    } catch (Throwable $e) {
      return $headers;
    }
  }

  // Minimal fallback redaction
  $redacted = [];
  foreach ($headers as $k => $v) {
    $key = is_string($k) ? $k : (string)$k;
    $val = is_string($v) ? $v : (string)$v;

    $lk = strtolower($key);
    if (strpos($lk, "authorization") !== false) $val = "[REDACTED]";
    if (strpos($lk, "cookie") !== false) $val = "[REDACTED]";
    if (strpos($lk, "x-api-key") !== false) $val = "[REDACTED]";
    if (strpos($lk, "token") !== false) $val = "[REDACTED]";

    $redacted[$key] = $val;
  }
  return $redacted;
}

function raw_request_body_safe(): ?string {
  try {
    $raw = file_get_contents("php://input");
    if ($raw === false || $raw === "") return null;
    return $raw;
  } catch (Throwable $e) {
    return null;
  }
}

function try_log_error_best_effort($pdo, array $payload): void {
  try {
    if (!isset($pdo) || !($pdo instanceof PDO)) return;
    if (!function_exists("log_api_error")) return;
    log_api_error($pdo, $payload);
  } catch (Throwable $e) {
    // swallow
  }
}

/**
 * Consistent JSON failure helper (always adds correlation id header)
 */
function fail_json(string $message, int $status, array $data = [], array $headers = []): void {
  $cid = (string)($GLOBALS["correlationId"] ?? "");
  $headers = array_merge([
    "X-Correlation-Id" => $cid,
  ], $headers);

  $data = array_merge([
    "correlation_id" => $cid,
  ], $data);

  json_error($message, $status, $data, $headers);
}

/* -------------------------------
   Load config
-------------------------------- */
try {
  $config = load_local_config();
  $GLOBALS["config"] = $config;
} catch (Throwable $e) {
  $GLOBALS["config"] = [
    "debug" => ["show_exceptions" => false, "show_db_errors" => false],
  ];

  fail_json("Server config error", 500, [
    "stage" => "bootstrap.config",
  ], [
    "X-Error-Stage" => "bootstrap.config",
  ]);
}

/* -------------------------------
   DB connect
-------------------------------- */
try {
  $pdo = db_connect($GLOBALS["config"]);
  $GLOBALS["pdo"] = $pdo;
} catch (Throwable $e) {
  $show = (bool)($GLOBALS["config"]["debug"]["show_db_errors"] ?? false);

  fail_json("DB connection failed", 500, array_filter([
    "stage" => "bootstrap.db",
    "details" => $show ? $e->getMessage() : null,
  ]), [
    "X-Error-Stage" => "bootstrap.db",
  ]);
}

/* -------------------------------
   Provide db() helper alias
-------------------------------- */
if (!function_exists("db")) {
  function db(): PDO {
    global $pdo;
    if (!isset($pdo) || !($pdo instanceof PDO)) {
      fail_json("db_not_initialized", 500, [
        "stage" => "bootstrap.db_helper",
      ], [
        "X-Error-Stage" => "bootstrap.db_helper",
      ]);
    }
    return $pdo;
  }
}

/* -------------------------------
   Error handler (warnings/notices -> JSON)
-------------------------------- */
set_error_handler(function (
  int $severity,
  string $message,
  string $file = "",
  int $line = 0
) use (&$pdo, &$correlationId) {
  if (!(error_reporting() & $severity)) return true;

  $e = new ErrorException($message, 0, $severity, $file, $line);

  $headers = safe_get_headers();
  $safeHeaders = redact_headers_safe($headers);

  try_log_error_best_effort($pdo, [
    "correlation_id" => $correlationId,
    "endpoint" => $_SERVER["REQUEST_URI"] ?? null,
    "error_type" => get_class($e),
    "message" => $e->getMessage(),
    "stack_trace" => $e->getTraceAsString(),
    "request_headers" => $safeHeaders,
    "request_body" => raw_request_body_safe(),
    "ip_address" => $_SERVER["REMOTE_ADDR"] ?? null,
    "user_agent" => $_SERVER["HTTP_USER_AGENT"] ?? null,
  ]);

  $show = (bool)($GLOBALS["config"]["debug"]["show_exceptions"] ?? false);

  fail_json("Server error", 500, array_filter([
    "stage" => "bootstrap.error_handler",
    "details" => $show ? $e->getMessage() : null,
  ]), [
    "X-Error-Stage" => "bootstrap.error_handler",
  ]);
});

/* -------------------------------
   Exception handler
-------------------------------- */
set_exception_handler(function (Throwable $e) use (&$pdo, &$correlationId) {
  $headers = safe_get_headers();
  $safeHeaders = redact_headers_safe($headers);

  try_log_error_best_effort($pdo, [
    "correlation_id" => $correlationId,
    "endpoint" => $_SERVER["REQUEST_URI"] ?? null,
    "error_type" => get_class($e),
    "message" => $e->getMessage(),
    "stack_trace" => $e->getTraceAsString(),
    "request_headers" => $safeHeaders,
    "request_body" => raw_request_body_safe(),
    "ip_address" => $_SERVER["REMOTE_ADDR"] ?? null,
    "user_agent" => $_SERVER["HTTP_USER_AGENT"] ?? null,
  ]);

  $show = (bool)($GLOBALS["config"]["debug"]["show_exceptions"] ?? false);

  fail_json("Server error", 500, array_filter([
    "stage" => "bootstrap.exception_handler",
    "details" => $show ? $e->getMessage() : null,
  ]), [
    "X-Error-Stage" => "bootstrap.exception_handler",
  ]);
});

/* -------------------------------
   Shutdown handler (fatal errors)
-------------------------------- */
register_shutdown_function(function () use (&$pdo, &$correlationId) {
  $err = error_get_last();
  if (!$err) return;

  $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR];
  if (!in_array((int)($err["type"] ?? 0), $fatalTypes, true)) return;

  if (headers_sent()) return;

  $msg  = (string)($err["message"] ?? "Fatal error");
  $file = (string)($err["file"] ?? "");
  $line = (int)($err["line"] ?? 0);

  $headers = safe_get_headers();
  $safeHeaders = redact_headers_safe($headers);

  try_log_error_best_effort($pdo, [
    "correlation_id" => $correlationId,
    "endpoint" => $_SERVER["REQUEST_URI"] ?? null,
    "error_type" => "FatalError",
    "message" => $msg . ($file ? " in {$file}:{$line}" : ""),
    "stack_trace" => null,
    "request_headers" => $safeHeaders,
    "request_body" => null,
    "ip_address" => $_SERVER["REMOTE_ADDR"] ?? null,
    "user_agent" => $_SERVER["HTTP_USER_AGENT"] ?? null,
  ]);

  $show = (bool)($GLOBALS["config"]["debug"]["show_exceptions"] ?? false);

  fail_json("Server error", 500, array_filter([
    "stage" => "bootstrap.shutdown_handler",
    "details" => $show ? $msg : null,
  ]), [
    "X-Error-Stage" => "bootstrap.shutdown_handler",
  ]);
});