// /src/lib/beveragePlanner.js
// Deeper Than Skin — Custom Beverage Planner
// Safe wellness-language planner for juice trials + recurring Square plans.
// Notes:
// - Avoid medical claims.
// - Do not say juices treat, cure, reverse, or prevent disease.
// - Use "support", "conscious", "friendly", "wellness-focused".

export const WELLNESS_GOALS = [
  {
    id: "gut",
    label: "Gut Support",
    helper: "Digestive comfort, bloating support, and daily rhythm.",
  },
  {
    id: "kidney",
    label: "Kidney-Conscious Hydration",
    helper: "Gentle hydration-focused blends with mineral awareness.",
  },
  {
    id: "pressure",
    label: "Blood Pressure / Heart Support",
    helper: "Low-sodium, circulation-conscious beverage support.",
  },
  {
    id: "blood_sugar",
    label: "Blood Sugar Conscious",
    helper: "Lower-sugar, veggie-forward blends for steadier routines.",
  },
  {
    id: "inflammation",
    label: "Inflammation Support",
    helper: "Botanical blends with ginger, turmeric, greens, and berries.",
  },
  {
    id: "immune",
    label: "Immune Support",
    helper: "Vitamin-rich fruits, herbs, citrus alternatives, and greens.",
  },
  {
    id: "energy",
    label: "Natural Energy",
    helper: "Bright, nutrient-dense blends without heavy stimulants.",
  },
  {
    id: "stress",
    label: "Stress / Calm Support",
    helper: "Gentle, grounding blends for a calmer wellness ritual.",
  },
  {
    id: "skin",
    label: "Skin Glow Support",
    helper: "Hydrating antioxidant-rich blends for beauty-from-within rituals.",
  },
];

export const DELIVERY_CADENCE = [
  {
    id: "one_time",
    label: "One-time Trial",
    helper: "Try your custom set once before starting a rhythm.",
  },
  {
    id: "weekly",
    label: "Weekly Delivery",
    helper: "Best for consistent juice routines.",
  },
  {
    id: "biweekly",
    label: "Biweekly Delivery",
    helper: "A balanced rhythm for ongoing support.",
  },
  {
    id: "monthly",
    label: "Monthly Reset",
    helper: "A lighter monthly wellness refresh.",
  },
];

export const PREFERENCES = [
  { id: "low_sugar", label: "Low Sugar" },
  { id: "low_sodium", label: "Low Sodium" },
  { id: "low_potassium", label: "Lower Potassium" },
  { id: "no_citrus", label: "No Citrus" },
  { id: "caffeine_free", label: "Caffeine Free" },
  { id: "hydration_focus", label: "Hydration Focus" },
  { id: "ginger_forward", label: "Ginger Forward" },
  { id: "greens_forward", label: "Greens Forward" },
  { id: "fruit_light", label: "Fruit-Light" },
];

const CADENCE_LABELS = {
  one_time: "One-time Trial",
  weekly: "Weekly Delivery",
  biweekly: "Biweekly Delivery",
  monthly: "Monthly Reset",
};

const GOAL_LABELS = WELLNESS_GOALS.reduce((acc, item) => {
  acc[item.id] = item.label;
  return acc;
}, {});

const PREFERENCE_LABELS = PREFERENCES.reduce((acc, item) => {
  acc[item.id] = item.label;
  return acc;
}, {});

