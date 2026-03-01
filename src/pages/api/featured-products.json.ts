import type { APIRoute } from "astro";

const SQUARE_VERSION = "2025-01-23"; // safe default
const BASE = "https://connect.squareup.com/v2";

export const GET: APIRoute = async () => {
  const token = import.meta.env.SQUARE_ACCESS_TOKEN;
  const locationId = import.meta.env.SQUARE_LOCATION_ID; // optional

  if (!token) {
    return new Response(
      JSON.stringify({ ok: false, error: "Missing SQUARE_ACCESS_TOKEN" }),
      { status: 500, headers: { "Content-Type": "application/json" } }
    );
  }

  // Example: pull items (you can filter by category, text, etc.)
  // We'll keep it simple and return a small set.
  const res = await fetch(`${BASE}/catalog/list?types=ITEM`, {
    headers: {
      Authorization: `Bearer ${token}`,
      "Square-Version": SQUARE_VERSION,
      "Content-Type": "application/json",
    },
  });

  if (!res.ok) {
    const text = await res.text();
    return new Response(JSON.stringify({ ok: false, error: text }), {
      status: 500,
      headers: { "Content-Type": "application/json" },
    });
  }

  const data = await res.json();

  // Map Square items into a carousel-friendly shape
  const items = (data.objects ?? []).map((obj: any) => {
    const item = obj.item_data;
    const firstVariation = item?.variations?.[0]?.item_variation_data;

    // NOTE: Square image URLs require another call (catalog/retrieve) unless you already have image_id mapping.
    // We’ll return imageId for now so you can resolve it later.
    const imageIds = item?.image_ids ?? [];

    return {
      id: obj.id,
      name: item?.name ?? "Untitled",
      description: item?.description ?? "",
      priceCents: firstVariation?.price_money?.amount ?? null,
      currency: firstVariation?.price_money?.currency ?? "USD",
      imageId: imageIds[0] ?? null,
      // If you already have square.site product URLs, you can store them as custom attributes or map by name.
      // For now, leave null and map manually client-side.
      url: null,
    };
  });

  // OPTIONAL: pick only featured items by name (fast + effective)
  const featuredNames = new Set([
    "Noir Bloom Whipped Body Butter (4 oz)",
    "Velvet Forest Ritual Whipped Body Butter (4 oz)",
    "Golden Restore Gummies (Inflammation Support) – 60 ct",
    "Tranquil Night Gummies (Sleep Support) – 60 ct",
    "Whole Root Revival Capsules – 60 ct",
  ]);

  const featured = items
    .filter((x: any) => featuredNames.has(x.name))
    .slice(0, 12);

  return new Response(JSON.stringify({ ok: true, items: featured }), {
    headers: {
      "Content-Type": "application/json",
      // simple caching: 10 min CDN + 10 min browser
      "Cache-Control": "public, max-age=600, s-maxage=600",
    },
  });
};
