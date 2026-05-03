<?php
// /api/v1/jobs.php
declare(strict_types=1);

require_once __DIR__ . "/../bootstrap/bootstrap.php";
require_once __DIR__ . "/../bootstrap/auth.php";
require_once __DIR__ . "/../bootstrap/response.php";

global $pdo, $correlationId;

require_api_key($pdo, $correlationId);

$method = $_SERVER["REQUEST_METHOD"] ?? "GET";

if ($method !== "GET") {
  json_error("Method Not Allowed", 405, ["correlation_id" => $correlationId], [
    "X-Correlation-Id" => $correlationId
  ]);
}

$userId = isset($_GET["user_id"]) ? (int)$_GET["user_id"] : 0;
if ($userId <= 0) {
  json_error("user_id is required", 400, ["correlation_id" => $correlationId], [
    "X-Correlation-Id" => $correlationId
  ]);
}

// TODO: replace with your real query/table
$stmt = $pdo->prepare("
  SELECT *
  FROM spd_jobs
  WHERE user_id = :uid
  ORDER BY created_at DESC
  LIMIT 200
");
$stmt->execute([":uid" => $userId]);
$rows = $stmt->fetchAll();

echo json_encode($rows);
