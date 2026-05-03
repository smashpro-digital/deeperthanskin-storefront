<?php
declare(strict_types=1);

/**
 * logger.php
 * Best-effort logging of API errors to spd_api_error_logs.
 * Never breaks the API response if logging fails.
 */

function redact_headers(array $headers): array {
  $redactKeys = ["authorization", "x-api-key", "cookie"];

  $out = [];
  foreach ($headers as $k => $v) {
    $lk = strtolower((string)$k);
    if (in_array($lk, $redactKeys, true)) {
      $out[$k] = "[REDACTED]";
    } else {
      $out[$k] = is_array($v) ? json_encode($v) : (string)$v;
    }
  }
  return $out;
}

function ensure_api_error_logs_table(PDO $pdo): void {
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS spd_api_error_logs (
      id INT UNSIGNED NOT NULL AUTO_INCREMENT,
      correlation_id VARCHAR(64) NULL,
      endpoint VARCHAR(128) NULL,
      error_type VARCHAR(64) NULL,
      message TEXT NULL,
      stack_trace MEDIUMTEXT NULL,
      request_headers MEDIUMTEXT NULL,
      request_body MEDIUMTEXT NULL,
      ip_address VARCHAR(64) NULL,
      user_agent VARCHAR(255) NULL,
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      KEY idx_correlation (correlation_id),
      KEY idx_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  ");
}

function log_api_error(?PDO $pdo, array $row): void {
  try {
    if (!$pdo) return;

    // Optional: create table only if explicitly allowed
    $cfg = $GLOBALS["config"] ?? [];
    $create = (bool)($cfg["debug"]["create_log_tables"] ?? false);
    if ($create) ensure_api_error_logs_table($pdo);

    $stmt = $pdo->prepare("
      INSERT INTO spd_api_error_logs
        (correlation_id, endpoint, error_type, message, stack_trace,
         request_headers, request_body, ip_address, user_agent)
      VALUES
        (:correlation_id, :endpoint, :error_type, :message, :stack_trace,
         :request_headers, :request_body, :ip_address, :user_agent)
    ");

    $stmt->execute([
      ":correlation_id" => $row["correlation_id"] ?? null,
      ":endpoint"       => $row["endpoint"] ?? null,
      ":error_type"     => $row["error_type"] ?? null,
      ":message"        => $row["message"] ?? null,
      ":stack_trace"    => $row["stack_trace"] ?? null,
      ":request_headers"=> json_encode($row["request_headers"] ?? [], JSON_UNESCAPED_SLASHES),
      ":request_body"   => $row["request_body"] ?? null,
      ":ip_address"     => $row["ip_address"] ?? null,
      ":user_agent"     => $row["user_agent"] ?? null,
    ]);
  } catch (Throwable $e) {
    error_log("[spd_api_error_logs] insert failed: " . $e->getMessage());
  }
}
