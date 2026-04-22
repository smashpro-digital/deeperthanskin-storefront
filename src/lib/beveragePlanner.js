// /src/lib/beveragePlanner.js

export const WELLNESS_GOALS = [
  { id: "gut", label: "Gut Support" },
  { id: "kidney", label: "Kidney Support" },
  { id: "pressure", label: "Blood Pressure Support" },
];

export const DELIVERY_CADENCE = [
  { id: "one_time", label: "One-time Trial" },
  { id: "weekly", label: "Weekly" },
  { id: "biweekly", label: "Biweekly" },
  { id: "monthly", label: "Monthly" },
];

export const PREFERENCES = [
  { id: "low_sugar", label: "Low Sugar" },
  { id: "no_citrus", label: "No Citrus" },
  { id: "caffeine_free", label: "Caffeine Free" },
  { id: "hydration_focus", label: "Hydration Focus" },
];

export function buildBeveragePlan(input = {}) {
  const goals = Array.isArray(input.goals) ? input.goals : [];
  const preferences = Array.isArray(input.preferences) ? input.preferences : [];
  const cadence = input.cadence || "one_time";

  const hasGut = goals.includes("gut");
  const hasKidney = goals.includes("kidney");
  const hasPressure = goals.includes("pressure");

  const title = hasGut || hasKidney || hasPressure
    ? "Your Custom Beverage Plan"
    : "Starter Beverage Plan";

  const recommendations = [];

  if (hasGut) {
    recommendations.push({
      key: "gut",
      name: "Gut-Friendly Greens",
      note: "Designed for digestive support and balanced daily nourishment.",
    });
  }

  if (hasKidney) {
    recommendations.push({
      key: "kidney",
      name: "Hydration Reset Blend",
      note: "Hydration-forward juices with a fresh, lighter profile.",
    });
  }

  if (hasPressure) {
    recommendations.push({
      key: "pressure",
      name: "Circulation Support Blend",
      note: "A vegetable-forward option built around a steady wellness routine.",
    });
  }

  if (!recommendations.length) {
    recommendations.push({
      key: "starter",
      name: "Balanced Trial Set",
      note: "A simple starting set with a mix of greens, citrus, and hydration blends.",
    });
  }

  const size = cadence === "one_time" ? "6-Pack Trial" : "9-Pack Subscription Set";

  const cadenceLabelMap = {
    one_time: "One-time Trial",
    weekly: "Weekly Delivery",
    biweekly: "Biweekly Delivery",
    monthly: "Monthly Delivery",
  };

  const summary = [
    goals.length ? `Goals: ${goals.join(", ")}` : "Goals: general wellness",
    preferences.length ? `Preferences: ${preferences.join(", ")}` : "Preferences: none selected",
    `Cadence: ${cadenceLabelMap[cadence] || "One-time Trial"}`,
  ];

  // These IDs are placeholders for your later Square mapping layer.
  const square = {
    catalogItemKey: cadence === "one_time" ? "custom-trial-set" : "custom-subscription-set",
    planKey: cadence === "one_time" ? null : cadence,
    modifierKeys: [...goals, ...preferences],
  };

  return {
    title,
    size,
    cadence,
    cadenceLabel: cadenceLabelMap[cadence] || "One-time Trial",
    recommendations,
    summary,
    square,
  };
}