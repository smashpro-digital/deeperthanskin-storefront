export async function GET() {
  const upstream =
    "https://smashpro.app/api/v1/index.php" +
    "?path=public/commerce/categories" +
    "&app_slug=deeper-than-skin" +
    "&sync_db=1";

  try {
    const r = await fetch(upstream, {
      method: "GET",
      headers: { Accept: "application/json" },
      cache: "no-store",
    });

    const text = await r.text();

    // pass-through “ok” if upstream returned ok:true, otherwise report what we got
    let data: any = null;
    try { data = JSON.parse(text); } catch {}

    return new Response(
      JSON.stringify({
        ok: r.ok && (data?.ok !== false),
        upstream_ok: r.ok,
        upstream_status: r.status,
        upstream_json: data ?? null,
        upstream_text_preview: data ? null : text.slice(0, 250),
      }),
      {
        status: 200,
        headers: {
          "Content-Type": "application/json; charset=utf-8",
          "Cache-Control": "no-store, no-cache, must-revalidate",
        },
      }
    );
  } catch (e: any) {
    return new Response(
      JSON.stringify({
        ok: false,
        error: "proxy_fetch_failed",
        details: String(e?.message || e),
      }),
      { status: 200, headers: { "Content-Type": "application/json; charset=utf-8" } }
    );
  }
}