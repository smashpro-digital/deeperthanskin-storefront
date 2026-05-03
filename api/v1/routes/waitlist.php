<?php
declare(strict_types=1);

/**
 * API v1 – Waitlist
 * POST /api/v1/waitlist
 * GET  /api/v1/waitlist?app_slug=hodos   (admin)
 */

header('Content-Type: application/json; charset=utf-8');

// -------------------------------------------------
// CORS (GitHub Pages + SmashPro domains)
// -------------------------------------------------
$allowedOrigins = [
  'https://smashpro-digital.github.io',
  'https://smashpro.app',
  'http://localhost:3000',
  'http://localhost:5173',
];

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin && in_array($origin, $allowedOrigins, true)) {
  header("Access-Control-Allow-Origin: {$origin}");
  header('Vary: Origin');
  header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
  header('Access-Control-Allow-Headers: Content-Type, X-API-Key');
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
  http_response_code(204);
  exit;
}

// -------------------------------------------------
// Bootstrap DB (your existing system)
// -------------------------------------------------
require_once __DIR__ . '/../../bootstrap/db.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'DB unavailable']);
  exit;
}

// -------------------------------------------------
// Simple IP rate limiter
// -------------------------------------------------
function rate_limit(string $key, int $max = 10, int $window = 60): bool {
  $dir = sys_get_temp_dir() . '/spd_rl';
  if (!is_dir($dir)) @mkdir($dir, 0755, true);

  $file = $dir . '/' . md5($key) . '.json';
  $now  = time();
  $data = ['t' => $now, 'c' => 0];

  if (file_exists($file)) {
    $stored = json_decode(file_get_contents($file), true);
    if (is_array($stored)) $data = $stored;
  }

  if (($now - (int)$data['t']) > $window) {
    $data = ['t' => $now, 'c' => 0];
  }

  $data['c']++;
  file_put_contents($file, json_encode($data));

  return $data['c'] <= $max;
}

// =================================================
// POST → join waitlist
// =================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

  if (!rate_limit('waitlist_' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'))) {
    http_response_code(429);
    echo json_encode(['ok' => false, 'error' => 'Too many requests']);
    exit;
  }

  $body = json_decode(file_get_contents('php://input'), true);
  if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid JSON']);
    exit;
  }

  // Honeypot
  if (!empty($body['company'])) {
    echo json_encode(['ok' => true, 'status' => 'waitlisted']);
    exit;
  }

  $app   = trim((string)($body['app_slug'] ?? ''));
  $email = strtolower(trim((string)($body['email'] ?? '')));
  $first = trim((string)($body['first_name'] ?? ''));
  $last  = trim((string)($body['last_name'] ?? ''));
  $phone = trim((string)($body['phone'] ?? ''));
  $src   = trim((string)($body['source'] ?? 'public-site'));

  if ($app === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid input']);
    exit;
  }

  $name = trim($first . ' ' . $last);
  if ($name === '') $name = $email;

  try {
    $pdo->beginTransaction();

    // Ensure app exists
    $chk = $pdo->prepare("SELECT app_key FROM spd_apps WHERE app_key=? LIMIT 1");
    $chk->execute([$app]);
    if (!$chk->fetchColumn()) {
      $pdo->prepare(
        "INSERT INTO spd_apps (app_key, name, description, created_at)
         VALUES (?, ?, 'Waitlist registration', NOW())"
      )->execute([$app, strtoupper($app)]);
    }

    // User upsert
    $u = $pdo->prepare("SELECT id FROM spd_users WHERE email=? LIMIT 1");
    $u->execute([$email]);
    $userId = $u->fetchColumn();

    if (!$userId) {
      $pdo->prepare(
        "INSERT INTO spd_users
         (email, name, first_name, last_name, phone, app_source, created_at)
         VALUES (?, ?, ?, ?, ?, ?, NOW())"
      )->execute([$email, $name, $first, $last, $phone, $src]);
      $userId = (int)$pdo->lastInsertId();
    }

    // Waitlist record
    $pdo->prepare(
      "INSERT INTO spd_user_applications
       (user_id, app_slug, role, status, created_at)
       VALUES (?, ?, 'waitlist', 'waitlisted', NOW())
       ON DUPLICATE KEY UPDATE status=status"
    )->execute([$userId, $app]);

    $pdo->commit();

    echo json_encode(['ok' => true, 'status' => 'waitlisted']);
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Server error']);
  }

  exit;
}

// =================================================
// GET → admin list (API key protected)
// =================================================
if ($_SERVER['REQUEST_METHOD'] === 'GET') {

  $apiKey = $_SERVER['HTTP_X_API_KEY'] ?? '';
  if ($apiKey === '') {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Missing API key']);
    exit;
  }

  $k = $pdo->prepare("SELECT id FROM spd_api_keys WHERE api_key=? LIMIT 1");
  $k->execute([$apiKey]);
  if (!$k->fetchColumn()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Invalid API key']);
    exit;
  }

  $app = trim((string)($_GET['app_slug'] ?? 'hodos'));

  $q = $pdo->prepare(
    "SELECT u.email, u.name, u.phone, a.status, a.created_at
     FROM spd_user_applications a
     JOIN spd_users u ON u.id = a.user_id
     WHERE a.app_slug = ?
     ORDER BY a.created_at DESC
     LIMIT 500"
  );
  $q->execute([$app]);

  echo json_encode(['ok' => true, 'rows' => $q->fetchAll()]);
  exit;
}

http_response_code(405);
echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
