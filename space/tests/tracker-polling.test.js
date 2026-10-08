import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { createTrackerPoller } from "../src/bitcraft-ui/trackerPolling";

describe("tracker background and resume polling", () => {
  let browser, page, poller;
  beforeEach(() => {
    vi.useFakeTimers();
    browser = Object.assign(new EventTarget(), {
      setInterval,
      clearInterval,
      setTimeout,
      clearTimeout,
    });
    page = Object.assign(new EventTarget(), { hidden: false });
    vi.stubGlobal("window", browser);
    vi.stubGlobal("document", page);
  });
  afterEach(() => {
    poller?.stop();
    vi.useRealTimers();
    vi.unstubAllGlobals();
  });

  it("continues XP refreshes in a hidden tab", async () => {
    const refresh = vi.fn().mockResolvedValue(0);
    poller = createTrackerPoller(refresh, 10000, { pauseWhenHidden: false });
    poller.start();
    page.hidden = true;
    await vi.advanceTimersByTimeAsync(30000);
    expect(refresh).toHaveBeenCalledTimes(3);
  });

  it("refreshes immediately on return while other trackers can still pause", async () => {
    const refresh = vi.fn().mockResolvedValue(0);
    poller = createTrackerPoller(refresh, 10000);
    poller.start();
    page.hidden = true;
    await vi.advanceTimersByTimeAsync(30000);
    expect(refresh).not.toHaveBeenCalled();
    page.hidden = false;
    page.dispatchEvent(new Event("visibilitychange"));
    expect(refresh).toHaveBeenCalledTimes(1);
    await vi.advanceTimersByTimeAsync(0);
  });

  it("respects retry delays when focus and visibility return", async () => {
    const refresh = vi.fn().mockResolvedValue(60);
    poller = createTrackerPoller(refresh, 10000, { pauseWhenHidden: false });
    poller.start();
    await vi.advanceTimersByTimeAsync(10000);
    browser.dispatchEvent(new Event("focus"));
    page.dispatchEvent(new Event("visibilitychange"));
    await vi.advanceTimersByTimeAsync(59999);
    expect(refresh).toHaveBeenCalledTimes(1);
    await vi.advanceTimersByTimeAsync(1);
    expect(refresh).toHaveBeenCalledTimes(2);
  });

  it("does not overlap requests when returning during an in-flight refresh", async () => {
    let finish;
    const refresh = vi.fn(
      () =>
        new Promise((resolve) => {
          finish = resolve;
        }),
    );
    poller = createTrackerPoller(refresh, 10000, { pauseWhenHidden: false });
    poller.start();
    await vi.advanceTimersByTimeAsync(10000);
    page.dispatchEvent(new Event("visibilitychange"));
    browser.dispatchEvent(new Event("focus"));
    await vi.advanceTimersByTimeAsync(20000);
    expect(refresh).toHaveBeenCalledTimes(1);
    finish(0);
    await vi.advanceTimersByTimeAsync(0);
  });

  it("removes resume and update listeners when the tracker stops", async () => {
    const refresh = vi.fn().mockResolvedValue(0);
    poller = createTrackerPoller(refresh, 10000, { pauseWhenHidden: false });
    poller.start();
    poller.stop();
    browser.dispatchEvent(new Event("focus"));
    browser.dispatchEvent(new Event("pageshow"));
    browser.dispatchEvent(new Event("bitcraft-data-updated"));
    page.dispatchEvent(new Event("visibilitychange"));
    await vi.advanceTimersByTimeAsync(30000);
    expect(refresh).not.toHaveBeenCalled();
  });
});
