// src/lib/subscription-catalog.ts

export type Money = { amount: number; currency: string };

export type PurchaseType = "product" | "subscription" | "service" | "appointment";

export type ServiceBookingConfig = {
  enabled: boolean;
  purchase_type: "service" | "appointment";
  cta_label?: string;
  booking_url: string;
  booking_summary?: string;
};

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

type SubscriptionCatalogMap = Record<string, ProductSubscriptionConfig>;
type ServiceCatalogMap = Record<string, ServiceBookingConfig>;

function money(amount: number, currency = "USD"): Money {
  return { amount, currency };
}

/**
 * Map subscriptions by PRODUCT ID from your commerce API.
 */
export const SUBSCRIPTION_CATALOG: SubscriptionCatalogMap = {
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

/**
 * Map service / appointment products by PRODUCT ID from your commerce API.
 *
 * Ionic Foot Detox product id from your URL/screenshot:
 * BSRNUARXBO7IYS7GDGUJYRP4
 */
export const SERVICE_BOOKING_CATALOG: ServiceCatalogMap = {
  BSRNUARXBO7IYS7GDGUJYRP4: {
    enabled: true,
    purchase_type: "appointment",
    cta_label: "Book appointment",
    booking_url: "REPLACE_WITH_SQUARE_APPOINTMENTS_BOOKING_URL",
    booking_summary:
      "This is a scheduled service. You’ll choose your time in Square Appointments.",
  },
};

export function getSubscriptionConfig(
  productId: string | number | null | undefined
): ProductSubscriptionConfig | null {
  const key = String(productId || "").trim();
  if (!key) return null;

  const config = SUBSCRIPTION_CATALOG[key];
  return config?.enabled ? config : null;
}

export function getServiceBookingConfig(
  productId: string | number | null | undefined
): ServiceBookingConfig | null {
  const key = String(productId || "").trim();
  if (!key) return null;

  const config = SERVICE_BOOKING_CATALOG[key];
  return config?.enabled ? config : null;
}

export function isServiceBookingProduct(
  productId: string | number | null | undefined
): boolean {
  return !!getServiceBookingConfig(productId);
}