import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { createConnectionRecovery } from "../src/connectionRecovery";

describe("connection recovery after backgrounding or network loss", () => {
  let browser: EventTarget;
  let page: EventTarget & { hidden: boolean };
  beforeEach(() => {
    vi.useFakeTimers();
    browser = new EventTarget();
    page = Object.assign(new EventTarget(), { hidden: false });
    vi.stubGlobal("window", browser);
    vi.stubGlobal("document", page);
  });
  afterEach(() => {
    vi.useRealTimers();
    vi.unstubAllGlobals();
  });

  it("leaves healthy connections alone on focus, visibility, pageshow and online", () => {
    const reconnect = vi.fn();
    const recovery = createConnectionRecovery(reconnect, () => false);
    for (const event of ["focus", "online", "pageshow"])
      browser.dispatchEvent(new Event(event));
    page.dispatchEvent(new Event("visibilitychange"));
    recovery.schedule();
    vi.runAllTimers();
    expect(reconnect).not.toHaveBeenCalled();
    recovery.stop();
  });

  it("retries with capped backoff and combines duplicate failure callbacks", () => {
    const reconnect = vi.fn();
    const recovery = createConnectionRecovery(reconnect, () => true);
    for (const delay of [1000, 2000, 4000, 8000, 16000, 30000, 30000]) {
      const previous = reconnect.mock.calls.length;
      recovery.schedule();
      recovery.schedule();
      vi.advanceTimersByTime(delay - 1);
      expect(reconnect).toHaveBeenCalledTimes(previous);
      vi.advanceTimersByTime(1);
      expect(reconnect).toHaveBeenCalledTimes(previous + 1);
    }
    recovery.reset();
    recovery.schedule();
    vi.advanceTimersByTime(1000);
    expect(reconnect).toHaveBeenCalledTimes(8);
    recovery.stop();
  });

  it("resumes a stalled retry immediately and cancels the old timer", () => {
    const reconnect = vi.fn();
    const recovery = createConnectionRecovery(reconnect, () => true);
    recovery.schedule();
    page.hidden = true;
    page.dispatchEvent(new Event("visibilitychange"));
    expect(reconnect).not.toHaveBeenCalled();
    page.hidden = false;
    page.dispatchEvent(new Event("visibilitychange"));
    expect(reconnect).toHaveBeenCalledTimes(1);
    vi.runAllTimers();
    expect(reconnect).toHaveBeenCalledTimes(1);
    recovery.stop();
  });

  it("does not reconnect after sign-out, route exit or a connection already recovering", () => {
    const reconnect = vi.fn();
    let needed = true;
    const recovery = createConnectionRecovery(reconnect, () => needed);
    recovery.schedule();
    needed = false;
    browser.dispatchEvent(new Event("focus"));
    vi.runAllTimers();
    expect(reconnect).not.toHaveBeenCalled();
    recovery.stop();
  });

  it("cancels timers and removes listeners when stopped", () => {
    const reconnect = vi.fn();
    const recovery = createConnectionRecovery(reconnect, () => true);
    recovery.schedule();
    recovery.stop();
    for (const event of ["focus", "online", "pageshow"])
      browser.dispatchEvent(new Event(event));
    page.dispatchEvent(new Event("visibilitychange"));
    vi.runAllTimers();
    expect(reconnect).not.toHaveBeenCalled();
  });
});
