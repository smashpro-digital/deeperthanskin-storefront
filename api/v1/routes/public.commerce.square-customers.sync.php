<?php
declare(strict_types=1);

require_once __DIR__ . "/../../bootstrap/bootstrap.php";
require_once __DIR__ . "/../../bootstrap/commerce_tenant.php";
require_once __DIR__ . "/../../bootstrap/square.php";

if (!headers_sent()) {
  header("Content-Type: application/json; charset=utf-8");
}

const DTS_SQUARE_SYNC_DEFAULT_SOURCES = [
  "market-signup-page",
  "ritual-list",
  "market-kiosk",
  "qr-market",
];

function sync_body(): array {
  $raw = file_get_contents("php://input");
  $body = json_decode($raw ?: "{}", true);
  return is_array($body) ? $body : [];
}

function sync_clamp($value, int $max): ?string {
  if ($value === null) return null;
  $s = trim((string)$value);
  if ($s === "") return null;
  return function_exists("mb_substr") ? mb_substr($s, 0, $max) : substr($s, 0, $max);
}

function sync_bool($value): bool {
  if (is_bool($value)) return $value;
  $s = strtolower(trim((string)$value));
  return in_array($s, ["1", "true", "yes", "y", "on"], true);
}

function sync_waitlist_columns(): array {
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

function sync_has_column(string $column): bool {
  $columns = sync_waitlist_columns();
  return isset($columns[$column]);
}

function sync_select_expr(string $column, string $fallback = "NULL"): string {
  return sync_has_column($column) ? $column : "{$fallback} AS {$column}";
}

function sync_request(array $cfg, string $method, string $path, ?array $payload = null): array {
  $token = trim((string)($cfg["square_access_token"] ?? ""));
  if ($token === "") {
    return ["ok" => false, "status" => 500, "error" => "Square token missing", "json" => []];
  }

  if (!function_exists("curl_init")) {
    return ["ok" => false, "status" => 500, "error" => "cURL unavailable", "json" => []];
  }

  $url = rtrim(square_base_url($cfg), "/") . "/" . ltrim($path, "/");
  $headers = [
    "Authorization: Bearer {$token}",
    "Square-Version: " . square_version(),
    "Content-Type: application/json",
    "Accept: application/json",
  ];

  $cid = (string)($GLOBALS["correlationId"] ?? "");
  if ($cid !== "") $headers[] = "X-Correlation-Id: {$cid}";

  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 20,
    CURLOPT_HTTPHEADER => $headers,
  ]);

  $method = strtoupper(trim($method));
  if ($method === "POST") {
    curl_setopt($ch, CURLOPT_POST, true);
  } elseif ($method !== "GET") {
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
  }

  if ($payload !== null) {
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_SLASHES));
  }

  $raw = curl_exec($ch);
  $err = curl_error($ch);
  $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  $json = json_decode((string)$raw, true);
  if (!is_array($json)) $json = [];

  if ($err !== "") {
    return ["ok" => false, "status" => 502, "error" => "Square request failed", "json" => []];
  }

  return [
    "ok" => $status >= 200 && $status < 300,
    "status" => $status,
    "error" => $status >= 200 && $status < 300 ? null : "Square API error",
    "json" => $json,
  ];
}

function sync_reasonable_given_name_from_email(string $email): ?string {
  $prefix = strtolower(trim((string)strstr($email, "@", true)));
  if ($prefix === "") return null;
  $prefix = preg_replace('/\+.*/', '', $prefix) ?: $prefix;
  $parts = preg_split('/[._\-]+/', $prefix) ?: [];
  $first = preg_replace('/[^a-z]/', '', (string)($parts[0] ?? ""));

  $blocked = [
    "admin", "contact", "hello", "help", "info", "mail", "sales",
    "service", "support", "team", "test", "user", "webmaster",
  ];

  if ($first === "" || strlen($first) < 2) return null;
  if (in_array($first, $blocked, true)) return null;

  return ucfirst($first);
}

function sync_source_note(array $lead): string {
  $parts = [
    "Deeper Than Skin signup.",
    "Source: " . (($lead["source"] ?? "") ?: "unknown") . ".",
  ];

  $map = [
    "source_device" => "Device",
    "event_slug" => "Event",
    "event_date" => "Date",
    "campaign" => "Campaign",
    "interest" => "Interest",
  ];

  foreach ($map as $key => $label) {
    $value = trim((string)($lead[$key] ?? ""));
    if ($value !== "") $parts[] = "{$label}: {$value}.";
  }

  if ((int)($lead["sms_opt_in"] ?? 0) === 1) {
    $parts[] = "SMS opt-in: yes.";
  }

  return trim(implode(" ", $parts));
}

function sync_merge_note(?string $existing, string $addition): string {
  $existing = trim((string)$existing);
  if ($existing === "") return $addition;
  if (stripos($existing, $addition) !== false) return $existing;

  $merged = $existing . "\n" . $addition;
  return strlen($merged) > 3000 ? substr($merged, -3000) : $merged;
}

