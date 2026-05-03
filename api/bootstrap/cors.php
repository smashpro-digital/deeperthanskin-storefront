<?php
declare(strict_types=1);

/**
 * Shared CORS handler
 *
 * If CORS_REQUIRE_CREDENTIALS is true:
 * - NO wildcard allowed
 * - Must echo Origin + send Allow-Credentials
 */

$origin = $_SERVER["HTTP_ORIGIN"] ?? "";

$allowedOrigins = [
  "https://smashpro-digital.github.io",
  "http://localhost:3000",
  "http://127.0.0.1:3000",
  "http://localhost:5173",
  "http://127.0.0.1:5173",
];

$needsCreds = defined("CORS_REQUIRE_CREDENTIALS") && CORS_REQUIRE_CREDENTIALS === true;

if ($origin && in_array($origin, $allowedOrigins, true)) {
  header("Access-Control-Allow-Origin: {$origin}");
  header("Vary: Origin");
  if ($needsCreds) header("Access-Control-Allow-Credentials: true");
} else {
  if ($needsCreds) {
    // credentialed request from a non-allowed origin: block by not setting Allow-Origin
    // (Browser will enforce)
  } else {
    header("Access-Control-Allow-Origin: *");
  }
}

header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Accept, X-Correlation-Id");
header("Access-Control-Max-Age: 86400");

if (($_SERVER["REQUEST_METHOD"] ?? "") === "OPTIONS") {
  http_response_code(204);
  exit;
}
}

header("Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Accept, X-Correlation-Id");
header("Access-Control-Max-Age: 86400");

// Preflight support
if (($_SERVER["REQUEST_METHOD"] ?? "") === "OPTIONS") {
  http_response_code(204);
  exit;
}
