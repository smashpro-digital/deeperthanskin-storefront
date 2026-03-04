// /src/lib/cart.js
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

function read() {
  try {
    const raw = JSON.parse(localStorage.getItem(CART_KEY) || "null");
    return normalize(raw);
  } catch {
    return { v: 1, items: [] };
  }
}

function write(state) {
  const next = normalize(state);
  localStorage.setItem(CART_KEY, JSON.stringify(next));
  // Notify pages (category/product/cart) to refresh UI
  window.dispatchEvent(new CustomEvent("dts:cart"));
}

function keyOf(id, variation_id) {
  return `${String(id || "")}::${String(variation_id || "")}`;
}

export const Cart = {
  key: CART_KEY,

  get() {
    return read();
  },

  set(items) {
    write({ v: 1, items: Array.isArray(items) ? items : [] });
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

    const id = String(item?.id || "").trim();
    if (!id) throw new Error("Missing product id");

    const variation_id = String(item?.variation_id || "").trim(); // can be empty (we allow but warn elsewhere)
    const k = keyOf(id, variation_id);

    const found = items.find((x) => keyOf(x?.id, x?.variation_id) === k);
    const qtyAdd = Math.max(1, Number(item?.qty || 1));

    if (found) found.qty = Math.max(1, Number(found.qty || 1)) + qtyAdd;
    else {
      items.push({
        id,
        variation_id,
        name: item?.name || "Item",
        image_url: item?.image_url || "",
        price_money: item?.price_money || null,
        has_price: !!item?.has_price,
        qty: qtyAdd,
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