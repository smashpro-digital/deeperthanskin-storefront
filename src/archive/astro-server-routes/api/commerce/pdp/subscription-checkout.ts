// src/pages/api/commerce/subscription-checkout.ts
import type { APIRoute } from "astro";
import { getSubscriptionConfig } from "../../../lib/subscription-catalog";

type Body = {
  productId?: string;
  optionId?: string;
};

const PREBUILT_LINKS: Record<string, string> = {
  // format: `${productId}::${optionId}`: "https://square.link/..."
  "3LATECLIFLHDKBII35Z6UZV2::every-2-weeks": "REPLACE_WITH_SQUARE_PAYMENT_LINK_2W",
  "3LATECLIFLHDKBII35Z6UZV2::every-4-weeks": "REPLACE_WITH_SQUARE_PAYMENT_LINK_4W",
  "3LATECLIFLHDKBII35Z6UZV2::every-6-weeks": "REPLACE_WITH_SQUARE_PAYMENT_LINK_6W",
};

export const POST: APIRoute = async ({ request }) => {
  try {
    const body = (await request.json()) as Body;

    const productId = String(body?.productId || "").trim();
    const optionId = String(body?.optionId || "").trim();

    if (!productId || !optionId) {
      return new Response(JSON.stringify({ ok: false, error: "Missing productId or optionId" }), {
        status: 400,
        headers: { "Content-Type": "application/json" },
      });
    }

    const config = getSubscriptionConfig(productId);
    if (!config?.enabled) {
      return new Response(JSON.stringify({ ok: false, error: "Subscriptions unavailable for this product" }), {
        status: 400,
        headers: { "Content-Type": "application/json" },
      });
    }

    const option = config.options.find((x) => x.id === optionId && x.enabled);
    if (!option) {
      return new Response(JSON.stringify({ ok: false, error: "Invalid subscription option" }), {
        status: 400,
        headers: { "Content-Type": "application/json" },
      });
    }

    const key = `${productId}::${optionId}`;
    const checkoutUrl = PREBUILT_LINKS[key];

    if (!checkoutUrl || checkoutUrl.startsWith("REPLACE_WITH_")) {
      return new Response(
        JSON.stringify({
          ok: false,
          error:
            "Subscription checkout link is not configured yet. Add a Square payment link for this option.",
        }),
        {
          status: 501,
          headers: { "Content-Type": "application/json" },
        }
      );
    }

    return new Response(
      JSON.stringify({
        ok: true,
        checkoutUrl,
      }),
      {
        status: 200,
        headers: { "Content-Type": "application/json", "Cache-Control": "no-store" },
      }
    );
  } catch (error: any) {
    return new Response(
      JSON.stringify({
        ok: false,
        error: error?.message || "Unable to start subscription checkout",
      }),
      {
        status: 500,
        headers: { "Content-Type": "application/json" },
      }
    );
  }
};