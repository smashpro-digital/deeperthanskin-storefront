<?php
declare(strict_types=1);

/**
 * /api/v1/routes/admin.square.categories.sync.post.php
 *
 * Admin bootstrap auth via admin_key.
 * Pulls CATEGORY objects from Square and upserts them into spd_square_categories.
 */

$adminBootstrapCandidates = [
  __DIR__ . "/../../../admin/_admin_bootstrap.php",
  __DIR__ . "/../../../../admin/_admin_bootstrap.php",
  dirname(__DIR__, 4) . "/admin/_admin_bootstrap.php",
];

$adminBootstrap = null;
foreach ($adminBootstrapCandidates as $cand) {
  if (is_file($cand)) {
    $adminBootstrap = $cand;
    break;
  }
}

if (!$adminBootstrap) {
  http_response_code(500);
  header("Content-Type: application/json; charset=utf-8");
  echo json_encode([
    "ok" => false,
    "error" => "Admin bootstrap not found",
    "tried" => $adminBootstrapCandidates,
    "dir" => __DIR__,
  ]);
  exit;
}

require $adminBootstrap;

if (!headers_sent()) {
  header("Content-Type: application/json; charset=utf-8");
}

$method = strtoupper((string)($_SERVER["REQUEST_METHOD"] ?? "POST"));

if ($method === "OPTIONS") {
  http_response_code(204);
  exit;
}

if ($method !== "POST") {
  http_response_code(405);
  echo json_encode([
    "ok" => false,
    "error" => "Method Not Allowed",
    "method" => $method,
  ]);
  exit;
}

$origin = $_SERVER["HTTP_ORIGIN"] ?? "";
$allowed = [
  "https://smashpro.app",
  "https://smashpro-digital.github.io",
];

$isLocal = (bool)preg_match('#^http://(localhost|127\.0\.0\.1)(:\d+)?$#', $origin);

if ($origin && (in_array($origin, $allowed, true) || $isLocal)) {
  header("Access-Control-Allow-Origin: {$origin}");
  header("Vary: Origin");
  header("Access-Control-Allow-Methods: POST, OPTIONS");
  header("Access-Control-Allow-Headers: Content-Type, Accept");
  header("Access-Control-Max-Age: 86400");
}

function json_fail_local(string $msg, int $code = 400, array $extra = []): void {
  http_response_code($code);
  echo json_encode(array_merge([
    "ok" => false,
    "error" => $msg,
  ], $extra));
  exit;
}

function json_ok_local(array $data, int $code = 200): void {
  http_response_code($code);
  echo json_encode(array_merge(["ok" => true], $data));
  exit;
}

$raw = file_get_contents("php://input");
$body = json_decode($raw ?: "{}", true);

if (!is_array($body)) {
  $body = [];
}

$appSlug = $body["app_slug"] ?? ($_GET["app_slug"] ?? null);
$appSlug = is_string($appSlug) ? trim($appSlug) : null;

if (!$appSlug) {
  json_fail_local("app_slug is required", 400);
}

$dryRun = ((int)($_GET["dry_run"] ?? ($body["dry_run"] ?? 0)) === 1);
$reset = ((int)($_GET["reset"] ?? ($body["reset"] ?? 0)) === 1);

