<?php
declare(strict_types=1);

/**
 * /api/v1/routes/public.commerce.products.get.php
 * DB-first products endpoint with optional Square sync.
 *
 * Critical checkout rule:
 * - id = Square ITEM id
 * - variation_id = Square ITEM_VARIATION id
 *
 * Fixes:
 * - Filters by ALL product-category links, not just primary category.
 * - Parent category pages include child categories.
 * - /category/market includes Lake Carolina Market products.
 */

if (!function_exists('str_starts_with')) {
  function str_starts_with(string $haystack, string $needle): bool {
    if ($needle === '') return true;
    return substr($haystack, 0, strlen($needle)) === $needle;
  }
}

if (!function_exists('str_ends_with')) {
  function str_ends_with(string $haystack, string $needle): bool {
    if ($needle === '') return true;
    $len = strlen($needle);
    return $len === 0 ? true : substr($haystack, -$len) === $needle;
  }
}

if (!function_exists('str_contains')) {
  function str_contains(string $haystack, string $needle): bool {
    if ($needle === '') return true;
    return strpos($haystack, $needle) !== false;
  }
}

require_once __DIR__ . "/../../bootstrap/bootstrap.php";
require_once __DIR__ . "/../../bootstrap/commerce_tenant.php";
require_once __DIR__ . "/../../bootstrap/square.php";

if (!headers_sent()) {
  header("Content-Type: application/json; charset=utf-8");
}

function product_slugify(string $s): string {
  $s = strtolower(trim($s));
  $s = preg_replace('/[\'"`’]/u', '', $s);
  $s = preg_replace('/[^a-z0-9]+/i', '-', $s);
  $s = trim((string)$s, '-');
  return $s !== "" ? $s : "product";
}

function product_db_text(?string $value): ?string {
  if (!is_string($value)) return null;

  $value = trim($value);
  if ($value === "") return null;

  // Some legacy MySQL text columns reject 4-byte Unicode characters.
  // Strip those for DB writes so one decorated Square item cannot block sync.
  $clean = preg_replace('/[\x{10000}-\x{10FFFF}]/u', '', $value);
  if (!is_string($clean)) $clean = $value;

  $clean = trim($clean);
  return $clean !== "" ? $clean : null;
}

function product_db_name(?string $value): ?string {
  $clean = product_db_text($value);
  if ($clean === null) return null;

  $clean = preg_replace('/\p{So}/u', '', $clean);
  if (!is_string($clean)) $clean = product_db_text($value) ?? "";

  $ascii = @iconv("UTF-8", "ASCII//TRANSLIT//IGNORE", $clean);
  if (is_string($ascii) && trim($ascii) !== "") {
    $clean = $ascii;
  }

  $clean = preg_replace('/[^\x20-\x7E]/', '', $clean);
  if (!is_string($clean)) $clean = "";

  $clean = trim(preg_replace('/\s+/u', ' ', $clean) ?? $clean);
  return $clean !== "" ? $clean : null;
}

function normalize_slug(?string $s): ?string {
  if (!is_string($s)) return null;
  $s = trim($s);
  if ($s === '') return null;
  return product_slugify($s);
}

function square_try_products(array $cfg, string $method, array $paths, array $body = null): array {
  $last = null;

  foreach ($paths as $p) {
    try {
      if ($body === null) return square_request($cfg, $method, $p);
      return square_request($cfg, $method, $p, $body);
    } catch (Throwable $e) {
      $last = $e->getMessage();
    }
  }

  throw new RuntimeException($last ?: "Square request failed");
}

function extract_best_variation_from_square_item(array $obj): array {
  $item = $obj["item_data"] ?? null;
  if (!is_array($item)) {
    return [
      "variation_id" => null,
      "amount" => null,
      "currency" => null,
      "sku" => null,
      "upc" => null,
    ];
  }

  $best = [
    "variation_id" => null,
    "amount" => null,
    "currency" => null,
    "sku" => null,
    "upc" => null,
  ];

  $variations = is_array($item["variations"] ?? null) ? $item["variations"] : [];

  foreach ($variations as $v) {
    if (!is_array($v)) continue;

    $variationId = trim((string)($v["id"] ?? ""));
    $vd = $v["item_variation_data"] ?? null;

    if ($variationId === "" || !is_array($vd)) continue;

    $priceMoney = $vd["price_money"] ?? null;
    $amount = is_array($priceMoney) ? ($priceMoney["amount"] ?? null) : null;
    $currency = is_array($priceMoney) ? ($priceMoney["currency"] ?? null) : null;

    if (!(is_int($amount) || ctype_digit((string)$amount))) continue;
    if (!is_string($currency) || trim($currency) === "") continue;

    $amount = (int)$amount;
    $currency = trim($currency);

    if ($best["amount"] === null || $amount < (int)$best["amount"]) {
      $sku = isset($vd["sku"]) ? trim((string)$vd["sku"]) : null;
      if ($sku === "") $sku = null;

      $upc = isset($vd["upc"]) ? trim((string)$vd["upc"]) : null;
      if ($upc === "") $upc = null;

      $best = [
        "variation_id" => $variationId,
        "amount" => $amount,
        "currency" => $currency,
        "sku" => $sku,
        "upc" => $upc,
      ];
    }
  }

  return $best;
}

function extract_variations_from_square_item(array $obj): array {
  $itemId = trim((string)($obj["id"] ?? ""));
  $item = $obj["item_data"] ?? null;
  if ($itemId === "" || !is_array($item)) return [];

  $variations = is_array($item["variations"] ?? null) ? $item["variations"] : [];
  $out = [];

  foreach ($variations as $idx => $v) {
    if (!is_array($v)) continue;

    $variationId = trim((string)($v["id"] ?? ""));
    $vd = $v["item_variation_data"] ?? null;
    if ($variationId === "" || !is_array($vd)) continue;

    $priceMoney = $vd["price_money"] ?? null;
    $amount = is_array($priceMoney) ? ($priceMoney["amount"] ?? null) : null;
    $currency = is_array($priceMoney) ? ($priceMoney["currency"] ?? null) : null;

    $name = product_db_name(isset($vd["name"]) ? (string)$vd["name"] : null) ?? "";
    if ($name === "") $name = "Regular";

    $sku = isset($vd["sku"]) ? trim((string)$vd["sku"]) : null;
    if ($sku === "") $sku = null;

    $upc = isset($vd["upc"]) ? trim((string)$vd["upc"]) : null;
    if ($upc === "") $upc = null;

    $out[] = [
      "square_item_id" => $itemId,
      "square_variation_id" => $variationId,
      "name" => $name,
      "sku" => $sku,
      "upc" => $upc,
      "price_amount" => (is_int($amount) || ctype_digit((string)$amount)) ? (int)$amount : null,
      "currency_code" => is_string($currency) && trim($currency) !== "" ? trim($currency) : null,
      "sort_order" => $idx + 1,
      "is_deleted" => !empty($v["is_deleted"]) ? 1 : 0,
      "is_active" => empty($v["is_deleted"]) ? 1 : 0,
      "raw_json" => json_encode($v, JSON_UNESCAPED_SLASHES),
    ];
  }

  return $out;
}