function sync_search_customer_by_email(array $cfg, string $email): ?array {
  $result = sync_request($cfg, "POST", "/customers/search", [
    "query" => [
      "filter" => [
        "email_address" => [
          "exact" => $email,
        ],
      ],
    ],
    "limit" => 1,
  ]);

  if (!$result["ok"]) {
    throw new RuntimeException((string)$result["error"]);
  }

  $customers = $result["json"]["customers"] ?? [];
  return is_array($customers) && isset($customers[0]) && is_array($customers[0])
    ? $customers[0]
    : null;
}

function sync_create_customer(array $cfg, array $lead, string $note): array {
  $given = sync_clamp($lead["first_name"] ?? null, 80);
  $family = sync_clamp($lead["last_name"] ?? null, 80);
  if (!$given) $given = sync_reasonable_given_name_from_email((string)$lead["email"]);

  $customer = [
    "email_address" => (string)$lead["email"],
    "note" => $note,
  ];
  if ($given) $customer["given_name"] = $given;
  if ($family) $customer["family_name"] = $family;
  if (!empty($lead["phone"])) $customer["phone_number"] = (string)$lead["phone"];

  $payload = [
    "idempotency_key" => bin2hex(random_bytes(16)),
    "given_name" => $customer["given_name"] ?? null,
    "family_name" => $customer["family_name"] ?? null,
    "email_address" => $customer["email_address"],
    "phone_number" => $customer["phone_number"] ?? null,
    "note" => $customer["note"],
  ];

  $payload = array_filter($payload, function ($value) {
    return $value !== null && $value !== "";
  });

  $result = sync_request($cfg, "POST", "/customers", $payload);

  if (!$result["ok"]) {
    throw new RuntimeException((string)$result["error"]);
  }

  $customer = $result["json"]["customer"] ?? null;
  if (!is_array($customer) || empty($customer["id"])) {
    throw new RuntimeException("Square customer create returned no id");
  }

  return $customer;
}

function sync_update_customer_note(array $cfg, array $customer, string $note): array {
  $id = (string)($customer["id"] ?? "");
  if ($id === "") return $customer;

  $mergedNote = sync_merge_note($customer["note"] ?? "", $note);
  if ($mergedNote === trim((string)($customer["note"] ?? ""))) return $customer;

  $payload = [
    "given_name" => $customer["given_name"] ?? null,
    "family_name" => $customer["family_name"] ?? null,
    "company_name" => $customer["company_name"] ?? null,
    "nickname" => $customer["nickname"] ?? null,
    "email_address" => $customer["email_address"] ?? null,
    "address" => $customer["address"] ?? null,
    "phone_number" => $customer["phone_number"] ?? null,
    "reference_id" => $customer["reference_id"] ?? null,
    "note" => $mergedNote,
    "birthday" => $customer["birthday"] ?? null,
    "version" => $customer["version"] ?? null,
  ];

  $payload = array_filter($payload, function ($value) {
    return $value !== null && $value !== "";
  });

  $result = sync_request($cfg, "PUT", "/customers/" . rawurlencode($id), $payload);
  if (!$result["ok"]) {
    throw new RuntimeException((string)$result["error"]);
  }

  $updated = $result["json"]["customer"] ?? null;
  return is_array($updated) ? $updated : $customer;
}

function sync_update_waitlist_status(string $appSlug, string $email, ?string $customerId, string $status, ?string $error): void {
  $sets = [];
  $params = [
    ":app_slug" => $appSlug,
    ":email" => $email,
  ];

  if (sync_has_column("square_customer_id")) {
    $sets[] = "square_customer_id = :square_customer_id";
    $params[":square_customer_id"] = $customerId;
  }
  if (sync_has_column("square_synced_at")) {
    $sets[] = "square_synced_at = CURRENT_TIMESTAMP";
  }
  if (sync_has_column("square_sync_status")) {
    $sets[] = "square_sync_status = :square_sync_status";
    $params[":square_sync_status"] = $status;
  }
  if (sync_has_column("square_sync_error")) {
    $sets[] = "square_sync_error = :square_sync_error";
    $params[":square_sync_error"] = $error;
  }
  if (sync_has_column("updated_at")) {
    $sets[] = "updated_at = CURRENT_TIMESTAMP";
  }
  if (!$sets) return;

  $sql = "
    UPDATE spd_waitlist
    SET " . implode(", ", $sets) . "
    WHERE app_slug = :app_slug AND email = :email
    LIMIT 1
  ";
  $stmt = db()->prepare($sql);
  $stmt->execute($params);
}

