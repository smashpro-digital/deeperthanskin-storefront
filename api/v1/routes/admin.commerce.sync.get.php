<?php
declare(strict_types=1);

/**
 * GET /api/v1/index.php?path=admin/commerce/sync&app_slug=deeper-than-skin&token=YOUR_SYNC_TOKEN
 *
 * Master sync endpoint for DB-backed storefront mirror.
 * Calls the public sync-capable endpoints internally so one cron job can keep
 * categories, products, and services warm in the database.
 *
 * FULL DROP-IN
 */

require_once __DIR__ . "/../../bootstrap/bootstrap.php";
require_once __DIR__ . "/../../bootstrap/commerce_tenant.php";

if (!headers_sent()) {
  header("Content-Type: application/json; charset=utf-8");
}

/* -------------------------------
   Helpers
-------------------------------- */
function sync_safe_json_decode(?string $body): ?array {
  if (!is_string($body) || trim($body) === '') return null;

  try {
    $json = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    return is_array($json) ? $json : null;
  } catch (Throwable $e) {
    return null;
  }
}

function sync_http_get_json(string $url, int $timeout = 60): array {
  $headers = [];
  $statusCode = 0;
  $body = null;
  $error = null;

  $context = stream_context_create([
    'http' => [
      'method' => 'GET',
      'timeout' => $timeout,
      'ignore_errors' => true,
      'header' => implode("\r\n", [
        'Accept: application/json',
        'User-Agent: SmashPro-CommerceSync/1.0',
        'Connection: close',
      ]),
    ],
  ]);

  try {
    $body = @file_get_contents($url, false, $context);
    if ($body === false) {
      $body = null;
      $lastError = error_get_last();
      $error = is_array($lastError) ? (string)($lastError['message'] ?? 'Request failed') : 'Request failed';
    }
  } catch (Throwable $e) {
    $error = $e->getMessage();
  }

  if (isset($http_response_header) && is_array($http_response_header)) {
    $headers = $http_response_header;

    foreach ($headers as $line) {
      if (preg_match('#^HTTP/\S+\s+(\d{3})#i', $line, $m)) {
        $statusCode = (int)$m[1];
        break;
      }
    }
  }

  $json = sync_safe_json_decode($body);

  return [
    'url' => $url,
    'status_code' => $statusCode,
    'headers' => $headers,
    'body' => $body,
    'json' => $json,
    'error' => $error,
  ];
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
if ($method === 'OPTIONS') {
  http_response_code(204);
  exit;
}

if ($method !== 'GET') {
  fail_json('Method Not Allowed', 405, [
    'path' => '/admin/commerce/sync',
    'method' => $method,
  ]);
}

/* -------------------------------
   Auth
-------------------------------- */
$expectedToken = (string)($cfg['sync_token'] ?? '');
$providedToken = (string)($_GET['token'] ?? '');

if ($expectedToken !== '' && !hash_equals($expectedToken, $providedToken)) {
  fail_json('Unauthorized', 401);
}

/* -------------------------------
   Config
-------------------------------- */
$baseUrl = trim((string)($cfg['api_base_url'] ?? 'https://smashpro.app/api/v1/index.php'));
if ($baseUrl === '') {
  $baseUrl = 'https://smashpro.app/api/v1/index.php';
}

$timeout = max(10, (int)($_GET['timeout'] ?? 60));

/**
 * Keep ordering intentional:
 * 1. categories
 * 2. products
 * 3. services
 *
 * Services may rely on category-aware storefront logic, so categories go first.
 */
$targets = [
  'categories' => [
    'path' => 'public/commerce/categories',
    'params' => ['sync_db' => 1],
  ],
  'products' => [
    'path' => 'public/commerce/products',
    'params' => ['sync_db' => 1],
  ],
  'services' => [
    'path' => 'public/commerce/services',
    'params' => ['sync_db' => 1],
  ],
];

/* -------------------------------
   Run sync targets
-------------------------------- */
$results = [];
$ok = true;

foreach ($targets as $key => $target) {
  $query = array_merge([
    'path' => $target['path'],
    'app_slug' => $appSlug,
  ], (array)($target['params'] ?? []));

  $url = $baseUrl . '?' . http_build_query($query);
  $response = sync_http_get_json($url, $timeout);

  $json = is_array($response['json']) ? $response['json'] : null;
  $targetOk = false;

  if (is_array($json)) {
    $targetOk = (bool)($json['ok'] ?? false);
  } else {
    $targetOk = ($response['status_code'] >= 200 && $response['status_code'] < 300);
  }

  $results[$key] = [
    'path' => $target['path'],
    'url' => $url,
    'status_code' => $response['status_code'],
    'ok' => $targetOk,
    'error' => $response['error'],
    'response_ok' => is_array($json) ? (bool)($json['ok'] ?? false) : null,
    'count' => is_array($json) ? ($json['count'] ?? null) : null,
    'sync_db' => is_array($json) ? ($json['sync_db'] ?? null) : null,
    'featured_service_slug' => ($key === 'services' && is_array($json))
      ? ($json['featured_service_slug'] ?? null)
      : null,
    'category_filter_applied' => ($key === 'services' && is_array($json))
      ? ($json['category_filter_applied'] ?? null)
      : null,
    'service_type_counts' => ($key === 'services' && is_array($json))
      ? ($json['service_type_counts'] ?? null)
      : null,
    'message' => is_array($json) ? ($json['error'] ?? null) : null,
  ];

  if (!$targetOk) {
    $ok = false;
  }
}

/* -------------------------------
   Summary
-------------------------------- */
$summary = [
  'total' => count($results),
  'ok' => count(array_filter($results, static fn(array $r): bool => !empty($r['ok']))),
  'failed' => count(array_filter($results, static fn(array $r): bool => empty($r['ok']))),
];

/* -------------------------------
   Response
-------------------------------- */
json_ok([
  'ok' => $ok,
  'app_slug' => $appSlug,
  'summary' => $summary,
  'results' => $results,
], $ok ? 200 : 207, [
  'X-Correlation-Id' => $GLOBALS['correlationId'] ?? '',
  'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
]);