<?php
declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . "/../../bootstrap/bootstrap.php";
require_once __DIR__ . "/../../bootstrap/commerce_tenant.php";
require_once __DIR__ . "/../../bootstrap/square.php";

if (!headers_sent()) {
  header("Content-Type: application/json; charset=utf-8");
}

const CHECKOUT_DEBUG_EARLY_EXIT = false;
const CHECKOUT_DEBUG_LOG = __DIR__ . '/checkout_debug.log';

function checkout_debug_log(array $data): void {
  $line = "[" . date("Y-m-d H:i:s") . "] " . json_encode($data, JSON_UNESCAPED_SLASHES) . PHP_EOL;
  @file_put_contents(CHECKOUT_DEBUG_LOG, $line, FILE_APPEND);
  error_log("[checkout_debug] " . json_encode($data, JSON_UNESCAPED_SLASHES));
}

function checkout_debug_respond(int $status, array $payload): void {
  checkout_debug_log([
    'http_status' => $status,
    'response' => $payload,
  ]);

  if (!headers_sent()) {
    http_response_code($status);
    header("Content-Type: application/json; charset=utf-8");
  }

  echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
  exit;
}

/**
 * Local Square request helper for checkout.
 * Does NOT call fail_json(), so we can log the exact Square response.
 */
function checkout_square_request(array $cfg, string $method, string $path, ?array $payload = null): array {
  $token = trim((string)($cfg["square_access_token"] ?? ""));

  if ($token === "") {
    return [
      'ok' => false,
      'http_code' => 500,
      'error' => 'Square token missing for app',
      'json' => [
        'app_slug' => $cfg["app_slug"] ?? null,
        'square_environment' => $cfg["square_environment"] ?? null,
      ],
    ];
  }

  $method = strtoupper(trim($method));
  $url = rtrim(square_base_url($cfg), "/") . "/" . ltrim($path, "/");

  $headers = [
    "Authorization: Bearer {$token}",
    "Square-Version: " . square_version(),
    "Content-Type: application/json",
    "Accept: application/json",
  ];

  $cid = (string)($GLOBALS["correlationId"] ?? "");
  if ($cid !== "") {
    $headers[] = "X-Correlation-Id: {$cid}";
  }

  if (!function_exists("curl_init")) {
    return [
      'ok' => false,
      'http_code' => 500,
      'error' => 'cURL not available on server',
      'json' => [],
    ];
  }

  $ch = curl_init($url);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_TIMEOUT, 20);
  curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

  if ($method === "POST") {
    curl_setopt($ch, CURLOPT_POST, true);
    if ($payload !== null) {
      curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_SLASHES));
    }
  } elseif ($method !== "GET") {
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    if ($payload !== null) {
      curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_SLASHES));
    }
  }

  $resp = curl_exec($ch);
  $err = curl_error($ch);
  $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  $json = json_decode((string)$resp, true);
  if (!is_array($json)) {
    $json = [];
  }

  if ($err) {
    return [
      'ok' => false,
      'http_code' => 502,
      'error' => 'Square cURL error',
      'curl_error' => $err,
      'json' => $json,
      'raw' => $resp,
    ];
  }

  return [
    'ok' => ($code >= 200 && $code < 300),
    'http_code' => $code,
    'json' => $json,
    'raw' => $resp,
  ];
}

register_shutdown_function(function (): void {
  $err = error_get_last();
  if (!$err) return;

  $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
  if (!in_array((int)($err['type'] ?? 0), $fatalTypes, true)) return;

  $payload = [
    'ok' => false,
    'error' => 'Fatal server error',
    'stage' => 'shutdown',
    'details' => [
      'type' => $err['type'] ?? null,
      'message' => $err['message'] ?? null,
      'file' => $err['file'] ?? null,
      'line' => $err['line'] ?? null,
    ],
  ];

  if (!headers_sent()) {
    http_response_code(500);
    header("Content-Type: application/json; charset=utf-8");
  }

  checkout_debug_log($payload);
  echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
});

