<?php
declare(strict_types=1);

/**
 * Minimal Square HTTP client wrapper.
 * Keeps tokens server-side, returns decoded arrays.
 */

function square_base_url(array $cfg): string {
  $env = strtolower(trim((string)($cfg["square_environment"] ?? "production")));
  if ($env === "sandbox") return "https://connect.squareupsandbox.com/v2";
  return "https://connect.squareup.com/v2";
}

function square_version(): string {
  return "2025-01-23";
}

function square_request(array $cfg, string $method, string $path, ?array $payload = null): array {
  $token = trim((string)($cfg["square_access_token"] ?? ""));

  if ($token === "") {
    fail_json(
      "Square token missing for app",
      500,
      [
        "app_slug" => $cfg["app_slug"] ?? null,
        "square_environment" => $cfg["square_environment"] ?? null,
        "token_len" => 0,
      ],
      ["X-Error-Stage" => "square.token"]
    );
  }

  $method = strtoupper(trim($method));
  $url = rtrim(square_base_url($cfg), "/") . "/" . ltrim($path, "/");

  $headers = [
    "Authorization: Bearer {$token}",
    "Square-Version: " . square_version(),
    "Content-Type: application/json",
    "Accept: application/json",
  ];

  $cid = (string)($GLOBALS["correlationId"] ?? "");
  if ($cid !== "") $headers[] = "X-Correlation-Id: {$cid}";

  if (!function_exists("curl_init")) {
    fail_json("cURL not available on server", 500, ["stage" => "square.curl_missing"]);
  }

  $ch = curl_init($url);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_TIMEOUT, 12);
  curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

  if ($method === "POST") {
    curl_setopt($ch, CURLOPT_POST, true);
    if ($payload !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
  } elseif ($method !== "GET") {
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    if ($payload !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
  }

  $resp = curl_exec($ch);
  $err  = curl_error($ch);
  $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  if ($err) {
    fail_json("Square request failed", 502, [
      "stage" => "square.curl_error",
      "details" => $err,
    ], ["X-Error-Stage" => "square.curl_error"]);
  }

  $json = json_decode((string)$resp, true);
  if (!is_array($json)) $json = [];

  if ($code < 200 || $code >= 300) {
    fail_json("Square API error", 502, [
      "stage" => "square.non_2xx",
      "http_code" => $code,
      "square" => $json,
      "square_environment" => $cfg["square_environment"] ?? null,
    ], ["X-Error-Stage" => "square.non_2xx"]);
  }

  return $json;
}