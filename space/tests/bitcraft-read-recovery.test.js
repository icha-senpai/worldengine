import { afterEach, beforeEach, expect, it, vi } from "vitest";
import { effectScope, nextTick } from "vue";
vi.mock("../src/bitcraft", async () => {
  const { ref } = await import("vue");
  return { bitcraftConnected: ref(false) };
});
import { bitcraftConnected } from "../src/bitcraft";
import {
  isConnectionReadError,
  useBitcraftReadRecovery,
} from "../src/bitcraft-ui/useBitcraftReadRecovery";
let scope;
beforeEach(() => {
  vi.useFakeTimers();
  vi.stubGlobal("window", new EventTarget());
  vi.stubGlobal(
    "document",
    Object.assign(new EventTarget(), { hidden: false }),
  );
  bitcraftConnected.value = false;
  scope = effectScope();
});
afterEach(() => {
  scope.stop();
  vi.useRealTimers();
  vi.unstubAllGlobals();
});
it("waits through a long outage then retries once when subscriptions recover", async () => {
  const retry = vi.fn();
  const recovery = scope.run(() => useBitcraftReadRecovery(retry));
  recovery.failed();
  await vi.advanceTimersByTimeAsync(120000);
  expect(retry).not.toHaveBeenCalled();
  bitcraftConnected.value = true;
  await nextTick();
  await vi.advanceTimersByTimeAsync(1000);
  expect(retry).toHaveBeenCalledOnce();
  recovery.succeeded();
  window.dispatchEvent(new Event("focus"));
  await vi.advanceTimersByTimeAsync(60000);
  expect(retry).toHaveBeenCalledOnce();
});
it("does not overlap an active read and can reschedule after it finishes", async () => {
  bitcraftConnected.value = true;
  let busy = true;
  const retry = vi.fn();
  const recovery = scope.run(() => useBitcraftReadRecovery(retry, () => !busy));
  recovery.failed();
  window.dispatchEvent(new Event("focus"));
  await vi.advanceTimersByTimeAsync(30000);
  expect(retry).not.toHaveBeenCalled();
  busy = false;
  recovery.schedule();
  await vi.advanceTimersByTimeAsync(1000);
  expect(retry).toHaveBeenCalledOnce();
});
it("disposes pending retries and ignores late failures after leaving the page", async () => {
  bitcraftConnected.value = true;
  const retry = vi.fn();
  const recovery = scope.run(() => useBitcraftReadRecovery(retry));
  recovery.failed();
  scope.stop();
  recovery.failed();
  recovery.schedule();
  window.dispatchEvent(new Event("focus"));
  await vi.advanceTimersByTimeAsync(60000);
  expect(retry).not.toHaveBeenCalled();
});
it("distinguishes connection failures from validation and access errors", () => {
  expect(isConnectionReadError(Error("Not connected to SpacetimeDB"))).toBe(
    true,
  );
  expect(
    isConnectionReadError(
      Error("Live data is still reconnecting. Waiting for the next refresh."),
    ),
  ).toBe(true);
  expect(
    isConnectionReadError(
      Error("Guide editing needs a local administrator grant."),
    ),
  ).toBe(false);
});
