<?php
declare(strict_types=1);

require_once __DIR__ . "/../../bootstrap/bootstrap.php";
require_once __DIR__ . "/../../bootstrap/commerce_tenant.php";
require_once __DIR__ . "/../../bootstrap/square.php";

$appSlug = commerce_get_app_slug();
if (!$appSlug) json_fail("app_slug is required", 400);

$cfg = commerce_load_app_config($appSlug);
commerce_apply_cors($cfg);

$method = $_SERVER["REQUEST_METHOD"] ?? "POST";
if ($method === "OPTIONS") { http_response_code(204); exit; }
if ($method !== "POST") json_fail("method not allowed", 405);

$raw  = file_get_contents("php://input");
$body = json_decode($raw ?: "{}", true);
if (!is_array($body)) $body = [];

$subscriptionId = trim((string)($body["subscription_id"] ?? ""));
if ($subscriptionId === "") json_fail("subscription_id is required", 400);

$payload = [];

$pauseEffectiveDate = trim((string)($body["pause_effective_date"] ?? ""));
$pauseCycleDuration = isset($body["pause_cycle_duration"]) ? (int)$body["pause_cycle_duration"] : null;
$resumeEffectiveDate = trim((string)($body["resume_effective_date"] ?? ""));
$resumeChangeTiming = trim((string)($body["resume_change_timing"] ?? ""));
$pauseReason = trim((string)($body["pause_reason"] ?? ""));

if ($pauseEffectiveDate !== "") $payload["pause_effective_date"] = $pauseEffectiveDate;
if ($pauseCycleDuration !== null && $pauseCycleDuration > 0) $payload["pause_cycle_duration"] = $pauseCycleDuration;
if ($resumeEffectiveDate !== "") $payload["resume_effective_date"] = $resumeEffectiveDate;
if ($resumeChangeTiming !== "") $payload["resume_change_timing"] = $resumeChangeTiming;
if ($pauseReason !== "") $payload["pause_reason"] = mb_substr($pauseReason, 0, 255);

$res = square_request($cfg, "POST", "/subscriptions/" . rawurlencode($subscriptionId) . "/pause", $payload ?: new stdClass());

if (!empty($res["errors"])) {
  json_fail("Failed to pause subscription", 502, ["square" => $res]);
}

json_ok([
  "ok" => true,
  "subscription" => $res["subscription"] ?? null,
  "actions" => $res["actions"] ?? [],
], 200, [
  "X-Correlation-Id" => $GLOBALS["correlationId"] ?? "",
]);