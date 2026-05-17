<?php
declare(strict_types=1);

/**
 * GET /api/v1/index.php?path=public/commerce/market-pulse&app_slug=deeper-than-skin
 *
 * Public, privacy-safe market activity aggregate.
 * Does not expose customer names, emails, phones, payment ids, order ids, or addresses.
 */

require_once __DIR__ . "/../../bootstrap/bootstrap.php";
require_once __DIR__ . "/../../bootstrap/commerce_tenant.php";

if (!headers_sent()) {
  header("Content-Type: application/json; charset=utf-8");
  header("Cache-Control: no-store, max-age=0");
}

function market_pulse_json(array $payload, int $status = 200): void {
  http_response_code($status);
  echo json_encode($payload, JSON_UNESCAPED_SLASHES);
  exit;
}

function market_pulse_fallback(string $source = "fallback"): void {
  market_pulse_json([
    "ok" => true,
    "source" => $source,
    "updatedAt" => gmdate("c"),
    "stats" => [
      "ordersToday" => 0,
      "itemsSoldToday" => 0,
      "topItem" => "Fresh pours",
      "revenueToday" => null,
    ],
    "ticker" => [
      [
        "label" => "Fresh pours are available at the market table",
        "meta" => "Today",
        "type" => "note",
      ],
      [
        "label" => "Ask about seasonal botanical lemonades",
        "meta" => "Market special",
        "type" => "note",
      ],
      [
        "label" => "Reaction clips and table notes are refreshed after market moments",
        "meta" => "Curated",
        "type" => "note",
      ],
    ],
  ]);
}

function market_pulse_clean($value, string $fallback = ""): string {
  $s = trim((string)($value ?? $fallback));
  $s = preg_replace('/[<>{}\[\]\\\\]+/', '', $s) ?: "";
  $s = preg_replace('/\s+/', ' ', $s) ?: "";
  return substr($s, 0, 80);
}

function market_pulse_money($money): int {
  if (!is_array($money)) return 0;
  $amount = $money["amount"] ?? 0;
  return is_numeric($amount) ? (int)$amount : 0;
}

function market_pulse_quantity($value): float {
  $n = (float)($value ?? 1);
  return $n > 0 ? $n : 1.0;
}

function market_pulse_format_ago(string $iso): string {
  $then = strtotime($iso);
  if (!$then) return "Today";

  $diffMinutes = max(0, (int)round((time() - $then) / 60));
  if ($diffMinutes < 1) return "Just now";
  if ($diffMinutes < 60) return "{$diffMinutes} min ago";

  $diffHours = (int)round($diffMinutes / 60);
  if ($diffHours < 24) return "{$diffHours} hr ago";

  return "Today";
}

function market_pulse_sale_label(string $name, float $quantity): string {
  $qty = abs($quantity - round($quantity)) < 0.01 ? (string)(int)round($quantity) : number_format($quantity, 1);
  $isPour = (bool)preg_match('/pour|juice|lemonade|tea|sip|smoothie/i', $name);

  if ($quantity > 1) return "{$qty} {$name} picked up";

  return $name . ($isPour ? " fresh pour sold" : " sold at the market");
}

function market_pulse_day_range(string $timeZone = "America/New_York"): array {
  $tz = new DateTimeZone($timeZone);
  $start = new DateTimeImmutable("today", $tz);
  $end = $start->modify("+1 day");

  return [
    "start_at" => $start->setTimezone(new DateTimeZone("UTC"))->format("Y-m-d\TH:i:s\Z"),
    "end_at" => $end->setTimezone(new DateTimeZone("UTC"))->format("Y-m-d\TH:i:s\Z"),
  ];
}