try {
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS spd_square_categories (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      app_slug VARCHAR(50) NOT NULL,
      square_category_id VARCHAR(64) NOT NULL,
      name VARCHAR(255) NOT NULL,
      slug VARCHAR(255) NOT NULL,
      parent_square_category_id VARCHAR(64) DEFAULT NULL,
      is_deleted TINYINT(1) NOT NULL DEFAULT 0,
      is_active TINYINT(1) NOT NULL DEFAULT 1,
      raw_json JSON DEFAULT NULL,
      updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      UNIQUE KEY uq_app_square (app_slug, square_category_id),
      UNIQUE KEY uq_app_slug (app_slug, slug),
      KEY idx_parent (parent_square_category_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
  ");
} catch (Throwable $e) {
  json_fail_local("DB init failed", 500, [
    "details" => $e->getMessage(),
  ]);
}

function normalize_square_env($v): string {
  $s = strtolower(trim((string)$v));

  if ($s === "") return "production";
  if (strpos($s, "squareupsandbox") !== false) return "sandbox";
  if (strpos($s, "connect.squareup.com") !== false) return "production";
  if (in_array($s, ["sandbox", "sb", "test", "testing"], true)) return "sandbox";
  if (in_array($s, ["production", "prod", "live", "main"], true)) return "production";
  if (strpos($s, "sand") !== false) return "sandbox";

  return "production";
}

function square_base_url(string $env): string {
  return ($env === "sandbox")
    ? "https://connect.squareupsandbox.com/v2"
    : "https://connect.squareup.com/v2";
}

/**
 * Square List Catalog is GET /v2/catalog/list.
 * Do not POST here, or Square returns 404.
 */
function square_get_local(string $base, string $token, string $path, array $params = []): array {
  $url = rtrim($base, "/") . "/" . ltrim($path, "/");

  if (!empty($params)) {
    $url .= "?" . http_build_query($params);
  }

  if (!function_exists("curl_init")) {
    throw new Exception("cURL not available on server");
  }

  $squareVersion = getenv("SQUARE_VERSION") ?: "2024-06-04";

  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_HTTPGET => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
      "Authorization: Bearer {$token}",
      "Accept: application/json",
      "Square-Version: {$squareVersion}",
    ],
    CURLOPT_TIMEOUT => 25,
  ]);

  $resp = curl_exec($ch);
  $err = curl_error($ch);
  $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

  curl_close($ch);

  if ($err) {
    throw new Exception("Square curl error: {$err} (url={$url})");
  }

  $data = json_decode($resp ?: "{}", true);
  if (!is_array($data)) {
    $data = ["raw" => (string)$resp];
  }

  if ($code < 200 || $code >= 300) {
    $snippet = substr((string)$resp, 0, 800);
    throw new Exception("Square HTTP {$code} (url={$url}) :: {$snippet}");
  }

  return $data;
}

function slugify_local(string $name): string {
  $s = strtolower(trim($name));
  $s = preg_replace('/[^a-z0-9]+/', '-', $s);
  $s = trim((string)$s, '-');

  return $s !== "" ? $s : "category";
}

