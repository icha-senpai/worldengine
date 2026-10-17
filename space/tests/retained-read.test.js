import { describe, expect, it, vi } from "vitest";
import { createRetainedRead } from "../src/bitcraft-ui/useRetainedRead";
vi.mock("../src/bitcraft-ui/useBitcraftReadRecovery", () => ({
  useBitcraftReadRecovery: vi.fn(),
  isConnectionReadError: vi.fn(),
}));
describe("retained history reads", () => {
  it("discards stale selections and retains the previous result on failure", async () => {
    const loading = { value: false },
      error = { value: "" };
    let value = "retained";
    const completions = [];
    const read = createRetainedRead({
      loading,
      error,
      read: () =>
        new Promise((resolve, reject) => completions.push({ resolve, reject })),
      apply: (next) => {
        value = next;
      },
      fallback: "Unavailable",
    });
    const first = read.run("old"),
      second = read.run("new");
    completions[1].resolve("new result");
    await second;
    completions[0].resolve("stale");
    await first;
    expect(value).toBe("new result");
    expect(loading.value).toBe(false);
    const failure = read.run();
    completions[2].reject(Error("Disconnected"));
    await failure;
    expect(value).toBe("new result");
    expect(error.value).toBe("Disconnected");
  });
  it("does not reopen a closed detail view when a response arrives", async () => {
    let finish;
    const apply = vi.fn();
    const reader = createRetainedRead({
      loading: { value: false },
      error: { value: "" },
      read: () =>
        new Promise((resolve) => {
          finish = resolve;
        }),
      apply,
    });
    const pending = reader.run();
    reader.invalidate();
    finish({ id: 1 });
    await pending;
    expect(apply).not.toHaveBeenCalled();
  });
});
