<?php
declare(strict_types=1);

/**
 * Loads api/bootstrap/config.php (NOT tracked in git).
 * This file is safe to track.
 */

function load_local_config(): array {
  static $cached = null;
  if (is_array($cached)) return $cached;

  $path = __DIR__ . "/config.php";
  if (!is_file($path)) {
    $cached = [];
    return $cached;
  }

  $cfg = require $path;
  $cached = is_array($cfg) ? $cfg : [];
  return $cached;
}

function get_config_value(string $path, $default = null) {
  $cfg = load_local_config();
  $parts = array_values(array_filter(explode(".", $path), fn($p) => $p !== ""));
  $cur = $cfg;

  foreach ($parts as $p) {
    if (!is_array($cur) || !array_key_exists($p, $cur)) return $default;
    $cur = $cur[$p];
  }

  return $cur ?? $default;
}
