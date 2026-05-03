<?php
declare(strict_types=1);

require_once __DIR__ . "/../../bootstrap/bootstrap.php";
require_once __DIR__ . "/../../bootstrap/commerce_tenant.php";
require_once __DIR__ . "/../../bootstrap/square.php";

if (!headers_sent()) {
  header("Content-Type: application/json; charset=utf-8");
}

$appSlug = commerce_get_app_slug();
if (!$appSlug) json_fail("app_slug is required", 400);

$cfg = commerce_load_app_config($appSlug);
if (!$cfg) json_fail("Unknown app_slug", 400, ["app_slug" => $appSlug]);

commerce_apply_cors($cfg);

$method = strtoupper((string)($_SERVER["REQUEST_METHOD"] ?? "GET"));
if ($method === "OPTIONS") {
  http_response_code(204);
  exit;
}
if ($method !== "GET") {
  json_fail("Method Not Allowed", 405, ["method" => $method]);
}

$id = trim((string)($_GET["id"] ?? ""));
$slug = trim((string)($_GET["slug"] ?? ""));

if ($id === "" && $slug === "") {
  json_fail("id or slug is required", 400);
}

try {
  $pdo = db();
} catch (Throwable $e) {
  json_fail("Database connection failed", 500, [
    "detail" => $e->getMessage(),
  ]);
}

function dts_resolve_variation_id(PDO $pdo, string $appSlug, string $squareItemId, ?string $candidate): ?string {
  $candidate = is_string($candidate) ? trim($candidate) : "";
  $squareItemId = trim($squareItemId);

  /**
   * If candidate exists and is not the parent item ID, keep it.
   */
  if ($candidate !== "" && $candidate !== $squareItemId) {
    return $candidate;
  }

  /**
   * Fallback to synced Square products table.
   */
  try {
    $stmt = $pdo->prepare("
      SELECT square_variation_id
      FROM spd_square_products
      WHERE app_slug = :app_slug
        AND square_item_id = :square_item_id
        AND is_active = 1
        AND is_deleted = 0
      LIMIT 1
    ");
    $stmt->execute([
      ":app_slug" => $appSlug,
      ":square_item_id" => $squareItemId,
    ]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $variationId = trim((string)($row["square_variation_id"] ?? ""));

    if ($variationId !== "" && $variationId !== $squareItemId) {
      return $variationId;
    }
  } catch (Throwable $e) {
    // Non-fatal. Continue to Square fallback.
  }

  /**
   * Last-resort live Square lookup.
   * This prevents parent item_id from being used as checkout variation_id.
   */
  try {
    $resp = square_request($GLOBALS["cfg"] ?? [], "GET", "/catalog/object/" . rawurlencode($squareItemId));
    $obj = $resp["object"] ?? null;
    $variations = is_array($obj) ? ($obj["item_data"]["variations"] ?? []) : [];

    if (is_array($variations)) {
      foreach ($variations as $v) {
        if (!is_array($v)) continue;

        $variationId = trim((string)($v["id"] ?? ""));
        $vd = $v["item_variation_data"] ?? null;
        if ($variationId === "" || !is_array($vd)) continue;

        $priceMoney = $vd["price_money"] ?? null;
        if (is_array($priceMoney) && isset($priceMoney["amount"], $priceMoney["currency"])) {
          return $variationId;
        }
      }
    }
  } catch (Throwable $e) {
    // Non-fatal.
  }

  return $candidate !== "" && $candidate !== $squareItemId ? $candidate : null;
}

$GLOBALS["cfg"] = $cfg;

/**
 * Build lookup condition safely.
 */
$whereLookup = "";
$params = [
  ":app_slug" => $appSlug,
];

if ($id !== "") {
  $whereLookup = "AND p.square_product_id = :product_id";
  $params[":product_id"] = $id;
} else {
  $whereLookup = "AND p.slug = :product_slug";
  $params[":product_slug"] = $slug;
}

$sql = "
  SELECT
    p.id,
    p.app_slug,
    p.square_product_id,
    p.square_variation_id,
    p.slug,
    p.name,
    p.short_name,
    p.description,
    COALESCE(NULLIF(p.image_url, ''), NULLIF(p.fallback_image_url, '')) AS image_url,
    p.category_slug,
    p.brand_name,
    p.status,
    p.is_visible,
    p.is_featured,
    p.one_time_enabled,
    p.subscription_enabled,
    p.has_valid_price,
    p.price_amount,
    p.price_currency,
    p.sort_order,
    p.validation_errors_json,
    p.source_updated_at,
    p.published_at,
    p.updated_at
  FROM spd_storefront_products p
  WHERE p.app_slug = :app_slug
    AND p.status = 'active'
    AND p.is_visible = 1
    $whereLookup
  LIMIT 1
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);

$product = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$product) {
  json_fail("Product not found", 404);
}

