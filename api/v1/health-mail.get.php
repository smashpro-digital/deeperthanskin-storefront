<?php
declare(strict_types=1);

require_once __DIR__ . "/../../bootstrap/response.php";
require_once __DIR__ . "/../../bootstrap/mail.php";

global $pdo, $correlationId;

$cfg = mail_config();
$to = trim((string)($_GET["to"] ?? ($cfg["admin_email"] ?? "")));
if ($to === "") $to = "bookings@smashpro.app";

$dryRun = (string)($_GET["dry_run"] ?? "") === "1";

$subject = "SmashPro API Mail Health Check";
$body = implode("\n", [
  "This is a test email from SmashPro API /health/mail.",
  "Time: " . date("c"),
  "Correlation ID: " . (string)$correlationId,
]);

$emailAttempted = true;
$emailSent = false;

if (!$dryRun) {
  $emailSent = send_smtp_mail(
    $to,
    $subject,
    $body,
    ["X-Correlation-Id: {$correlationId}"],
    $pdo,
    (string)$correlationId,
    "health.mail.get"
  );
}

json_ok([
  "ok" => true,
  "dryRun" => $dryRun,
  "attempted" => $emailAttempted,
  "sent" => $emailSent,
  "to" => $to,
  "provider_hint" => ((string)($cfg["smtp_host"] ?? "") !== "" ? "smtp-first" : "php-mail"),
  "correlation_id" => $correlationId
], 200, [
  "X-Correlation-Id" => $correlationId
]);
