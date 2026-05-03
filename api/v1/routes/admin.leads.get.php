<?php
declare(strict_types=1);

/**
 * DEV ONLY:
 * Private lead viewer for shared hosting where remote MySQL is blocked.
 * Protected by api/v1/index.php require_api_key().
 * Remove/disable if not needed.
 */

require_once __DIR__ . "/../../bootstrap/bootstrap.php";

if (!headers_sent()) {
  header("Content-Type: application/json; charset=utf-8");
}

$method = strtoupper((string)($_SERVER["REQUEST_METHOD"] ?? "GET"));

header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Accept");
header("Access-Control-Max-Age: 86400");

if ($method === "OPTIONS") {
  http_response_code(204);
  exit;
}

if ($method !== "GET") {
  json_fail("Method Not Allowed", 405, ["method" => $method]);
}

function mask_email($value): string {
  $email = trim((string)$value);
  if ($email === "" || strpos($email, "@") === false) return "";

  [$local, $domain] = explode("@", $email, 2);
  $first = $local !== "" ? substr($local, 0, 1) : "*";

  return $first . "***@" . $domain;
}

function mask_phone($value): string {
  $phone = preg_replace("/\D+/", "", (string)$value);
  if ($phone === "") return "";

  $last = substr($phone, -4);
  return str_repeat("*", max(0, strlen($phone) - 4)) . $last;
}

function safe_row(array $row): array {
  $safe = [];
  $blocked = [
    "password",
    "pass",
    "token",
    "tokens",
    "access_token",
    "refresh_token",
    "secret",
    "client_secret",
    "webhook_signature_key",
    "square_access_token",
  ];

  foreach ($row as $key => $value) {
    $keyString = (string)$key;
    $lower = strtolower($keyString);

    foreach ($blocked as $blockedPart) {
      if (strpos($lower, $blockedPart) !== false) {
        continue 2;
      }
    }

    if (strpos($lower, "email") !== false) {
      $safe[$keyString] = mask_email($value);
      continue;
    }

    if (strpos($lower, "phone") !== false || strpos($lower, "mobile") !== false || strpos($lower, "tel") !== false) {
      $safe[$keyString] = mask_phone($value);
      continue;
    }

    if (is_string($value) && strlen($value) > 4000) {
      $safe[$keyString] = substr($value, 0, 4000) . "...";
      continue;
    }

    $safe[$keyString] = $value;
  }

  return $safe;
}

function table_exists(string $table): bool {
  $allowedTables = ["spd_waitlist", "quiz_leads", "pending_orders", "square_events"];
  if (!in_array($table, $allowedTables, true)) return false;

  $stmt = db()->prepare("
    SELECT COUNT(*)
    FROM information_schema.tables
    WHERE table_schema = DATABASE()
      AND table_name = :table
  ");
  $stmt->execute([":table" => $table]);
  return ((int)$stmt->fetchColumn()) > 0;
}

function table_columns(string $table): array {
  $allowedTables = ["spd_waitlist", "quiz_leads", "pending_orders", "square_events"];
  if (!in_array($table, $allowedTables, true)) return [];

  $stmt = db()->prepare("
    SELECT column_name
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = :table
    ORDER BY ordinal_position
  ");
  $stmt->execute([":table" => $table]);

  return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
}

function fetch_recent_rows(string $table, string $appSlug): array {
  $allowedTables = [
    "spd_waitlist",
    "quiz_leads",
    "pending_orders",
    "square_events",
  ];

  if (!in_array($table, $allowedTables, true)) {
    return [];
  }

  $pdo = db();
  $columns = table_columns($table);
  $columnMap = array_fill_keys(array_map("strtolower", $columns), true);

  $where = "";
  $params = [];
  if (isset($columnMap["app_slug"])) {
    $where = "WHERE `app_slug` = :app_slug";
    $params[":app_slug"] = $appSlug;
  }

  $orderColumn = "id";
  foreach (["updated_at", "created_at", "received_at", "processed_at", "id"] as $candidate) {
    if (isset($columnMap[$candidate])) {
      $orderColumn = $candidate;
      break;
    }
  }

  $sql = "SELECT * FROM `{$table}` {$where} ORDER BY `{$orderColumn}` DESC LIMIT 25";
  $stmt = $pdo->prepare($sql);
  $stmt->execute($params);

  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
  return array_map("safe_row", $rows);
}

$appSlug = trim((string)($_GET["app_slug"] ?? ""));

if ($appSlug !== "deeper-than-skin") {
  json_fail("Invalid app_slug", 400, ["app_slug" => $appSlug]);
}

$tableNames = [
  "spd_waitlist",
  "quiz_leads",
  "pending_orders",
  "square_events",
];

$tables = [];
foreach ($tableNames as $table) {
  $exists = table_exists($table);
  $tables[$table] = [
    "exists" => $exists,
    "rows" => $exists ? fetch_recent_rows($table, $appSlug) : [],
  ];
}

json_ok([
  "app_slug" => $appSlug,
  "tables" => $tables,
]);
