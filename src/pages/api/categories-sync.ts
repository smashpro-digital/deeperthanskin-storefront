export async function GET() {
  const url =
    "https://smashpro.app/api/v1/routes/public.commerce.categories.get.php" +
    "?app_slug=deeper-than-skin&sync_db=1";

  try {
    await fetch(url, { method: "GET" });
  } catch {}

  return new Response(JSON.stringify({ ok: true }), {
    status: 200,
    headers: { "Content-Type": "application/json" },
  });
}