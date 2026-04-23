// src/pages/api/commerce/pdp/[id].ts
import type { APIRoute } from "astro";
import { getSubscriptionConfig } from "../../../../../lib/subscription-catalog";

export const prerender = false;

type Money = {
  amount: number;
  currency: string;
};

type RawProduct = {
  id?: string | number;
  product_id?: string | number;
  name?: string;
  description?: string;
  image_url?: string;
  variation_id?: string;
  has_price?: boolean;
  price_money?: Money | null;
  checkout_url?: string;
  buy_url?: string;
  square_url?: string;
  permalink?: string;
  [key: string]: unknown;
};

type ProductApiResponse =
  | {
      item?: RawProduct | null;
      product?: RawProduct | null;
      items?: RawProduct[];
      products?: RawProduct[];
      data?: RawProduct[] | RawProduct | null;
      ok?: boolean;
      error?: string;
      [key: string]: unknown;
    }
  | RawProduct[]
  | null;

const API_BASE =
  (import.meta.env.PUBLIC_COMMERCE_API_BASE as string | undefined) ||
  "https://smashpro.app/api/v1/routes";

const APP_SLUG =
  (import.meta.env.PUBLIC_COMMERCE_APP_SLUG as string | undefined) ||
  "deeper-than-skin";

const baseRaw = import.meta.env.BASE_URL || "/";
const base = baseRaw.endsWith("/") ? baseRaw : `${baseRaw}/`;

const JSON_HEADERS = {
  "Content-Type": "application/json; charset=utf-8",
  "Cache-Control": "no-store",
};

function jsonResponse(payload: unknown, status = 200) {
  return new Response(JSON.stringify(payload), {
    status,
    headers: JSON_HEADERS,
  });
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === "object" && value !== null && !Array.isArray(value);
}

function safeString(value: unknown): string {
  return typeof value === "string" ? value : value == null ? "" : String(value);
}

function pickFallback(name: string) {
  const s = safeString(name).toLowerCase();
  if (s.includes("juice") || s.includes("beverage")) return `${base}images/cat-juices.jpg`;
  if (s.includes("moss")) return `${base}images/cat-seamoss.jpg`;
  if (s.includes("kit") || s.includes("cleanse") || s.includes("detox")) return `${base}images/cat-kits.jpg`;
  if (s.includes("tea") || s.includes("herb") || s.includes("oil")) return `${base}images/cat-herbs.jpg`;
  return `${base}images/product-fallback.jpg`;
}

async function fetchJson(url: string): Promise<ProductApiResponse> {
  const res = await fetch(url, {
    headers: { Accept: "application/json" },
  });

  const text = await res.text();

  if (!res.ok) {
    throw new Error(`HTTP ${res.status}`);
  }

  if (!text.trim()) {
    throw new Error("Empty response");
  }

  if (text.trim().startsWith("<")) {
    throw new Error("Non-JSON response");
  }

  try {
    return JSON.parse(text) as ProductApiResponse;
  } catch {
    throw new Error("Invalid JSON response");
  }
}

function extractProduct(data: ProductApiResponse): RawProduct | null {
  if (!data) return null;

  if (Array.isArray(data)) {
    return isRecord(data[0]) ? (data[0] as RawProduct) : null;
  }

  if (isRecord(data.item)) return data.item as RawProduct;
  if (isRecord(data.product)) return data.product as RawProduct;

  if (Array.isArray(data.items) && data.items.length > 0 && isRecord(data.items[0])) {
    return data.items[0] as RawProduct;
  }

  if (Array.isArray(data.products) && data.products.length > 0 && isRecord(data.products[0])) {
    return data.products[0] as RawProduct;
  }

  if (isRecord(data.data)) {
    return data.data as RawProduct;
  }

  if (Array.isArray(data.data) && data.data.length > 0 && isRecord(data.data[0])) {
    return data.data[0] as RawProduct;
  }

  return null;
}

function extractItems(data: ProductApiResponse): RawProduct[] {
  if (!data) return [];

  if (Array.isArray(data)) {
    return data.filter((x): x is RawProduct => isRecord(x));
  }

  if (Array.isArray(data.items)) {
    return data.items.filter((x): x is RawProduct => isRecord(x));
  }

  if (Array.isArray(data.products)) {
    return data.products.filter((x): x is RawProduct => isRecord(x));
  }

  if (Array.isArray(data.data)) {
    return data.data.filter((x): x is RawProduct => isRecord(x));
  }

  return [];
}

function normalizeMoney(value: unknown): Money | null {
  if (!isRecord(value)) return null;

  const amountRaw = value.amount;
  const currencyRaw = value.currency;

  if (typeof amountRaw !== "number" || !Number.isFinite(amountRaw)) return null;
  if (typeof currencyRaw !== "string" || !currencyRaw.trim()) return null;

  return {
    amount: amountRaw,
    currency: currencyRaw.trim(),
  };
}

function normalizeProduct(product: RawProduct) {
  const id = safeString(product.id ?? product.product_id).trim();
  const name = safeString(product.name).trim() || "Product";
  const description = safeString(product.description).trim();

  const imageCandidate = safeString(product.image_url).trim();
  const image_url = imageCandidate || pickFallback(name);

  const variationId = safeString(product.variation_id).trim();
  const hasPrice = Boolean(product.has_price);
  const priceMoney = hasPrice ? normalizeMoney(product.price_money) : null;

  return {
    product: {
      id,
      name,
      description,
      image_url,
      brand: "Deeper Than Skin",
      badges: [] as string[],
    },
    one_time: {
      enabled: variationId.length > 0,
      variation_id: variationId || null,
      price_money: priceMoney,
      has_price: hasPrice && !!priceMoney,
    },
    subscription: getSubscriptionConfig(id),
    merchandising: {
      ritual_title: "Make it a ritual",
      ritual_text: "Pair a product with a wellness staple for a complete routine.",
    },
    policies: {
      checkout_summary: "Payment happens securely at Square checkout.",
    },
    raw: product,
  };
}

export const GET: APIRoute = async ({ params }) => {
  const id = safeString(params.id).trim();

  if (!id) {
    return jsonResponse({ ok: false, error: "Missing product id" }, 400);
  }

  const productEndpoint =
    `${API_BASE}/public.commerce.product.get.php` +
    `?app_slug=${encodeURIComponent(APP_SLUG)}` +
    `&id=${encodeURIComponent(id)}`;

  const listEndpoint =
    `${API_BASE}/public.commerce.products.get.php` +
    `?app_slug=${encodeURIComponent(APP_SLUG)}` +
    `&limit=700`;

  try {
    try {
      const data = await fetchJson(productEndpoint);
      const product = extractProduct(data);

      if (product) {
        return jsonResponse({
          ok: true,
          ...normalizeProduct(product),
        });
      }
    } catch {
      // Fall through to list endpoint lookup.
    }

    const list = await fetchJson(listEndpoint);
    const items = extractItems(list);

    const found =
      items.find((x) => safeString(x.id ?? x.product_id).trim() === id) || null;

    if (!found) {
      return jsonResponse({ ok: false, error: "Product not found" }, 404);
    }

    return jsonResponse({
      ok: true,
      ...normalizeProduct(found),
    });
  } catch (error: unknown) {
    const message = error instanceof Error ? error.message : "Unable to load PDP";

    return jsonResponse(
      {
        ok: false,
        error: message,
      },
      500
    );
  }
};