<?php
declare(strict_types=1);

require_once __DIR__ . "/../../bootstrap/response.php";

global $pdo, $correlationId;

$serviceId = trim((string)($_GET["service_id"] ?? ""));
if ($serviceId === "") {
  json_error("service_id is required", 400, ["correlation_id" => $correlationId]);
}

try {
  // service_id is your slug in the app
  $stmt = $pdo->prepare("
    SELECT *
    FROM spd_services
    WHERE slug = :slug
    LIMIT 1
  ");
  $stmt->execute([":slug" => $serviceId]);
  $service = $stmt->fetch();

  if (!$service) {
    json_error("Service not found", 404, [
      "correlation_id" => $correlationId,
      "service_id" => $serviceId,
    ]);
  }

  // If you later add job_details fields, you can join or fetch them here.
  // For now, return service object only (your RN code tolerates shapes)
  json_ok([
    "ok" => true,
    "service" => $service,
    "job_details" => null,
    "correlation_id" => $correlationId,
  ]);
} catch (Throwable $e) {
  json_error("Server error", 500, [
    "correlation_id" => $correlationId,
  ], [
    "X-Correlation-Id" => $correlationId,
  ]);
}
