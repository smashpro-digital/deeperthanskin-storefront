<?php
declare(strict_types=1);

/**
 * /api/v1/routes/public.waitlist.csv.get.php (FULL DROP-IN)
 *
 * GET /api/v1/index.php?path=public/waitlist/csv&app_slug=...&pin=1234
 *
 * Optional header (works in Postman/server-to-server; may trigger browser preflight):
 *   X-Owner-Password: 1234
 *
 * IMPORTANT REALITY:
 * - Browsers will preflight when you send X-Owner-Password.
 * - Some shared hosts return 500 on OPTIONS before PHP runs.
 * - So: for browser downloads, prefer query param pin.
 */

/* -------------------------------
   CORS + OPTIONS (MUST RUN FIRST)
-------------------------------- */
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

$allowedOrigins = [
  'https://smashpro-digital.github.io',
  'https://smashpro.app',
];

$isLocal = (bool)preg_match('#^http://(localhost|127\.0\.0\.1)(:\d+)?$#', (string)$origin);
$allowOrigin = ($origin && (in_array($origin, $allowedOrigins, true) || $isLocal));

if ($allowOrigin) {
  header("Access-Control-Allow-Origin: {$origin}");
  header("Vary: Origin");
  header("Access-Control-Allow-Methods: GET, OPTIONS");
  header("Access-Control-Allow-Headers: Accept, X-Owner-Password, Content-Type");
  header("Access-Control-Max-Age: 86400");
}

// If OPTIONS reaches PHP, answer cleanly
if ($method === 'OPTIONS') {
  http_response_code(204);
  exit;
}

/* -------------------------------
   Helpers
-------------------------------- */
function digits(string $s): string {
  $d = preg_replace('/\D+/', '', $s);
  return $d ?: "";
}

function json_out(int $status, array $payload): void {
  http_response_code($status);
  header("Content-Type: application/json; charset=utf-8");
  echo json_encode($payload);
  exit;
}

/* -------------------------------
   Inputs
-------------------------------- */
$appSlug = trim((string)($_GET["app_slug"] ?? ""));

// Prefer query pin (browser-safe). Fallback to header pin (Postman).
$pin = trim((string)($_GET["pin"] ?? ""));
$hdr = $_SERVER["HTTP_X_OWNER_PASSWORD"] ?? "";
if ($pin === "" && $hdr !== "") $pin = trim((string)$hdr);

if ($appSlug === "") {
  json_out(400, ["ok" => false, "error" => "app_slug is required"]);
}

$pinDigits = digits($pin);
if ($pinDigits === "" || strlen($pinDigits) < 4) {
  json_out(400, ["ok" => false, "error" => "pin is required (last 4 digits)"]);
}

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
  json_out(500, ["ok" => false, "error" => "Bootstrap not found"]);
}

require_once $bootstrap; // provides db()

/* -------------------------------
   Main
-------------------------------- */
try {
  // 1) Resolve owner via mapping table
  $stmt = db()->prepare("
    SELECT a.client_id, a.is_enabled, c.phone
    FROM spd_waitlist_apps a
    LEFT JOIN spd_clients c ON c.id = a.client_id
    WHERE a.app_slug = :app
    LIMIT 1
  ");
  $stmt->execute([":app" => $appSlug]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$row || (int)($row["is_enabled"] ?? 0) !== 1) {
    json_out(403, ["ok" => false, "error" => "Not enabled"]);
  }

  $ownerPhone = digits((string)($row["phone"] ?? ""));
  $last4 = $ownerPhone !== "" ? substr($ownerPhone, -4) : "";
  $providedLast4 = substr($pinDigits, -4);

  if ($last4 === "" || !hash_equals($last4, $providedLast4)) {
    json_out(401, ["ok" => false, "error" => "Unauthorized"]);
  }

  // 2) Pull waitlist rows
  $q = db()->prepare("
    SELECT app_slug, email, first_name, last_name, phone, source, consent, created_at, updated_at
    FROM spd_waitlist
    WHERE app_slug = :app
    ORDER BY created_at DESC
    LIMIT 5000
  ");
  $q->execute([":app" => $appSlug]);

  // 3) Stream CSV
  $filename = "waitlist_" . preg_replace('/[^a-z0-9\-_]+/i', '-', $appSlug) . ".csv";

  // NOTE: don't override Content-Type to JSON here
  header("Content-Type: text/csv; charset=utf-8");
  header("Content-Disposition: attachment; filename=\"{$filename}\"");
  header("Pragma: no-cache");
  header("Expires: 0");

  $out = fopen("php://output", "w");
  fputcsv($out, ["app_slug","email","first_name","last_name","phone","source","consent","created_at","updated_at"]);

  while ($r = $q->fetch(PDO::FETCH_ASSOC)) {
    fputcsv($out, [
      $r["app_slug"] ?? "",
      $r["email"] ?? "",
      $r["first_name"] ?? "",
      $r["last_name"] ?? "",
      $r["phone"] ?? "",
      $r["source"] ?? "",
      (string)($r["consent"] ?? ""),
      $r["created_at"] ?? "",
      $r["updated_at"] ?? "",
    ]);
  }

  fclose($out);
  exit;

} catch (Throwable $e) {
  error_log("[waitlist.csv] " . $e->getMessage());
  json_out(500, ["ok" => false, "error" => "Server error"]);
}