function extract_category_ids_from_square_item_data(array $item): array {
  $ids = [];

  $categories = is_array($item["categories"] ?? null) ? $item["categories"] : [];
  foreach ($categories as $c) {
    if (!is_array($c)) continue;

    $cid = trim((string)($c["id"] ?? ""));
    if ($cid !== "") $ids[] = $cid;
  }

  $legacyCid = trim((string)($item["category_id"] ?? ""));
  if ($legacyCid !== "") $ids[] = $legacyCid;

  $categoryIds = is_array($item["category_ids"] ?? null) ? $item["category_ids"] : [];
  foreach ($categoryIds as $cidRaw) {
    $cid = is_string($cidRaw) ? trim($cidRaw) : "";
    if ($cid !== "") $ids[] = $cid;
  }

  $reporting = $item["reporting_category"] ?? null;
  if (is_array($reporting)) {
    foreach (["id", "category_id"] as $key) {
      $cid = trim((string)($reporting[$key] ?? ""));
      if ($cid !== "") $ids[] = $cid;
    }
  }

  foreach (["reporting_category_id", "reporting_category"] as $key) {
    $cid = is_string($item[$key] ?? null) ? trim((string)$item[$key]) : "";
    if ($cid !== "") $ids[] = $cid;
  }

  return array_values(array_unique($ids));
}

function square_category_item_objects(array $cfg, array $categoryIds): array {
  $categoryIds = array_values(array_unique(array_filter($categoryIds, function ($v) {
    return is_string($v) && trim($v) !== "";
  })));

  if (!$categoryIds) return [];

  $searchResp = square_try_products($cfg, "POST", [
    "/catalog/search-catalog-items",
    "catalog/search-catalog-items",
    "/v2/catalog/search-catalog-items",
    "v2/catalog/search-catalog-items",
  ], [
    "category_ids" => $categoryIds,
    "archived_state" => "ARCHIVED_STATE_NOT_ARCHIVED",
    "limit" => 100,
    "sort_order" => "ASC",
  ]);

  $searchedItems = $searchResp["items"] ?? [];
  if (!is_array($searchedItems)) return [];

  $ids = [];
  foreach ($searchedItems as $item) {
    if (!is_array($item)) continue;
    if (($item["type"] ?? "") !== "ITEM") continue;

    $id = trim((string)($item["id"] ?? ""));
    if ($id !== "") $ids[] = $id;
  }

  $ids = array_values(array_unique($ids));
  if (!$ids) return [];

  try {
    $batchResp = square_try_products($cfg, "POST", [
      "/catalog/batch-retrieve",
      "catalog/batch-retrieve",
      "/v2/catalog/batch-retrieve",
      "v2/catalog/batch-retrieve",
    ], [
      "object_ids" => $ids,
      "include_related_objects" => false,
    ]);

    $batchObjects = $batchResp["objects"] ?? [];
    if (is_array($batchObjects) && count($batchObjects) > 0) {
      return array_values(array_filter($batchObjects, function ($obj) {
        return is_array($obj) && (($obj["type"] ?? "") === "ITEM");
      }));
    }
  } catch (Throwable $e) {
    // Fall back to search result objects.
  }

  return $searchedItems;
}

function square_category_fallback_rows(array $cfg, array $objects): array {
  $imageIds = [];

  foreach ($objects as $obj) {
    if (!is_array($obj)) continue;
    $item = $obj["item_data"] ?? null;
    if (!is_array($item)) continue;

    foreach ((is_array($item["image_ids"] ?? null) ? $item["image_ids"] : []) as $iid) {
      if (is_string($iid) && trim($iid) !== "") $imageIds[trim($iid)] = true;
    }
  }

  $imageUrlMap = [];
  $imageIdList = array_values(array_keys($imageIds));
  foreach (array_chunk($imageIdList, 250) as $chunk) {
    try {
      $imgResp = square_try_products($cfg, "POST", [
        "/catalog/batch-retrieve",
        "catalog/batch-retrieve",
        "/v2/catalog/batch-retrieve",
        "v2/catalog/batch-retrieve",
      ], [
        "object_ids" => $chunk,
        "include_related_objects" => false,
      ]);

      foreach (($imgResp["objects"] ?? []) as $imgObj) {
        if (!is_array($imgObj) || (($imgObj["type"] ?? "") !== "IMAGE")) continue;
        $iid = trim((string)($imgObj["id"] ?? ""));
        $url = trim((string)($imgObj["image_data"]["url"] ?? ""));
        if ($iid !== "" && $url !== "") $imageUrlMap[$iid] = $url;
      }
    } catch (Throwable $e) {
      // Images are optional for fallback rows.
    }
  }

  $rows = [];
  foreach ($objects as $obj) {
    if (!is_array($obj)) continue;
    if (!empty($obj["is_deleted"]) || !empty($obj["is_archived"])) continue;

    $itemId = trim((string)($obj["id"] ?? ""));
    $item = $obj["item_data"] ?? null;
    if ($itemId === "" || !is_array($item)) continue;

    $name = product_db_name(isset($item["name"]) ? (string)$item["name"] : null) ?? "";
    if ($name === "") continue;

    $best = extract_best_variation_from_square_item($obj);
    $imageIdsForItem = is_array($item["image_ids"] ?? null) ? $item["image_ids"] : [];
    $primaryImageId = isset($imageIdsForItem[0]) && is_string($imageIdsForItem[0]) ? trim($imageIdsForItem[0]) : "";

    $rows[] = [
      "id" => $itemId,
      "name" => $name,
      "description" => product_db_text(isset($item["description"]) ? (string)$item["description"] : null) ?? "",
      "image_url" => $primaryImageId !== "" ? ($imageUrlMap[$primaryImageId] ?? null) : null,
      "variation_id" => $best["variation_id"],
      "price_amount" => $best["amount"],
      "currency_code" => $best["currency"],
      "is_active" => 1,
      "is_deleted" => 0,
      "raw_json" => json_encode($obj, JSON_UNESCAPED_SLASHES),
    ];
  }

  return $rows;
}

function resolve_variation_id_for_response(?string $dbVariationId, string $itemId, ?string $rawJson): ?string {
  $dbVariationId = is_string($dbVariationId) ? trim($dbVariationId) : "";

  if ($dbVariationId !== "" && $dbVariationId !== $itemId) {
    return $dbVariationId;
  }

  if (is_string($rawJson) && trim($rawJson) !== "") {
    $decoded = json_decode($rawJson, true);
    if (is_array($decoded)) {
      $best = extract_best_variation_from_square_item($decoded);
      $vid = $best["variation_id"] ?? null;

      if (is_string($vid) && trim($vid) !== "" && trim($vid) !== $itemId) {
        return trim($vid);
      }
    }
  }

  return $dbVariationId !== "" ? $dbVariationId : null;
}

function choose_response_variation(array $variations, ?string $fallbackVariationId, ?array $fallbackPriceMoney): array {
  $fallbackVariationId = is_string($fallbackVariationId) && trim($fallbackVariationId) !== ""
    ? trim($fallbackVariationId)
    : null;

  foreach ($variations as $v) {
    if (empty($v["is_active"])) continue;

    $vid = trim((string)($v["id"] ?? ""));
    $pm = $v["price_money"] ?? null;

    if ($vid !== "" && is_array($pm) && isset($pm["amount"], $pm["currency"])) {
      return [
        "variation_id" => $vid,
        "price_money" => $pm,
      ];
    }
  }

  return [
    "variation_id" => $fallbackVariationId,
    "price_money" => $fallbackPriceMoney,
  ];
}