try {
  $st = $pdo->prepare("
    SELECT app_slug, square_access_token, square_environment
    FROM spd_commerce_apps
    WHERE app_slug = :app
    LIMIT 1
  ");

  $st->execute([":app" => $appSlug]);
  $cfg = $st->fetch(PDO::FETCH_ASSOC);

  if (!$cfg) {
    json_fail_local("Unknown app_slug", 400, [
      "app_slug" => $appSlug,
    ]);
  }

  $token = trim((string)($cfg["square_access_token"] ?? ""));

  if ($token === "") {
    json_fail_local("Square access token missing for app", 500, [
      "app_slug" => $appSlug,
    ]);
  }

  $env = normalize_square_env($cfg["square_environment"] ?? "");
  $base = square_base_url($env);
} catch (Throwable $e) {
  json_fail_local("Failed to load tenant config", 500, [
    "details" => $e->getMessage(),
  ]);
}

$cats = [];
$cursor = null;
$loops = 0;

try {
  do {
    $loops++;

    if ($loops > 60) {
      break;
    }

    $params = [
      "types" => "CATEGORY",
    ];

    if (is_string($cursor) && $cursor !== "") {
      $params["cursor"] = $cursor;
    }

    $resp = square_get_local($base, $token, "/catalog/list", $params);

    $objects = $resp["objects"] ?? [];
    if (!is_array($objects)) {
      $objects = [];
    }

    foreach ($objects as $o) {
      if (!is_array($o)) continue;
      if (($o["type"] ?? "") !== "CATEGORY") continue;

      $cats[] = $o;
    }

    $cursor = $resp["cursor"] ?? null;
  } while (is_string($cursor) && $cursor !== "");
} catch (Throwable $e) {
  json_fail_local("Square fetch failed", 500, [
    "details" => $e->getMessage(),
    "square_env" => $env,
    "square_base" => $base,
    "square_path" => "/catalog/list",
    "square_method" => "GET",
  ]);
}

$upserted = 0;
$slugCollisions = 0;
$squareIdsSeen = [];

$up = null;

if (!$dryRun) {
  $up = $pdo->prepare("
    INSERT INTO spd_square_categories
      (app_slug, square_category_id, name, slug, parent_square_category_id, is_deleted, is_active, raw_json)
    VALUES
      (:app_slug, :sid, :name, :slug, :parent, :del, :active, CAST(:raw AS JSON))
    ON DUPLICATE KEY UPDATE
      name = VALUES(name),
      slug = VALUES(slug),
      parent_square_category_id = VALUES(parent_square_category_id),
      is_deleted = VALUES(is_deleted),
      is_active = VALUES(is_active),
      raw_json = VALUES(raw_json),
      updated_at = CURRENT_TIMESTAMP
  ");
}

foreach ($cats as $cat) {
  $sid = $cat["id"] ?? null;
  $name = $cat["category_data"]["name"] ?? null;

  if (!is_string($sid) || trim($sid) === "") continue;
  if (!is_string($name) || trim($name) === "") continue;

  $squareIdsSeen[$sid] = true;

  $parent = $cat["category_data"]["parent_category"]["id"] ?? null;
  $del = !empty($cat["is_deleted"]) ? 1 : 0;
  $active = $del ? 0 : 1;

  $baseSlug = slugify_local($name);
  $slug = $baseSlug;

  try {
    $chk = $pdo->prepare("
      SELECT 1
      FROM spd_square_categories
      WHERE app_slug = :app
        AND slug = :slug
        AND square_category_id <> :sid
      LIMIT 1
    ");

    $chk->execute([
      ":app" => $appSlug,
      ":slug" => $slug,
      ":sid" => $sid,
    ]);

    if ((bool)$chk->fetchColumn()) {
      $slug = $baseSlug . "-" . substr($sid, -6);
      $slugCollisions++;
    }
  } catch (Throwable $e) {
    // Non-fatal. Continue with base slug.
  }

  if ($dryRun) {
    continue;
  }

  if (!$up) {
    json_fail_local("Upsert statement unavailable", 500);
  }

  $up->execute([
    ":app_slug" => $appSlug,
    ":sid" => $sid,
    ":name" => $name,
    ":slug" => $slug,
    ":parent" => is_string($parent) ? $parent : null,
    ":del" => $del,
    ":active" => $active,
    ":raw" => json_encode($cat),
  ]);

  $upserted++;
}

if (!$dryRun && $reset) {
  try {
    $ids = array_keys($squareIdsSeen);

    if (count($ids) > 0) {
      $placeholders = implode(",", array_fill(0, count($ids), "?"));

      $q = $pdo->prepare("
        UPDATE spd_square_categories
        SET is_active = 0,
            updated_at = CURRENT_TIMESTAMP
        WHERE app_slug = ?
          AND square_category_id NOT IN ($placeholders)
      ");

      $q->execute(array_merge([$appSlug], $ids));
    }
  } catch (Throwable $e) {
    // Non-fatal reset cleanup.
  }
}

json_ok_local([
  "app_slug" => $appSlug,
  "fetched" => count($cats),
  "upserted" => $dryRun ? 0 : $upserted,
  "slug_collisions" => $slugCollisions,
  "dry_run" => $dryRun,
  "reset" => $reset,
  "square_env" => $env,
  "square_base" => $base,
  "square_method" => "GET",
  "square_path" => "/catalog/list",
]);