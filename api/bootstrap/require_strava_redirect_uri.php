<?php
declare(strict_types=1);

/**
 * require_strava_redirect_uri.php
 *
 * Enforces:
 * - STRAVA_REDIRECT_URI env exists
 * - is valid https URL
 * - optional allowlist of hosts
 * - normalized string (trailing slash trimmed)
 * - optional strict match to provided redirect_uri (from request or computed)
 */

function normalize_redirect_uri(string $uri): string {
  // Trim whitespace + normalize trailing slash
  $uri = trim($uri);
  // Remove trailing slash but keep "https://example.com/"
  // This makes "…/callback" and "…/callback/" equivalent internally.
  return rtrim($uri, "/");
}

function get_env_redirect_uri(string $key = "STRAVA_REDIRECT_URI"): string {
  // Prefer getenv; fall back to $_SERVER if needed on some hosts
  $raw = getenv($key);
  if (!$raw && isset($_SERVER[$key])) $raw = (string)$_SERVER[$key];
  if (!$raw) {
    http_response_code(500);
    header("Content-Type: application/json");
    echo json_encode([
      "ok" => false,
      "error" => "Missing env var: {$key}. Check SetEnv / .htaccess / vhost config.",
    ]);
    exit;
  }
  return normalize_redirect_uri($raw);
}

function assert_valid_https_url(string $uri, array $allowedHosts = []): void {
  $parts = parse_url($uri);
  $scheme = $parts["scheme"] ?? "";
  $host = $parts["host"] ?? "";

  if (strtolower($scheme) !== "https" || !$host) {
    http_response_code(500);
    header("Content-Type: application/json");
    echo json_encode([
      "ok" => false,
      "error" => "STRAVA_REDIRECT_URI must be a valid https URL.",
      "value" => $uri,
    ]);
    exit;
  }

  if (!empty($allowedHosts)) {
    $hostLower = strtolower($host);
    $allowedLower = array_map("strtolower", $allowedHosts);
    if (!in_array($hostLower, $allowedLower, true)) {
      http_response_code(500);
      header("Content-Type: application/json");
      echo json_encode([
        "ok" => false,
        "error" => "STRAVA_REDIRECT_URI host not allowed.",
        "host" => $host,
        "allowed" => $allowedHosts,
        "value" => $uri,
      ]);
      exit;
    }
  }
}

function assert_redirect_uri_matches(string $envUri, ?string $providedUri): void {
  if ($providedUri === null || $providedUri === "") return; // nothing to compare
  $providedNorm = normalize_redirect_uri($providedUri);

  if (!hash_equals($envUri, $providedNorm)) {
    http_response_code(400);
    header("Content-Type: application/json");
    echo json_encode([
      "ok" => false,
      "error" => "redirect_uri mismatch (env vs provided). Fix config drift before continuing.",
      "env" => $envUri,
      "provided" => $providedNorm,
    ]);
    exit;
  }
}

/**
 * Main entry:
 * - Reads env redirect uri
 * - Validates scheme/host
 * - Optionally compares to a provided redirect_uri
 * - Returns normalized env uri
 */
function require_strava_redirect_uri(?string $providedUri = null): string {
  $envUri = get_env_redirect_uri("STRAVA_REDIRECT_URI");

  // Adjust allowlist as desired (recommended)
  $allowedHosts = [
    "dashboard.smashpro.app",
    "smashpro.app",
  ];

  assert_valid_https_url($envUri, $allowedHosts);
  assert_redirect_uri_matches($envUri, $providedUri);

  return $envUri;
}
