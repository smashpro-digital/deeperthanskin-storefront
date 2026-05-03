<?php
declare(strict_types=1);

require_once __DIR__ . "/response.php";
require_once __DIR__ . "/config_loader.php";

/**
 * API key auth:
 * - Accepts X-Api-Key header (preferred)
 * - Also accepts ?api_key=... for manual browser testing
 *
 * config.php should include either:
 *  ["api" => ["key" => "...."]]
 * or legacy:
 *  ["api_key" => "...."]
 */

function get_expected_api_key(): string {
  $cfg = load_local_config();
  $k = "";

  if (isset($cfg["api"]) && is_array($cfg["api"])) {
    $k = (string)($cfg["api"]["key"] ?? "");
  }
  if ($k === "") $k = (string)($cfg["api_key"] ?? "");

  return trim($k);
}

function require_api_key(?PDO $pdo, string $correlationId): void {
  $expected = get_expected_api_key();

  if ($expected === "") {
    json_error("Server auth not configured", 500, ["correlation_id" => $correlationId], [
      "X-Correlation-Id" => $correlationId
    ]);
  }

  $provided =
    (string)($_SERVER["HTTP_X_API_KEY"] ?? "") ?: (string)($_GET["api_key"] ?? "");

  $provided = trim($provided);

  if ($provided === "" || !hash_equals($expected, $provided)) {
    json_error("Unauthorized – missing API key", 401, ["correlation_id" => $correlationId], [
      "X-Correlation-Id" => $correlationId
    ]);
  }
}
