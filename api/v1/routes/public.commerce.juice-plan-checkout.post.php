<?php
declare(strict_types=1);

/**
 * CORS MUST RUN BEFORE BOOTSTRAP OR ANY JSON OUTPUT.
 */
$allowedOrigins = [
  'http://localhost:4321',
  'https://shop.deeperthanskin.store',
  'https://deeperthanskin.store',
];

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

if (in_array($origin, $allowedOrigins, true)) {
  header("Access-Control-Allow-Origin: {$origin}");
  header("Vary: Origin");
}

header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Accept, Authorization, X-Requested-With");
header("Access-Control-Max-Age: 86400");
header("Content-Type: application/json; charset=utf-8");

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
  http_response_code(204);
  exit;
}

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . "/../../bootstrap/bootstrap.php";
require_once __DIR__ . "/../../bootstrap/commerce_tenant.php";
require_once __DIR__ . "/../../bootstrap/square.php";

const JUICE_PLAN_DEBUG_LOG = __DIR__ . '/juice_plan_checkout_debug.log';

function juice_plan_debug_log(array $data): void {
  $line = "[" . date("Y-m-d H:i:s") . "] " . json_encode($data, JSON_UNESCAPED_SLASHES) . PHP_EOL;
  @file_put_contents(JUICE_PLAN_DEBUG_LOG, $line, FILE_APPEND);
  error_log("[juice_plan_checkout] " . json_encode($data, JSON_UNESCAPED_SLASHES));
}

