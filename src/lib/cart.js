// src/lib/cart.js
const KEY = "dts_cart_v1";

function read() {
  try {
    const raw = localStorage.getItem(KEY);
    const data = raw ? JSON.parse(raw) : { items: [] };
    if (!data || !Array.isArray(data.items)) return { items: [] };
    return data;
  } catch {
    return { items: [] };
  }
}

function write(data) {
  localStorage.setItem(KEY, JSON.stringify(data));
  window.dispatchEvent(new CustomEvent("dts:cart", { detail: data }));
}

function normItem(it) {
  // minimum needed for checkout + UI
  return {
    id: String(it.id || ""),
    variation_id: it.variation_id ? String(it.variation_id) : null,
    name: String(it.name || "Item"),
    image_url: it.image_url || null,
    price_money: it.price_money || null,
    qty: Math.max(1, Number(it.qty || 1)),
  };
}

export const Cart = {
  get() {
    return read();
  },
  count() {
    const { items } = read();
    return items.reduce((sum, it) => sum + (Number(it.qty) || 0), 0);
  },
  add(item, qty = 1) {
    const data = read();
    const incoming = normItem({ ...item, qty });
    if (!incoming.id) return;

    const idx = data.items.findIndex(
      (x) => x.id === incoming.id && String(x.variation_id || "") === String(incoming.variation_id || "")
    );

    if (idx >= 0) data.items[idx].qty += incoming.qty;
    else data.items.push(incoming);

    write(data);
  },
  setQty(id, variation_id, qty) {
    const data = read();
    const q = Math.max(0, Number(qty || 0));
    data.items = data.items
      .map((it) => {
        if (it.id === id && String(it.variation_id || "") === String(variation_id || "")) {
          return { ...it, qty: q };
        }
        return it;
      })
      .filter((it) => it.qty > 0);
    write(data);
  },
  remove(id, variation_id) {
    const data = read();
    data.items = data.items.filter(
      (it) => !(it.id === id && String(it.variation_id || "") === String(variation_id || ""))
    );
    write(data);
  },
  clear() {
    write({ items: [] });
  },
};