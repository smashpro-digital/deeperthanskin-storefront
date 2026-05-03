<?php
declare(strict_types=1);

/**
 * /api/v1/routes/square.sync.get.php
 *
 * GET /api/v1/index.php?path=square/sync
 *
 * Server-side Square sync scaffold.
 *
 * Security notes:
 * - Do not commit Square credentials.
 * - Do not expose Square access tokens to Astro, public JS, or browser responses.
 * - Keep real credentials in environment variables or a local server-only config file.
 *
 * DB persistence TODO tables:
 * - square_events
 * - square_orders
 * - square_payments
 * - square_subscriptions
 * - square_customers
 */

$API_ROOT = dirname(dirname(__DIR__)); // /api
$bootstrap = $API_ROOT . "/bootstrap/bootstrap.php";

if (!is_file($bootstrap)) {
  $dir = __DIR__;
  for ($i = 0; $i < 6; $i++) {
    $candidate = $dir . "/bootstrap/bootstrap.php";
    if (is_file($candidate)) {
      $bootstrap = $candidate;
      break;
    }
    $dir = dirname($dir);
  }
}

if (is_file($bootstrap)) {
  require_once $bootstrap;
}

if (!function_exists("square_json_ok")) {
  function square_json_ok(array $payload = []): void {
    if (function_exists("json_ok")) {
      json_ok($payload);
      return;
    }

    header("Content-Type: application/json; charset=utf-8");
    echo json_encode(array_merge(["ok" => true], $payload));
    exit;
  }
}

if (!function_exists("square_json_fail")) {
  function square_json_fail(string $message, int $status = 400, array $extra = []): void {
    if (function_exists("json_fail")) {
      json_fail($message, $status, $extra);
      return;
    }

    http_response_code($status);
    header("Content-Type: application/json; charset=utf-8");
    echo json_encode(array_merge(["ok" => false, "error" => $message], $extra));
    exit;
  }
}

function square_sync_config_value(string $key, string $default = ""): string {
  $value = getenv($key);
  if (is_string($value) && trim($value) !== "") {
    return trim($value);
  }

  $localConfig = __DIR__ . "/_square.config.php";
  if (is_file($localConfig)) {
    $config = require $localConfig;
    if (is_array($config) && isset($config[$key])) {
      return trim((string)$config[$key]);
    }
  }

  return $default;
}

$method = strtoupper((string)($_SERVER["REQUEST_METHOD"] ?? "GET"));

if ($method !== "GET") {
  square_json_fail("Method Not Allowed", 405, ["method" => $method]);
}

$environment = square_sync_config_value("SQUARE_ENVIRONMENT", "production");
$accessToken = square_sync_config_value("SQUARE_ACCESS_TOKEN");
$locationId = square_sync_config_value("SQUARE_LOCATION_ID");
$squareVersion = square_sync_config_value("SQUARE_VERSION", "2026-01-22");

if ($accessToken === "") {
  square_json_ok([
    "configured" => false,
    "message" => "Square access token is not configured on the server.",
    "token_exposed" => false,
  ]);
}

// TODO: Require an internal admin secret or authenticated operator before running sync.
// TODO: Use the Square API server-side only. Never return SQUARE_ACCESS_TOKEN.
// TODO: Pull and upsert Square orders into square_orders.
// TODO: Pull and upsert Square payments into square_payments.
// TODO: Pull and upsert Square subscriptions into square_subscriptions.
// TODO: Pull and upsert Square customers into square_customers.
// TODO: Record sync checkpoints, cursors, and errors in square_events or a sync table.

$apiBase = $environment === "sandbox"
  ? "https://connect.squareupsandbox.com"
  : "https://connect.squareup.com";

square_json_ok([
  "configured" => true,
  "environment" => $environment,
  "api_base" => $apiBase,
  "location_configured" => $locationId !== "",
  "square_version" => $squareVersion,
  "token_exposed" => false,
  "todo" => [
    "Implement Square API calls for orders, payments, subscriptions, and customers.",
    "Persist synced records to square_orders, square_payments, square_subscriptions, and square_customers.",
    "Protect this route before enabling live sync.",
  ],
]);