$body = sync_body();
$appSlug = sync_clamp($_GET["app_slug"] ?? $body["app_slug"] ?? null, 50) ?: "deeper-than-skin";
$sourceFilter = sync_clamp($body["source"] ?? null, 80);
$deviceFilter = sync_clamp($body["source_device"] ?? $body["device"] ?? null, 80);
$eventFilter = sync_clamp($body["event_slug"] ?? $body["event"] ?? null, 120);
$limit = max(1, min(100, (int)($body["limit"] ?? 25)));
$dryRun = sync_bool($body["dry_run"] ?? false);
$sources = $sourceFilter ? [$sourceFilter] : DTS_SQUARE_SYNC_DEFAULT_SOURCES;

try {
  $cfg = commerce_load_app_config($appSlug);

  $select = [
    "app_slug",
    "email",
    "first_name",
    "last_name",
    "phone",
    "source",
    "consent",
    sync_select_expr("source_device"),
    sync_select_expr("event_slug"),
    sync_select_expr("event_date"),
    sync_select_expr("campaign"),
    sync_select_expr("interest"),
    sync_select_expr("sms_opt_in", "0"),
    sync_select_expr("square_customer_id"),
    sync_select_expr("square_sync_status"),
  ];

  $where = [
    "app_slug = :app_slug",
    "email IS NOT NULL",
    "email <> ''",
  ];
  $params = [":app_slug" => $appSlug];

  $sourceClauses = [];
  foreach ($sources as $idx => $source) {
    $exactKey = ":source_{$idx}";
    $prefixKey = ":source_prefix_{$idx}";
    $sourceClauses[] = "(source = {$exactKey} OR source LIKE {$prefixKey})";
    $params[$exactKey] = $source;
    $params[$prefixKey] = $source . ":%";

    if ($source === "ritual-list") {
      $legacyExactKey = ":source_legacy_{$idx}";
      $legacyPrefixKey = ":source_legacy_prefix_{$idx}";
      $sourceClauses[] = "(source = {$legacyExactKey} OR source LIKE {$legacyPrefixKey})";
      $params[$legacyExactKey] = "ritual-list-page";
      $params[$legacyPrefixKey] = "ritual-list-page:%";
    }
  }
  $where[] = "(" . implode(" OR ", $sourceClauses) . ")";

  if ($deviceFilter && sync_has_column("source_device")) {
    $where[] = "source_device = :source_device";
    $params[":source_device"] = $deviceFilter;
  }
  if ($eventFilter && sync_has_column("event_slug")) {
    $where[] = "event_slug = :event_slug";
    $params[":event_slug"] = $eventFilter;
  }

  if (sync_has_column("square_customer_id") && sync_has_column("square_sync_status")) {
    $where[] = "(square_customer_id IS NULL OR square_customer_id = '' OR square_sync_status IS NULL OR square_sync_status <> 'success')";
  }

  $sql = "
    SELECT " . implode(", ", $select) . "
    FROM spd_waitlist
    WHERE " . implode(" AND ", $where) . "
    ORDER BY created_at ASC
    LIMIT {$limit}
  ";

  $stmt = db()->prepare($sql);
  $stmt->execute($params);
  $leads = $stmt->fetchAll(PDO::FETCH_ASSOC);

  $results = [];
  foreach ($leads as $lead) {
    $email = strtolower(trim((string)($lead["email"] ?? "")));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) continue;

    $lead["email"] = $email;
    $note = sync_source_note($lead);

    if ($dryRun) {
      $results[] = [
        "email" => $email,
        "source" => $lead["source"] ?? null,
        "source_device" => $lead["source_device"] ?? null,
        "event_slug" => $lead["event_slug"] ?? null,
        "interest" => $lead["interest"] ?? null,
        "inferred_given_name" => sync_clamp($lead["first_name"] ?? null, 80)
          ?: sync_reasonable_given_name_from_email($email),
        "square_note" => $note,
        "dry_run" => true,
      ];
      continue;
    }

    try {
      $customer = sync_search_customer_by_email($cfg, $email);
      $matched = (bool)$customer;
      if ($customer) {
        $customer = sync_update_customer_note($cfg, $customer, $note);
      } else {
        $customer = sync_create_customer($cfg, $lead, $note);
      }

      $customerId = (string)($customer["id"] ?? "");
      sync_update_waitlist_status($appSlug, $email, $customerId, "success", null);

      $results[] = [
        "email" => $email,
        "square_customer_id" => $customerId,
        "matched_existing" => $matched,
        "status" => "success",
      ];
    } catch (Throwable $e) {
      sync_update_waitlist_status($appSlug, $email, null, "error", "Square customer sync failed");
      $results[] = [
        "email" => $email,
        "status" => "error",
        "error" => "Square customer sync failed",
      ];
    }
  }

  json_ok([
    "ok" => true,
    "app_slug" => $appSlug,
    "dry_run" => $dryRun,
    "filters" => [
      "sources" => $sources,
      "source_device" => $deviceFilter,
      "event_slug" => $eventFilter,
      "limit" => $limit,
    ],
    "count" => count($results),
    "results" => $results,
  ]);
} catch (Throwable $e) {
  json_fail("Server error", 500, ["stage" => "square_customers.sync"]);
}
