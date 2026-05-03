<?php
declare(strict_types=1);

/**
 * /api/v1/routes/public.commerce.featured.get.php
 *
 * GET /api/v1/index.php?path=public/commerce/featured&app_slug=deeper-than-skin&limit=12
 * GET /api/v1/index.php?path=public/commerce/featured&app_slug=deeper-than-skin&section=homepage_signature_ritual
 */

require_once __DIR__ . "/../../bootstrap/bootstrap.php";
require_once __DIR__ . "/../../bootstrap/commerce_tenant.php";

if (!headers_sent()) {
  header("Content-Type: application/json; charset=utf-8");
}

/* -------------------------------
   Tenant
-------------------------------- */
$appSlug = commerce_get_app_slug();
if (!$appSlug) fail_json("app_slug is required", 400);

$cfg = commerce_load_app_config($appSlug);
if (!$cfg) fail_json("Unknown app_slug", 400, ["app_slug" => $appSlug]);

commerce_apply_cors($cfg);

/* -------------------------------
   Method
-------------------------------- */
$method = strtoupper((string)($_SERVER["REQUEST_METHOD"] ?? "GET"));
if ($method === "OPTIONS") {
  http_response_code(204);
  exit;
}

if ($method !== "GET") {
  fail_json("Method Not Allowed", 405, ["method" => $method]);
}

/* -------------------------------
   Inputs
-------------------------------- */
$limit = (int)($_GET["limit"] ?? 12);
if ($limit <= 0) $limit = 12;
if ($limit > 24) $limit = 24;

$sectionKey = trim((string)($_GET["section"] ?? "homepage_signature_ritual"));
if ($sectionKey === "") $sectionKey = "homepage_signature_ritual";

/* -------------------------------
   Helpers
-------------------------------- */
function normalize_featured_row(array $r): array {
  $amount = $r["price_amount"] ?? null;
  $currency = $r["currency_code"] ?? null;

  $priceMoney = null;
  if (
    ($amount !== null && $amount !== '') &&
    is_string((string)$currency) &&
    trim((string)$currency) !== ""
  ) {
    $priceMoney = [
      "amount" => (int)$amount,
      "currency" => trim((string)$currency),
    ];
  }

  return [
    "id" => (string)($r["id"] ?? ""),
    "name" => (string)($r["name"] ?? ""),
    "description" => (string)($r["description"] ?? ""),
    "image_url" => ($r["image_url"] ?? null) !== null ? (string)$r["image_url"] : null,
    "primary_image_id" => null,
    "variation_id" => ($r["variation_id"] ?? null) !== null ? (string)$r["variation_id"] : null,
    "price_money" => $priceMoney,
    "has_price" => $priceMoney !== null,
    "image_ids" => [],
    "badge" => ($r["badge"] ?? null) !== null ? (string)$r["badge"] : null,
    "category" => ($r["category"] ?? null) !== null ? (string)$r["category"] : null,
  ];
}

function fetch_rows(PDO $pdo, string $sql, array $params = []): array {
  $stmt = $pdo->prepare($sql);
  $stmt->execute($params);
  return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/* -------------------------------
   Query strategy
-------------------------------- */
$pdo = db();
$items = [];
$mode = "section";

/* -------------------------------
   1) Section-driven curation
-------------------------------- */
try {
  $sql = "
    SELECT
      p.square_item_id AS id,
      COALESCE(si.title_override, p.name) AS name,
      COALESCE(si.subtitle_override, p.description, '') AS description,
      COALESCE(si.image_url_override, p.primary_image_url) AS image_url,
      p.square_variation_id AS variation_id,
      p.price_amount,
      p.currency_code,
      si.badge,
      NULL AS category
    FROM spd_storefront_section_items si
    INNER JOIN spd_storefront_sections ss
      ON ss.app_slug = si.app_slug
     AND ss.section_key = si.section_key
     AND ss.is_active = 1
    INNER JOIN spd_square_products p
      ON p.app_slug = si.app_slug
     AND si.item_type = 'product'
     AND p.square_item_id = si.item_ref
    WHERE si.app_slug = :app
      AND si.section_key = :section_key
      AND si.is_active = 1
      AND p.is_active = 1
      AND p.is_deleted = 0
    ORDER BY si.sort ASC, p.sort ASC, p.name ASC
    LIMIT {$limit}
  ";

  $rows = fetch_rows($pdo, $sql, [
    ":app" => $appSlug,
    ":section_key" => $sectionKey,
  ]);

  foreach ($rows as $r) {
    $items[] = normalize_featured_row($r);
  }
} catch (Throwable $e) {
  $items = [];
}

/* -------------------------------
   2) Fallback to is_featured
-------------------------------- */
if (count($items) === 0) {
  $mode = "is_featured";

  try {
    $sql = "
      SELECT
        square_item_id AS id,
        name,
        COALESCE(description, '') AS description,
        primary_image_url AS image_url,
        square_variation_id AS variation_id,
        price_amount,
        currency_code,
        NULL AS badge,
        NULL AS category
      FROM spd_square_products
      WHERE app_slug = :app
        AND is_active = 1
        AND is_deleted = 0
        AND is_featured = 1
      ORDER BY sort ASC, name ASC
      LIMIT {$limit}
    ";

    $rows = fetch_rows($pdo, $sql, [":app" => $appSlug]);

    foreach ($rows as $r) {
      $items[] = normalize_featured_row($r);
    }
  } catch (Throwable $e) {
    $items = [];
  }
}

/* -------------------------------
   3) Final fallback to all active products
-------------------------------- */
if (count($items) === 0) {
  $mode = "fallback";

  try {
    $sql = "
      SELECT
        square_item_id AS id,
        name,
        COALESCE(description, '') AS description,
        primary_image_url AS image_url,
        square_variation_id AS variation_id,
        price_amount,
        currency_code,
        NULL AS badge,
        NULL AS category
      FROM spd_square_products
      WHERE app_slug = :app
        AND is_active = 1
        AND is_deleted = 0
      ORDER BY is_featured DESC, sort ASC, name ASC
      LIMIT {$limit}
    ";

    $rows = fetch_rows($pdo, $sql, [":app" => $appSlug]);

    foreach ($rows as $r) {
      $items[] = normalize_featured_row($r);
    }
  } catch (Throwable $e) {
    $items = [];
  }
}

/* -------------------------------
   Response
-------------------------------- */
json_ok([
  "ok" => true,
  "app_slug" => $cfg["app_slug"] ?? $appSlug,
  "mode" => $mode,
  "section" => $sectionKey,
  "count" => count($items),
  "items" => $items,
], 200, [
  "X-Correlation-Id" => $GLOBALS["correlationId"] ?? "",
  "Cache-Control" => "public, max-age=300, s-maxage=300",
]);