try {
  checkout_debug_log([
    'stage' => 'boot_entered',
    'uri' => $_SERVER['REQUEST_URI'] ?? null,
    'method' => $_SERVER['REQUEST_METHOD'] ?? null,
    'query' => $_GET,
  ]);

  /**
   * OPTIONS/CORS is handled by /api/v1/index.php and .htaccess.
   * Do not call commerce_apply_cors() here.
   */
  $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'POST'));

  if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
  }

  if ($method !== 'POST') {
    checkout_debug_respond(405, [
      'ok' => false,
      'error' => 'Method Not Allowed',
      'method' => $method,
    ]);
  }

  if (CHECKOUT_DEBUG_EARLY_EXIT) {
    checkout_debug_respond(200, [
      'ok' => true,
      'stage' => 'early_exit',
      'message' => 'Route executed before Square calls.',
      'log_path' => CHECKOUT_DEBUG_LOG,
      'cwd' => getcwd(),
      'file' => __FILE__,
      'tmp_writable' => is_writable('/tmp'),
    ]);
  }

  $appSlug = commerce_get_app_slug();
  if (!$appSlug) {
    checkout_debug_respond(400, [
      'ok' => false,
      'error' => 'app_slug is required',
    ]);
  }

  $cfg = commerce_load_app_config($appSlug);
  if (!$cfg) {
    checkout_debug_respond(400, [
      'ok' => false,
      'error' => 'Unknown app_slug',
      'app_slug' => $appSlug,
    ]);
  }

  $raw = file_get_contents('php://input');
  $body = json_decode($raw ?: "{}", true);
  if (!is_array($body)) $body = [];

  checkout_debug_log([
    'stage' => 'request_received',
    'app_slug' => $appSlug,
    'method' => $method,
    'raw_body' => $raw,
    'decoded_body' => $body,
  ]);

  $cart = $body['cart'] ?? null;
  if (!is_array($cart) || count($cart) === 0) {
    checkout_debug_respond(400, [
      'ok' => false,
      'error' => 'cart is required',
      'received_body' => $body,
      'raw_body' => $raw,
    ]);
  }

  $lineItems = [];
  $cartDebug = [];

  foreach ($cart as $idx => $it) {
    if (!is_array($it)) continue;

    $varId = trim((string)($it['variation_id'] ?? ''));
    $qty = (int)($it['qty'] ?? 1);

    if ($qty <= 0) $qty = 1;
    if ($qty > 20) $qty = 20;

    $cartDebug[] = [
      'index' => $idx,
      'variation_id' => $varId,
      'qty' => $qty,
    ];

    if ($varId === '') continue;

    $lineItems[] = [
      'catalog_object_id' => $varId,
      'quantity' => (string)$qty,
    ];
  }

  checkout_debug_log([
    'stage' => 'line_items_built',
    'app_slug' => $appSlug,
    'cart_debug' => $cartDebug,
    'line_items' => $lineItems,
  ]);

  if (count($lineItems) === 0) {
    checkout_debug_respond(400, [
      'ok' => false,
      'error' => 'cart has no valid items',
      'cart_debug' => $cartDebug,
      'received_body' => $body,
      'raw_body' => $raw,
    ]);
  }

  $locationId = trim((string)($cfg['square_location_id'] ?? ''));
  if ($locationId === '') {
    checkout_debug_respond(500, [
      'ok' => false,
      'error' => 'Square location_id missing',
      'app_slug' => $appSlug,
      'square_environment' => $cfg['square_environment'] ?? null,
      'has_access_token' => !empty($cfg['square_access_token']),
    ]);
  }

  $redirectUrl = trim((string)($cfg['storefront_config']['checkout_success_url'] ?? ''));
  if ($redirectUrl === '') {
    $redirectUrl = trim((string)($cfg['checkout_success_url'] ?? ''));
  }

  $paymentLinkPayload = [
    'idempotency_key' => bin2hex(random_bytes(16)),
    'order' => [
      'location_id' => $locationId,
      'source' => [
        'name' => 'Deeper Than Skin Premium Storefront',
      ],
      'line_items' => $lineItems,
    ],
  ];

  if ($redirectUrl !== '') {
    $paymentLinkPayload['checkout_options'] = [
      'redirect_url' => $redirectUrl,
    ];
  }

  checkout_debug_log([
    'stage' => 'payment_links_request_prepared',
    'app_slug' => $appSlug,
    'square_environment' => $cfg['square_environment'] ?? null,
    'location_id' => $locationId,
    'request_payload' => $paymentLinkPayload,
  ]);

  $squareRes = checkout_square_request(
    $cfg,
    'POST',
    '/online-checkout/payment-links',
    $paymentLinkPayload
  );

  checkout_debug_log([
    'stage' => 'payment_links_response',
    'app_slug' => $appSlug,
    'square_http_code' => $squareRes['http_code'] ?? null,
    'square_ok' => $squareRes['ok'] ?? false,
    'square_response' => $squareRes['json'] ?? null,
    'square_raw' => $squareRes['raw'] ?? null,
  ]);

  if (empty($squareRes['ok'])) {
    checkout_debug_respond(502, [
      'ok' => false,
      'error' => 'Square payment link request failed',
      'stage' => 'payment_links_square_non_2xx',
      'app_slug' => $appSlug,
      'square_environment' => $cfg['square_environment'] ?? null,
      'location_id' => $locationId,
      'square_http_code' => $squareRes['http_code'] ?? null,
      'square' => $squareRes['json'] ?? null,
      'square_raw' => $squareRes['raw'] ?? null,
      'request_payload' => $paymentLinkPayload,
      'cart_debug' => $cartDebug,
    ]);
  }

  $linkRes = $squareRes['json'] ?? [];
  $url = $linkRes['payment_link']['url'] ?? null;

  if (!$url) {
    checkout_debug_respond(502, [
      'ok' => false,
      'error' => 'Failed to create checkout link',
      'stage' => 'payment_links_response_missing_url',
      'app_slug' => $appSlug,
      'square_environment' => $cfg['square_environment'] ?? null,
      'location_id' => $locationId,
      'request_payload' => $paymentLinkPayload,
      'square' => $linkRes,
    ]);
  }

  checkout_debug_respond(200, [
    'ok' => true,
    'app_slug' => $cfg['app_slug'] ?? $appSlug,
    'checkout_url' => $url,
    'payment_link_id' => $linkRes['payment_link']['id'] ?? null,
    'order_id' => $linkRes['payment_link']['order_id'] ?? null,
  ]);
} catch (Throwable $e) {
  checkout_debug_respond(500, [
    'ok' => false,
    'error' => 'Unhandled server exception',
    'stage' => 'top_level_catch',
    'details' => [
      'message' => $e->getMessage(),
      'file' => $e->getFile(),
      'line' => $e->getLine(),
    ],
  ]);
}