// src/lib/subscription-catalog.ts

export type Money = { amount: number; currency: string };

export type SubscriptionOption = {
  id: string;
  label: string;
  description?: string;
  badge?: string;
  subscriber_price_money: Money;
  compare_at_price_money?: Money | null;
  discount_percent?: number | null;
  square_subscription_plan_variation_id: string;
  cadence: "2w" | "4w" | "6w" | "8w" | "monthly";
  enabled: boolean;
};

export type ProductSubscriptionConfig = {
  enabled: boolean;
  headline?: string;
  subheadline?: string;
  options: SubscriptionOption[];
  cancellation_summary?: string;
  shipping_summary?: string;
};

type CatalogMap = Record<string, ProductSubscriptionConfig>;

function money(amount: number, currency = "USD"): Money {
  return { amount, currency };
}

/**
 * Map this by PRODUCT ID from your commerce API.
 * Replace the sample product id below with your real product ids.
 */
export const SUBSCRIPTION_CATALOG: CatalogMap = {
  "3LATECLIFLHDKBII35Z6UZV2": {
    enabled: true,
    headline: "Subscribe & save",
    subheadline: "Keep your ritual stocked on your schedule.",
    cancellation_summary: "Pause, change frequency, or cancel anytime.",
    shipping_summary: "Recurring shipments are billed securely and fulfilled automatically.",
    options: [
      {
        id: "every-2-weeks",
        label: "Every 2 weeks",
        description: "Best for heavy daily use",
        badge: "Fastest refill",
        subscriber_price_money: money(2250, "USD"),
        compare_at_price_money: money(2500, "USD"),
        discount_percent: 10,
        square_subscription_plan_variation_id: "REPLACE_WITH_SQUARE_PLAN_VARIATION_ID_2W",
        cadence: "2w",
        enabled: true,
      },
      {
        id: "every-4-weeks",
        label: "Every 4 weeks",
        description: "Most popular cadence",
        badge: "Most popular",
        subscriber_price_money: money(2250, "USD"),
        compare_at_price_money: money(2500, "USD"),
        discount_percent: 10,
        square_subscription_plan_variation_id: "REPLACE_WITH_SQUARE_PLAN_VARIATION_ID_4W",
        cadence: "4w",
        enabled: true,
      },
      {
        id: "every-6-weeks",
        label: "Every 6 weeks",
        description: "Slower replenishment rhythm",
        badge: "Flexible",
        subscriber_price_money: money(2300, "USD"),
        compare_at_price_money: money(2500, "USD"),
        discount_percent: 8,
        square_subscription_plan_variation_id: "REPLACE_WITH_SQUARE_PLAN_VARIATION_ID_6W",
        cadence: "6w",
        enabled: true,
      },
    ],
  },
};

export function getSubscriptionConfig(productId: string | number | null | undefined): ProductSubscriptionConfig | null {
  const key = String(productId || "").trim();
  if (!key) return null;
  return SUBSCRIPTION_CATALOG[key] || null;
}