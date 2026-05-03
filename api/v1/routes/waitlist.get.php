<?php
declare(strict_types=1);

require_once __DIR__ . "/../../bootstrap/response.php";
global $pdo, $correlationId;

try {
  if (!isset($pdo) || !($pdo instanceof PDO)) {
    throw new RuntimeException("PDO not initialized.");
  }

  $app = trim((string)($_GET["app_slug"] ?? "hodos"));
  $status = trim((string)($_GET["status"] ?? "waitlisted"));

  $stmt = $pdo->prepare("
    SELECT
      u.id AS user_id,
      u.email,
      u.name,
      u.first_name,
      u.last_name,
      u.phone,
      a.app_slug,
      a.role,
      a.status,
      a.created_at,
      a.invited_at,
      a.responded_at
    FROM spd_user_applications a
    JOIN spd_users u ON u.id = a.user_id
    WHERE a.app_slug = :app
      AND (:status = '' OR a.status = :status)
    ORDER BY a.created_at DESC
    LIMIT 500
  ");
  $stmt->execute([
    ":app" => $app,
    ":status" => $status
  ]);

  json_ok([
    "rows" => $stmt->fetchAll(),
    "correlation_id" => $correlationId
  ], 200, ["X-Correlation-Id" => $correlationId]);

} catch (Throwable $e) {
  json_error("Server error", 500, ["correlation_id" => $correlationId], [
    "X-Correlation-Id" => $correlationId
  ]);
}
