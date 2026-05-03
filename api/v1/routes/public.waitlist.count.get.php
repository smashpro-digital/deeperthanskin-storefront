<?php
declare(strict_types=1);

/**
 * /api/v1/routes/public.waitlist.count.php
 *
 * GET /api/v1/index.php?path=public/waitlist/count&app_slug=...
 *
 * Returns:
 *   { ok:true, app_slug:string, count:int }
 */

/* -------------------------------
   Robust bootstrap loader
-------------------------------- */
$API_ROOT = dirname(dirname(__DIR__)); // /api
$bootstrap = $API_ROOT . "/bootstrap/bootstrap.php";

if (!is_file($bootstrap)) {
  // Walk upward if needed (shared hosting safe)
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
  echo json_encode([
    "ok" => false,
    "error" => "Bootstrap not found",
    "stage" => "bootstrap.load",
  ]);
  exit;
}

require_once $bootstrap; // provides db(), json_ok(), json_fail()

/* -------------------------------
   CORS
-------------------------------- */
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

$allowedOrigins = [
  'https://smashpro-digital.github.io',
  'https://smashpro.app',
];

$isLocal = (bool)preg_match('#^http://(localhost|127\.0\.0\.1)(:\d+)?$#', $origin);

if ($origin && (in_array($origin, $allowedOrigins, true) || $isLocal)) {
  header("Access-Control-Allow-Origin: {$origin}");
  header("Vary: Origin");
}

header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Accept");
header("Content-Type: application/json; charset=utf-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
  http_response_code(204);
  exit;
}

/* -------------------------------
   Input
-------------------------------- */
$appSlug = trim((string)($_GET['app_slug'] ?? ''));

if ($appSlug === '') {
  json_fail("app_slug is required", 400);
}

/* -------------------------------
   Count
-------------------------------- */
try {
  $stmt = db()->prepare("
    SELECT COUNT(*) AS n
    FROM spd_waitlist
    WHERE app_slug = :app_slug
  ");
  $stmt->execute([":app_slug" => $appSlug]);
  $count = (int)$stmt->fetchColumn();

  json_ok([
    "ok" => true,
    "app_slug" => $appSlug,
    "count" => $count,
  ]);
} catch (Throwable $e) {
  json_fail("Server error", 500, [
    "stage" => "waitlist.count",
  ]);
}
