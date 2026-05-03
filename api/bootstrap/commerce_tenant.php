<?php
declare(strict_types=1);

/**
 * /api/bootstrap/commerce_tenant.php (FULL DROP-IN) — DB-first, env fallback
 *
 * Tenant resolution for commerce endpoints.
 * Supports:
 *  A) ?app_slug=...
 *  B) Header: X-App-Slug: ...
 *
 * Loads tenant config from:
 *  1) spd_commerce_apps (DB-first)
 *  2) Apache env vars (SetEnv) fallback
 *
 * Provides tenant-aware CORS helper + safe debug snapshot.
 */

/* -------------------------------
   Compatibility helpers
-------------------------------- */
function commerce_fail(string $msg, int $code = 500, array $data = [], array $headers = []): void {
  if (function_exists("fail_json")) {
    fail_json($msg, $code, $data, $headers);
  }
  if (function_exists("json_fail")) {
    json_fail($msg, $code, $data, $headers);
  }
  // last resort
  if (!headers_sent()) header("Content-Type: application/json; charset=utf-8");
  http_response_code($code);
  echo json_encode(["ok" => false, "error" => $msg] + $data);
  exit;
}

/* -------------------------------
   App slug
-------------------------------- */
function commerce_get_app_slug(): ?string {
  // A) Query param wins
  $q = $_GET["app_slug"] ?? null;
  if (is_string($q)) {
    $q = trim($q);
    if ($q !== "") return $q;
  }

  // B) Header fallback
  $hdr = $_SERVER["HTTP_X_APP_SLUG"] ?? "";
  $hdr = is_string($hdr) ? trim($hdr) : "";
  if ($hdr !== "") return $hdr;

  return null;
}

/* -------------------------------
   ENV helpers
-------------------------------- */
function commerce_slug_env_prefix(string $appSlug): string {
  $s = strtoupper($appSlug);
  $s = preg_replace('/[^A-Z0-9]+/', '_', $s) ?: $s;
  $s = trim($s, '_');
  return "COMMERCE_" . $s . "_";
}

function commerce_env(string $appSlug, string $key, string $default = ""): string {
  $prefix = commerce_slug_env_prefix($appSlug);
  $val = getenv($prefix . $key);
  if ($val === false) return $default;
  $val = is_string($val) ? trim($val) : $default;
  return $val !== "" ? $val : $default;
}

function commerce_parse_json_or_csv($v) {
  if (!is_string($v)) return $v;
  $s = trim($v);
  if ($s === "") return $v;

  if ($s[0] === "{" || $s[0] === "[") {
    $decoded = json_decode($s, true);
    if (is_array($decoded)) return $decoded;
    return $v;
  }

  if (strpos($s, ",") !== false) {
    return array_values(array_filter(array_map("trim", explode(",", $s))));
  }

  return $v;
}