const PLAN_COPY = {
  gut: {
    name: "Gut-Friendly Greens",
    note:
      "Digestive-support-forward blends with greens, ginger, herbs, and fruit-light balance.",
    ingredients: ["cucumber", "celery", "ginger", "mint", "green apple"],
  },
  kidney: {
    name: "Kidney-Conscious Hydration",
    note:
      "Hydration-focused blends with careful mineral awareness and lighter produce choices.",
    ingredients: ["cucumber", "apple", "grape", "mint", "lemon"],
    caution:
      "For kidney concerns, confirm potassium needs with a healthcare professional.",
  },
  pressure: {
    name: "Heart & Circulation Support",
    note:
      "Low-sodium, circulation-conscious blends inspired by veggie-forward wellness routines.",
    ingredients: ["beet", "celery", "apple", "ginger", "lemon"],
    caution:
      "For blood pressure concerns, avoid high-sodium add-ins and follow clinician guidance.",
  },
  blood_sugar: {
    name: "Blood Sugar Conscious Blend",
    note:
      "Lower-sugar, veggie-forward blends designed for a steadier daily beverage ritual.",
    ingredients: ["cucumber", "greens", "celery", "lemon", "ginger"],
    caution:
      "For diabetes or blood sugar concerns, monitor carbohydrates and follow your care plan.",
  },
  inflammation: {
    name: "Golden Botanical Support",
    note:
      "Ginger, turmeric, greens, and antioxidant-rich produce for a warming wellness ritual.",
    ingredients: ["turmeric", "ginger", "pineapple-light", "lemon", "greens"],
  },
  immune: {
    name: "Immune Glow Blend",
    note:
      "Vitamin-rich fruits, herbs, and greens designed for everyday immune-support routines.",
    ingredients: ["orange-light", "lemon", "ginger", "greens", "berries"],
  },
  energy: {
    name: "Clean Energy Elixir",
    note:
      "Bright, nutrient-dense blends for natural lift without relying on heavy stimulants.",
    ingredients: ["green apple", "greens", "ginger", "lemon", "cucumber"],
  },
  stress: {
    name: "Calm Ritual Blend",
    note:
      "A gentler blend profile for grounding, hydration, and a slower daily ritual.",
    ingredients: ["cucumber", "mint", "lavender-note", "apple", "lemon"],
  },
  skin: {
    name: "Skin Glow Hydration",
    note:
      "Hydrating, antioxidant-rich blends designed for a beauty-from-within ritual.",
    ingredients: ["carrot", "cucumber", "berries", "lemon", "ginger"],
  },
};

const SAFETY_NOTES = {
  kidney:
    "Kidney-conscious does not mean kidney treatment. Some people need to limit potassium, phosphorus, sodium, or fluids.",
  blood_sugar:
    "Blood sugar conscious does not mean diabetes treatment. Juice can contain carbohydrates and natural sugars.",
  pressure:
    "Blood pressure support does not replace medication, monitoring, or medical care.",
  low_potassium:
    "Lower potassium preference should be reviewed with a clinician or renal dietitian if kidney disease is present.",
  cleanse:
    "This planner avoids detox/cure claims. Cleanses have limited evidence and may not be appropriate for everyone.",
};

function normalizeArray(value) {
  return Array.isArray(value) ? value.filter(Boolean).map(String) : [];
}

function labelList(ids, labels) {
  return ids.map((id) => labels[id] || id);
}

function unique(items) {
  return Array.from(new Set(items.filter(Boolean)));
}

function hasAny(list, ids) {
  return ids.some((id) => list.includes(id));
}

function buildRecommendation(goalId, preferences = []) {
  const base = PLAN_COPY[goalId];
  if (!base) return null;

  const flags = [];

  if (preferences.includes("low_sugar") || preferences.includes("fruit_light")) {
    flags.push("Fruit-light");
  }

  if (preferences.includes("low_sodium")) {
    flags.push("Low-sodium conscious");
  }

  if (preferences.includes("low_potassium")) {
    flags.push("Lower-potassium review needed");
  }

  if (preferences.includes("no_citrus")) {
    flags.push("No-citrus adjustment");
  }

  if (preferences.includes("hydration_focus")) {
    flags.push("Hydration-forward");
  }

  return {
    key: goalId,
    name: base.name,
    note: flags.length ? `${base.note} Adjusted for: ${flags.join(", ")}.` : base.note,
    ingredients: base.ingredients,
    caution: base.caution || "",
  };
}

function getDefaultGoals(goals) {
  return goals.length ? goals : ["gut", "hydration"];
}

