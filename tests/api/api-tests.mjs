import { existsSync, readFileSync } from "node:fs";
import { dirname, resolve } from "node:path";
import { fileURLToPath } from "node:url";

const __dirname = dirname(fileURLToPath(import.meta.url));

loadEnv(resolve(__dirname, ".env"));

const API_BASE = process.env.API_BASE || "https://smashpro.app/api/v1/index.php";
const APP_SLUG = process.env.APP_SLUG || "deeper-than-skin";
const API_KEY = process.env.API_KEY || "";

const requiredTests = [
  {
    name: "PUBLIC health",
    method: "GET",
    path: "health",
    expectStatus: [200, 299],
  },
  {
    name: "PUBLIC commerce categories",
    method: "GET",
    path: "public/commerce/categories",
    query: { app_slug: APP_SLUG },
    expectStatus: [200, 299],
  },
  {
    name: "PUBLIC commerce products",
    method: "GET",
    path: "public/commerce/products",
    query: { app_slug: APP_SLUG },
    expectStatus: [200, 299],
  },
  {
    name: "PUBLIC commerce products lake-carolina-market",
    method: "GET",
    path: "public/commerce/products",
    query: { app_slug: APP_SLUG, category: "lake-carolina-market" },
    expectStatus: [200, 299],
  },
  {
    name: "PUBLIC commerce featured",
    method: "GET",
    path: "public/commerce/featured",
    query: { app_slug: APP_SLUG },
    expectStatus: [200, 299],
  },
  {
    name: "PUBLIC commerce services",
    method: "GET",
    path: "public/commerce/services",
    query: { app_slug: APP_SLUG },
    expectStatus: [200, 299],
  },
  {
    name: "PUBLIC waitlist count",
    method: "GET",
    path: "public/waitlist/count",
    query: { app_slug: APP_SLUG },
    expectStatus: [200, 299],
  },
  {
    name: "POST checkout empty cart controlled error",
    method: "POST",
    path: "public/commerce/checkout",
    query: { app_slug: APP_SLUG },
    body: { cart: [] },
    expectStatus: [400, 400],
    validateJson: (json) => json?.ok === false,
  },
  {
    name: "POST quiz lead smoke",
    method: "POST",
    path: "public/quiz-lead",
    query: { app_slug: APP_SLUG },
    body: {
      app_slug: APP_SLUG,
      name: "API Test",
      email: "api-test@example.com",
      kit_slug: "balanced-starter-kit",
      kit_title: "The Balanced Starter Kit",
      goals: ["energy"],
      prefs: ["juice"],
      notes: ["low-sugar"],
      source: "api-test",
      company: "",
    },
    expectStatus: [200, 499],
    validateJson: (json) => json?.ok === true || json?.ok === false,
    failOnStatuses: [404],
  },
];

const protectedTests = [
  {
    name: "PROTECTED admin leads",
    method: "GET",
    path: "admin/leads",
    query: { app_slug: APP_SLUG, api_key: API_KEY },
    expectStatus: [200, 299],
    failOnStatuses: [404],
  },
  {
    name: "PROTECTED jobs",
    method: "GET",
    path: "jobs",
    query: { api_key: API_KEY },
    expectStatus: [200, 400],
    failOnStatuses: [404],
    validateJson: (json, status) => {
      if (status >= 200 && status <= 299) return true;
      return status === 400 && json?.error === "user_id is required";
    },
  },
  {
    name: "PROTECTED services",
    method: "GET",
    path: "services",
    query: { api_key: API_KEY },
    expectStatus: [200, 299],
    failOnStatuses: [404],
  },
];

const tests = API_KEY
  ? [...requiredTests, ...protectedTests]
  : requiredTests;

if (!API_KEY) {
  console.warn("WARNING: API_KEY is missing. Protected tests will be skipped.");
}

let passed = 0;
let failed = 0;
let skipped = API_KEY ? 0 : protectedTests.length;

