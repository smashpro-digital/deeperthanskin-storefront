<?php
declare(strict_types=1);

/**
 * /api/v1/index.php (FULL DROP-IN) — Commerce + tenant-ready headers
 * Router + CORS (public + protected)
 *
 * Public:
 * - GET  /health
 * - GET  /health/mail
 * - POST /public/waitlist
 * - POST /waitlist (back-compat)
 * - POST /public/contact
 * - GET  /public/waitlist/count?app_slug=...
 * - GET  /public/waitlist/csv?app_slug=...&pin=1234
 *
 * Commerce (PUBLIC):
 * - GET  /public/commerce/categories?app_slug=...      ✅ (A + B)
 * - GET  /public/commerce/services?app_slug=...        ✅ (Booking + featured service)
 * - GET  /public/commerce/featured?app_slug=...        (A + B)
 * - GET  /public/commerce/products?app_slug=...        (A + B)
 * - GET  /public/commerce/product?id=...&app_slug=...  (A + B)
 * - POST /public/commerce/checkout?app_slug=...        (A + B)
 *
 * Protected (API key):
 * - GET  /waitlist           (admin list)
 * - POST /waitlist/admin     (internal write)
 * - GET/POST /jobs
 * - GET /services
 * - GET /service-details
 */

/* -------------------------------
   CORS (run BEFORE anything else)
-------------------------------- */
function cors_apply(): void {
  $allowedOrigins = [
    "https://join.deeperthanskin.store",
    "https://shop.deeperthanskin.store",
    "https://smashpro-digital.github.io",
    "https://smashpro.app",
    "https://dashboard.smashpro.app",
    "http://localhost:3000",
    "http://localhost:4321",
    "http://localhost:5173",
  ];

  $origin = $_SERVER["HTTP_ORIGIN"] ?? "";
  $origin = is_string($origin) ? trim($origin) : "";

  if ($origin !== "" && in_array($origin, $allowedOrigins, true)) {
    header("Access-Control-Allow-Origin: {$origin}");
    header("Vary: Origin");
    header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, Accept, X-Api-Key, X-Correlation-Id, Authorization, X-App-Slug");
    header("Access-Control-Max-Age: 600");
  }
}

$method = $_SERVER["REQUEST_METHOD"] ?? "GET";
$method = is_string($method) ? strtoupper($method) : "GET";

/**
 * Apply CORS early for BOTH preflight and normal requests.
 * This ensures browser always sees CORS headers on errors too.
 */
cors_apply();

if ($method === "OPTIONS") {
  http_response_code(204);
  exit;
}

// Now safe to emit JSON content-type
header("Content-Type: application/json; charset=utf-8");

/* -------------------------------
   Bootstrap
-------------------------------- */
require_once __DIR__ . "/../bootstrap/bootstrap.php";
require_once __DIR__ . "/../bootstrap/response.php";

global $pdo, $correlationId;

/* -------------------------------
   Resolve route path
-------------------------------- */
$rewritePath = $_GET["path"] ?? "";
if (is_string($rewritePath) && $rewritePath !== "") {
  $sub = "/" . ltrim($rewritePath, "/");
} else {
  $path = parse_url($_SERVER["REQUEST_URI"] ?? "", PHP_URL_PATH) ?? "";
  $sub  = preg_replace("#^/api/v1#", "", (string)$path) ?: "/";
}
$sub = rtrim((string)$sub, "/") ?: "/";

/* -------------------------------
   PUBLIC ROUTES (NO AUTH)
-------------------------------- */

// Profile
if ($sub === "/profile") {
  if ($method === "GET") {
    header("X-Route: profile.get");
    header("X-Correlation-Id: " . $correlationId);
    require __DIR__ . "/routes/profile.get.php";
    exit;
  }
  if ($method === "POST") {
    header("X-Route: profile.post");
    header("X-Correlation-Id: " . $correlationId);
    require __DIR__ . "/routes/profile.post.php";
    exit;
  }
  json_error("Method Not Allowed", 405, [
    "path" => $sub,
    "method" => $method,
    "correlation_id" => $correlationId
  ], [
    "X-Correlation-Id" => $correlationId,
    "X-Route" => "profile.405",
  ]);
}

// Health
if ($sub === "/health" && $method === "GET") {
  json_ok([
    "ok" => true,
    "status" => "ok",
    "correlation_id" => $correlationId
  ], 200, [
    "X-Correlation-Id" => $correlationId,
    "X-Route" => "health.get",
  ]);
}

