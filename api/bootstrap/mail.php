<?php
declare(strict_types=1);

/**
 * /api/bootstrap/mail.php (FULL DROP-IN)
 *
 * SMTP-first (if configured), fallback to PHP mail(), and best-effort logging to spd_email_logs.
 *
 * Fixes:
 * - PHP7 compatible
 * - Respects smtp_port from config (no hidden default to 465)
 * - Implicit TLS for 465 (ssl://)
 * - STARTTLS for non-465 if advertised
 * - From/Reply-To can be overridden by caller headers
 * - Optional from-name override param supported
 * - SMTP exceptions do NOT block php_mail fallback
 */

if (!function_exists("load_local_config")) {
  function load_local_config(): array {
    if (isset($GLOBALS["config"]) && is_array($GLOBALS["config"])) return $GLOBALS["config"];
    $path = __DIR__ . "/config.php";
    if (is_file($path)) {
      $cfg = require $path;
      return is_array($cfg) ? $cfg : [];
    }
    return [];
  }
}

if (!function_exists("mail_config")) {
  function mail_config(): array {
    $cfg = load_local_config();
    $mail = $cfg["mail"] ?? [];
    if (!is_array($mail)) $mail = [];

    return [
      "from_email"  => (string)($mail["from_email"] ?? "no-reply@smashpro.app"),
      "from_name"   => (string)($mail["from_name"]  ?? "SmashPro (Do Not Reply)"),
      "admin_email" => (string)($mail["admin_email"] ?? "bookings@smashpro.app"),

      "smtp_host"   => (string)($mail["smtp_host"] ?? ""),
      "smtp_port"   => (int)($mail["smtp_port"] ?? 587), // IMPORTANT: default 587, not 465
      "smtp_user"   => (string)($mail["smtp_user"] ?? ""),
      "smtp_pass"   => (string)($mail["smtp_pass"] ?? ""),

      "log_to_db"         => (bool)($mail["log_to_db"] ?? true),
      "create_logs_table" => (bool)($mail["create_logs_table"] ?? false),
      "debug_smtp"        => (bool)($mail["debug_smtp"] ?? false),

      // SSL context toggles for hosting quirks (optional)
      "smtp_verify_peer"       => (bool)($mail["smtp_verify_peer"] ?? true),
      "smtp_verify_peer_name"  => (bool)($mail["smtp_verify_peer_name"] ?? true),
      "smtp_allow_self_signed" => (bool)($mail["smtp_allow_self_signed"] ?? false),
    ];
  }
}

if (!function_exists("headers_have_prefix")) {
  function headers_have_prefix(array $extraHeaders, string $prefix): bool {
    $prefix = strtolower($prefix);
    foreach ($extraHeaders as $h) {
      $h = trim((string)$h);
      if ($h !== "" && strtolower(substr($h, 0, strlen($prefix))) === $prefix) return true;
    }
    return false;
  }
}

if (!function_exists("mail_headers_base")) {
  function mail_headers_base(array $cfg, array $extraHeaders = [], ?string $fromNameOverride = null): array {
    $fromEmail = (string)($cfg["from_email"] ?? "no-reply@smashpro.app");
    $fromName  = $fromNameOverride !== null && trim($fromNameOverride) !== ""
      ? trim($fromNameOverride)
      : (string)($cfg["from_name"] ?? "SmashPro");

    $headers = [
      "MIME-Version: 1.0",
      "Content-Type: text/plain; charset=utf-8",
    ];

    // Only set From if caller didn't pass a From:
    if (!headers_have_prefix($extraHeaders, "from:")) {
      $headers[] = "From: " . ($fromName !== "" ? "{$fromName} <{$fromEmail}>" : $fromEmail);
    }

    // Only set Reply-To if caller didn't pass Reply-To:
    if (!headers_have_prefix($extraHeaders, "reply-to:")) {
      $headers[] = "Reply-To: {$fromEmail}";
    }

    foreach ($extraHeaders as $h) {
      $h = trim((string)$h);
      if ($h !== "") $headers[] = $h;
    }
    return $headers;
  }
}

