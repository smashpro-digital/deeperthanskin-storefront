<?php
declare(strict_types=1);

/**
 * Fitbit Redirect URI guard
 * Source of truth: FITBIT_REDIRECT_URI env var
 *
 * Usage:
 *   $redirectUri = require_fitbit_redirect_uri(null);
 */

function require_fitbit_redirect_uri(?string $fallback = null): string
{
  $raw = trim((string)getenv("FITBIT_REDIRECT_URI"));
  if ($raw === "" && $fallback !== null) $raw = trim($fallback);

  if ($raw === "") {
    http_response_code(500);
    echo json_encode([
      "ok" => false,
      "error" => "Missing FITBIT_REDIRECT_URI env var",
      "hint" => "SetEnv FITBIT_REDIRECT_URI \"https://smashpro.app/dashboard/fitbit/callback\" (or API callback path)"
    ]);
    exit;
  }

  $uri = rtrim($raw, "/");
  $parts = parse_url($uri);

  $scheme = strtolower((string)($parts["scheme"] ?? ""));
  $host = strtolower((string)($parts["host"] ?? ""));

  if ($scheme !== "https") {
    http_response_code(500);
    echo json_encode([
      "ok" => false,
      "error" => "Invalid FITBIT_REDIRECT_URI (must be https)",
      "value" => $uri
    ]);
    exit;
  }

  // Allowed hosts (tighten as needed)
  $allowed = [
    "smashpro.app",
    "dashboard.smashpro.app"
  ];

  if ($host === "" || !in_array($host, $allowed, true)) {
    http_response_code(500);
    echo json_encode([
      "ok" => false,
      "error" => "FITBIT_REDIRECT_URI host not allowed",
      "host" => $host,
      "allowed" => $allowed,
      "value" => $uri
    ]);
    exit;
  }

  return $uri;
}