<?php
declare(strict_types=1);

/**
 * /api/v1/routes/public.commerce.categories.get.php (FULL DROP-IN)
 *
 * GET  /api/v1/index.php?path=public/commerce/categories&app_slug=deeper-than-skin
 * GET  ...&sync_db=1
 * OPTIONS (CORS preflight)
 */

require_once __DIR__ . "/../../bootstrap/bootstrap.php";
require_once __DIR__ . "/../../bootstrap/commerce_tenant.php";
require_once __DIR__ . "/../../bootstrap/square.php";

if (!headers_sent()) {
  header("Content-Type: application/json; charset=utf-8");
}

/* -------------------------------
   Helpers
-------------------------------- */
function slugify(string $s): string {
  $s = strtolower(trim($s));
  $s = preg_replace('/[\'"`’]/u', '', $s);
  $s = preg_replace('/[^a-z0-9]+/i', '-', $s);
  $s = trim((string)$s, '-');
  return $s !== "" ? $s : "category";
}

/**
 * Try to resolve the best Square image URL from a CATALOG_IMAGE object.
 */
function square_catalog_image_url(?array $imgObject): ?string {
  if (!is_array($imgObject)) return null;

  $imgData = $imgObject["image_data"] ?? null;
  if (!is_array($imgData)) return null;

  $candidates = [
    $imgData["url"] ?? null,
    $imgData["image_url"] ?? null,
    $imgData["original_url"] ?? null,
  ];

  foreach ($candidates as $u) {
    if (is_string($u) && trim($u) !== "") return $u;
  }

  return null;
}

/**
 * Batch load Square catalog image objects and return:
 *   [ imageId => imageUrl ]
 */
function square_load_catalog_image_urls(array $cfg, array $imageIds): array {
  $imageIds = array_values(array_unique(array_filter(array_map(
    fn($v) => is_string($v) ? trim($v) : "",
    $imageIds
  ))));

  if (!$imageIds) return [];

  $map = [];

  // Batch in chunks just in case
  foreach (array_chunk($imageIds, 100) as $chunk) {
    try {
      $resp = square_request($cfg, "POST", "/catalog/batch-retrieve", [
        "object_ids" => array_values($chunk),
        "include_related_objects" => false,
      ]);

      $objects = $resp["objects"] ?? [];
      if (!is_array($objects)) continue;

      foreach ($objects as $obj) {
        if (!is_array($obj)) continue;
        if (($obj["type"] ?? "") !== "IMAGE") continue;

        $id = $obj["id"] ?? null;
        if (!is_string($id) || $id === "") continue;

        $url = square_catalog_image_url($obj);
        if ($url) {
          $map[$id] = $url;
        }
      }
    } catch (Throwable $e) {
      // swallow and return partial map
    }
  }

  return $map;
}

/* -------------------------------
   Tenant
-------------------------------- */
$appSlug = commerce_get_app_slug();
if (!$appSlug) json_fail("app_slug is required", 400);

$cfg = commerce_load_app_config($appSlug);
if (!$cfg) json_fail("Unknown app_slug", 400);

commerce_apply_cors($cfg);

/* -------------------------------
   Method handling
-------------------------------- */
$method = strtoupper((string)($_SERVER["REQUEST_METHOD"] ?? "GET"));
if ($method === "OPTIONS") { http_response_code(204); exit; }
if ($method === "HEAD")    { http_response_code(204); exit; }

if ($method !== "GET") {
  json_fail("Method Not Allowed", 405, [
    "path" => "/public/commerce/categories",
    "method" => $method,
  ]);
}

/* -------------------------------
   Optional: DB sync from Square
-------------------------------- */
$syncDb = ((int)($_GET["sync_db"] ?? 0) === 1);

$syncReport = [
  "attempted" => $syncDb,
  "ok" => false,
  "fetched" => 0,
  "upserted" => 0,
  "error" => null,
];

