<?php
declare(strict_types=1);

/**
 * /api/v1/routes/public.commerce.services.get.php (FULL DROP-IN, PHP 7.4+ SAFE)
 *
 * GET  /api/v1/index.php?path=public/commerce/services&app_slug=deeper-than-skin
 * GET  ...&include_inactive=1
 * GET  ...&sync_db=1
 */

require_once __DIR__ . "/../../bootstrap/bootstrap.php";
require_once __DIR__ . "/../../bootstrap/commerce_tenant.php";
require_once __DIR__ . "/../../bootstrap/square.php";

if (!headers_sent()) {
  header("Content-Type: application/json; charset=utf-8");
}

/* -------------------------------
   Category / storefront config
-------------------------------- */
const SERVICE_PARENT_CATEGORY = 'services';
const FEATURED_CATEGORY = 'featured';

const SERVICE_TYPE_MAP = [
  'in-studio' => ['in-studio', 'instudio', 'studio'],
  'mobile'    => ['mobile'],
  'in-home'   => ['in-home', 'in home', 'home'],
  'pop-up'    => ['pop-up', 'popup', 'pop up'],
];

const SERVICE_NAME_HINTS = [
  'service',
  'session',
  'consultation',
  'consult',
  'therapy',
  'treatment',
  'footbath',
  'foot bath',
  'detox',
  'facial',
  'wellness',
];

/* -------------------------------
   Helpers
-------------------------------- */
function ci_contains($haystack, $needle): bool {
  return strpos(strtolower((string)$haystack), strtolower((string)$needle)) !== false;
}

function ci_starts_with($haystack, $needle): bool {
  $haystack = strtolower((string)$haystack);
  $needle = strtolower((string)$needle);
  return substr($haystack, 0, strlen($needle)) === $needle;
}

function service_slugify(string $s): string {
  $s = strtolower(trim($s));
  $s = preg_replace('/[\'"`’]/u', '', $s);
  $s = preg_replace('/[^a-z0-9]+/i', '-', $s);
  $s = trim((string)$s, '-');
  return $s !== "" ? $s : "service";
}

function normalize_text($s): string {
  return strtolower(trim((string)$s));
}

function unique_strings(array $items): array {
  $out = [];
  $seen = [];

  foreach ($items as $item) {
    if (!is_scalar($item)) continue;
    $v = trim((string)$item);
    if ($v === '') continue;

    $key = strtolower($v);
    if (isset($seen[$key])) continue;

    $seen[$key] = true;
    $out[] = $v;
  }

  return array_values($out);
}

function safe_json_decode($json): ?array {
  if (!is_string($json) || trim($json) === '') return null;

  $decoded = json_decode($json, true);
  return is_array($decoded) ? $decoded : null;
}

function in_array_ci(string $needle, array $haystack): bool {
  $target = normalize_text($needle);
  foreach ($haystack as $item) {
    if (normalize_text($item) === $target) {
      return true;
    }
  }
  return false;
}

function normalize_square_category_ids($raw): array {
  $out = [];

  if (is_string($raw)) {
    $v = trim($raw);
    if ($v !== '') $out[] = $v;
    return array_values(array_unique($out));
  }

  if (!is_array($raw)) {
    return [];
  }

  foreach ($raw as $item) {
    if (is_string($item)) {
      $v = trim($item);
      if ($v !== '') $out[] = $v;
      continue;
    }

    if (!is_array($item)) continue;

    foreach (['id', 'category_id', 'square_category_id'] as $key) {
      if (isset($item[$key]) && is_string($item[$key])) {
        $v = trim($item[$key]);
        if ($v !== '') $out[] = $v;
        break;
      }
    }
  }

  return array_values(array_unique($out));
}

/**
 * Fallback only
 */
