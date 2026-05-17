import type { APIRoute } from "astro";

type Money = {
  amount?: number;
  currency?: string;
};

type SquareLineItem = {
  name?: string;
  quantity?: string;
  total_money?: Money;
  gross_sales_money?: Money;
};

type SquareOrder = {
  state?: string;
  created_at?: string;
  closed_at?: string;
  line_items?: SquareLineItem[];
  total_money?: Money;
  net_amount_due_money?: Money;
  tenders?: unknown[];
};

type PulseItem = {
  label: string;
  meta: string;
  type: "sale" | "note";
};

const SQUARE_SEARCH_ORDERS_URL = "https://connect.squareup.com/v2/orders/search";
const MARKET_TIME_ZONE = "America/New_York";

const fallbackPulse = (): Response =>
  jsonResponse({
    ok: true,
    source: "fallback",
    updatedAt: new Date().toISOString(),
    stats: {
      ordersToday: 0,
      itemsSoldToday: 0,
      topItem: "Fresh pours",
      revenueToday: null,
    },
    ticker: [
      {
        label: "Fresh pours are available at the market table",
        meta: "Today",
        type: "note",
      },
      {
        label: "Ask about seasonal botanical lemonades",
        meta: "Market special",
        type: "note",
      },
    ],
  });

const jsonResponse = (payload: unknown, status = 200): Response =>
  new Response(JSON.stringify(payload), {
    status,
    headers: {
      "Content-Type": "application/json; charset=utf-8",
      "Cache-Control": "no-store, max-age=0",
    },
  });

const cleanText = (value: unknown, fallback = ""): string =>
  String(value ?? fallback)
    .replace(/[<>{}[\]\\]/g, "")
    .replace(/\s+/g, " ")
    .trim()
    .slice(0, 80);

const quantityNumber = (value: unknown): number => {
  const parsed = Number.parseFloat(String(value ?? "1"));
  return Number.isFinite(parsed) && parsed > 0 ? parsed : 1;
};

const moneyAmount = (money?: Money): number => {
  const amount = Number(money?.amount ?? 0);
  return Number.isFinite(amount) ? amount : 0;
};

const formatAgo = (iso: string): string => {
  const then = Date.parse(iso);
  if (!Number.isFinite(then)) return "Today";

  const diffMinutes = Math.max(0, Math.round((Date.now() - then) / 60000));
  if (diffMinutes < 1) return "Just now";
  if (diffMinutes < 60) return `${diffMinutes} min ago`;

  const diffHours = Math.round(diffMinutes / 60);
  if (diffHours < 24) return `${diffHours} hr ago`;

  return "Today";
};

const getZonedParts = (date: Date, timeZone: string) => {
  const parts = new Intl.DateTimeFormat("en-US", {
    timeZone,
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
    hour: "2-digit",
    minute: "2-digit",
    second: "2-digit",
    hourCycle: "h23",
  }).formatToParts(date);

  const pick = (type: string) => Number(parts.find((part) => part.type === type)?.value || 0);

  return {
    year: pick("year"),
    month: pick("month"),
    day: pick("day"),
    hour: pick("hour"),
    minute: pick("minute"),
    second: pick("second"),
  };
};

const zonedTimeToUtc = (
  year: number,
  month: number,
  day: number,
  hour: number,
  minute: number,
  second: number,
  timeZone: string
): Date => {
  const utcGuess = new Date(Date.UTC(year, month - 1, day, hour, minute, second));
  const zoned = getZonedParts(utcGuess, timeZone);
  const zonedAsUtc = Date.UTC(
    zoned.year,
    zoned.month - 1,
    zoned.day,
    zoned.hour,
    zoned.minute,
    zoned.second
  );
  const offset = zonedAsUtc - utcGuess.getTime();

  return new Date(utcGuess.getTime() - offset);
};