function juice_plan_respond(int $status, array $payload): void {
  juice_plan_debug_log([
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

function juice_plan_square_request(array $cfg, string $method, string $path, ?array $payload = null): array {
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

function juice_plan_get_plan_variation_id(array $cfg, string $cadence): string {
  $storefront = is_array($cfg['storefront_config'] ?? null) ? $cfg['storefront_config'] : [];

  $plans = [
    'weekly' =>
      $storefront['square_weekly_juice_plan_variation_id'] ??
      $cfg['square_weekly_juice_plan_variation_id'] ??
      getenv('SQUARE_WEEKLY_JUICE_PLAN_VARIATION_ID') ??
      '',

    'biweekly' =>
      $storefront['square_biweekly_juice_plan_variation_id'] ??
      $cfg['square_biweekly_juice_plan_variation_id'] ??
      getenv('SQUARE_BIWEEKLY_JUICE_PLAN_VARIATION_ID') ??
      '',

    '2w' =>
      $storefront['square_biweekly_juice_plan_variation_id'] ??
      $cfg['square_biweekly_juice_plan_variation_id'] ??
      getenv('SQUARE_BIWEEKLY_JUICE_PLAN_VARIATION_ID') ??
      '',

    'monthly' =>
      $storefront['square_monthly_juice_plan_variation_id'] ??
      $cfg['square_monthly_juice_plan_variation_id'] ??
      getenv('SQUARE_MONTHLY_JUICE_PLAN_VARIATION_ID') ??
      '',
  ];

  return trim((string)($plans[$cadence] ?? ''));
}

function juice_plan_money_total(array $cart): int {
  $total = 0;

  foreach ($cart as $it) {
    if (!is_array($it)) continue;

    $amount = (int)($it['price_money']['amount'] ?? 0);
    $qty = (int)($it['qty'] ?? 1);

    if ($qty <= 0) $qty = 1;
    if ($qty > 20) $qty = 20;

    $total += $amount * $qty;
  }

  return max($total, 100);
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

  juice_plan_debug_log($payload);
  echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
});

try {
  juice_plan_debug_log([
    'stage' => 'boot_entered',
    'uri' => $_SERVER['REQUEST_URI'] ?? null,
    'method' => $_SERVER['REQUEST_METHOD'] ?? null,
    'query' => $_GET,
  ]);

  $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'POST'));

  if ($method !== 'POST') {
    juice_plan_respond(405, [
      'ok' => false,
      'error' => 'Method Not Allowed',
      'method' => $method,
    ]);
  }

  $appSlug = commerce_get_app_slug();
  if (!$appSlug) {
    juice_plan_respond(400, [
      'ok' => false,
      'error' => 'app_slug is required',
    ]);
  }

  $cfg = commerce_load_app_config($appSlug);
  if (!$cfg) {
    juice_plan_respond(400, [
      'ok' => false,
      'error' => 'Unknown app_slug',
      'app_slug' => $appSlug,
    ]);
  }

  $raw = file_get_contents('php://input');
  $body = json_decode($raw ?: "{}", true);
  if (!is_array($body)) $body = [];

  juice_plan_debug_log([
    'stage' => 'request_received',
    'app_slug' => $appSlug,
    'raw_body' => $raw,
    'decoded_body' => $body,
  ]);

  $cart = $body['cart'] ?? ($body['items'] ?? []);
  if (!is_array($cart)) $cart = [];

  $plan = $body['plan'] ?? [];
  if (!is_array($plan)) $plan = [];

  $cadence = strtolower(trim((string)($plan['cadence'] ?? 'weekly')));

  if ($cadence === 'one_time' || $cadence === 'trial') {
    juice_plan_respond(400, [
      'ok' => false,
      'error' => 'This endpoint is for recurring juice plans only.',
      'cadence' => $cadence,
    ]);
  }

  $subscriptionPlanVariationId = juice_plan_get_plan_variation_id($cfg, $cadence);

  if ($subscriptionPlanVariationId === '') {
    juice_plan_respond(400, [
      'ok' => false,
      'error' => 'No Square subscription plan variation mapped for this cadence',
      'cadence' => $cadence,
      'expected_config_keys' => [
        'square_weekly_juice_plan_variation_id',
        'square_biweekly_juice_plan_variation_id',
        'square_monthly_juice_plan_variation_id',
      ],
    ]);
  }

  $locationId = trim((string)($cfg['square_location_id'] ?? ''));
  if ($locationId === '') {
    juice_plan_respond(500, [
      'ok' => false,
      'error' => 'Square location_id missing',
      'app_slug' => $appSlug,
      'square_environment' => $cfg['square_environment'] ?? null,
      'has_access_token' => !empty($cfg['square_access_token']),
    ]);
  }

  $amount = juice_plan_money_total($cart);

  $goals = is_array($plan['goals'] ?? null) ? $plan['goals'] : [];
  $preferences = is_array($plan['preferences'] ?? null) ? $plan['preferences'] : [];
  $recommendations = is_array($plan['recommendations'] ?? null) ? $plan['recommendations'] : [];
  $notes = trim((string)($plan['notes'] ?? ''));

  $noteParts = [
    'Custom Juice Plan',
    'Cadence: ' . $cadence,
  ];

  if (count($goals)) {
    $noteParts[] = 'Goals: ' . implode(', ', array_map('strval', $goals));
  }

  if (count($preferences)) {
    $noteParts[] = 'Preferences: ' . implode(', ', array_map('strval', $preferences));
  }

  if (count($recommendations)) {
    $recNames = [];
    foreach ($recommendations as $rec) {
      if (is_array($rec) && !empty($rec['name'])) {
        $recNames[] = (string)$rec['name'];
      }
    }
    if (count($recNames)) {
      $noteParts[] = 'Recommendations: ' . implode(', ', $recNames);
    }
  }

  if ($notes !== '') {
    $noteParts[] = 'Notes: ' . $notes;
  }

  $redirectUrl = trim((string)($body['redirect_url'] ?? ''));
  if ($redirectUrl === '') {
    $redirectUrl = trim((string)($cfg['storefront_config']['checkout_success_url'] ?? ''));
  }
  if ($redirectUrl === '') {
    $redirectUrl = trim((string)($cfg['checkout_success_url'] ?? ''));
  }

  $paymentLinkPayload = [
    'idempotency_key' => bin2hex(random_bytes(16)),
    'quick_pay' => [
      'name' => $cadence === 'weekly'
        ? 'Weekly Juice Ritual Plan'
        : ($cadence === 'monthly' ? 'Monthly Juice Ritual Plan' : 'Deeper Than Skin Ritual Plan'),
      'price_money' => [
        'amount' => $amount,
        'currency' => 'USD',
      ],
      'location_id' => $locationId,
    ],
    'checkout_options' => [
      'subscription_plan_id' => $subscriptionPlanVariationId,
      'ask_for_shipping_address' => true,
    ],
    'note' => implode("\n", $noteParts),
  ];

  if ($redirectUrl !== '') {
    $paymentLinkPayload['checkout_options']['redirect_url'] = $redirectUrl;
  }

  $buyerEmail = trim((string)($body['customer']['email'] ?? ''));
  if ($buyerEmail !== '') {
    $paymentLinkPayload['pre_populated_data'] = [
      'buyer_email' => $buyerEmail,
    ];
  }

  juice_plan_debug_log([
    'stage' => 'payment_links_request_prepared',
    'app_slug' => $appSlug,
    'cadence' => $cadence,
    'subscription_plan_variation_id' => $subscriptionPlanVariationId,
    'square_environment' => $cfg['square_environment'] ?? null,
    'location_id' => $locationId,
    'request_payload' => $paymentLinkPayload,
  ]);

  $squareRes = juice_plan_square_request(
    $cfg,
    'POST',
    '/online-checkout/payment-links',
    $paymentLinkPayload
  );

  juice_plan_debug_log([
    'stage' => 'payment_links_response',
    'app_slug' => $appSlug,
    'square_http_code' => $squareRes['http_code'] ?? null,
    'square_ok' => $squareRes['ok'] ?? false,
    'square_response' => $squareRes['json'] ?? null,
    'square_raw' => $squareRes['raw'] ?? null,
  ]);

  if (empty($squareRes['ok'])) {
    juice_plan_respond(502, [
      'ok' => false,
      'error' => 'Square juice plan payment link request failed',
      'stage' => 'payment_links_square_non_2xx',
      'app_slug' => $appSlug,
      'square_environment' => $cfg['square_environment'] ?? null,
      'location_id' => $locationId,
      'cadence' => $cadence,
      'subscription_plan_variation_id' => $subscriptionPlanVariationId,
      'square_http_code' => $squareRes['http_code'] ?? null,
      'square' => $squareRes['json'] ?? null,
      'square_raw' => $squareRes['raw'] ?? null,
      'request_payload' => $paymentLinkPayload,
    ]);
  }

  $linkRes = $squareRes['json'] ?? [];
  $url = $linkRes['payment_link']['url'] ?? null;

  if (!$url) {
    juice_plan_respond(502, [
      'ok' => false,
      'error' => 'Failed to create juice plan checkout link',
      'stage' => 'payment_links_response_missing_url',
      'app_slug' => $appSlug,
      'square_environment' => $cfg['square_environment'] ?? null,
      'location_id' => $locationId,
      'request_payload' => $paymentLinkPayload,
      'square' => $linkRes,
    ]);
  }

  juice_plan_respond(200, [
    'ok' => true,
    'app_slug' => $cfg['app_slug'] ?? $appSlug,
    'checkout_url' => $url,
    'url' => $url,
    'payment_link_id' => $linkRes['payment_link']['id'] ?? null,
    'order_id' => $linkRes['payment_link']['order_id'] ?? null,
    'cadence' => $cadence,
    'subscription_plan_variation_id' => $subscriptionPlanVariationId,
  ]);
} catch (Throwable $e) {
  juice_plan_respond(500, [
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