function market_pulse_square_post(array $cfg, string $path, array $payload): ?array {
  $token = trim((string)($cfg["square_access_token"] ?? ""));
  if ($token === "") return null;

  $env = strtolower(trim((string)($cfg["square_environment"] ?? "production")));
  $base = $env === "sandbox"
    ? "https://connect.squareupsandbox.com/v2"
    : "https://connect.squareup.com/v2";

  if (!function_exists("curl_init")) return null;

  $ch = curl_init(rtrim($base, "/") . "/" . ltrim($path, "/"));
  curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
      "Authorization: Bearer {$token}",
      "Square-Version: " . (getenv("SQUARE_VERSION") ?: "2025-01-23"),
      "Content-Type: application/json",
      "Accept: application/json",
    ],
    CURLOPT_POSTFIELDS => json_encode($payload),
    CURLOPT_TIMEOUT => 12,
  ]);

  $raw = curl_exec($ch);
  $err = curl_error($ch);
  $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  if ($err || $code < 200 || $code >= 300) {
    error_log("[market-pulse.square] code={$code} err={$err}");
    return null;
  }

  $json = json_decode((string)$raw, true);
  return is_array($json) ? $json : null;
}

$appSlug = commerce_get_app_slug() ?: "deeper-than-skin";
$cfg = commerce_load_app_config($appSlug);
commerce_apply_cors($cfg);

if (($_SERVER["REQUEST_METHOD"] ?? "GET") === "OPTIONS") {
  http_response_code(204);
  exit;
}

if (($_SERVER["REQUEST_METHOD"] ?? "GET") !== "GET") {
  market_pulse_json(["ok" => false, "error" => "Method Not Allowed"], 405);
}

$locationId = trim((string)(
  getenv("SQUARE_LOCATION_ID_MARKET")
  ?: ($cfg["square_location_id"] ?? "")
));

if ($locationId === "") {
  market_pulse_fallback("fallback");
}

$range = market_pulse_day_range("America/New_York");
$payload = market_pulse_square_post($cfg, "orders/search", [
  "location_ids" => [$locationId],
  "limit" => 1000,
  "return_entries" => false,
  "query" => [
    "filter" => [
      "date_time_filter" => [
        "closed_at" => [
          "start_at" => $range["start_at"],
          "end_at" => $range["end_at"],
        ],
      ],
      "state_filter" => [
        "states" => ["COMPLETED"],
      ],
    ],
    "sort" => [
      "sort_field" => "CLOSED_AT",
      "sort_order" => "DESC",
    ],
  ],
]);

if (!is_array($payload)) {
  market_pulse_fallback("fallback");
}

$orders = is_array($payload["orders"] ?? null) ? $payload["orders"] : [];
$itemCounts = [];
$ticker = [];
$itemsSoldToday = 0.0;
$revenueCents = 0;
$closedOrders = 0;

foreach ($orders as $order) {
  if (!is_array($order)) continue;

  $state = strtoupper(trim((string)($order["state"] ?? "")));
  if ($state !== "COMPLETED") continue;

  $closedOrders++;
  $revenueCents += market_pulse_money($order["total_money"] ?? null);
  $happenedAt = (string)($order["closed_at"] ?? $order["created_at"] ?? gmdate("c"));
  $lineItems = is_array($order["line_items"] ?? null) ? $order["line_items"] : [];

  foreach ($lineItems as $item) {
    if (!is_array($item)) continue;

    $name = market_pulse_clean($item["name"] ?? null, "Market item");
    if ($name === "") continue;

    $qty = market_pulse_quantity($item["quantity"] ?? 1);
    $itemsSoldToday += $qty;
    $itemCounts[$name] = ($itemCounts[$name] ?? 0) + $qty;

    if (count($ticker) < 10) {
      $ticker[] = [
        "label" => market_pulse_sale_label($name, $qty),
        "meta" => market_pulse_format_ago($happenedAt),
        "type" => "sale",
      ];
    }
  }
}

arsort($itemCounts);
$topItem = array_key_first($itemCounts) ?: "Fresh pours";

market_pulse_json([
  "ok" => true,
  "source" => "square",
  "updatedAt" => gmdate("c"),
  "stats" => [
    "ordersToday" => $closedOrders,
    "itemsSoldToday" => $itemsSoldToday,
    "topItem" => market_pulse_clean($topItem, "Fresh pours"),
    "revenueToday" => round($revenueCents / 100, 2),
  ],
  "ticker" => count($ticker) ? $ticker : [[
    "label" => "Fresh pours are moving at the market table",
    "meta" => "Today",
    "type" => "note",
  ]],
]);