$storefrontProductId = (int)($product["id"] ?? 0);
$squareProductId = trim((string)($product["square_product_id"] ?? ""));
$rawVariationId = trim((string)($product["square_variation_id"] ?? ""));

$resolvedVariationId = dts_resolve_variation_id(
  $pdo,
  $appSlug,
  $squareProductId,
  $rawVariationId
);

$subSql = "
  SELECT
    o.id,
    o.option_key,
    o.label,
    o.description,
    o.badge,
    o.square_plan_variation_id,
    o.price_amount,
    o.price_currency,
    o.compare_at_amount,
    o.compare_at_currency,
    o.discount_percent,
    o.enabled,
    o.sort_order
  FROM spd_storefront_product_subscription_options o
  WHERE o.app_slug = :app_slug
    AND o.storefront_product_id = :storefront_product_id
    AND o.enabled = 1
  ORDER BY o.sort_order ASC, o.id ASC
";

$subStmt = $pdo->prepare($subSql);
$subStmt->execute([
  ":app_slug" => $appSlug,
  ":storefront_product_id" => $storefrontProductId,
]);

$subscriptionRows = $subStmt->fetchAll(PDO::FETCH_ASSOC);

$priceMoney = null;
if (
  isset($product["price_amount"], $product["price_currency"]) &&
  $product["price_amount"] !== null &&
  $product["price_currency"] !== null &&
  (int)$product["price_amount"] > 0 &&
  trim((string)$product["price_currency"]) !== ""
) {
  $priceMoney = [
    "amount" => (int)$product["price_amount"],
    "currency" => (string)$product["price_currency"],
  ];
}

$subscriptionOptions = [];
foreach ($subscriptionRows as $row) {
  $subscriptionOptions[] = [
    "id" => (string)$row["option_key"],
    "label" => (string)$row["label"],
    "description" => (string)($row["description"] ?? ""),
    "badge" => (string)($row["badge"] ?? ""),
    "square_plan_variation_id" => (string)$row["square_plan_variation_id"],
    "price_money" => [
      "amount" => (int)$row["price_amount"],
      "currency" => (string)$row["price_currency"],
    ],
    "compare_at_price_money" => (
      isset($row["compare_at_amount"], $row["compare_at_currency"]) &&
      $row["compare_at_amount"] !== null &&
      $row["compare_at_currency"] !== null &&
      (int)$row["compare_at_amount"] > 0 &&
      trim((string)$row["compare_at_currency"]) !== ""
    )
      ? [
          "amount" => (int)$row["compare_at_amount"],
          "currency" => (string)$row["compare_at_currency"],
        ]
      : null,
    "discount_percent" => $row["discount_percent"] !== null
      ? (float)$row["discount_percent"]
      : null,
    "enabled" => (bool)$row["enabled"],
    "sort_order" => (int)$row["sort_order"],
  ];
}

$item = [
  /**
   * Parent Square ITEM id. Good for URL/display.
   */
  "id" => $squareProductId,
  "square_product_id" => $squareProductId,
  "storefront_product_id" => $storefrontProductId,

  /**
   * Child Square ITEM_VARIATION id. This is what checkout MUST use.
   */
  "variation_id" => $resolvedVariationId,
  "square_variation_id" => $resolvedVariationId,
  "raw_square_variation_id" => $rawVariationId,

  "slug" => (string)($product["slug"] ?? ""),
  "name" => (string)$product["name"],
  "short_name" => (string)($product["short_name"] ?? ""),
  "description" => (string)($product["description"] ?? ""),
  "image_url" => (string)($product["image_url"] ?? ""),
  "category_slug" => (string)($product["category_slug"] ?? ""),
  "brand_name" => (string)($product["brand_name"] ?? ($cfg["brand_name"] ?? "")),
  "has_price" => (bool)$product["has_valid_price"],
  "price_money" => $priceMoney,
  "one_time_enabled" => (bool)$product["one_time_enabled"],
  "subscription_enabled" => (bool)$product["subscription_enabled"],
  "subscription_options" => $subscriptionOptions,
  "is_featured" => (bool)$product["is_featured"],
  "status" => (string)$product["status"],
  "checkout_ready" => $resolvedVariationId !== null && $resolvedVariationId !== "" && $resolvedVariationId !== $squareProductId,
];

json_ok([
  "ok" => true,
  "app_slug" => $cfg["app_slug"],
  "item" => $item,
], 200, [
  "X-Correlation-Id" => $GLOBALS["correlationId"] ?? "",
  "Cache-Control" => "public, max-age=60, s-maxage=60",
]);