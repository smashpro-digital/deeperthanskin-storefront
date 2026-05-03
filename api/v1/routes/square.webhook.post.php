<?php
declare(strict_types=1);

/**
 * /api/v1/routes/square.webhook.post.php
 *
 * POST /api/v1/index.php?path=square/webhook
 *
 * Square webhook receiver.
 *
 * Security notes:
 * - Do not commit Square credentials.
 * - Do not expose Square access tokens to Astro, public JS, or browser responses.
 * - TODO: Verify the x-square-hmacsha256-signature header before trusting events.
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

$method = strtoupper((string)($_SERVER["REQUEST_METHOD"] ?? "POST"));

header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Accept, X-Square-HmacSha256-Signature");
header("Access-Control-Max-Age: 86400");

if ($method === "OPTIONS") {
  http_response_code(204);
  exit;
}

if ($method !== "POST") {
  square_json_fail("Method Not Allowed", 405, ["method" => $method]);
}

$rawBody = file_get_contents("php://input");
$rawBody = is_string($rawBody) ? $rawBody : "";

$event = json_decode($rawBody !== "" ? $rawBody : "{}", true);
if (!is_array($event)) {
  error_log("[square.webhook] Invalid JSON body");
  square_json_ok(["received" => false]);
}

$eventId = trim((string)($event["event_id"] ?? $event["id"] ?? ""));
$eventType = trim((string)($event["type"] ?? ""));
$merchantId = trim((string)($event["merchant_id"] ?? ""));
$createdAt = trim((string)($event["created_at"] ?? ""));
$data = $event["data"] ?? [];

// TODO: Load SQUARE_WEBHOOK_SIGNATURE_KEY from environment or local server config.
// TODO: Verify signature with the raw request body and notification URL:
// https://smashpro.app/api/v1/index.php?path=square/webhook
// Reject unverified events before any database writes.
$signature = (string)($_SERVER["HTTP_X_SQUARE_HMACSHA256_SIGNATURE"] ?? "");
if ($signature === "") {
  error_log("[square.webhook] Missing Square signature header for event {$eventId}");
}

error_log(sprintf(
  "[square.webhook] event_id=%s type=%s merchant_id=%s created_at=%s",
  $eventId !== "" ? $eventId : "unknown",
  $eventType !== "" ? $eventType : "unknown",
  $merchantId !== "" ? $merchantId : "unknown",
  $createdAt !== "" ? $createdAt : "unknown"
));

// TODO: Insert raw webhook into square_events.
// Suggested columns: event_id, event_type, merchant_id, created_at, payload_json,
// signature_verified, processed_at, processing_status, processing_error.

switch ($eventType) {
  case "payment.created":
  case "payment.updated":
    // TODO: Upsert payment object into square_payments.
    // TODO: Link payment to square_orders and square_customers when IDs exist.
    break;

  case "order.created":
  case "order.updated":
    // TODO: Upsert order object into square_orders.
    // TODO: Persist line items, fulfillment state, totals, and customer link.
    break;

  case "subscription.created":
  case "subscription.updated":
  case "subscription.canceled":
    // TODO: Upsert subscription object into square_subscriptions.
    // TODO: Persist status, plan variation, customer ID, cadence, and cancellation fields.
    break;

  default:
    // Unknown Square events should still be acknowledged after logging so Square
    // does not retry forever while future event handlers are added.
    error_log("[square.webhook] Unhandled event type: " . ($eventType !== "" ? $eventType : "missing"));
    break;
}

// TODO: Mark square_events.processing_status as processed or ignored.
// TODO: Add idempotency around event_id so duplicate webhook deliveries are safe.

square_json_ok([
  "received" => true,
  "event_type" => $eventType,
]);