function collect_possible_category_strings($node, array &$bucket, int $depth = 0): void {
  if ($depth > 8) return;

  if (is_string($node)) {
    $v = trim($node);
    if ($v !== '') $bucket[] = $v;
    return;
  }

  if (!is_array($node)) return;

  foreach ($node as $key => $value) {
    $k = strtolower((string)$key);

    $looksCategoryLike =
      $k === 'category' ||
      $k === 'categories' ||
      $k === 'category_id' ||
      $k === 'category_ids' ||
      $k === 'name' ||
      $k === 'path' ||
      $k === 'full_path';

    if ($looksCategoryLike) {
      if (is_string($value)) {
        $v = trim($value);
        if ($v !== '') $bucket[] = $v;
      } elseif (is_array($value)) {
        foreach ($value as $child) {
          collect_possible_category_strings($child, $bucket, $depth + 1);
        }
      }
    } else {
      if (is_array($value)) {
        collect_possible_category_strings($value, $bucket, $depth + 1);
      }
    }
  }
}

function split_category_path(string $value): array {
  $value = trim($value);
  if ($value === '') return [];

  $parts = preg_split('/\s*(?:>|\/|\\\\|\|)\s*/', $value) ?: [];
  $parts = array_map(function ($v) {
    return trim((string)$v);
  }, $parts);
  $parts = array_values(array_filter($parts, function ($v) {
    return $v !== '';
  }));

  return unique_strings($parts);
}

function extract_service_categories_from_raw($raw, array $cfg = []): array {
  $bucket = [];

  if (is_array($raw)) {
    collect_possible_category_strings($raw, $bucket);
  }

  if (isset($cfg['default_service_categories']) && is_array($cfg['default_service_categories'])) {
    foreach ($cfg['default_service_categories'] as $v) {
      if (is_scalar($v)) $bucket[] = (string)$v;
    }
  }

  $expanded = [];
  foreach ($bucket as $entry) {
    $expanded[] = $entry;
    foreach (split_category_path($entry) as $part) {
      $expanded[] = $part;
    }
  }

  return unique_strings($expanded);
}

function service_has_category(array $service, string $category): bool {
  $target = normalize_text($category);
  $categories = $service['categories'] ?? [];
  if (!is_array($categories)) return false;

  foreach ($categories as $c) {
    if (normalize_text($c) === $target) {
      return true;
    }
  }
  return false;
}

function derive_parent_category(array $categories): ?string {
  foreach ($categories as $c) {
    if (normalize_text($c) === SERVICE_PARENT_CATEGORY) {
      return 'Services';
    }
  }

  foreach ($categories as $c) {
    $value = trim((string)$c);
    if ($value !== '' && ci_starts_with($value, 'Services')) {
      return 'Services';
    }
  }

  return null;
}

function derive_service_type_from_categories(array $categories): string {
  $normalized = array_map(function ($v) {
    return normalize_text($v);
  }, $categories);

  foreach (SERVICE_TYPE_MAP as $type => $aliases) {
    foreach ($aliases as $alias) {
      $aliasNorm = normalize_text($alias);
      foreach ($normalized as $cat) {
        if ($cat === $aliasNorm) return $type;
      }
    }
  }

  return 'other';
}

function derive_service_type_fallback(string $name, ?string $subtitle = null, ?string $description = null): string {
  $haystack = strtolower(trim($name . ' ' . ($subtitle ?? '') . ' ' . ($description ?? '')));

  if ($haystack === '') return 'other';

  if (strpos($haystack, 'mobile') !== false) return 'mobile';
  if (strpos($haystack, 'in-home') !== false || strpos($haystack, 'in home') !== false) return 'in-home';
  if (strpos($haystack, 'pop-up') !== false || strpos($haystack, 'popup') !== false || strpos($haystack, 'pop up') !== false) return 'pop-up';
  if (strpos($haystack, 'studio') !== false || strpos($haystack, 'in-studio') !== false || strpos($haystack, 'in studio') !== false) return 'in-studio';

  return 'other';
}

function is_featured_by_rule(array $service, string $featuredSlug = ''): bool {
  $slug = normalize_text($service['slug'] ?? '');

  if ($featuredSlug !== '' && $slug === normalize_text($featuredSlug)) {
    return true;
  }

  if (!empty($service['is_featured'])) {
    return true;
  }

  if (service_has_category($service, FEATURED_CATEGORY)) {
    return true;
  }

  return false;
}

