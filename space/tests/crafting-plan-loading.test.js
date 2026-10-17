import { afterEach, describe, expect, it, vi } from "vitest";
import { effectScope, nextTick, reactive } from "vue";
import { useCraftingPlan } from "../src/bitcraft-ui/useCraftingPlan";
import { bitcraftFetch } from "../src/bitcraft-ui/api";
vi.mock("../src/bitcraft-ui/api", () => ({ bitcraftFetch: vi.fn() }));
vi.mock("../src/bitcraft-ui/navigation", () => ({
  route: (_name, params) => `/branch/${params.itemId}`,
}));

const root = (id, ingredients = []) => ({
  id: `crafting:${id}:item:${id}`,
  targetKey: `item:${id}`,
  name: `Craft ${id}`,
  action: "craft",
  preference: "normal",
  outputQuantity: 1,
  ingredients,
});
const deferred = (id) => ({
  id,
  kind: "item",
  name: `Material ${id}`,
  quantity: 1,
  recipes: [],
  recipesDeferred: true,
});
const scopes = [];
function plan(recipes) {
  const props = reactive({ recipes, storageKey: null });
  const scope = effectScope();
  scopes.push(scope);
  return { props, state: scope.run(() => useCraftingPlan(props)) };
}
afterEach(() => {
  scopes.splice(0).forEach((scope) => scope.stop());
  vi.resetAllMocks();
});
describe("crafting plan branch loading", () => {
  it("deduplicates repeated branch reads while keeping independent recipe nodes", async () => {
    bitcraftFetch.mockResolvedValue(Response.json({ recipes: [root(2)] }));
    const { state } = plan([root(1, [deferred(2), deferred(2)])]);
    await vi.waitFor(() => expect(state.deferredBranchCount.value).toBe(0));
    expect(bitcraftFetch).toHaveBeenCalledTimes(1);
    const [a, b] = state.recipeTree.value[0].ingredients;
    expect(a.recipes).toEqual(b.recipes);
    expect(a.recipes).not.toBe(b.recipes);
    a.recipes[0].name = "Only this occurrence";
    expect(b.recipes[0].name).toBe("Craft 2");
  });
  it("ignores an old response and aborts its request when the target changes", async () => {
    let finish;
    bitcraftFetch.mockReturnValue(
      new Promise((resolve) => {
        finish = resolve;
      }),
    );
    const { state, props } = plan([root(1, [deferred(2)])]);
    const signal = bitcraftFetch.mock.calls[0][1].signal;
    props.recipes = [root(3)];
    await nextTick();
    expect(signal.aborted).toBe(true);
    finish(Response.json({ recipes: [root(2)] }));
    await nextTick();
    await nextTick();
    expect(state.recipeTree.value[0].targetKey).toBe("item:3");
    expect(state.recipeTree.value[0].ingredients).toEqual([]);
    expect(state.loadAllError.value).toBeNull();
  });
  it("can retry a failed branch without keeping a rejected request cached", async () => {
    bitcraftFetch.mockRejectedValueOnce(Error("Disconnected"));
    const { state } = plan([root(1, [deferred(2)])]);
    await vi.waitFor(() =>
      expect(state.loadAllError.value).toBe("Could not load every branch."),
    );
    bitcraftFetch.mockResolvedValue(Response.json({ recipes: [root(2)] }));
    await state.loadAllBranches();
    expect(state.loadAllError.value).toBeNull();
    expect(state.deferredBranchCount.value).toBe(0);
    expect(bitcraftFetch).toHaveBeenCalledTimes(2);
  });
  it("ignores a response that arrives after the plan is unmounted", async () => {
    let finish;
    bitcraftFetch.mockReturnValue(
      new Promise((resolve) => {
        finish = resolve;
      }),
    );
    const { state } = plan([root(1, [deferred(2)])]);
    const ingredient = state.recipeTree.value[0].ingredients[0];
    scopes.at(-1).stop();
    finish(Response.json({ recipes: [root(2)] }));
    await nextTick();
    await nextTick();
    expect(ingredient.recipes).toEqual([]);
    expect(ingredient.recipesDeferred).toBe(true);
  });
});