function buildWarnings(goals, preferences) {
  const warnings = [];

  if (goals.includes("kidney")) warnings.push(SAFETY_NOTES.kidney);
  if (goals.includes("blood_sugar")) warnings.push(SAFETY_NOTES.blood_sugar);
  if (goals.includes("pressure")) warnings.push(SAFETY_NOTES.pressure);
  if (preferences.includes("low_potassium")) warnings.push(SAFETY_NOTES.low_potassium);

  if (hasAny(goals, ["kidney", "blood_sugar", "pressure"])) {
    warnings.push(
      "Please consult a healthcare professional if you are pregnant, managing a medical condition, taking medication, or following a restricted diet."
    );
  }

  return unique(warnings);
}

export function buildBeveragePlan(input = {}) {
  const goals = normalizeArray(input.goals);
  const preferences = normalizeArray(input.preferences);
  const cadence = String(input.cadence || "one_time");
  const notes = String(input.notes || "").trim();

  const safeGoals = goals.filter((id) => GOAL_LABELS[id]);
  const safePreferences = preferences.filter((id) => PREFERENCE_LABELS[id]);

  const goalLabels = labelList(safeGoals, GOAL_LABELS);
  const preferenceLabels = labelList(safePreferences, PREFERENCE_LABELS);

  const isRecurring = cadence !== "one_time";

  const recommendationGoals = safeGoals.length
    ? safeGoals
    : ["gut", "energy", "skin"];

  let recommendations = recommendationGoals
    .map((goalId) => buildRecommendation(goalId, safePreferences))
    .filter(Boolean);

  if (!recommendations.length) {
    recommendations = [
      {
        key: "starter",
        name: "Balanced Trial Set",
        note:
          "A simple starter set with greens, hydration blends, and bright fruit-light balance.",
        ingredients: ["greens", "cucumber", "ginger", "apple", "lemon"],
        caution: "",
      },
    ];
  }

  const size = isRecurring ? "9-Pack Subscription Set" : "6-Pack Trial Set";

  const title = safeGoals.length
    ? "Your Custom Juice Plan"
    : "Your Starter Juice Plan";

  const resultHeadline = isRecurring
    ? "Your recurring juice rhythm is ready."
    : "Your custom trial set is ready.";

  const resultSubheadline = isRecurring
    ? "Review your recommendation, then continue to Square to start your plan."
    : "Review your recommendation, then continue to checkout for your trial set.";

  const primaryCta = isRecurring
    ? "Start Plan Checkout"
    : "Start Trial Checkout";

  const planType = isRecurring ? "subscription" : "trial";

  const warnings = buildWarnings(safeGoals, safePreferences);

  const summary = [
    goalLabels.length
      ? `Goals: ${goalLabels.join(", ")}`
      : "Goals: General Wellness",
    preferenceLabels.length
      ? `Preferences: ${preferenceLabels.join(", ")}`
      : "Preferences: None selected",
    `Cadence: ${CADENCE_LABELS[cadence] || CADENCE_LABELS.one_time}`,
    `Plan Type: ${isRecurring ? "Recurring" : "One-time Trial"}`,
    notes ? `Notes: ${notes}` : "",
  ].filter(Boolean);

  const ingredientFocus = unique(
    recommendations.flatMap((item) => item.ingredients || [])
  );

  return {
    title,
    size,
    cadence,
    cadenceLabel: CADENCE_LABELS[cadence] || CADENCE_LABELS.one_time,
    planType,
    isRecurring,
    primaryCta,
    resultHeadline,
    resultSubheadline,

    goals: safeGoals,
    goalLabels,
    preferences: safePreferences,
    preferenceLabels,
    notes,

    recommendations,
    ingredientFocus,
    warnings,
    summary,

    medicalDisclaimer:
      "These beverages are for general wellness support only and are not intended to diagnose, treat, cure, or prevent disease.",

    square: {
      catalogItemKey: isRecurring
        ? "custom-beverage-subscription-set"
        : "custom-beverage-trial-set",
      planKey: isRecurring ? cadence : null,
      modifierKeys: [...safeGoals, ...safePreferences],
      planType,
    },
  };
}