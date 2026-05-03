<?php
declare(strict_types=1);

require_once __DIR__ . "/../../bootstrap/bootstrap.php";
require_once __DIR__ . "/../../bootstrap/commerce_tenant.php";
require_once __DIR__ . "/../../bootstrap/square.php";

$appSlug = commerce_get_app_slug();
if (!$appSlug) json_fail("app_slug is required", 400);

$cfg = commerce_load_app_config($appSlug);
commerce_apply_cors($cfg);

$method = $_SERVER["REQUEST_METHOD"] ?? "POST";
if ($method === "OPTIONS") { http_response_code(204); exit; }
if ($method !== "POST") json_fail("method not allowed", 405);

$raw  = file_get_contents("php://input");
$body = json_decode($raw ?: "{}", true);
if (!is_array($body)) $body = [];

$subscriptionId = trim((string)($body["subscription_id"] ?? ""));
$productId      = trim((string)($body["product_id"] ?? ""));
$optionId       = trim((string)($body["option_id"] ?? ""));

if ($subscriptionId === "") json_fail("subscription_id is required", 400);
if ($productId === "" || $optionId === "") json_fail("product_id and option_id are required", 400);

$subscriptionOptions = $cfg["subscription_options"] ?? null;
if (!is_array($subscriptionOptions)) {
  json_fail("subscription_options config missing", 500);
}

$productOptions = $subscriptionOptions[$productId] ?? null;
if (!is_array($productOptions)) {
  json_fail("No subscription config for this product", 404);
}

$opt = $productOptions[$optionId] ?? null;
if (!is_array($opt)) {
  json_fail("Invalid subscription option", 400);
}

$newPlanVariationId = trim((string)($opt["square_subscription_plan_variation_id"] ?? ""));
if ($newPlanVariationId === "") {
  json_fail("square_subscription_plan_variation_id missing", 500);
}

$payload = [
  "new_plan_variation_id" => $newPlanVariationId,
];

$res = square_request($cfg, "POST", "/subscriptions/" . rawurlencode($subscriptionId) . "/swap-plan", $payload);

if (!empty($res["errors"])) {
  json_fail("Failed to swap subscription plan", 502, ["square" => $res]);
}

json_ok([
  "ok" => true,
  "subscription" => $res["subscription"] ?? null,
  "actions" => $res["actions"] ?? [],
], 200, [
  "X-Correlation-Id" => $GLOBALS["correlationId"] ?? "",
]);