if ($sub === "/health/mail" && $method === "GET") {
  header("X-Route: health.mail.get");
  header("X-Correlation-Id: " . $correlationId);
  require __DIR__ . "/routes/health-mail.get.php";
  exit;
}

// Waitlist (PUBLIC)
if (($sub === "/public/waitlist" || $sub === "/waitlist") && $method === "POST") {
  header("X-Route: public.waitlist.post");
  header("X-Correlation-Id: " . $correlationId);
  require __DIR__ . "/routes/public.waitlist.post.php";
  exit;
}

// Contact (PUBLIC)
if ($sub === "/public/contact" && $method === "POST") {
  header("X-Route: public.contact.post");
  header("X-Correlation-Id: " . $correlationId);
  require __DIR__ . "/routes/public.contact.post.php";
  exit;
}

// Waitlist counter (PUBLIC)
if ($sub === "/public/waitlist/count" && $method === "GET") {
  header("X-Route: public.waitlist.count.get");
  header("X-Correlation-Id: " . $correlationId);
  require __DIR__ . "/routes/public.waitlist.count.get.php";
  exit;
}

// Waitlist CSV download (PUBLIC but PIN protected)
if ($sub === "/public/waitlist/csv" && $method === "GET") {
  header("X-Route: public.waitlist.csv.get");
  header("X-Correlation-Id: " . $correlationId);
  require __DIR__ . "/routes/public.waitlist.csv.get.php";
  exit;
}

/* -------------------------------
   Commerce (PUBLIC)
-------------------------------- */

if ($sub === "/public/commerce/categories" && $method === "GET") {
  header("X-Route: public.commerce.categories.get");
  header("X-Correlation-Id: " . $correlationId);
  require __DIR__ . "/routes/public.commerce.categories.get.php";
  exit;
}

/** ✅ NEW: Services registry for booking flow (Ionic Footbath featured) */
if ($sub === "/public/commerce/services" && $method === "GET") {
  header("X-Route: public.commerce.services.get");
  header("X-Correlation-Id: " . $correlationId);
  require __DIR__ . "/routes/public.commerce.services.get.php";
  exit;
}

if ($sub === "/public/commerce/featured" && $method === "GET") {
  header("X-Route: public.commerce.featured.get");
  header("X-Correlation-Id: " . $correlationId);
  require __DIR__ . "/routes/public.commerce.featured.get.php";
  exit;
}

if ($sub === "/public/commerce/market-pulse" && $method === "GET") {
  header("X-Route: public.commerce.market-pulse.get");
  header("X-Correlation-Id: " . $correlationId);
  require __DIR__ . "/routes/public.commerce.market-pulse.get.php";
  exit;
}

if ($sub === "/public/commerce/products" && $method === "GET") {
  header("X-Route: public.commerce.products.get");
  header("X-Correlation-Id: " . $correlationId);
  require __DIR__ . "/routes/public.commerce.products.get.php";
  exit;
}

if ($sub === "/public/commerce/product" && $method === "GET") {
  header("X-Route: public.commerce.product.get");
  header("X-Correlation-Id: " . $correlationId);
  require __DIR__ . "/routes/public.commerce.product.get.php";
  exit;
}

if ($sub === "/public/commerce/checkout" && $method === "POST") {
  header("X-Route: public.commerce.checkout.post");
  header("X-Correlation-Id: " . $correlationId);
  require __DIR__ . "/routes/public.commerce.checkout.post.php";
  exit;
}
if ($sub === "/public/commerce/checkout" && $method === "POST") {
  header("X-Route: public.commerce.checkout.post");
  header("X-Correlation-Id: " . $correlationId);
  require __DIR__ . "/routes/public.commerce.checkout.post.php";
  exit;
}

if ($sub === "/public/commerce/juice-plan-checkout" && $method === "POST") {
  header("X-Route: public.commerce.juice-plan-checkout.post");
  header("X-Correlation-Id: " . $correlationId);
  require __DIR__ . "/routes/public.commerce.juice-plan-checkout.post.php";
  exit;
}
/**
 * If someone hits /public/commerce/* with a wrong method,
 * return 405 instead of falling into protected auth. The protected Square
 * customer sync route is intentionally allowed through to auth below.
 */