/* -------------------------------
   DB-first config load
-------------------------------- */
function commerce_load_app_config(string $appSlug): array {
  $pdo = db();

  // Try DB first, no metadata checks.
  try {
    $stmt = $pdo->prepare("
      SELECT
        app_slug,
        is_enabled,
        brand_name,
        allowed_origins,
        square_environment,
        square_access_token,
        square_location_id,
        currency,
        featured_config,
        category_map
      FROM spd_commerce_apps
      WHERE app_slug = :app
      LIMIT 1
    ");
    $stmt->execute([":app" => $appSlug]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (is_array($row) && $row) {
      if ((int)($row["is_enabled"] ?? 0) !== 1) {
        commerce_fail("App disabled", 403, ["app_slug" => $appSlug], ["X-Error-Stage" => "commerce.tenant.disabled"]);
      }

      // Parse JSON fields safely
      foreach (["allowed_origins","featured_config","category_map"] as $k) {
        $v = $row[$k] ?? null;
        if (is_string($v) && trim($v) !== "") {
          $decoded = json_decode($v, true);
          if (is_array($decoded)) $row[$k] = $decoded;
        }
      }

      // annotate source (helpful for your debug)
      $row["_cfg_source"] = "db";
      return $row;
    }
  } catch (Throwable $e) {
    // If table missing or permissions error, we fall back to env.
    // You can log $e->getMessage() if you have a logger.
  }

  // ENV fallback
  $isEnabled = commerce_env($appSlug, "IS_ENABLED", "1");
  if ($isEnabled !== "1") {
    commerce_fail("App disabled", 403, ["app_slug" => $appSlug], ["X-Error-Stage" => "commerce.tenant.env_disabled"]);
  }

  $allowedOriginsRaw = commerce_env($appSlug, "ALLOWED_ORIGINS", "[]");
  $allowedOrigins = commerce_parse_json_or_csv($allowedOriginsRaw);
  if (!is_array($allowedOrigins)) $allowedOrigins = [];

  $featuredCfgRaw = commerce_env($appSlug, "FEATURED_CONFIG", "{}");
  $featuredCfg = commerce_parse_json_or_csv($featuredCfgRaw);
  if (!is_array($featuredCfg)) $featuredCfg = [];

  $categoryMapRaw = commerce_env($appSlug, "CATEGORY_MAP", "{}");
  $categoryMap = commerce_parse_json_or_csv($categoryMapRaw);
  if (!is_array($categoryMap)) $categoryMap = [];

  return [
    "app_slug" => $appSlug,
    "is_enabled" => 1,
    "brand_name" => commerce_env($appSlug, "BRAND_NAME", "Commerce"),
    "allowed_origins" => $allowedOrigins,

    "square_environment" => commerce_env($appSlug, "SQUARE_ENVIRONMENT", "production"),
    "square_access_token" => commerce_env($appSlug, "SQUARE_ACCESS_TOKEN", ""),
    "square_location_id" => commerce_env($appSlug, "SQUARE_LOCATION_ID", ""),
    "currency" => commerce_env($appSlug, "CURRENCY", "USD"),

    "featured_config" => $featuredCfg,
    "category_map" => $categoryMap,

    "_cfg_source" => "env",
  ];
}

/* -------------------------------
   Tenant-aware CORS
-------------------------------- */
function commerce_apply_cors(array $cfg): void {
  $origin = $_SERVER["HTTP_ORIGIN"] ?? "";
  $origin = is_string($origin) ? trim($origin) : "";

  $allowed = $cfg["allowed_origins"] ?? [];
  if (!is_array($allowed)) $allowed = [];

  $isLocal = (bool)preg_match('#^http://(localhost|127\.0\.0\.1)(:\d+)?$#', $origin);

  $allow = false;
  if ($origin === "") $allow = true;
  if ($origin !== "" && $isLocal) $allow = true;
  if ($origin !== "" && in_array($origin, $allowed, true)) $allow = true;

  if ($allow) {
    if ($origin !== "") {
      header("Access-Control-Allow-Origin: {$origin}");
      header("Vary: Origin");
    }
    header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, Accept, X-App-Slug, X-Correlation-Id, Authorization");
    header("Access-Control-Max-Age: 600");
  }
}

/* -------------------------------
   Safe debug snapshot (no token)
-------------------------------- */
function commerce_debug_snapshot(array $cfg): array {
  $token = (string)($cfg["square_access_token"] ?? "");
  $allowed = $cfg["allowed_origins"] ?? [];
  if (!is_array($allowed)) $allowed = [];

  return [
    "ok" => true,
    "app_slug" => $cfg["app_slug"] ?? null,
    "cfg_source" => $cfg["_cfg_source"] ?? "unknown",
    "db_name" => (function_exists("db") ? (db()->query("SELECT DATABASE()")->fetchColumn() ?: null) : null),
    "square_environment" => $cfg["square_environment"] ?? null,
    "square_location_id" => $cfg["square_location_id"] ?? null,
    "token_len" => strlen($token),
    "token_prefix" => ($token !== "" ? substr($token, 0, 6) : ""),
    "allowed_origins_count" => count($allowed),
  ];
}