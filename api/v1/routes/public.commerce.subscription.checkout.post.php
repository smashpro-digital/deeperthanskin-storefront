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

$productId = trim((string)($body["product_id"] ?? ""));
$optionId  = trim((string)($body["option_id"] ?? ""));

if ($productId === "" || $optionId === "") {
  json_fail("product_id and option_id are required", 400);
}

$locationId = trim((string)($cfg["square_location_id"] ?? ""));
if ($locationId === "") json_fail("Square location_id missing", 500);

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

$planVariationId = trim((string)($opt["square_subscription_plan_variation_id"] ?? ""));
$priceMoney = $opt["price_money"] ?? null;
$label = trim((string)($opt["label"] ?? "Subscription"));

if ($planVariationId === "") {
  json_fail("square_subscription_plan_variation_id missing", 500);
}
if (!is_array($priceMoney) || !isset($priceMoney["amount"], $priceMoney["currency"])) {
  json_fail("price_money missing or invalid", 500);
}

$redirectUrl = trim((string)($cfg["subscription_success_url"] ?? ""));
if ($redirectUrl === "") {
  $redirectUrl = trim((string)($cfg["site_url"] ?? ""));
  if ($redirectUrl !== "") {
    $redirectUrl = rtrim($redirectUrl, "/") . "/thank-you";
  }
}

$linkPayload = [
  "idempotency_key" => bin2hex(random_bytes(16)),
  "quick_pay" => [
    "name" => $label,
    "price_money" => [
      "amount" => (int)$priceMoney["amount"],
      "currency" => (string)$priceMoney["currency"],
    ],
    "location_id" => $locationId,
  ],
  "checkout_options" => array_filter([
    "subscription_plan_id" => $planVariationId,
    "redirect_url" => $redirectUrl !== "" ? $redirectUrl : null,
  ], static fn ($v) => $v !== null && $v !== ""),
];

$linkRes = square_request($cfg, "POST", "/online-checkout/payment-links", $linkPayload);
$url = $linkRes["payment_link"]["url"] ?? null;

if (!$url) {
  json_fail("Failed to create subscription checkout link", 502, [
    "square" => $linkRes,
    "debug" => [
      "product_id" => $productId,
      "option_id" => $optionId,
      "plan_variation_id" => $planVariationId,
    ],
  ]);
}

json_ok([
  "ok" => true,
  "app_slug" => $cfg["app_slug"],
  "product_id" => $productId,
  "option_id" => $optionId,
  "checkout_url" => $url,
], 200, [
  "X-Correlation-Id" => $GLOBALS["correlationId"] ?? "",
]);