if (!function_exists("ensure_email_logs_table")) {
  function ensure_email_logs_table(PDO $pdo): void {
    $pdo->exec("
      CREATE TABLE IF NOT EXISTS spd_email_logs (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        correlation_id VARCHAR(64) NULL,
        endpoint VARCHAR(128) NULL,
        to_email VARCHAR(255) NOT NULL,
        subject VARCHAR(255) NOT NULL,
        provider VARCHAR(32) NOT NULL,
        success TINYINT(1) NOT NULL DEFAULT 0,
        error_message TEXT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_correlation (correlation_id),
        KEY idx_created (created_at)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
  }
}

if (!function_exists("log_email_attempt")) {
  function log_email_attempt(?PDO $pdo, array $row): void {
    try {
      if (!$pdo) return;
      $cfg = mail_config();
      if (!($cfg["log_to_db"] ?? true)) return;

      if (($cfg["create_logs_table"] ?? false) === true) {
        ensure_email_logs_table($pdo);
      }

      $stmt = $pdo->prepare("
        INSERT INTO spd_email_logs
          (correlation_id, endpoint, to_email, subject, provider, success, error_message)
        VALUES
          (:correlation_id, :endpoint, :to_email, :subject, :provider, :success, :error_message)
      ");

      $stmt->execute([
        ":correlation_id" => $row["correlation_id"] ?? null,
        ":endpoint"       => $row["endpoint"] ?? null,
        ":to_email"       => (string)($row["to_email"] ?? ""),
        ":subject"        => (string)($row["subject"] ?? ""),
        ":provider"       => (string)($row["provider"] ?? "unknown"),
        ":success"        => (int)(($row["success"] ?? false) ? 1 : 0),
        ":error_message"  => $row["error_message"] ?? null,
      ]);
    } catch (Throwable $e) {
      error_log("[spd_email_logs] insert failed: " . $e->getMessage());
    }
  }
}

/* ---------------- SMTP helpers ---------------- */

if (!function_exists("smtp_read")) {
  function smtp_read($fp, int $timeout = 10): string {
    stream_set_timeout($fp, $timeout);
    $data = "";
    while (!feof($fp)) {
      $line = fgets($fp, 512);
      if ($line === false) break;
      $data .= $line;
      if (strlen($line) >= 4 && $line[3] === " ") break;
    }
    return $data;
  }
}

if (!function_exists("smtp_write")) {
  function smtp_write($fp, string $cmd, bool $debug = false): void {
    if ($debug) error_log("[smtp] C: " . rtrim($cmd));
    fwrite($fp, $cmd . "\r\n");
  }
}

if (!function_exists("smtp_expect_ok")) {
  function smtp_expect_ok(string $resp, array $okPrefixes, string $step): void {
    $resp = trim($resp);
    foreach ($okPrefixes as $p) {
      $p = (string)$p;
      if ($p !== "" && strncmp($resp, $p, strlen($p)) === 0) return;
    }
    throw new RuntimeException("SMTP failed at {$step}: {$resp}");
  }
}

if (!function_exists("smtp_open_socket")) {
  function smtp_open_socket(array $cfg) {
    $host  = (string)($cfg["smtp_host"] ?? "");
    $port  = (int)($cfg["smtp_port"] ?? 587);
    $debug = (bool)($cfg["debug_smtp"] ?? false);

    $context = stream_context_create([
      "ssl" => [
        "verify_peer"       => (bool)($cfg["smtp_verify_peer"] ?? true),
        "verify_peer_name"  => (bool)($cfg["smtp_verify_peer_name"] ?? true),
        "allow_self_signed" => (bool)($cfg["smtp_allow_self_signed"] ?? false),
        "SNI_enabled"       => true,
      ],
    ]);

    $transport = ($port === 465) ? "ssl://" : "";
    $fp = @stream_socket_client(
      $transport . $host . ":" . $port,
      $errno,
      $errstr,
      10,
      STREAM_CLIENT_CONNECT,
      $context
    );

    if ($debug) error_log("[smtp] connect {$transport}{$host}:{$port} => " . ($fp ? "OK" : "FAIL {$errno} {$errstr}"));
    if (!$fp) throw new RuntimeException("SMTP connect failed: {$errno} {$errstr}");
    return $fp;
  }
}

if (!function_exists("smtp_send_mail")) {
  function smtp_send_mail(array $cfg, string $to, string $subject, string $body, array $extraHeaders = [], ?string $fromNameOverride = null): bool {
    $host  = (string)($cfg["smtp_host"] ?? "");
    $port  = (int)($cfg["smtp_port"] ?? 587);
    $user  = (string)($cfg["smtp_user"] ?? "");
    $pass  = (string)($cfg["smtp_pass"] ?? "");
    $debug = (bool)($cfg["debug_smtp"] ?? false);

    if ($host === "" || $user === "" || $pass === "") return false;

    $fp = smtp_open_socket($cfg);

    $hello = smtp_read($fp);
    smtp_expect_ok($hello, ["220"], "connect");

    smtp_write($fp, "EHLO smashpro.app", $debug);
    $ehlo = smtp_read($fp);
    smtp_expect_ok($ehlo, ["250"], "EHLO");

    // STARTTLS only for non-465 and only if advertised
    if ($port !== 465 && stripos($ehlo, "STARTTLS") !== false) {
      smtp_write($fp, "STARTTLS", $debug);
      $tls = smtp_read($fp);
      smtp_expect_ok($tls, ["220"], "STARTTLS");

      $method = defined("STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT")
        ? STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT
        : STREAM_CRYPTO_METHOD_TLS_CLIENT;

      $cryptoOk = @stream_socket_enable_crypto($fp, true, $method);
      if (!$cryptoOk) throw new RuntimeException("SMTP STARTTLS crypto negotiation failed");

      smtp_write($fp, "EHLO smashpro.app", $debug);
      $ehlo2 = smtp_read($fp);
      smtp_expect_ok($ehlo2, ["250"], "EHLO after TLS");
    }

    smtp_write($fp, "AUTH LOGIN", $debug);
    $r = smtp_read($fp);
    smtp_expect_ok($r, ["334"], "AUTH LOGIN");

    smtp_write($fp, base64_encode($user), $debug);
    $r = smtp_read($fp);
    smtp_expect_ok($r, ["334"], "AUTH user");

    smtp_write($fp, base64_encode($pass), $debug);
    $r = smtp_read($fp);
    smtp_expect_ok($r, ["235"], "AUTH pass");

    $fromEmail = (string)($cfg["from_email"] ?? "no-reply@smashpro.app");

    smtp_write($fp, "MAIL FROM:<{$fromEmail}>", $debug);
    $r = smtp_read($fp);
    smtp_expect_ok($r, ["250"], "MAIL FROM");

    smtp_write($fp, "RCPT TO:<{$to}>", $debug);
    $r = smtp_read($fp);
    smtp_expect_ok($r, ["250", "251"], "RCPT TO");

    smtp_write($fp, "DATA", $debug);
    $r = smtp_read($fp);
    smtp_expect_ok($r, ["354"], "DATA");

    $headers = mail_headers_base($cfg, $extraHeaders, $fromNameOverride);

    $raw =
      "To: {$to}\r\n" .
      "Subject: {$subject}\r\n" .
      implode("\r\n", $headers) . "\r\n\r\n" .
      $body . "\r\n";

    $raw = preg_replace("/\r\n\./", "\r\n..", $raw);

    smtp_write($fp, rtrim($raw) . "\r\n.", $debug);
    $r = smtp_read($fp, 20);
    smtp_expect_ok($r, ["250"], "DATA end");

    smtp_write($fp, "QUIT", $debug);
    fclose($fp);

    return true;
  }
}

/* ---------------- Public API ---------------- */

if (!function_exists("send_smtp_mail")) {
  function send_smtp_mail(
    string $to,
    string $subject,
    string $body,
    array $extraHeaders = [],
    ?PDO $pdo = null,
    ?string $correlationId = null,
    ?string $endpoint = null,
    ?string $fromNameOverride = null
  ): bool {
    $cfg = mail_config();
    $cid = $correlationId ?: ($GLOBALS["correlationId"] ?? null);

    $smtpConfigured =
      (string)($cfg["smtp_host"] ?? "") !== "" &&
      (string)($cfg["smtp_user"] ?? "") !== "" &&
      (string)($cfg["smtp_pass"] ?? "") !== "";

    // 1) Try SMTP (log success/fail). Never block fallback.
    if ($smtpConfigured) {
      try {
        $ok = smtp_send_mail($cfg, $to, $subject, $body, $extraHeaders, $fromNameOverride);

        log_email_attempt($pdo, [
          "correlation_id" => $cid,
          "endpoint" => $endpoint,
          "to_email" => $to,
          "subject" => $subject,
          "provider" => "smtp",
          "success" => $ok,
          "error_message" => $ok ? null : "smtp_send_mail returned false",
        ]);

        if ($ok) return true;
      } catch (Throwable $e) {
        log_email_attempt($pdo, [
          "correlation_id" => $cid,
          "endpoint" => $endpoint,
          "to_email" => $to,
          "subject" => $subject,
          "provider" => "smtp",
          "success" => false,
          "error_message" => $e->getMessage(),
        ]);
        // continue to fallback
      }
    }

    // 2) Fallback: PHP mail()
    $headers = mail_headers_base($cfg, $extraHeaders, $fromNameOverride);
    $ok = @mail($to, $subject, $body, implode("\r\n", $headers));

    log_email_attempt($pdo, [
      "correlation_id" => $cid,
      "endpoint" => $endpoint,
      "to_email" => $to,
      "subject" => $subject,
      "provider" => "php_mail",
      "success" => $ok,
      "error_message" => $ok ? null : "mail() returned false",
    ]);

    return $ok;
  }
}