const marketDayRange = (): { startAt: string; endAt: string } => {
  const nowParts = getZonedParts(new Date(), MARKET_TIME_ZONE);
  const start = zonedTimeToUtc(
    nowParts.year,
    nowParts.month,
    nowParts.day,
    0,
    0,
    0,
    MARKET_TIME_ZONE
  );
  const end = zonedTimeToUtc(
    nowParts.year,
    nowParts.month,
    nowParts.day + 1,
    0,
    0,
    0,
    MARKET_TIME_ZONE
  );

  return {
    startAt: start.toISOString(),
    endAt: end.toISOString(),
  };
};

const isClosedSale = (order: SquareOrder): boolean => {
  const state = String(order?.state || "").toUpperCase();
  const amountDue = moneyAmount(order?.net_amount_due_money);

  return (
    state === "COMPLETED" &&
    (Boolean(order?.closed_at) || amountDue <= 0 || Boolean(order?.tenders?.length))
  );
};

const saleLabel = (name: string, quantity: number): string => {
  const roundedQuantity = Number.isInteger(quantity) ? quantity : Number(quantity.toFixed(1));
  const isPour = /pour|juice|lemonade|tea|sip|smoothie/i.test(name);

  if (roundedQuantity > 1) {
    return `${roundedQuantity} ${name} picked up`;
  }

  return `${name} ${isPour ? "fresh pour sold" : "sold at the market"}`;
};

export const GET: APIRoute = async () => {
  const accessToken = import.meta.env.SQUARE_ACCESS_TOKEN;
  const locationId =
    import.meta.env.SQUARE_LOCATION_ID_MARKET || import.meta.env.SQUARE_LOCATION_ID;
  const squareVersion = import.meta.env.SQUARE_VERSION || "2025-01-23";

  if (!accessToken || !locationId) {
    return fallbackPulse();
  }

  const { startAt, endAt } = marketDayRange();

  try {
    const response = await fetch(SQUARE_SEARCH_ORDERS_URL, {
      method: "POST",
      headers: {
        Authorization: `Bearer ${accessToken}`,
        "Content-Type": "application/json",
        "Square-Version": squareVersion,
      },
      body: JSON.stringify({
        location_ids: [locationId],
        limit: 1000,
        return_entries: false,
        query: {
          filter: {
            date_time_filter: {
              closed_at: {
                start_at: startAt,
                end_at: endAt,
              },
            },
            state_filter: {
              states: ["COMPLETED"],
            },
          },
          sort: {
            sort_field: "CLOSED_AT",
            sort_order: "DESC",
          },
        },
      }),
    });

    if (!response.ok) {
      return fallbackPulse();
    }

    const payload = await response.json();
    const orders = Array.isArray(payload?.orders)
      ? (payload.orders as SquareOrder[]).filter(isClosedSale)
      : [];

    const itemCounts = new Map<string, number>();
    const ticker: PulseItem[] = [];

    let itemsSoldToday = 0;
    let revenueCents = 0;

    for (const order of orders) {
      revenueCents += moneyAmount(order.total_money);
      const happenedAt = order.closed_at || order.created_at || new Date().toISOString();

      for (const item of order.line_items || []) {
        const name = cleanText(item.name, "Market item");
        if (!name) continue;

        const quantity = quantityNumber(item.quantity);
        itemsSoldToday += quantity;
        itemCounts.set(name, (itemCounts.get(name) || 0) + quantity);

        if (ticker.length < 10) {
          ticker.push({
            label: saleLabel(name, quantity),
            meta: formatAgo(happenedAt),
            type: "sale",
          });
        }
      }
    }

    const topItem =
      Array.from(itemCounts.entries()).sort((a, b) => b[1] - a[1])[0]?.[0] || "Fresh pours";

    return jsonResponse({
      ok: true,
      source: "square",
      updatedAt: new Date().toISOString(),
      stats: {
        ordersToday: orders.length,
        itemsSoldToday,
        topItem,
        revenueToday: Number((revenueCents / 100).toFixed(2)),
      },
      ticker: ticker.length
        ? ticker
        : [
            {
              label: "Fresh pours are moving at the market table",
              meta: "Today",
              type: "note",
            },
          ],
    });
  } catch {
    return fallbackPulse();
  }
};
