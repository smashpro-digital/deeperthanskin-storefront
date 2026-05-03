<?php
declare(strict_types=1);

require_once __DIR__ . "/../../bootstrap/response.php";

global $pdo, $correlationId;

$view = isset($_GET["view"]) ? trim((string)$_GET["view"]) : "list";

$activeOnly    = (($_GET["active_only"] ?? "1") === "1");
$includeCustom = (($_GET["include_custom"] ?? "1") === "1");

try {
  // view=one (by slug or id)
  if ($view === "one") {
    $slug = trim((string)($_GET["slug"] ?? ""));
    $id   = isset($_GET["id"]) ? (int)$_GET["id"] : 0;

    if ($slug === "" && $id <= 0) {
      json_error("slug or id is required", 400, ["correlation_id" => $correlationId]);
    }

    if ($slug !== "") {
      $stmt = $pdo->prepare("SELECT * FROM spd_services WHERE slug = :slug LIMIT 1");
      $stmt->execute([":slug" => $slug]);
    } else {
      $stmt = $pdo->prepare("SELECT * FROM spd_services WHERE id = :id LIMIT 1");
      $stmt->execute([":id" => $id]);
    }

    $row = $stmt->fetch();
    json_ok([
      "ok" => true,
      "service" => $row ?: null,
      "correlation_id" => $correlationId,
    ]);
  }

  // view=list
  $where = [];
  $params = [];

  if ($activeOnly) {
    $where[] = "is_active = 1";
  }

  if (!$includeCustom) {
    $where[] = "(is_custom IS NULL OR is_custom = 0)";
  }

  $whereSql = $where ? ("WHERE " . implode(" AND ", $where)) : "";

  $sql = "
    SELECT *
    FROM spd_services
    {$whereSql}
    ORDER BY
      CASE WHEN is_custom = 1 THEN 999999 ELSE COALESCE(sort_order, 9999) END,
      name ASC
  ";

  $stmt = $pdo->prepare($sql);
  $stmt->execute($params);
  $rows = $stmt->fetchAll();

  json_ok([
    "ok" => true,
    "services" => $rows,
    "correlation_id" => $correlationId,
  ]);
} catch (Throwable $e) {
  json_error("Server error", 500, [
    "correlation_id" => $correlationId,
  ], [
    "X-Correlation-Id" => $correlationId,
  ]);
}