if (str_starts_with($sub, "/public/commerce/") && $sub !== "/public/commerce/square-customers/sync") {
  json_error("Method Not Allowed", 405, [
    "path" => $sub,
    "method" => $method,
    "correlation_id" => $correlationId
  ], [
    "X-Correlation-Id" => $correlationId,
    "X-Route" => "commerce.405",
  ]);
}

/* -------------------------------
   Story Mode (PUBLIC)
-------------------------------- */
if ($sub === "/story/characters" && $method === "GET") {
  header("X-Route: story.characters.get");
  header("X-Correlation-Id: " . $correlationId);
  require __DIR__ . "/routes/story.characters.get.php";
  exit;
}

if ($sub === "/story/quests" && $method === "GET") {
  header("X-Route: story.quests.get");
  header("X-Correlation-Id: " . $correlationId);
  require __DIR__ . "/routes/story.quests.get.php";
  exit;
}

if ($sub === "/story/quest" && $method === "GET") {
  header("X-Route: story.quest.get");
  header("X-Correlation-Id: " . $correlationId);
  require __DIR__ . "/routes/story.quest.get.php";
  exit;
}

if ($sub === "/story/run/start" && $method === "POST") {
  header("X-Route: story.run.start.post");
  header("X-Correlation-Id: " . $correlationId);
  require __DIR__ . "/routes/story.run.start.post.php";
  exit;
}

if ($sub === "/story/run/progress" && $method === "POST") {
  header("X-Route: story.run.progress.post");
  header("X-Correlation-Id: " . $correlationId);
  require __DIR__ . "/routes/story.run.progress.post.php";
  exit;
}

if ($sub === "/story/run/complete" && $method === "POST") {
  header("X-Route: story.run.complete.post");
  header("X-Correlation-Id: " . $correlationId);
  require __DIR__ . "/routes/story.run.complete.post.php";
  exit;
}

/* -------------------------------
   AUTH (protected routes)
-------------------------------- */
require_once __DIR__ . "/../bootstrap/auth.php";
require_api_key($pdo, $correlationId);

if ($sub === "/admin/leads" && $method === "GET") {
  header("X-Route: admin.leads.get");
  header("X-Correlation-Id: " . $correlationId);
  require __DIR__ . "/routes/admin.leads.get.php";
  exit;
}

if ($sub === "/public/commerce/square-customers/sync" && $method === "POST") {
  header("X-Route: public.commerce.square-customers.sync");
  header("X-Correlation-Id: " . $correlationId);
  require __DIR__ . "/routes/public.commerce.square-customers.sync.php";
  exit;
}

/* -------------------------------
   ROUTES (protected)
-------------------------------- */
if ($sub === "/jobs") {
  if ($method === "GET") {
    header("X-Route: jobs.get");
    header("X-Correlation-Id: " . $correlationId);
    require __DIR__ . "/routes/jobs.get.php";
    exit;
  }
  if ($method === "POST") {
    header("X-Route: jobs.post");
    header("X-Correlation-Id: " . $correlationId);
    require __DIR__ . "/routes/jobs.post.php";
    exit;
  }

  json_error("Method Not Allowed", 405, [
    "path" => $sub,
    "method" => $method,
    "correlation_id" => $correlationId
  ], [
    "X-Correlation-Id" => $correlationId,
    "X-Route" => "jobs.405",
  ]);
}

if ($sub === "/services" && $method === "GET") {
  header("X-Route: services.get");
  header("X-Correlation-Id: " . $correlationId);
  require __DIR__ . "/routes/services.get.php";
  exit;
}

if ($sub === "/service-details" && $method === "GET") {
  header("X-Route: service-details.get");
  header("X-Correlation-Id: " . $correlationId);
  require __DIR__ . "/routes/service-details.get.php";
  exit;
}

// Waitlist admin list
if ($sub === "/waitlist" && $method === "GET") {
  header("X-Route: waitlist.get");
  header("X-Correlation-Id: " . $correlationId);
  require __DIR__ . "/routes/waitlist.get.php";
  exit;
}

// Waitlist internal write
if ($sub === "/waitlist/admin" && $method === "POST") {
  header("X-Route: waitlist.admin.post");
  header("X-Correlation-Id: " . $correlationId);
  require __DIR__ . "/routes/waitlist.post.php";
  exit;
}

/* -------------------------------
   404
-------------------------------- */
json_error("Not Found", 404, [
  "path" => $sub,
  "method" => $method,
  "correlation_id" => $correlationId
], [
  "X-Correlation-Id" => $correlationId,
  "X-Route" => "not-found",
]);
