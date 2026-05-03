<?php
declare(strict_types=1);

/**
 * /api/bootstrap/db.php (FULL DROP-IN)
 *
 * Tightened:
 * - No unnecessary config_loader include (bootstrap already loads config)
 * - Safer DSN building + charset handling
 * - Consistent PDO defaults (ERRMODE_EXCEPTION, FETCH_ASSOC, no emulated prepares)
 * - Session hardening: time_zone, sql_mode, names/charset, optional wait_timeout
 * - Clean error messages unless debug.show_db_errors = true
 *
 * Contract:
 *   function db_connect(array $config): PDO
 */

function db_connect(array $config): PDO
{
  $dbCfg = $config["db"] ?? null;
  if (!is_array($dbCfg)) {
    throw new RuntimeException("Server config invalid (missing db settings)");
  }

  $host    = trim((string)($dbCfg["host"] ?? ""));
  $port    = (int)($dbCfg["port"] ?? 3306);
  $socket  = trim((string)($dbCfg["unix_socket"] ?? ""));
  $db      = trim((string)($dbCfg["name"] ?? ""));
  $user    = (string)($dbCfg["user"] ?? "");
  $pass    = (string)($dbCfg["pass"] ?? "");
  $charset = trim((string)($dbCfg["charset"] ?? "utf8mb4"));

  // Optional session settings
  $tz = (string)($dbCfg["timezone"] ?? "+00:00"); // MySQL supports '+00:00' style
  $sqlMode = (string)($dbCfg["sql_mode"] ?? "STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION");
  $waitTimeout = (int)($dbCfg["wait_timeout"] ?? 0); // 0 = no change

  if ($db === "" || $user === "") {
    throw new RuntimeException("DB config invalid (db.name and db.user are required)");
  }

  // DSN (socket-first for shared hosting)
  if ($socket !== "") {
    $dsn = "mysql:unix_socket={$socket};dbname={$db};charset={$charset}";
  } else {
    if ($host === "") {
      throw new RuntimeException("DB config invalid (db.host required when no unix_socket)");
    }
    $dsn = "mysql:host={$host};port={$port};dbname={$db};charset={$charset}";
  }

  $options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
    PDO::ATTR_STRINGIFY_FETCHES  => false,
  ];

  // Helpful but not supported everywhere; keep best-effort only.
  if (defined("PDO::MYSQL_ATTR_INIT_COMMAND")) {
    $options[PDO::MYSQL_ATTR_INIT_COMMAND] = "SET NAMES {$charset}";
  }

  try {
    $pdo = new PDO($dsn, $user, $pass, $options);

    // Enforce session settings post-connect (works across hosts)
    $pdo->exec("SET NAMES {$charset}");

    // Time zone
    if ($tz !== "") {
      $pdo->exec("SET time_zone = " . $pdo->quote($tz));
    }

    // SQL mode (strictness)
    if (trim($sqlMode) !== "") {
      $pdo->exec("SET SESSION sql_mode = " . $pdo->quote($sqlMode));
    }

    // Optional: wait_timeout
    if ($waitTimeout > 0) {
      $pdo->exec("SET SESSION wait_timeout = " . (int)$waitTimeout);
    }

    return $pdo;
  } catch (PDOException $e) {
    $debug = (bool)($config["debug"]["show_db_errors"] ?? false);

    if ($debug) {
      throw new RuntimeException("DB connection failed: " . $e->getMessage(), 0, $e);
    }
    throw new RuntimeException("DB connection failed");
  }
}