function build_one_time_purchase_option_for_products(
  bool $enabled,
  ?string $variationId,
  ?array $priceMoney,
  array $variations,
  bool $selectedByDefault
): ?array {
  if (!$enabled) return null;

  $variationId = is_string($variationId) ? trim($variationId) : "";

  return [
    "id" => "one_time",
    "type" => "one_time",
    "label" => "One-time purchase",
    "description" => "Add once and checkout securely.",
    "enabled" => true,
    "selected_by_default" => $selectedByDefault,
    "variation_id" => $variationId !== "" ? $variationId : null,
    "price_money" => $priceMoney,
    "variations" => $variations,
  ];
}

function build_subscription_purchase_option_for_products(array $row, bool $selectedByDefault): array {
  $compareAtPriceMoney = (
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
    : null;

  return [
    "id" => (string)$row["option_key"],
    "type" => "subscription",
    "label" => (string)$row["label"],
    "description" => (string)($row["description"] ?? ""),
    "badge" => (string)($row["badge"] ?? "Subscription"),
    "enabled" => (bool)$row["enabled"],
    "selected_by_default" => $selectedByDefault,
    "square_plan_variation_id" => (string)$row["square_plan_variation_id"],
    "price_money" => [
      "amount" => (int)$row["price_amount"],
      "currency" => (string)$row["price_currency"],
    ],
    "compare_at_price_money" => $compareAtPriceMoney,
    "discount_percent" => $row["discount_percent"] !== null
      ? (float)$row["discount_percent"]
      : null,
    "sort_order" => (int)$row["sort_order"],
  ];
}

function commerce_category_ids_from_cfg_products(array $cfg, string $categorySlug): array {
  $map = $cfg["category_map"] ?? [];
  if (!is_array($map)) $map = [];

  $raw = $map[$categorySlug] ?? null;

  if (is_string($raw) && trim($raw) !== "") {
    return [trim($raw)];
  }

  if (is_array($raw)) {
    $tmp = [];
    foreach ($raw as $v) {
      if (is_string($v) && trim($v) !== "") {
        $tmp[] = trim($v);
      }
    }
    return array_values(array_unique($tmp));
  }

  return [];
}

function product_env_value(string $appSlug, string $key, string $default = ""): string {
  $prefixed = commerce_env($appSlug, $key, "");
  if ($prefixed !== "") return $prefixed;

  $raw = getenv($key);
  if ($raw === false) return $default;

  $raw = is_string($raw) ? trim($raw) : "";
  return $raw !== "" ? $raw : $default;
}

function product_market_category_env(string $appSlug, string $categorySlug): array {
  $categorySlug = product_slugify($categorySlug);

  if ($categorySlug === "market") {
    return [
      "id" => product_env_value($appSlug, "MARKET_CATEGORY_ID", ""),
      "name" => product_env_value($appSlug, "MARKET_CATEGORY_NAME", "Market"),
    ];
  }

  if ($categorySlug === "featured-market-drinks" || $categorySlug === "featured-drinks") {
    return [
      "id" => product_env_value($appSlug, "FEATURED_MARKET_CATEGORY_ID", ""),
      "name" => product_env_value($appSlug, "FEATURED_MARKET_CATEGORY_NAME", "Featured Drinks"),
    ];
  }

  if ($categorySlug === "lake-carolina-market") {
    return [
      "id" => product_env_value($appSlug, "LAKE_CAROLINA_MARKET_CATEGORY_ID", ""),
      "name" => "Lake Carolina Market",
    ];
  }

  if ($categorySlug === "soda-city-market") {
    return [
      "id" => product_env_value($appSlug, "SODA_CITY_MARKET_CATEGORY_ID", ""),
      "name" => "Soda City Market",
    ];
  }

  return ["id" => "", "name" => ""];
}

/**
 * Resolve category slug/name/id to Square category IDs.
 * Includes descendants so a parent category like "market" returns:
 * - Market
 * - Lake Carolina Market
 */
function commerce_category_ids_from_db_products(
  string $appSlug,
  string $categorySlug,
  ?string $categoryName = null,
  ?string $categoryId = null
): array {
  $stmt = db()->prepare("
    SELECT
      square_category_id,
      parent_square_category_id,
      name,
      slug
    FROM spd_square_categories
    WHERE app_slug = :app
      AND is_deleted = 0
  ");

  $stmt->execute([":app" => $appSlug]);
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

  $targetSlug = product_slugify($categorySlug);
  $targetNameSlug = is_string($categoryName) && trim($categoryName) !== ""
    ? product_slugify($categoryName)
    : "";
  $targetId = is_string($categoryId) ? trim($categoryId) : "";

  $rootIds = [];
  $childrenByParent = [];

  foreach ($rows as $r) {
    $id = trim((string)($r["square_category_id"] ?? ""));
    $parent = trim((string)($r["parent_square_category_id"] ?? ""));
    $slug = product_slugify((string)($r["slug"] ?? ""));
    $nameSlug = product_slugify((string)($r["name"] ?? ""));

    if ($id === "") continue;

    if ($parent !== "") {
      if (!isset($childrenByParent[$parent])) {
        $childrenByParent[$parent] = [];
      }

      $childrenByParent[$parent][] = $id;
    }

    $matchesId = $targetId !== "" && hash_equals($targetId, $id);
    $matchesSlug = $targetSlug !== "" && ($slug === $targetSlug || $nameSlug === $targetSlug);
    $matchesName = $targetNameSlug !== "" && ($nameSlug === $targetNameSlug || $slug === $targetNameSlug);

    if ($matchesId || $matchesSlug || $matchesName) {
      $rootIds[] = $id;
    }
  }

  $rootIds = array_values(array_unique($rootIds));
  if (!$rootIds) return [];

  $ids = $rootIds;
  $queue = $rootIds;
  $seen = array_fill_keys($rootIds, true);

  while ($queue) {
    $current = array_shift($queue);
    $kids = $childrenByParent[$current] ?? [];

    foreach ($kids as $kid) {
      if (isset($seen[$kid])) continue;

      $seen[$kid] = true;
      $ids[] = $kid;
      $queue[] = $kid;
    }
  }

  return array_values(array_unique($ids));
}

function commerce_resolve_category_ids_products(
  array $cfg,
  string $appSlug,
  string $categorySlug,
  ?string $categoryName = null,
  ?string $categoryId = null
): array {
  $env = product_market_category_env($appSlug, $categorySlug);
  $categoryName = is_string($categoryName) && trim($categoryName) !== ""
    ? trim($categoryName)
    : (string)($env["name"] ?? "");
  $categoryId = is_string($categoryId) && trim($categoryId) !== ""
    ? trim($categoryId)
    : (string)($env["id"] ?? "");

  try {
    $ids = commerce_category_ids_from_db_products($appSlug, $categorySlug, $categoryName, $categoryId);
    if (count($ids) > 0) return $ids;
  } catch (Throwable $e) {
    // fallback below
  }

  $ids = [];
  if ($categoryId !== "") $ids[] = $categoryId;
  $ids = array_merge($ids, commerce_category_ids_from_cfg_products($cfg, $categorySlug));

  return array_values(array_unique(array_filter($ids, function ($v) {
    return is_string($v) && trim($v) !== "";
  })));
}

function load_category_meta_map(string $appSlug): array {
  $out = [];

  try {
    $stmt = db()->prepare("
      SELECT
        square_category_id,
        name,
      slug,
      parent_square_category_id
    FROM spd_square_categories
    WHERE app_slug = :app
      AND is_deleted = 0
    ");

    $stmt->execute([":app" => $appSlug]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($rows as $r) {
      $id = trim((string)($r["square_category_id"] ?? ""));
      if ($id === "") continue;

      $parent = trim((string)($r["parent_square_category_id"] ?? ""));

      $out[$id] = [
        "id" => $id,
        "name" => (string)($r["name"] ?? ""),
        "slug" => (string)($r["slug"] ?? ""),
        "parent_id" => $parent !== "" ? $parent : null,
      ];
    }
  } catch (Throwable $e) {
    // non-fatal
  }

  return $out;
}

function load_product_images_map(string $appSlug, array $itemIds): array {
  $itemIds = array_values(array_unique(array_filter(array_map(function ($v) {
    return is_string($v) ? trim($v) : "";
  }, $itemIds))));

  if (!$itemIds) return [];

  $placeholders = implode(",", array_fill(0, count($itemIds), "?"));
  $params = array_merge([$appSlug], $itemIds);

  $sql = "
    SELECT
      square_item_id,
      square_image_id,
      image_url,
      alt_text,
      sort,
      is_primary
    FROM spd_square_product_images
    WHERE app_slug = ?
      AND square_item_id IN ($placeholders)
    ORDER BY square_item_id ASC, is_primary DESC, sort ASC, square_image_id ASC
  ";

  $map = [];

  try {
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($rows as $r) {
      $itemId = trim((string)($r["square_item_id"] ?? ""));
      if ($itemId === "") continue;

      if (!isset($map[$itemId])) $map[$itemId] = [];

      $map[$itemId][] = [
        "image_id" => trim((string)($r["square_image_id"] ?? "")),
        "image_url" => ($r["image_url"] ?? null) !== null ? trim((string)$r["image_url"]) : null,
        "alt_text" => ($r["alt_text"] ?? null) !== null ? trim((string)$r["alt_text"]) : null,
        "sort" => (int)($r["sort"] ?? 0),
        "is_primary" => (int)($r["is_primary"] ?? 0) === 1,
      ];
    }
  } catch (Throwable $e) {
    // non-fatal
  }

  return $map;
}

function load_product_variations_map(string $appSlug, array $itemIds): array {
  $itemIds = array_values(array_unique(array_filter(array_map(function ($v) {
    return is_string($v) ? trim($v) : "";
  }, $itemIds))));

  if (!$itemIds) return [];

  $placeholders = implode(",", array_fill(0, count($itemIds), "?"));
  $params = array_merge([$appSlug], $itemIds);

  $sql = "
    SELECT
      square_item_id,
      square_variation_id,
      name,
      sku,
      upc,
      price_amount,
      currency_code,
      sort_order,
      is_deleted,
      is_active
    FROM spd_square_product_variations
    WHERE app_slug = ?
      AND square_item_id IN ($placeholders)
    ORDER BY square_item_id ASC, sort_order ASC, name ASC, square_variation_id ASC
  ";

  $map = [];

  try {
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($rows as $r) {
      $itemId = trim((string)($r["square_item_id"] ?? ""));
      $variationId = trim((string)($r["square_variation_id"] ?? ""));
      if ($itemId === "" || $variationId === "") continue;

      if (!isset($map[$itemId])) $map[$itemId] = [];

      $amount = $r["price_amount"] ?? null;
      $currency = $r["currency_code"] ?? null;
      $priceMoney = null;

      if ((is_int($amount) || ctype_digit((string)$amount)) && is_string($currency) && trim($currency) !== "") {
        $priceMoney = [
          "amount" => (int)$amount,
          "currency" => trim((string)$currency),
        ];
      }

      $map[$itemId][] = [
        "id" => $variationId,
        "variation_id" => $variationId,
        "name" => (string)($r["name"] ?? ""),
        "sku" => ($r["sku"] ?? null) !== null ? (string)$r["sku"] : null,
        "upc" => ($r["upc"] ?? null) !== null ? (string)$r["upc"] : null,
        "price_money" => $priceMoney,
        "has_price" => $priceMoney !== null,
        "sort_order" => (int)($r["sort_order"] ?? 0),
        "is_deleted" => (int)($r["is_deleted"] ?? 0) === 1,
        "is_active" => (int)($r["is_active"] ?? 0) === 1 && (int)($r["is_deleted"] ?? 0) === 0,
      ];
    }
  } catch (Throwable $e) {
    // Non-fatal. Old deployments can still use top-level variation_id.
  }

  return $map;
}

function load_storefront_product_meta_map(string $appSlug, array $itemIds): array {
  $itemIds = array_values(array_unique(array_filter(array_map(function ($v) {
    return is_string($v) ? trim($v) : "";
  }, $itemIds))));

  if (!$itemIds) return [];

  $placeholders = implode(",", array_fill(0, count($itemIds), "?"));
  $params = array_merge([$appSlug], $itemIds);

  $sql = "
    SELECT
      id,
      square_product_id,
      one_time_enabled,
      subscription_enabled,
      status,
      is_visible
    FROM spd_storefront_products
    WHERE app_slug = ?
      AND square_product_id IN ($placeholders)
  ";

  $map = [];

  try {
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($rows as $r) {
      $itemId = trim((string)($r["square_product_id"] ?? ""));
      if ($itemId === "") continue;

      $map[$itemId] = [
        "storefront_product_id" => (int)($r["id"] ?? 0),
        "one_time_enabled" => (int)($r["one_time_enabled"] ?? 0) === 1,
        "subscription_enabled" => (int)($r["subscription_enabled"] ?? 0) === 1,
        "status" => (string)($r["status"] ?? ""),
        "is_visible" => (int)($r["is_visible"] ?? 0) === 1,
      ];
    }
  } catch (Throwable $e) {
    // Non-fatal. Square-only products still expose one-time purchase options.
  }

  return $map;
}

function load_subscription_options_map(string $appSlug, array $storefrontProductIds): array {
  $storefrontProductIds = array_values(array_unique(array_filter(array_map(function ($v) {
    return is_int($v) ? $v : (ctype_digit((string)$v) ? (int)$v : 0);
  }, $storefrontProductIds))));

  if (!$storefrontProductIds) return [];

  $placeholders = implode(",", array_fill(0, count($storefrontProductIds), "?"));
  $params = array_merge([$appSlug], $storefrontProductIds);

  $sql = "
    SELECT
      storefront_product_id,
      option_key,
      label,
      description,
      badge,
      square_plan_variation_id,
      price_amount,
      price_currency,
      compare_at_amount,
      compare_at_currency,
      discount_percent,
      enabled,
      sort_order
    FROM spd_storefront_product_subscription_options
    WHERE app_slug = ?
      AND storefront_product_id IN ($placeholders)
      AND enabled = 1
    ORDER BY storefront_product_id ASC, sort_order ASC, id ASC
  ";

  $map = [];

  try {
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($rows as $r) {
      $storefrontProductId = (int)($r["storefront_product_id"] ?? 0);
      if ($storefrontProductId <= 0) continue;

      if (!isset($map[$storefrontProductId])) $map[$storefrontProductId] = [];
      $map[$storefrontProductId][] = build_subscription_purchase_option_for_products($r, false);
    }
  } catch (Throwable $e) {
    // Non-fatal. Product lists remain backwards-compatible.
  }

  return $map;
}

function load_product_categories_map(string $appSlug, array $itemIds, array $categoryMetaMap): array {
  $itemIds = array_values(array_unique(array_filter(array_map(function ($v) {
    return is_string($v) ? trim($v) : "";
  }, $itemIds))));

  if (!$itemIds) return [];

  $placeholders = implode(",", array_fill(0, count($itemIds), "?"));
  $params = array_merge([$appSlug], $itemIds);

  $sql = "
    SELECT
      square_item_id,
      square_category_id,
      is_primary
    FROM spd_square_product_categories
    WHERE app_slug = ?
      AND square_item_id IN ($placeholders)
    ORDER BY square_item_id ASC, is_primary DESC, square_category_id ASC
  ";

  $map = [];

  try {
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($rows as $r) {
      $itemId = trim((string)($r["square_item_id"] ?? ""));
      $catId = trim((string)($r["square_category_id"] ?? ""));

      if ($itemId === "" || $catId === "") continue;

      if (!isset($map[$itemId])) $map[$itemId] = [];

      $meta = $categoryMetaMap[$catId] ?? [
        "id" => $catId,
        "name" => "",
        "slug" => "",
        "parent_id" => null,
      ];

      $map[$itemId][] = [
        "id" => $meta["id"],
        "slug" => $meta["slug"],
        "name" => $meta["name"],
        "parent_id" => $meta["parent_id"],
        "is_primary" => (int)($r["is_primary"] ?? 0) === 1,
      ];
    }
  } catch (Throwable $e) {
    // non-fatal
  }

  return $map;
}

/* Tenant + CORS */
$appSlug = commerce_get_app_slug();
if (!$appSlug) json_fail("app_slug is required", 400);

$cfg = commerce_load_app_config($appSlug);
if (!$cfg) {
  json_fail("Unknown app_slug", 400, ["app_slug" => $appSlug]);
}

commerce_apply_cors($cfg);

/* Method */
$method = strtoupper((string)($_SERVER["REQUEST_METHOD"] ?? "GET"));

if ($method === "OPTIONS") {
  http_response_code(204);
  exit;
}

if ($method !== "GET") {
  json_fail("Method Not Allowed", 405, ["method" => $method]);
}

/* Inputs */
$limit = (int)($_GET["limit"] ?? 24);
if ($limit <= 0) $limit = 24;
if ($limit > 120) $limit = 120;

$categorySlug = $_GET["category"] ?? null;
$categorySlug = is_string($categorySlug) ? trim($categorySlug) : null;
if ($categorySlug === "") $categorySlug = null;

$categorySlugFilter = $_GET["category_slug"] ?? null;
$categorySlugFilter = is_string($categorySlugFilter) ? trim($categorySlugFilter) : null;
if ($categorySlugFilter === "") $categorySlugFilter = null;

$categoryNameFilter = $_GET["category_name"] ?? null;
$categoryNameFilter = is_string($categoryNameFilter) ? trim($categoryNameFilter) : null;
if ($categoryNameFilter === "") $categoryNameFilter = null;

$categoryIdFilter = $_GET["category_id"] ?? null;
$categoryIdFilter = is_string($categoryIdFilter) ? trim($categoryIdFilter) : null;
if ($categoryIdFilter === "") $categoryIdFilter = null;

$effectiveCategorySlug = normalize_slug($categorySlugFilter ?: $categorySlug ?: $categoryNameFilter ?: $categoryIdFilter);
$effectiveCategoryName = $categoryNameFilter;
$effectiveCategoryId = $categoryIdFilter;
$syncDb = ((int)($_GET["sync_db"] ?? 0) === 1);

/* Optional Square sync */
$syncReport = [
  "attempted" => $syncDb,
  "ok" => true,
  "fetched" => 0,
  "category_search_fetched" => 0,
  "category_search_items" => [],
  "upserted" => 0,
  "variations_upserted" => 0,
  "category_links_upserted" => 0,
  "images_upserted" => 0,
  "item_errors" => 0,
  "item_error_samples" => [],
  "error" => null,
];

if ($syncDb) {
  $syncReport["ok"] = false;

  try {
    $cursor = null;
    $loops = 0;
    $objects = [];

    do {
      $loops++;
      if ($loops > 50) break;

      $path = "/catalog/list?types=ITEM";
      if (is_string($cursor) && $cursor !== "") {
        $path .= "&cursor=" . rawurlencode($cursor);
      }

      $resp = square_try_products($cfg, "GET", [
        $path,
        ltrim($path, "/"),
        "/v2" . $path,
        "v2" . $path,
      ]);

      $objs = $resp["objects"] ?? [];
      if (is_array($objs)) {
        foreach ($objs as $o) {
          if (!is_array($o)) continue;
          if (($o["type"] ?? "") !== "ITEM") continue;
          $objects[] = $o;
        }
      }

      $cursor = $resp["cursor"] ?? null;
    } while (is_string($cursor) && $cursor !== "");

    $syncReport["fetched"] = count($objects);

    $explicitCategoryIdsByItemId = [];
    if ($effectiveCategorySlug !== null) {
      try {
        $syncCategoryIds = commerce_resolve_category_ids_products(
          $cfg,
          (string)($cfg["app_slug"] ?? $appSlug),
          $effectiveCategorySlug,
          $effectiveCategoryName,
          $effectiveCategoryId
        );

        if (count($syncCategoryIds) > 0) {
          $searchResp = square_try_products($cfg, "POST", [
            "/catalog/search-catalog-items",
            "catalog/search-catalog-items",
            "/v2/catalog/search-catalog-items",
            "v2/catalog/search-catalog-items",
          ], [
            "category_ids" => array_values($syncCategoryIds),
            "archived_state" => "ARCHIVED_STATE_NOT_ARCHIVED",
            "limit" => 100,
            "sort_order" => "ASC",
          ]);

          $searchedItems = $searchResp["items"] ?? [];
          if (is_array($searchedItems)) {
            $seenObjectIds = [];
            foreach ($objects as $existingObj) {
              if (is_array($existingObj) && is_string($existingObj["id"] ?? null)) {
                $seenObjectIds[$existingObj["id"]] = true;
              }
            }

            $searchedItemIds = [];
            foreach ($searchedItems as $searchedItem) {
              if (!is_array($searchedItem)) continue;
              if (($searchedItem["type"] ?? "") !== "ITEM") continue;

              $searchedItemId = trim((string)($searchedItem["id"] ?? ""));
              if ($searchedItemId === "") continue;
              $searchedItemIds[] = $searchedItemId;

              if (count($syncReport["category_search_items"]) < 5) {
                $searchedData = $searchedItem["item_data"] ?? [];
                $syncReport["category_search_items"][] = [
                  "id" => $searchedItemId,
                  "name" => product_db_name(is_array($searchedData) && isset($searchedData["name"]) ? (string)$searchedData["name"] : "") ?? "",
                ];
              }

              $explicitCategoryIdsByItemId[$searchedItemId] = array_values($syncCategoryIds);
            }

            $itemsToMerge = $searchedItems;
            $searchedItemIds = array_values(array_unique($searchedItemIds));

            if (count($searchedItemIds) > 0) {
              try {
                $batchResp = square_try_products($cfg, "POST", [
                  "/catalog/batch-retrieve",
                  "catalog/batch-retrieve",
                  "/v2/catalog/batch-retrieve",
                  "v2/catalog/batch-retrieve",
                ], [
                  "object_ids" => $searchedItemIds,
                  "include_related_objects" => false,
                ]);

                $batchObjects = $batchResp["objects"] ?? [];
                if (is_array($batchObjects) && count($batchObjects) > 0) {
                  $itemsToMerge = $batchObjects;
                }
              } catch (Throwable $e) {
                // Fall back to the search result objects.
              }
            }

            foreach ($itemsToMerge as $searchedItem) {
              if (!is_array($searchedItem)) continue;
              if (($searchedItem["type"] ?? "") !== "ITEM") continue;

              $searchedItemId = trim((string)($searchedItem["id"] ?? ""));
              if ($searchedItemId === "") continue;

              if (!isset($seenObjectIds[$searchedItemId])) {
                $objects[] = $searchedItem;
                $seenObjectIds[$searchedItemId] = true;
              }
            }

            $syncReport["category_search_fetched"] = count($searchedItems);
          }
        }
      } catch (Throwable $e) {
        // Non-fatal. Catalog list sync still handles normal category links.
      }
    }

    $pdo = db();

    $sqlProduct = "
      INSERT INTO spd_square_products
      (
        app_slug,
        square_item_id,
        square_variation_id,
        slug,
        name,
        description,
        sku,
        upc,
        price_amount,
        currency_code,
        primary_image_url,
        is_featured,
        sort,
        is_deleted,
        is_active,
        raw_json,
        created_at,
        updated_at
      )
      VALUES
      (
        :app_slug,
        :square_item_id,
        :square_variation_id,
        :slug,
        :name,
        :description,
        :sku,
        :upc,
        :price_amount,
        :currency_code,
        :primary_image_url,
        :is_featured,
        :sort,
        :is_deleted,
        :is_active,
        :raw_json,
        NOW(),
        NOW()
      )
      ON DUPLICATE KEY UPDATE
        square_variation_id = VALUES(square_variation_id),
        name = VALUES(name),
        description = VALUES(description),
        sku = VALUES(sku),
        upc = VALUES(upc),
        price_amount = VALUES(price_amount),
        currency_code = VALUES(currency_code),
        primary_image_url = VALUES(primary_image_url),
        is_deleted = VALUES(is_deleted),
        is_active = VALUES(is_active),
        raw_json = VALUES(raw_json),
        updated_at = NOW()
    ";

    $sqlLink = "
      INSERT INTO spd_square_product_categories
      (
        app_slug,
        square_item_id,
        square_category_id,
        is_primary,
        created_at,
        updated_at
      )
      VALUES
      (
        :app_slug,
        :square_item_id,
        :square_category_id,
        :is_primary,
        NOW(),
        NOW()
      )
      ON DUPLICATE KEY UPDATE
        is_primary = VALUES(is_primary),
        updated_at = NOW()
    ";

    $sqlImage = "
      INSERT INTO spd_square_product_images
      (
        app_slug,
        square_item_id,
        square_image_id,
        image_url,
        alt_text,
        sort,
        is_primary,
        raw_json,
        created_at,
        updated_at
      )
      VALUES
      (
        :app_slug,
        :square_item_id,
        :square_image_id,
        :image_url,
        :alt_text,
        :sort,
        :is_primary,
        :raw_json,
        NOW(),
        NOW()
      )
      ON DUPLICATE KEY UPDATE
        image_url = VALUES(image_url),
        alt_text = VALUES(alt_text),
        sort = VALUES(sort),
        is_primary = VALUES(is_primary),
        raw_json = VALUES(raw_json),
        updated_at = NOW()
    ";

    $sqlVariation = "
      INSERT INTO spd_square_product_variations
      (
        app_slug,
        square_item_id,
        square_variation_id,
        name,
        sku,
        upc,
        price_amount,
        currency_code,
        sort_order,
        is_deleted,
        is_active,
        raw_json,
        created_at,
        updated_at
      )
      VALUES
      (
        :app_slug,
        :square_item_id,
        :square_variation_id,
        :name,
        :sku,
        :upc,
        :price_amount,
        :currency_code,
        :sort_order,
        :is_deleted,
        :is_active,
        :raw_json,
        NOW(),
        NOW()
      )
      ON DUPLICATE KEY UPDATE
        name = VALUES(name),
        sku = VALUES(sku),
        upc = VALUES(upc),
        price_amount = VALUES(price_amount),
        currency_code = VALUES(currency_code),
        sort_order = VALUES(sort_order),
        is_deleted = VALUES(is_deleted),
        is_active = VALUES(is_active),
        raw_json = VALUES(raw_json),
        updated_at = NOW()
    ";

    $stmtProduct = $pdo->prepare($sqlProduct);
    $stmtLink = $pdo->prepare($sqlLink);
    $stmtImage = $pdo->prepare($sqlImage);
    $stmtVariation = $pdo->prepare($sqlVariation);

    $allImageIds = [];

    foreach ($objects as $obj) {
      if (!is_array($obj)) continue;
      if (!empty($obj["is_deleted"])) continue;
      if (!empty($obj["is_archived"])) continue;

      $item = $obj["item_data"] ?? null;
      if (!is_array($item)) continue;

      $imageIds = is_array($item["image_ids"] ?? null) ? $item["image_ids"] : [];
      foreach ($imageIds as $iid) {
        if (is_string($iid) && trim($iid) !== "") {
          $allImageIds[trim($iid)] = true;
        }
      }
    }

    $imageMap = [];
    $imageUrlMap = [];

    $imageIdList = array_values(array_keys($allImageIds));
    if (count($imageIdList) > 0) {
      $chunks = array_chunk($imageIdList, 250);

      foreach ($chunks as $chunk) {
        $imgResp = square_try_products($cfg, "POST", [
          "/catalog/batch-retrieve",
          "catalog/batch-retrieve",
          "/v2/catalog/batch-retrieve",
          "v2/catalog/batch-retrieve",
        ], [
          "object_ids" => $chunk,
          "include_related_objects" => false,
        ]);

        $imgObjs = $imgResp["objects"] ?? [];
        if (!is_array($imgObjs)) $imgObjs = [];

        foreach ($imgObjs as $imgObj) {
          if (!is_array($imgObj)) continue;
          if (($imgObj["type"] ?? "") !== "IMAGE") continue;

          $iid = trim((string)($imgObj["id"] ?? ""));
          if ($iid === "") continue;

          $url = trim((string)($imgObj["image_data"]["url"] ?? ""));
          if ($url === "") continue;

          $imageMap[$iid] = $imgObj;
          $imageUrlMap[$iid] = $url;
        }
      }
    }

    foreach ($objects as $obj) {
      if (!is_array($obj)) continue;
      if (!empty($obj["is_deleted"])) continue;
      if (!empty($obj["is_archived"])) continue;

      $itemId = trim((string)($obj["id"] ?? ""));
      $item = $obj["item_data"] ?? null;

      if ($itemId === "" || !is_array($item)) continue;

      try {
      $name = product_db_name(isset($item["name"]) ? (string)$item["name"] : null) ?? "";
      if ($name === "") continue;

      $description = product_db_text(isset($item["description"]) ? (string)$item["description"] : null);

      $slug = product_slugify($name);
      $best = extract_best_variation_from_square_item($obj);

      $variationId = $best["variation_id"];
      $bestAmount = $best["amount"];
      $bestCurrency = $best["currency"];
      $sku = $best["sku"];
      $upc = $best["upc"];

      $pdo->prepare("
        DELETE FROM spd_square_product_variations
        WHERE app_slug = :app
          AND square_item_id = :item
      ")->execute([
        ":app" => $appSlug,
        ":item" => $itemId,
      ]);

      foreach (extract_variations_from_square_item($obj) as $variation) {
        $stmtVariation->execute([
          ":app_slug" => $appSlug,
          ":square_item_id" => $itemId,
          ":square_variation_id" => $variation["square_variation_id"],
          ":name" => $variation["name"],
          ":sku" => $variation["sku"],
          ":upc" => $variation["upc"],
          ":price_amount" => $variation["price_amount"],
          ":currency_code" => $variation["currency_code"],
          ":sort_order" => $variation["sort_order"],
          ":is_deleted" => $variation["is_deleted"],
          ":is_active" => $variation["is_active"],
          ":raw_json" => $variation["raw_json"],
        ]);

        $syncReport["variations_upserted"]++;
      }

      if (
        !is_string($variationId) ||
        trim($variationId) === "" ||
        $variationId === $itemId ||
        $bestAmount === null ||
        $bestCurrency === null
      ) {
        continue;
      }

      $imageIds = is_array($item["image_ids"] ?? null) ? $item["image_ids"] : [];
      $primaryImageId = isset($imageIds[0]) && is_string($imageIds[0]) ? trim($imageIds[0]) : null;
      $primaryImageUrl = ($primaryImageId && isset($imageUrlMap[$primaryImageId]))
        ? $imageUrlMap[$primaryImageId]
        : null;

      $stmtProduct->execute([
        ":app_slug" => $appSlug,
        ":square_item_id" => $itemId,
        ":square_variation_id" => $variationId,
        ":slug" => $slug,
        ":name" => $name,
        ":description" => $description,
        ":sku" => $sku,
        ":upc" => $upc,
        ":price_amount" => $bestAmount,
        ":currency_code" => $bestCurrency,
        ":primary_image_url" => $primaryImageUrl,
        ":is_featured" => 0,
        ":sort" => 100,
        ":is_deleted" => 0,
        ":is_active" => 1,
        ":raw_json" => json_encode($obj, JSON_UNESCAPED_SLASHES),
      ]);

      $syncReport["upserted"]++;

      $pdo->prepare("
        DELETE FROM spd_square_product_categories
        WHERE app_slug = :app
          AND square_item_id = :item
      ")->execute([
        ":app" => $appSlug,
        ":item" => $itemId,
      ]);

      $categoryLinkIds = array_merge(
        extract_category_ids_from_square_item_data($item),
        $explicitCategoryIdsByItemId[$itemId] ?? []
      );
      $categoryLinkIds = array_values(array_unique(array_filter($categoryLinkIds, function ($v) {
        return is_string($v) && trim($v) !== "";
      })));
      $primaryDone = false;

      foreach ($categoryLinkIds as $cid) {
        $stmtLink->execute([
          ":app_slug" => $appSlug,
          ":square_item_id" => $itemId,
          ":square_category_id" => $cid,
          ":is_primary" => $primaryDone ? 0 : 1,
        ]);

        $primaryDone = true;
        $syncReport["category_links_upserted"]++;
      }

      $pdo->prepare("
        DELETE FROM spd_square_product_images
        WHERE app_slug = :app
          AND square_item_id = :item
      ")->execute([
        ":app" => $appSlug,
        ":item" => $itemId,
      ]);

      foreach ($imageIds as $idx => $iidRaw) {
        $iid = is_string($iidRaw) ? trim($iidRaw) : "";
        if ($iid === "") continue;
        if (!isset($imageUrlMap[$iid])) continue;

        $imgObj = $imageMap[$iid] ?? null;
        $imgUrl = $imageUrlMap[$iid];
        $altText = null;

        if (is_array($imgObj)) {
          $candidateAlt = $imgObj["image_data"]["caption"] ?? null;
          $altText = product_db_text(is_string($candidateAlt) ? $candidateAlt : null);
        }

        $stmtImage->execute([
          ":app_slug" => $appSlug,
          ":square_item_id" => $itemId,
          ":square_image_id" => $iid,
          ":image_url" => $imgUrl,
          ":alt_text" => $altText,
          ":sort" => $idx + 1,
          ":is_primary" => ($idx === 0 ? 1 : 0),
          ":raw_json" => is_array($imgObj) ? json_encode($imgObj, JSON_UNESCAPED_SLASHES) : null,
        ]);

        $syncReport["images_upserted"]++;
      }
      } catch (Throwable $itemSyncError) {
        $syncReport["item_errors"]++;
        if (count($syncReport["item_error_samples"]) < 3) {
          $rawName = isset($item["name"]) ? (string)$item["name"] : "";
          $syncReport["item_error_samples"][] = [
            "item_id" => $itemId,
            "name" => product_db_name($rawName) ?? "",
            "error" => $itemSyncError->getMessage(),
          ];
        }
      }
    }

    $syncReport["ok"] = true;
  } catch (Throwable $e) {
    $syncReport["error"] = $e->getMessage();
  }
}

/* Resolve category IDs */
$categoryIds = [];

if ($effectiveCategorySlug !== null) {
  $effectiveApp = (string)($cfg["app_slug"] ?? $appSlug);
  $categoryIds = commerce_resolve_category_ids_products(
    $cfg,
    $effectiveApp,
    $effectiveCategorySlug,
    $effectiveCategoryName,
    $effectiveCategoryId
  );

  if (count($categoryIds) === 0) {
    json_fail("Unknown category (no mapping in DB or category_map)", 400, [
      "category" => $effectiveCategorySlug,
      "category_name" => $effectiveCategoryName,
      "category_id" => $effectiveCategoryId,
      "hint" => "Sync categories into spd_square_categories or set category_map in spd_commerce_apps.",
    ]);
  }
}

/* Query DB */
try {
  if ($effectiveCategorySlug !== null && count($categoryIds) > 0) {
    $placeholders = implode(",", array_fill(0, count($categoryIds), "?"));

    $sql = "
      SELECT DISTINCT
        p.square_item_id AS id,
        p.name,
        p.description,
        p.primary_image_url AS image_url,
        p.square_variation_id AS variation_id,
        p.price_amount,
        p.currency_code,
        p.is_active,
        p.is_deleted,
        p.raw_json
      FROM spd_square_products p
      INNER JOIN spd_square_product_categories pc
        ON pc.app_slug = p.app_slug
       AND pc.square_item_id = p.square_item_id
      WHERE p.app_slug = ?
        AND p.is_active = 1
        AND p.is_deleted = 0
        AND pc.square_category_id IN ($placeholders)
      ORDER BY p.is_featured DESC, p.sort ASC, p.name ASC
      LIMIT ?
    ";

    $params = array_merge([$appSlug], $categoryIds, [$limit]);
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
  } else {
    $sql = "
      SELECT
        square_item_id AS id,
        name,
        description,
        primary_image_url AS image_url,
        square_variation_id AS variation_id,
        price_amount,
        currency_code,
        is_active,
        is_deleted,
        raw_json
      FROM spd_square_products
      WHERE app_slug = ?
        AND is_active = 1
        AND is_deleted = 0
      ORDER BY is_featured DESC, sort ASC, name ASC
      LIMIT ?
    ";

    $stmt = db()->prepare($sql);
    $stmt->execute([$appSlug, $limit]);
  }

  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

  if (!$rows && $effectiveCategorySlug !== null && count($categoryIds) > 0) {
    try {
      $rows = square_category_fallback_rows($cfg, square_category_item_objects($cfg, $categoryIds));
    } catch (Throwable $e) {
      $rows = [];
    }
  }
} catch (Throwable $e) {
  json_fail("Product query failed", 500, [
    "details" => $e->getMessage(),
    "category" => $effectiveCategorySlug,
    "category_ids" => $categoryIds,
  ]);
}

/* Enrich response */
$itemIds = array_values(array_filter(array_map(function ($r) {
  return isset($r["id"]) ? trim((string)$r["id"]) : "";
}, $rows)));

$categoryMetaMap = load_category_meta_map($appSlug);
$productCategoriesMap = load_product_categories_map($appSlug, $itemIds, $categoryMetaMap);
$productImagesMap = load_product_images_map($appSlug, $itemIds);
$productVariationsMap = load_product_variations_map($appSlug, $itemIds);
$storefrontProductMetaMap = load_storefront_product_meta_map($appSlug, $itemIds);
$storefrontProductIds = [];
foreach ($storefrontProductMetaMap as $meta) {
  $storefrontProductId = (int)($meta["storefront_product_id"] ?? 0);
  if ($storefrontProductId > 0) $storefrontProductIds[] = $storefrontProductId;
}
$subscriptionOptionsMap = load_subscription_options_map($appSlug, $storefrontProductIds);

$normalized = [];

foreach ($rows as $r) {
  $itemId = trim((string)($r["id"] ?? ""));
  if ($itemId === "") continue;

  $amount = $r["price_amount"] ?? null;
  $currency = $r["currency_code"] ?? null;

  $priceMoney = null;
  if ((is_int($amount) || ctype_digit((string)$amount)) && is_string($currency) && trim($currency) !== "") {
    $priceMoney = [
      "amount" => (int)$amount,
      "currency" => trim((string)$currency),
    ];
  }

  $variationId = resolve_variation_id_for_response(
    ($r["variation_id"] ?? null) !== null ? (string)$r["variation_id"] : null,
    $itemId,
    ($r["raw_json"] ?? null) !== null ? (string)$r["raw_json"] : null
  );

  $variations = $productVariationsMap[$itemId] ?? [];
  if (!$variations && ($r["raw_json"] ?? null) !== null) {
    $decoded = json_decode((string)$r["raw_json"], true);
    if (is_array($decoded)) {
      foreach (extract_variations_from_square_item($decoded) as $v) {
        $pm = null;
        if ($v["price_amount"] !== null && $v["currency_code"] !== null) {
          $pm = [
            "amount" => (int)$v["price_amount"],
            "currency" => (string)$v["currency_code"],
          ];
        }

        $variations[] = [
          "id" => $v["square_variation_id"],
          "variation_id" => $v["square_variation_id"],
          "name" => $v["name"],
          "sku" => $v["sku"],
          "upc" => $v["upc"],
          "price_money" => $pm,
          "has_price" => $pm !== null,
          "sort_order" => (int)$v["sort_order"],
          "is_deleted" => (int)$v["is_deleted"] === 1,
          "is_active" => (int)$v["is_active"] === 1 && (int)$v["is_deleted"] === 0,
        ];
      }
    }
  }

  $chosen = choose_response_variation($variations, $variationId, $priceMoney);
  $variationId = $chosen["variation_id"];
  $priceMoney = $chosen["price_money"];

  $storefrontMeta = $storefrontProductMetaMap[$itemId] ?? null;
  $storefrontProductId = is_array($storefrontMeta) ? (int)($storefrontMeta["storefront_product_id"] ?? 0) : 0;
  $storefrontOnline = !is_array($storefrontMeta) || (
    ($storefrontMeta["status"] ?? "") === "active" &&
    !empty($storefrontMeta["is_visible"])
  );

  $oneTimeEnabled = is_array($storefrontMeta)
    ? !empty($storefrontMeta["one_time_enabled"])
    : true;

  $oneTimeEnabled =
    $storefrontOnline &&
    $oneTimeEnabled &&
    $variationId !== null &&
    $variationId !== "" &&
    $variationId !== $itemId;

  $subscriptionOptions = $storefrontProductId > 0
    ? ($subscriptionOptionsMap[$storefrontProductId] ?? [])
    : [];

  $purchaseOptions = [];
  $oneTimePurchaseOption = build_one_time_purchase_option_for_products(
    $oneTimeEnabled,
    $variationId,
    $priceMoney,
    $variations,
    true
  );

  if ($oneTimePurchaseOption !== null) {
    $purchaseOptions[] = $oneTimePurchaseOption;
  }

  if ($storefrontOnline && (!is_array($storefrontMeta) || !empty($storefrontMeta["subscription_enabled"]))) {
    foreach ($subscriptionOptions as $subscriptionOption) {
      if (empty($subscriptionOption["enabled"])) continue;
      $purchaseOptions[] = $subscriptionOption;
    }
  }

  if (count($purchaseOptions) > 0 && !array_filter($purchaseOptions, function ($option) {
    return !empty($option["selected_by_default"]);
  })) {
    $purchaseOptions[0]["selected_by_default"] = true;
  }

  $categories = $productCategoriesMap[$itemId] ?? [];
  $categories = array_values(array_map(function ($c) {
    return [
      "id" => $c["id"],
      "slug" => $c["slug"],
      "name" => $c["name"],
      "parent_id" => $c["parent_id"],
    ];
  }, $categories));

  $categoryIdsForItem = [];
  $categorySlugsForItem = [];

  foreach ($categories as $c) {
    if (is_string($c["id"]) && $c["id"] !== "") $categoryIdsForItem[] = $c["id"];
    if (is_string($c["slug"]) && $c["slug"] !== "") $categorySlugsForItem[] = $c["slug"];
  }

  $categoryIdsForItem = array_values(array_unique($categoryIdsForItem));
  $categorySlugsForItem = array_values(array_unique($categorySlugsForItem));

  $images = $productImagesMap[$itemId] ?? [];
  $imageIds = [];
  $primaryImageUrl = ($r["image_url"] ?? null) !== null ? (string)$r["image_url"] : null;
  $primaryImageId = null;

  foreach ($images as $img) {
    if (!empty($img["image_id"])) {
      $imageIds[] = $img["image_id"];

      if ($primaryImageId === null && !empty($img["is_primary"])) {
        $primaryImageId = $img["image_id"];
      }
    }

    if ($primaryImageUrl === null && !empty($img["image_url"])) {
      $primaryImageUrl = $img["image_url"];
    }
  }

  if ($primaryImageId === null && count($imageIds) > 0) {
    $primaryImageId = $imageIds[0];
  }

  $isActive = ((int)($r["is_active"] ?? 0) === 1) && ((int)($r["is_deleted"] ?? 0) === 0);

  $normalized[] = [
    "id" => $itemId,
    "name" => (string)($r["name"] ?? ""),
    "description" => (string)($r["description"] ?? ""),
    "image_url" => $primaryImageUrl,
    "primary_image_id" => $primaryImageId,
    "variation_id" => $variationId,
    "price_money" => $priceMoney,
    "has_price" => $priceMoney !== null,
    "variations" => $variations,
    "has_variations" => count($variations) > 1,
    "purchase_options" => $purchaseOptions,
    "image_ids" => $imageIds,

    "category_id" => count($categoryIdsForItem) > 0 ? $categoryIdsForItem[0] : null,
    "category_ids" => $categoryIdsForItem,
    "category_slug" => count($categorySlugsForItem) > 0 ? $categorySlugsForItem[0] : null,
    "category_slugs" => $categorySlugsForItem,
    "categories" => $categories,

    "is_active" => $isActive,
    "active" => $isActive,
    "status" => $isActive ? "active" : "archived",
  ];
}

json_ok([
  "ok" => true,
  "app_slug" => $cfg["app_slug"] ?? $appSlug,
  "mode" => "db-first",
  "category" => $effectiveCategorySlug,
  "category_name" => $effectiveCategoryName,
  "category_ids" => $categoryIds,
  "count" => count($normalized),
  "items" => $normalized,
  "sync_db" => $syncReport,
], 200, [
  "X-Correlation-Id" => $GLOBALS["correlationId"] ?? "",
  "Cache-Control" => "public, max-age=300, s-maxage=300",
]);
