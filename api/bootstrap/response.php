<?php
declare(strict_types=1);

function send_headers(array $headers): void {
  foreach ($headers as $k => $v) {
    if (is_int($k)) header((string)$v);
    else header($k . ": " . $v);
  }
}

function json_ok($data = null, $statusOrMeta = 200, $headers = []): void {
  $status = 200;
  $meta = null;

  if (is_int($statusOrMeta)) $status = $statusOrMeta;
  elseif (is_array($statusOrMeta)) $meta = $statusOrMeta;

  http_response_code($status);
  header("Content-Type: application/json; charset=utf-8");
  if (is_array($headers) && $headers) send_headers($headers);

  $payload = ["ok" => true];

  if ($data !== null) {
    if (is_array($data)) $payload = array_merge($payload, $data);
    else $payload["data"] = $data;
  }

  if ($meta !== null) $payload["meta"] = $meta;

  echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
  exit;
}

function json_error(string $message, int $status = 400, array $data = [], array $headers = []): void {
  http_response_code($status);
  header("Content-Type: application/json; charset=utf-8");
  if ($headers) send_headers($headers);

  $payload = array_merge([
    "ok" => false,
    "error" => $message,
  ], $data);

  echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
  exit;
}