if ($syncDb) {
  try {
    $cursor = null;
    $loops = 0;
    $cats = [];

    do {
      $loops++;
      if ($loops > 50) break;

      $path = "/catalog/list?types=CATEGORY";
      if (is_string($cursor) && $cursor !== "") {
        $path .= "&cursor=" . rawurlencode($cursor);
      }

      $resp = square_request($cfg, "GET", $path);

      $objects = $resp["objects"] ?? [];
      if (is_array($objects)) {
        foreach ($objects as $o) {
          if (is_array($o) && ($o["type"] ?? "") === "CATEGORY") {
            $cats[] = $o;
          }
        }
      }

      $cursor = $resp["cursor"] ?? null;
    } while (is_string($cursor) && $cursor !== "");

    $syncReport["fetched"] = count($cats);

    $sql = "
      INSERT INTO spd_square_categories
        (app_slug, square_category_id, name, slug, parent_square_category_id, is_deleted, is_active, raw_json, created_at, updated_at)
      VALUES
        (:app_slug, :sid, :name, :slug, :parent, :del, :active, :raw, NOW(), NOW())
      ON DUPLICATE KEY UPDATE
        name = VALUES(name),
        parent_square_category_id = VALUES(parent_square_category_id),
        is_deleted = VALUES(is_deleted),
        is_active = VALUES(is_active),
        raw_json = VALUES(raw_json),
        updated_at = NOW()
    ";

    $pdo = db();
    $stmt = $pdo->prepare($sql);

    $seenIds = [];

    foreach ($cats as $cat) {
      $sid  = $cat["id"] ?? null;
      $data = $cat["category_data"] ?? null;
      $name = is_array($data) ? ($data["name"] ?? null) : null;

      if (!is_string($sid) || $sid === "" || !is_string($name) || trim($name) === "") {
        continue;
      }

      $seenIds[] = $sid;

      $slug = slugify($name);

      $parent = $data["parent_category"]["id"] ?? null;
      if (!is_string($parent) || $parent === "") $parent = null;

      $del = !empty($cat["is_deleted"]) ? 1 : 0;

      $onlineVis = $data["online_visibility"] ?? null;
      $active = ($del === 0) && ($onlineVis === null || $onlineVis === true) ? 1 : 0;

      $stmt->execute([
        ":app_slug" => $appSlug,
        ":sid" => $sid,
        ":name" => $name,
        ":slug" => $slug,
        ":parent" => $parent,
        ":del" => $del,
        ":active" => $active,
        ":raw" => json_encode($cat, JSON_UNESCAPED_SLASHES),
      ]);

      $pdo->prepare("
        UPDATE spd_square_categories
        SET slug = COALESCE(NULLIF(slug,''), :new_slug)
        WHERE app_slug = :app AND square_category_id = :sid
      ")->execute([
        ":new_slug" => $slug,
        ":app" => $appSlug,
        ":sid" => $sid,
      ]);

      $syncReport["upserted"]++;
    }

    if (count($seenIds) > 0) {
      $in = implode(",", array_fill(0, count($seenIds), "?"));
      $sqlDel = "
        UPDATE spd_square_categories
        SET is_deleted = 1, is_active = 0, updated_at = NOW()
        WHERE app_slug = ?
          AND square_category_id NOT IN ($in)
      ";
      $pdo->prepare($sqlDel)->execute(array_merge([$appSlug], $seenIds));
    }

    $syncReport["ok"] = true;
  } catch (Throwable $e) {
    $syncReport["error"] = $e->getMessage();
  }
}

/* -------------------------------
   Read categories from DB
-------------------------------- */
try {
  $stmt = db()->prepare("
    SELECT
      square_category_id,
      name,
      slug,
      parent_square_category_id,
      raw_json
    FROM spd_square_categories
    WHERE app_slug = :app
      AND is_active = 1
      AND is_deleted = 0
    ORDER BY parent_square_category_id IS NULL DESC, name ASC
  ");
  $stmt->execute([":app" => $appSlug]);
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
  $rows = [];
}

/* -------------------------------
   Pull image_ids out of raw_json
-------------------------------- */
$allImageIds = [];
$normalizedRows = [];

foreach ($rows as $r) {
  $parent = $r["parent_square_category_id"] ?? null;
  if (!is_string($parent) || $parent === "") $parent = null;

  $raw = $r["raw_json"] ?? null;
  $decoded = null;
  if (is_string($raw) && $raw !== "") {
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) $decoded = null;
  }

  $catData = is_array($decoded) ? ($decoded["category_data"] ?? null) : null;
  $imageIds = [];
  if (is_array($catData) && isset($catData["image_ids"]) && is_array($catData["image_ids"])) {
    foreach ($catData["image_ids"] as $iid) {
      if (is_string($iid) && trim($iid) !== "") {
        $imageIds[] = trim($iid);
      }
    }
  }

  foreach ($imageIds as $iid) {
    $allImageIds[] = $iid;
  }

  $normalizedRows[] = [
    "id" => (string)($r["square_category_id"] ?? ""),
    "name" => (string)($r["name"] ?? ""),
    "slug" => (string)($r["slug"] ?? ""),
    "parent_id" => $parent,
    "is_top_level" => $parent === null,
    "image_ids" => $imageIds,
  ];
}

/* -------------------------------
   Resolve Square image IDs -> URLs
-------------------------------- */
$imageUrlMap = square_load_catalog_image_urls($cfg, $allImageIds);

/* -------------------------------
   Normalize final API payload
-------------------------------- */
$categories = array_map(function($r) use ($imageUrlMap) {
  $imageIds = $r["image_ids"] ?? [];
  $imageUrl = null;

  if (is_array($imageIds)) {
    foreach ($imageIds as $iid) {
      if (isset($imageUrlMap[$iid]) && is_string($imageUrlMap[$iid]) && $imageUrlMap[$iid] !== "") {
        $imageUrl = $imageUrlMap[$iid];
        break;
      }
    }
  }

  return [
    "id" => $r["id"],
    "name" => $r["name"],
    "slug" => $r["slug"],
    "parent_id" => $r["parent_id"],
    "is_top_level" => $r["is_top_level"],
    "description" => null,
    "image_ids" => $imageIds,
    "image_url" => $imageUrl,
  ];
}, $normalizedRows);

/**
 * Optional: aliases for frontend “legacy” slugs.
 */
$slugAliases = [
  "seamoss" => "sea-moss",
  "sea-moss" => "sea-moss",
];

json_ok([
  "ok" => true,
  "app_slug" => $appSlug,
  "categories" => $categories,
  "slug_aliases" => $slugAliases,
  "sync_db" => $syncReport,
], 200);