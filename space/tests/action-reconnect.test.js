import { afterEach, it, expect, vi } from "vitest";
import { effectScope } from "vue";
const state = vi.hoisted(() => ({
  evergather: { value: null },
  evergatherConnected: { value: true },
  evergatherReconnecting: { value: false },
}));
vi.mock("../src/evergather", () => state);
import {
  executeAction,
  useActionForm,
  actionProcessing,
} from "../src/evergather/useActionForm";
afterEach(() => {
  actionProcessing.value = false;
  state.evergatherConnected.value = true;
  state.evergatherReconnecting.value = false;
});
it("refuses actions while cached game data is disconnected", async () => {
  const craft = vi.fn();
  state.evergather.value = { reducers: { craft } };
  state.evergatherConnected.value = false;
  await expect(executeAction("craft", { recipe: "fixture" })).rejects.toThrow(
    /Wait/,
  );
  expect(craft).not.toHaveBeenCalled();
});
it("preserves form inputs and never retries an action with an unknown result", async () => {
  const error = new Error("Result unknown");
  error.name = "UnknownCallResultError";
  const craft = vi.fn().mockRejectedValue(error),
    success = vi.fn();
  state.evergather.value = { reducers: { craft } };
  const scope = effectScope();
  const form = scope.run(() => useActionForm({ recipe: "fixture" }));
  try {
    await form.submit("craft", { onSuccess: success });
    expect(craft).toHaveBeenCalledOnce();
    expect(success).not.toHaveBeenCalled();
    expect(form.recipe).toBe("fixture");
    expect(form.errors._error).toMatch(/Check your updated inventory/);
    expect(form.processing).toBe(false);
  } finally {
    scope.stop();
  }
});