for (const test of tests) {
  const result = await runTest(test);
  if (result.pass) passed += 1;
  else failed += 1;
}

if (skipped > 0) {
  console.log(`\nSkipped ${skipped} protected test(s) because API_KEY is missing.`);
}

console.log(`\nSummary: Passed ${passed} / Failed ${failed}`);

if (failed > 0) {
  process.exitCode = 1;
}

async function runTest(test) {
  console.log(`\n${test.name}`);

  const url = buildUrl(test.path, test.query);
  const init = {
    method: test.method,
    headers: {
      Accept: "application/json",
    },
  };

  if (test.body !== undefined) {
    init.headers["Content-Type"] = "application/json";
    init.body = JSON.stringify(test.body);
  }

  let response;
  let text = "";

  try {
    response = await fetch(url, init);
    text = await response.text();
  } catch (error) {
    console.log("HTTP status: request failed");
    console.log(`FAIL: ${error?.message || "request error"}`);
    return { pass: false };
  }

  console.log(`HTTP status: ${response.status}`);

  const fatalText = hasPhpFatalText(text);
  const parsed = parseJson(text);
  const isJson = parsed.ok;
  const statusOk = isWithinStatus(response.status, test.expectStatus);
  const statusFailedExplicitly = test.failOnStatuses?.includes(response.status) || false;
  const jsonOk = test.validateJson ? test.validateJson(parsed.value, response.status) : true;

  const failures = [];

  if (response.status >= 500) failures.push("status >= 500");
  if (statusFailedExplicitly) failures.push(`unexpected status ${response.status}`);
  if (!statusOk) failures.push(`status outside expected range ${test.expectStatus.join("-")}`);
  if (!isJson) failures.push("response is not valid JSON");
  if (fatalText) failures.push("response includes PHP warning/fatal text");
  if (isJson && !jsonOk) failures.push("JSON body did not match expected shape");

  if (failures.length > 0) {
    console.log(`FAIL: ${failures.join("; ")}`);
    printBodyPreview(text);
    return { pass: false };
  }

  console.log("PASS");
  return { pass: true };
}

function buildUrl(path, query = {}) {
  const url = new URL(API_BASE);
  url.searchParams.set("path", path);

  for (const [key, value] of Object.entries(query)) {
    if (value !== undefined && value !== null && String(value) !== "") {
      url.searchParams.set(key, String(value));
    }
  }

  return url;
}

function parseJson(text) {
  try {
    return { ok: true, value: JSON.parse(text) };
  } catch {
    return { ok: false, value: null };
  }
}

function isWithinStatus(status, range = [200, 299]) {
  const [min, max] = range;
  return status >= min && status <= max;
}

function hasPhpFatalText(text) {
  return /(<br\s*\/?>\s*)?(Fatal error|Parse error|Warning|Notice|Deprecated):/i.test(text)
    || /SQLSTATE\[[A-Z0-9]+\]/i.test(text)
    || /Stack trace:/i.test(text);
}

function printBodyPreview(text) {
  const preview = String(text || "").replace(/\s+/g, " ").trim().slice(0, 500);
  if (preview) console.log(`Body preview: ${preview}`);
}

function loadEnv(path) {
  if (!existsSync(path)) return;

  const raw = readFileSync(path, "utf8");
  for (const line of raw.split(/\r?\n/)) {
    const trimmed = line.trim();
    if (!trimmed || trimmed.startsWith("#")) continue;

    const eq = trimmed.indexOf("=");
    if (eq === -1) continue;

    const key = trimmed.slice(0, eq).trim();
    let value = trimmed.slice(eq + 1).trim();

    if (
      (value.startsWith('"') && value.endsWith('"'))
      || (value.startsWith("'") && value.endsWith("'"))
    ) {
      value = value.slice(1, -1);
    }

    if (key && process.env[key] === undefined) {
      process.env[key] = value;
    }
  }
}
