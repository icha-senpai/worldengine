export const craftingTreeContext = Symbol("craftingTree");

export function defaultRecipeIndex(recipe) {
  const alternatives = recipe?.alternatives ?? [recipe];
  const primaryIndex = alternatives.findIndex((option) => option?.primaryRoute);
  if (primaryIndex >= 0) return primaryIndex;
  const index = alternatives.findIndex((option) =>
    /^craft\s/i.test(option?.name ?? ""),
  );
  return index < 0 ? 0 : index;
}

export function selectedRecipe(recipe) {
  const alternatives = recipe?.alternatives?.length
    ? recipe.alternatives
    : [recipe];
  return (
    alternatives[
      recipe?.selectedAlternativeIndex ?? defaultRecipeIndex(recipe)
    ] ?? alternatives[0]
  );
}

export function recipeRouteLabel(recipe) {
  const name = String(recipe?.name ?? "Recipe");
  const action = /^recraft\s/i.test(name)
    ? "Recraft existing"
    : /^craft\s/i.test(name)
      ? "Craft new"
      : name;
  return [
    action,
    recipe.station,
    Number(recipe.outputQuantity) > 1 ? `Makes ${recipe.outputQuantity}` : null,
    recipe.primaryRoute
      ? "Primary"
      : recipe.secondaryRoute
        ? "Secondary"
        : null,
  ]
    .filter(Boolean)
    .join(" · ");
}

export function formatCraftDuration(value) {
  const seconds = Number(value);
  if (!Number.isFinite(seconds) || seconds <= 0) return "";
  const unit = seconds >= 3600 ? "h" : seconds >= 60 ? "m" : "s";
  const divisor = unit === "h" ? 3600 : unit === "m" ? 60 : 1;
  return `${new Intl.NumberFormat(undefined, { maximumFractionDigits: 1 }).format(seconds / divisor)}${unit}`;
}

const positiveQuantity = (value) =>
  Number(value) > 0 && Number.isFinite(Number(value)) ? Number(value) : 1;

export function materialTotals(recipes, desiredQuantity, mode = "base") {
  const totals = new Map();
  function add(recipe, quantity) {
    const active = selectedRecipe(recipe);
    const batches = Math.max(
      1,
      Math.ceil(quantity / positiveQuantity(active?.outputQuantity)),
    );
    for (const ingredient of active?.ingredients ?? []) {
      const required = positiveQuantity(ingredient.quantity) * batches;
      const children = ingredient.recipes ?? [];
      if (
        mode === "base" &&
        children.some((child) => selectedRecipe(child)?.ingredients?.length)
      ) {
        children.forEach((child) => add(child, required));
        continue;
      }
      const key = `${ingredient.kind ?? "item"}:${ingredient.id ?? ingredient.name}`;
      const existing = totals.get(key);
      if (existing) {
        existing.quantity += required;
        existing.deferred ||= Boolean(ingredient.recipesDeferred);
      } else {
        totals.set(key, {
          ...ingredient,
          name: ingredient.name ?? "Unknown item",
          key,
          quantity: required,
          deferred: Boolean(ingredient.recipesDeferred),
        });
      }
    }
  }
  recipes.forEach((recipe) =>
    add(recipe, Math.max(1, Number(desiredQuantity) || 1)),
  );
  return [...totals.values()];
}

// Combine compatible occurrences without changing the calculator's batch rounding.
export function craftingSteps(recipes, desiredQuantity, target = {}) {
  const steps = new Map();
  function collect(recipe, quantity, item, path, ancestors) {
    const active = selectedRecipe(recipe);
    if (!active) return null;
    const key = JSON.stringify([
      active.id,
      active.name,
      active.station,
      active.outputQuantity,
      (active.ingredients ?? []).map((input) => [
        input.kind,
        input.id,
        input.quantity,
      ]),
    ]);
    if (ancestors.has(key)) return null;
    const batches = Math.max(
      1,
      Math.ceil(
        positiveQuantity(quantity) / positiveQuantity(active.outputQuantity),
      ),
    );
    let step = steps.get(key);
    if (!step) {
      step = {
        key,
        recipe,
        active,
        item,
        path,
        inspectionQuantity: quantity,
        quantity: 0,
        batches: 0,
        ingredients: new Map(),
        dependencies: new Set(),
      };
      steps.set(key, step);
    }
    step.quantity += positiveQuantity(quantity);
    step.batches += batches;
    const nextAncestors = new Set([...ancestors, key]);
    for (const ingredient of active.ingredients ?? []) {
      const required = positiveQuantity(ingredient.quantity) * batches;
      const ingredientKey = `${ingredient.kind ?? "item"}:${ingredient.id ?? ingredient.name}`;
      const existing = step.ingredients.get(ingredientKey);
      if (existing) existing.quantity += required;
      else
        step.ingredients.set(ingredientKey, {
          ...ingredient,
          quantity: required,
        });
      for (const child of ingredient.recipes ?? []) {
        const childPath = `${path}/${active.id ?? active.name}/${ingredient.kind}:${ingredient.id}/${child.id ?? child.name}`;
        const dependency = collect(
          child,
          required,
          ingredient,
          childPath,
          nextAncestors,
        );
        if (dependency) step.dependencies.add(dependency);
      }
    }
    return key;
  }
  recipes.forEach((recipe) =>
    collect(
      recipe,
      desiredQuantity,
      target,
      String(recipe.id ?? recipe.name),
      new Set(),
    ),
  );
  const ordered = [];
  const visited = new Set();
  function visit(key) {
    if (visited.has(key)) return;
    visited.add(key);
    const step = steps.get(key);
    step.dependencies.forEach(visit);
    step.stage = Math.max(
      0,
      ...[...step.dependencies].map(
        (dependency) => steps.get(dependency).stage + 1,
      ),
    );
    step.ingredients = [...step.ingredients.values()];
    step.produced = step.batches * positiveQuantity(step.active.outputQuantity);
    step.completionSignature = JSON.stringify([
      step.key,
      desiredQuantity,
      step.quantity,
      step.batches,
      step.ingredients.map((input) => [input.kind, input.id, input.quantity]),
    ]);
    ordered.push(step);
  }
  steps.forEach((_, key) => visit(key));
  return ordered
    .sort((a, b) => a.stage - b.stage)
    .map((step, index) => ({ ...step, number: index + 1 }));
}
