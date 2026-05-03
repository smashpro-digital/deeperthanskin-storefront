<?php
declare(strict_types=1);

require_once __DIR__ . "/../../bootstrap/bootstrap.php";
require_once __DIR__ . "/../../bootstrap/commerce_tenant.php";
require_once __DIR__ . "/../../bootstrap/square.php";

$appSlug = commerce_get_app_slug();
if (!$appSlug) json_fail("app_slug is required", 400);

$cfg = commerce_load_app_config($appSlug);
commerce_apply_cors($cfg);

$method = $_SERVER["REQUEST_METHOD"] ?? "GET";
if ($method === "OPTIONS") { http_response_code(204); exit; }
if ($method !== "GET") json_fail("method not allowed", 405);

$locationId = trim((string)($cfg["square_location_id"] ?? ""));
if ($locationId === "") json_fail("Square location_id missing", 500);

$customerId = trim((string)($_GET["customer_id"] ?? ""));
$cursor     = trim((string)($_GET["cursor"] ?? ""));
$limit      = (int)($_GET["limit"] ?? 50);
if ($limit <= 0) $limit = 50;
if ($limit > 200) $limit = 200;

$query = [
  "filter" => [
    "location_ids" => [$locationId],
  ],
];

if ($customerId !== "") {
  $query["filter"]["customer_ids"] = [$customerId];
}

$payload = [
  "query" => $query,
  "limit" => $limit,
];

if ($cursor !== "") {
  $payload["cursor"] = $cursor;
}

$res = square_request($cfg, "POST", "/subscriptions/search", $payload);

if (!empty($res["errors"])) {
  json_fail("Failed to search subscriptions", 502, ["square" => $res]);
}

json_ok([
  "ok" => true,
  "subscriptions" => $res["subscriptions"] ?? [],
  "cursor" => $res["cursor"] ?? null,
], 200, [
  "X-Correlation-Id" => $GLOBALS["correlationId"] ?? "",
]);