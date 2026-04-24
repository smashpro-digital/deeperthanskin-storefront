// ../lib/cart.js
// Deeper Than Skin — Cart v1 (single source of truth)
// Storage shape is ALWAYS: { v: 1, items: [...] }

const CART_KEY = "dts_cart_v1";

function normalize(raw) {
  // Accept legacy shapes:
  // - [] (array of items)
  // - { items: [] }
  // - null/undefined
  if (Array.isArray(raw)) return { v: 1, items: raw };
  if (raw && Array.isArray(raw.items)) return { v: 1, items: raw.items };
  return { v: 1, items: [] };
}

function canUseBrowser() {
  return typeof window !== "undefined" && typeof localStorage !== "undefined";
}

function read() {
  if (!canUseBrowser()) return { v: 1, items: [] };

  try {
    const raw = JSON.parse(localStorage.getItem(CART_KEY) || "null");
    return normalize(raw);
  } catch {
    return { v: 1, items: [] };
  }
}

function write(state) {
  if (!canUseBrowser()) return;

  const next = normalize(state);
  localStorage.setItem(CART_KEY, JSON.stringify(next));

  // Notify pages/components (category/product/cart/etc.) to refresh UI
  window.dispatchEvent(new CustomEvent("dts:cart"));
}

function keyOf(id, variation_id) {
  return `${String(id || "")}::${String(variation_id || "")}`;
}

function toMoneyObject(value) {
  if (value && typeof value === "object" && typeof value.amount === "number") {
    return {
      amount: Number(value.amount),
      currency: value.currency || "USD",
    };
  }

  const num = Number(value);
  if (!Number.isFinite(num)) return null;

  // Supports either dollars (e.g. 12.99) or cents if already integer-ish and large.
  const amount = num >= 100 && Number.isInteger(num) ? num : Math.round(num * 100);

  return {
    amount,
    currency: "USD",
  };
}

function normalizeIncomingItem(item) {
  const id = String(item?.id || item?.catalogItemId || "").trim();
  const variation_id = String(
    item?.variation_id ||
      item?.variationId ||
      item?.subscription ||
      ""
  ).trim();

  return {
    id,
    variation_id,
    name: item?.name || item?.title || "Item",
    image_url: item?.image_url || item?.image || "",
    price_money: item?.price_money || toMoneyObject(item?.price),
    has_price:
      typeof item?.has_price === "boolean"
        ? item.has_price
        : !!(item?.price_money || Number.isFinite(Number(item?.price))),
    qty: Math.max(1, Number(item?.qty || 1)),
    subscription: item?.subscription || "",
    modifiers: Array.isArray(item?.modifiers) ? item.modifiers : [],
    meta: item?.meta && typeof item.meta === "object" ? item.meta : {},
  };
}

export const Cart = {
  key: CART_KEY,

  get() {
    return read();
  },

  set(items) {
    const normalizedItems = Array.isArray(items)
      ? items.map((item) => normalizeIncomingItem(item))
      : [];

    write({ v: 1, items: normalizedItems });
  },

  clear() {
    write({ v: 1, items: [] });
  },

  count() {
    const { items } = read();
    return items.reduce((sum, it) => sum + Math.max(0, Number(it?.qty || 0)), 0);
  },

  add(item) {
    const state = read();
    const items = state.items;

    const normalized = normalizeIncomingItem(item);
    const id = normalized.id;

    if (!id) throw new Error("Missing product id");

    const variation_id = normalized.variation_id;
    const k = keyOf(id, variation_id);

    const found = items.find((x) => keyOf(x?.id, x?.variation_id) === k);
    const qtyAdd = Math.max(1, Number(normalized.qty || 1));

    if (found) {
      found.qty = Math.max(1, Number(found.qty || 1)) + qtyAdd;

      // Fill any missing fields from the latest add attempt
      if (!found.name && normalized.name) found.name = normalized.name;
      if (!found.image_url && normalized.image_url) found.image_url = normalized.image_url;
      if (!found.price_money && normalized.price_money) found.price_money = normalized.price_money;
      if (!found.has_price && normalized.has_price) found.has_price = normalized.has_price;
      if (!found.subscription && normalized.subscription) found.subscription = normalized.subscription;
      if ((!found.modifiers || !found.modifiers.length) && normalized.modifiers?.length) {
        found.modifiers = normalized.modifiers;
      }
      if ((!found.meta || !Object.keys(found.meta).length) && normalized.meta) {
        found.meta = normalized.meta;
      }
    } else {
      items.push({
        id,
        variation_id,
        name: normalized.name,
        image_url: normalized.image_url,
        price_money: normalized.price_money,
        has_price: !!normalized.has_price,
        qty: qtyAdd,
        subscription: normalized.subscription,
        modifiers: normalized.modifiers,
        meta: normalized.meta,
      });
    }

    write({ v: 1, items });
  },

  remove(id, variation_id = "") {
    const state = read();
    const k = keyOf(id, variation_id);
    const items = state.items.filter((x) => keyOf(x?.id, x?.variation_id) !== k);
    write({ v: 1, items });
  },

  setQty(id, variation_id = "", qty = 1) {
    const state = read();
    const k = keyOf(id, variation_id);
    const q = Math.min(20, Math.max(1, Number(qty || 1)));

    const it = state.items.find((x) => keyOf(x?.id, x?.variation_id) === k);
    if (!it) return;

    it.qty = q;
    write({ v: 1, items: state.items });
  },
};

// Backward-compatible helpers for components still importing named functions,
// including BeverageQuestionnaire.astro. :contentReference[oaicite:1]{index=1}
export function addItem(item) {
  return Cart.add(item);
}

export function removeItem(id, variation_id = "") {
  return Cart.remove(id, variation_id);
}

export function clearCart() {
  return Cart.clear();
}

export function getCart() {
  return Cart.get();
}

export function getCartCount() {
  return Cart.count();
}

export function setCartQty(id, variation_id = "", qty = 1) {
  return Cart.setQty(id, variation_id, qty);
}

export function openCart() {
  if (typeof window === "undefined") return;

  const base = (import.meta.env.BASE_URL || "/").endsWith("/")
    ? import.meta.env.BASE_URL || "/"
    : `${import.meta.env.BASE_URL || "/"}\/`;

  // Let any cart drawer/listener respond first
  window.dispatchEvent(new CustomEvent("dts:cart:open"));

  // Fallback to cart page navigation
  window.location.href = `${base}cart`;
}