function compare_services(array $a, array $b): int {
  $aFeatured = !empty($a['is_featured']) ? 1 : 0;
  $bFeatured = !empty($b['is_featured']) ? 1 : 0;

  if ($aFeatured !== $bFeatured) {
    return $bFeatured <=> $aFeatured;
  }

  $aSort = (int)($a['sort'] ?? 999);
  $bSort = (int)($b['sort'] ?? 999);
  if ($aSort !== $bSort) {
    return $aSort <=> $bSort;
  }

  return strcmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
}

function item_is_likely_service(string $name, array $categoryNames = []): bool {
  foreach ($categoryNames as $cat) {
    if (normalize_text($cat) === SERVICE_PARENT_CATEGORY) {
      return true;
    }
  }

  $haystack = strtolower(trim($name));
  foreach (SERVICE_NAME_HINTS as $hint) {
    if (strpos($haystack, strtolower($hint)) !== false) {
      return true;
    }
  }

  return false;
}

function get_item_variation_data(array $item): ?array {
  $itemData = $item['item_data'] ?? null;
  if (!is_array($itemData)) return null;

  $variations = $itemData['variations'] ?? [];
  if (!is_array($variations) || count($variations) === 0) return null;

  foreach ($variations as $variation) {
    if (!is_array($variation)) continue;
    $vData = $variation['item_variation_data'] ?? null;
    if (is_array($vData)) return $vData;
  }

  return null;
}

function extract_item_price_amount(?array $variationData): ?int {
  if (!is_array($variationData)) return null;

  $money = $variationData['price_money'] ?? null;
  if (!is_array($money)) return null;

  if (!isset($money['amount'])) return null;
  return (int)$money['amount'];
}

function extract_item_currency_code(?array $variationData): ?string {
  if (!is_array($variationData)) return null;

  $money = $variationData['price_money'] ?? null;
  if (!is_array($money)) return null;

  $currency = trim((string)($money['currency'] ?? ''));
  return $currency !== '' ? $currency : null;
}

/* -------------------------------
   Tenant
-------------------------------- */
$appSlug = commerce_get_app_slug();
if (!$appSlug) fail_json("app_slug is required", 400);

$cfg = commerce_load_app_config($appSlug);
if (!$cfg) fail_json("Unknown app_slug", 400);

commerce_apply_cors($cfg);

/* -------------------------------
   Method handling
-------------------------------- */
$method = strtoupper((string)($_SERVER["REQUEST_METHOD"] ?? "GET"));
if ($method === "OPTIONS") {
  http_response_code(204);
  exit;
}

if ($method !== "GET") {
  fail_json("Method Not Allowed", 405, [
    "path" => "/public/commerce/services",
    "method" => $method,
  ]);
}

/* -------------------------------
   Params
-------------------------------- */
$includeInactive = ((int)($_GET["include_inactive"] ?? 0) === 1);
$syncDb = ((int)($_GET["sync_db"] ?? 0) === 1);

/* -------------------------------
   Optional config overrides
-------------------------------- */
$cfgFeaturedSlug = trim((string)($cfg["featured_service_slug"] ?? ""));

$defaultBookingUrl = trim((string)($cfg["booking_url"] ?? ""));
if ($defaultBookingUrl === "" && isset($cfg["links"]["booking_url"])) {
  $defaultBookingUrl = trim((string)$cfg["links"]["booking_url"]);
}
if ($defaultBookingUrl === "") {
  $defaultBookingUrl = "https://deeper-than-skin-llc.square.site/appointments";
}

$serviceCategoryOverrides = [];
if (isset($cfg['service_category_overrides']) && is_array($cfg['service_category_overrides'])) {
  $serviceCategoryOverrides = $cfg['service_category_overrides'];
}

/* -------------------------------
   Optional: DB sync from Square
-------------------------------- */
$syncReport = [
  "attempted" => $syncDb,
  "ok" => true,
  "fetched" => 0,
  "upserted" => 0,
  "linked" => 0,
  "error" => null,
];

