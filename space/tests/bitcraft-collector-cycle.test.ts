import { afterEach, describe, expect, it, vi } from "vitest";
import { CollectorCycle } from "../scripts/bitcraft-collector-cycle";

afterEach(() => vi.useRealTimers());

describe("collector maintenance handoff", () => {
  it("interrupts a pending SDK call so the next cycle can rebuild", async () => {
    const cycle = new CollectorCycle();
    const calls: string[] = [];
    const worker = (async () => {
      try {
        await cycle.wait(() => new Promise<void>(() => {}));
        calls.push("publish");
      } finally {
        calls.push("disconnect");
      }
    })();
    const interrupted = expect(worker).rejects.toThrow("Storage maintenance");
    cycle.stop("Storage maintenance required.");
    await interrupted;
    calls.push("rebuild");
    await new CollectorCycle().wait(async () => calls.push("reconnect"));
    expect(calls).toEqual(["disconnect", "rebuild", "reconnect"]);
  });

  it("prevents a late response from publishing after maintenance starts", async () => {
    const cycle = new CollectorCycle();
    let response!: () => void;
    const publish = vi.fn(async () => {});
    const worker = (async () => {
      await cycle.wait(() => new Promise<void>((done) => (response = done)));
      await cycle.wait(publish);
    })();
    await Promise.resolve();
    const interrupted = expect(worker).rejects.toThrow("Storage maintenance");
    cycle.stop("Storage maintenance required.");
    await interrupted;
    response();
    await Promise.resolve();
    expect(publish).not.toHaveBeenCalled();
    await expect(cycle.wait(publish)).rejects.toThrow("Storage maintenance");
  });

  it("reconnects when an SDK request hangs without a disconnect event", async () => {
    vi.useFakeTimers();
    const cycle = new CollectorCycle();
    const request = cycle.wait(() => new Promise<void>(() => {}), 1000);
    const expired = expect(request).rejects.toThrow("timed out");
    await vi.advanceTimersByTimeAsync(1000);
    await expired;
    expect(cycle.stopped).toBe(true);
  });

  it("clears the timeout after a successful operation", async () => {
    vi.useFakeTimers();
    const cycle = new CollectorCycle();
    await expect(cycle.wait(async () => "ready", 1000)).resolves.toBe("ready");
    await vi.advanceTimersByTimeAsync(1000);
    expect(cycle.stopped).toBe(false);
    expect(vi.getTimerCount()).toBe(0);
  });
});
