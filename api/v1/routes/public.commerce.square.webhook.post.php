<?php
declare(strict_types=1);

/**
 * /api/v1/routes/public.commerce.square.webhook.post.php (DROP-IN)
 *
 * POST /api/v1/index.php?path=public/commerce/square/webhook&app_slug=deeper-than-skin
 *
 * Notes:
 * - Signature verification requires the Square Webhook Signature Key.
 * - Works DB-less using env var:
 *     SQUARE_APP_<APP>_WEBHOOK_SIGNATURE_KEY
 *
 * - For now: log events and return 200 quickly.
 */

require_once __DIR__ . "/../bootstrap/bootstrap.php"; // json_ok/json_fail

function clamp(?string $v, int $max): ?string {
  if ($v === null) return null;
  $s = trim((string)$v);
  if ($s === "") return null;
  return function_exists("mb_substr") ? mb_substr($s, 0, $max) : substr($s, 0, $max);
}

function resolve_app_slug(): ?string {
  $q = $_GET["app_slug"] ?? null;
  $h = $_SERVER["HTTP_X_APP_SLUG"] ?? null;
  $slug = clamp(is_string($q) ? $q : null, 80) ?: clamp(is_string($h) ? $h : null, 80);
  return $slug ?: null;
}

function app_key_prefix(string $appSlug): string {
  return strtoupper(preg_replace('/[^A-Z0-9]+/i', '_', $appSlug));
}

/**
 * Square signature verification (best-effort).
 * Square sends header: x-square-hmacsha256-signature
 * Signature: base64(hmac_sha256(notification_url + body, signature_key))
 */
function square_verify_signature(string $signatureKey, string $notificationUrl, string $body, string $providedSig): bool {
  if ($signatureKey === "" || $providedSig === "") return false;

  $toSign = $notificationUrl . $body;
  $hash = hash_hmac("sha256", $toSign, $signatureKey, true);
  $computed = base64_encode($hash);

  // timing safe compare
  if (function_exists("hash_equals")) return hash_equals($computed, $providedSig);
  return $computed === $providedSig;
}

// ---- main ----
$appSlug = resolve_app_slug();
if (!$appSlug) json_fail("app_slug is required", 400);

$raw = file_get_contents("php://input");
if ($raw === false) $raw = "";

$providedSig = $_SERVER["HTTP_X_SQUARE_HMACSHA256_SIGNATURE"] ?? "";
$providedSig = is_string($providedSig) ? trim($providedSig) : "";

$p = app_key_prefix($appSlug);
$signatureKey = getenv("SQUARE_APP_{$p}_WEBHOOK_SIGNATURE_KEY") ?: "";

// Square requires the exact notification URL (what you configured in Square dashboard).
// We rebuild it best-effort.
$scheme = (!empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off") ? "https" : "http";
$host = $_SERVER["HTTP_HOST"] ?? "";
$uri  = $_SERVER["REQUEST_URI"] ?? "";
$notificationUrl = "{$scheme}://{$host}{$uri}";

// Verify only if key is present; otherwise accept (dev mode)
$verified = true;
if ($signatureKey !== "") {
  $verified = square_verify_signature($signatureKey, $notificationUrl, $raw, $providedSig);
  if (!$verified) {
    // Reject but do not leak details
    http_response_code(401);
    echo json_encode(["ok" => false, "error" => "Invalid signature"]);
    exit;
  }
}

// Parse event
$evt = json_decode($raw ?: "{}", true);
if (!is_array($evt)) $evt = [];

// Minimal logging (best effort)
try {
  $type = (string)($evt["type"] ?? "");
  $id   = (string)($evt["event_id"] ?? $evt["merchant_id"] ?? "");
  error_log("[square.webhook] app={$appSlug} verified=" . ($verified ? "1" : "0") . " type={$type} id={$id}");
} catch (Throwable $e) {
  // ignore
}

// Always ACK quickly
json_ok([
  "ok" => true,
  "verified" => $verified,
  "app_slug" => $appSlug,
]);