if ($syncDb) {
  $syncReport["ok"] = false;

  try {
    $cursor = null;
    $loops = 0;
    $items = [];

    do {
      $loops++;
      if ($loops > 50) break;

      $path = "/catalog/list?types=ITEM";
      if (is_string($cursor) && $cursor !== "") {
        $path .= "&cursor=" . rawurlencode($cursor);
      }

      $resp = square_request($cfg, "GET", $path);
      $objects = $resp["objects"] ?? [];

      if (is_array($objects)) {
        foreach ($objects as $o) {
          if (!is_array($o)) continue;
          if (($o["type"] ?? "") !== "ITEM") continue;
          $items[] = $o;
        }
      }

      $cursor = $resp["cursor"] ?? null;
    } while (is_string($cursor) && $cursor !== "");

    $syncReport["fetched"] = count($items);

    $pdo = db();

    $pdo->exec("
      CREATE TABLE IF NOT EXISTS spd_square_service_categories (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        app_slug VARCHAR(50) NOT NULL,
        square_service_id VARCHAR(64) NOT NULL,
        square_category_id VARCHAR(64) NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_service_category (app_slug, square_service_id, square_category_id),
        KEY idx_service (square_service_id),
        KEY idx_category (square_category_id)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    $sql = "
      INSERT INTO spd_square_services
        (
          app_slug,
          square_service_id,
          slug,
          name,
          subtitle,
          description,
          image_url,
          booking_url,
          is_featured,
          sort,
          price_amount,
          currency_code,
          is_deleted,
          is_active,
          raw_json,
          created_at,
          updated_at
        )
      VALUES
        (
          :app_slug,
          :square_service_id,
          :slug,
          :name,
          :subtitle,
          :description,
          :image_url,
          :booking_url,
          :is_featured,
          :sort,
          :price_amount,
          :currency_code,
          :is_deleted,
          :is_active,
          :raw_json,
          NOW(),
          NOW()
        )
      ON DUPLICATE KEY UPDATE
        square_service_id = VALUES(square_service_id),
        name = VALUES(name),
        description = VALUES(description),
        price_amount = VALUES(price_amount),
        currency_code = VALUES(currency_code),
        is_deleted = VALUES(is_deleted),
        is_active = VALUES(is_active),
        raw_json = VALUES(raw_json),
        updated_at = NOW()
    ";

    $stmt = $pdo->prepare($sql);

    $linkStmt = $pdo->prepare("
      INSERT IGNORE INTO spd_square_service_categories
        (app_slug, square_service_id, square_category_id)
      VALUES
        (:app_slug, :square_service_id, :square_category_id)
    ");

    $deleteLinksStmt = $pdo->prepare("
      DELETE FROM spd_square_service_categories
      WHERE app_slug = :app_slug
        AND square_service_id = :square_service_id
    ");

    $catStmt = $pdo->prepare("
      SELECT
        square_category_id,
        name,
        parent_square_category_id,
        root_square_category_id
      FROM spd_square_categories
      WHERE app_slug = :app
        AND is_deleted = 0
        AND is_active = 1
    ");
    $catStmt->execute([":app" => $appSlug]);
    $categoryRows = $catStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $categoryById = [];
    foreach ($categoryRows as $catRow) {
      $categoryById[(string)$catRow['square_category_id']] = $catRow;
    }

    foreach ($items as $item) {
      $itemData = $item["item_data"] ?? null;
      if (!is_array($itemData)) continue;

      $squareServiceId = trim((string)($item["id"] ?? ""));
      $name = trim((string)($itemData["name"] ?? ""));
      if ($squareServiceId === "" || $name === "") continue;

      $categoryIds = normalize_square_category_ids($itemData["categories"] ?? []);

      $categoryNames = [];
      $belongsToServices = false;

      foreach ($categoryIds as $cid) {
        if (!is_string($cid)) continue;
        $cid = trim($cid);
        if ($cid === '') continue;
        if (!isset($categoryById[$cid])) continue;

        $catName = trim((string)($categoryById[$cid]['name'] ?? ''));
        if ($catName !== '') $categoryNames[] = $catName;

        $rootId = trim((string)($categoryById[$cid]['root_square_category_id'] ?? ''));
        $parentId = trim((string)($categoryById[$cid]['parent_square_category_id'] ?? ''));

        if (normalize_text($catName) === SERVICE_PARENT_CATEGORY) {
          $belongsToServices = true;
        }

        if ($rootId !== '' && isset($categoryById[$rootId])) {
          $rootName = trim((string)($categoryById[$rootId]['name'] ?? ''));
          if ($rootName !== '') $categoryNames[] = $rootName;
          if (normalize_text($rootName) === SERVICE_PARENT_CATEGORY) {
            $belongsToServices = true;
          }
        }

        if ($parentId !== '' && isset($categoryById[$parentId])) {
          $parentName = trim((string)($categoryById[$parentId]['name'] ?? ''));
          if ($parentName !== '') $categoryNames[] = $parentName;
          if (normalize_text($parentName) === SERVICE_PARENT_CATEGORY) {
            $belongsToServices = true;
          }
        }
      }

      $categoryNames = unique_strings($categoryNames);

      if (!$belongsToServices && !item_is_likely_service($name, $categoryNames)) {
        continue;
      }

      $slug = service_slugify($name);
      $description = isset($itemData["description"]) ? trim((string)$itemData["description"]) : null;
      if ($description === "") $description = null;

      $variationData = get_item_variation_data($item);
      $priceAmount = extract_item_price_amount($variationData);
      $currencyCode = extract_item_currency_code($variationData);

      $lower = strtolower($name);
      $isFeatured = 0;

      if ($cfgFeaturedSlug !== "" && $cfgFeaturedSlug === $slug) {
        $isFeatured = 1;
      } elseif (in_array_ci('Featured', $categoryNames)) {
        $isFeatured = 1;
      } elseif (strpos($lower, "ionic") !== false && (strpos($lower, "footbath") !== false || strpos($lower, "foot bath") !== false)) {
        $isFeatured = 1;
      }

      $sort = $isFeatured ? 10 : 100;
      $isDeleted = !empty($item["is_deleted"]) ? 1 : 0;
      $isActive = $isDeleted ? 0 : 1;

      $stmt->execute([
        ":app_slug"          => $appSlug,
        ":square_service_id" => $squareServiceId,
        ":slug"              => $slug,
        ":name"              => $name,
        ":subtitle"          => null,
        ":description"       => $description,
        ":image_url"         => null,
        ":booking_url"       => $defaultBookingUrl,
        ":is_featured"       => $isFeatured,
        ":sort"              => $sort,
        ":price_amount"      => $priceAmount,
        ":currency_code"     => $currencyCode,
        ":is_deleted"        => $isDeleted,
        ":is_active"         => $isActive,
        ":raw_json"          => json_encode($item, JSON_UNESCAPED_SLASHES),
      ]);

      $deleteLinksStmt->execute([
        ":app_slug"          => $appSlug,
        ":square_service_id" => $squareServiceId,
      ]);

      foreach ($categoryIds as $cid) {
        if (!is_string($cid)) continue;
        $cid = trim($cid);
        if ($cid === '') continue;

        $linkStmt->execute([
          ":app_slug"           => $appSlug,
          ":square_service_id"  => $squareServiceId,
          ":square_category_id" => $cid,
        ]);
        $syncReport["linked"]++;
      }

      $syncReport["upserted"]++;
    }

    $syncReport["ok"] = true;
  } catch (Throwable $e) {
    $syncReport["error"] = $e->getMessage();
  }
}

/* -------------------------------
   Query services (DB)
-------------------------------- */
try {
  $sql = "
    SELECT
      s.slug,
      s.square_service_id,
      s.name,
      s.subtitle,
      s.description,
      s.image_url,
      s.booking_url,
      s.is_featured,
      s.sort,
      s.price_amount,
      s.currency_code,
      s.raw_json
    FROM spd_square_services s
    WHERE s.app_slug = :app
      AND s.is_deleted = 0
  ";

  if (!$includeInactive) {
    $sql .= " AND s.is_active = 1 ";
  }

  $sql .= "
    ORDER BY
      s.is_featured DESC,
      s.sort ASC,
      s.name ASC
  ";

  $stmt = db()->prepare($sql);
  $stmt->execute([":app" => $appSlug]);
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
  $rows = [];
}

/* -------------------------------
   Fetch relational category map
-------------------------------- */
$serviceCategoryMap = [];
$serviceParentMap = [];

try {
  $sql = "
    SELECT
      sc.square_service_id,
      c.name AS category_name,
      p.name AS parent_name,
      r.name AS root_name
    FROM spd_square_service_categories sc
    INNER JOIN spd_square_categories c
      ON c.app_slug = sc.app_slug
     AND c.square_category_id = sc.square_category_id
    LEFT JOIN spd_square_categories p
      ON p.app_slug = c.app_slug
     AND p.square_category_id = c.parent_square_category_id
    LEFT JOIN spd_square_categories r
      ON r.app_slug = c.app_slug
     AND r.square_category_id = c.root_square_category_id
    WHERE sc.app_slug = :app
      AND c.is_deleted = 0
      AND c.is_active = 1
  ";

  $stmt = db()->prepare($sql);
  $stmt->execute([":app" => $appSlug]);
  $catRows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

  foreach ($catRows as $row) {
    $sid = trim((string)($row['square_service_id'] ?? ''));
    if ($sid === '') continue;

    $names = [];
    $categoryName = trim((string)($row['category_name'] ?? ''));
    $parentName = trim((string)($row['parent_name'] ?? ''));
    $rootName = trim((string)($row['root_name'] ?? ''));

    if ($categoryName !== '') $names[] = $categoryName;
    if ($parentName !== '') $names[] = $parentName;
    if ($rootName !== '') $names[] = $rootName;

    if (!isset($serviceCategoryMap[$sid])) {
      $serviceCategoryMap[$sid] = [];
    }

    $serviceCategoryMap[$sid] = unique_strings(array_merge($serviceCategoryMap[$sid], $names));

    if ($rootName !== '' && normalize_text($rootName) === SERVICE_PARENT_CATEGORY) {
      $serviceParentMap[$sid] = $rootName;
    } elseif ($parentName !== '' && normalize_text($parentName) === SERVICE_PARENT_CATEGORY) {
      $serviceParentMap[$sid] = $parentName;
    } elseif ($categoryName !== '' && normalize_text($categoryName) === SERVICE_PARENT_CATEGORY) {
      $serviceParentMap[$sid] = $categoryName;
    }
  }
} catch (Throwable $e) {
  // graceful fallback
}

/* -------------------------------
   Normalize + category enrichment
-------------------------------- */
$services = [];
$featured = null;
$categoryAwareCount = 0;

foreach ($rows as $r) {
  $slug = trim((string)($r["slug"] ?? ""));
  $name = trim((string)($r["name"] ?? ""));
  $squareServiceId = trim((string)($r["square_service_id"] ?? ""));
  if ($slug === "" || $name === "") continue;

  $subtitle = ($r["subtitle"] ?? null) !== null ? trim((string)$r["subtitle"]) : null;
  $description = ($r["description"] ?? null) !== null ? trim((string)$r["description"]) : null;
  $raw = safe_json_decode($r["raw_json"] ?? null);

  $categories = [];
  $parentCategory = null;

  if ($squareServiceId !== '' && isset($serviceCategoryMap[$squareServiceId])) {
    $categories = unique_strings($serviceCategoryMap[$squareServiceId]);
    $parentCategory = $serviceParentMap[$squareServiceId] ?? derive_parent_category($categories);
  } else {
    $categories = extract_service_categories_from_raw($raw, $cfg);
    $parentCategory = derive_parent_category($categories);
  }

  if (isset($serviceCategoryOverrides[$slug]) && is_array($serviceCategoryOverrides[$slug])) {
    $categories = unique_strings(array_merge($categories, $serviceCategoryOverrides[$slug]));
    if ($parentCategory === null) {
      $parentCategory = derive_parent_category($categories);
    }
  }

  $serviceType = derive_service_type_from_categories($categories);
  if ($serviceType === 'other') {
    $serviceType = derive_service_type_fallback($name, $subtitle, $description);
  }

  $svc = [
    "square_service_id" => $squareServiceId !== '' ? $squareServiceId : null,
    "slug"             => $slug,
    "name"             => $name,
    "subtitle"         => $subtitle !== '' ? $subtitle : null,
    "description"      => $description !== '' ? $description : null,
    "image_url"        => ($r["image_url"] ?? null) !== null ? trim((string)$r["image_url"]) : null,
    "booking_url"      => ($r["booking_url"] ?? null) !== null ? trim((string)$r["booking_url"]) : $defaultBookingUrl,
    "is_featured"      => ((int)($r["is_featured"] ?? 0) === 1),
    "sort"             => (int)($r["sort"] ?? 999),
    "price_amount"     => isset($r["price_amount"]) && $r["price_amount"] !== null ? (int)$r["price_amount"] : null,
    "currency_code"    => ($r["currency_code"] ?? null) !== null ? trim((string)$r["currency_code"]) : null,
    "categories"       => $categories,
    "parent_category"  => $parentCategory,
    "service_type"     => $serviceType,
  ];

  $svc["is_featured"] = is_featured_by_rule($svc, $cfgFeaturedSlug);

  if ($svc["parent_category"] !== null || !empty($svc["categories"])) {
    $categoryAwareCount++;
  }

  $services[] = $svc;
}

/* -------------------------------
   Filter to Services parent category
-------------------------------- */
if ($categoryAwareCount > 0) {
  $services = array_values(array_filter($services, function (array $svc): bool {
    return normalize_text($svc['parent_category'] ?? '') === SERVICE_PARENT_CATEGORY
      || service_has_category($svc, SERVICE_PARENT_CATEGORY);
  }));
}

/* -------------------------------
   Sort and determine featured
-------------------------------- */
usort($services, 'compare_services');

foreach ($services as $svc) {
  if (!empty($svc['is_featured'])) {
    $featured = $svc;
    break;
  }
}

if ($featured === null && count($services) > 0) {
  $featured = $services[0];
}

/* -------------------------------
   Fallback if DB empty after filter
-------------------------------- */
if (count($services) === 0) {
  $services = [[
    "square_service_id" => null,
    "slug"             => "ionic-footbath-therapy",
    "name"             => "Ionic Footbath Therapy",
    "subtitle"         => "Relax, Detox & Rebalance",
    "description"      => "A restorative wellness session designed to support relaxation, reset, and energetic renewal.",
    "image_url"        => null,
    "booking_url"      => $defaultBookingUrl,
    "is_featured"      => true,
    "sort"             => 10,
    "price_amount"     => null,
    "currency_code"    => null,
    "categories"       => ["Services", "Featured", "In-Studio"],
    "parent_category"  => "Services",
    "service_type"     => "in-studio",
  ]];

  $featured = $services[0];
}

/* -------------------------------
   Group summary for UI/debug
-------------------------------- */
$typeCounts = [
  "in-studio" => 0,
  "mobile"    => 0,
  "in-home"   => 0,
  "pop-up"    => 0,
  "other"     => 0,
];

foreach ($services as $svc) {
  $type = (string)($svc['service_type'] ?? 'other');
  if (!isset($typeCounts[$type])) $typeCounts[$type] = 0;
  $typeCounts[$type]++;
}

json_ok([
  "ok" => true,
  "app_slug" => $cfg["app_slug"] ?? $appSlug,
  "featured_service_slug" => (string)($featured["slug"] ?? ""),
  "featured" => $featured,
  "count" => count($services),
  "service_type_counts" => $typeCounts,
  "category_filter_applied" => ($categoryAwareCount > 0),
  "services" => $services,
  "sync_db" => $syncReport,
], 200, [
  "X-Correlation-Id" => $GLOBALS["correlationId"] ?? "",
  "Cache-Control" => "public, max-age=300, s-maxage=300",
]);