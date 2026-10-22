import { afterEach, expect, it, vi } from "vitest";
import { recordConnectionLifecycle } from "../src/pageLifecycle";
afterEach(() => vi.unstubAllGlobals());
it("bounds connection diagnostics and excludes credentials, URLs, close reasons and arbitrary names", () => {
  const values = new Map<string, string>();
  vi.stubGlobal("sessionStorage", {
    getItem: (key: string) => values.get(key) ?? null,
    setItem: (_key: string, value: string) => {
      values.set(_key, value);
    },
  });
  vi.stubGlobal("navigator", { onLine: false });
  vi.stubGlobal("document", { hidden: true });
  for (let i = 0; i < 240; i++) {
    const error = new Error(
      "WebSocket closed (code 1006: https://secret.invalid/?token=secret)",
    );
    error.name = "secret error name";
    recordConnectionLifecycle("bitcraft-disconnected", error, 2, 1500);
  }
  const saved = values.get("space.pageLifecycle")!;
  const rows = JSON.parse(saved);
  expect(rows).toHaveLength(200);
  expect(rows[0]).toMatchObject({
    reason: "socket_closed",
    closeCode: 1006,
    errorType: "Error",
    retryAttempt: 2,
    retryDelayMs: 1500,
    online: false,
    visibility: "hidden",
  });
  expect(saved).not.toContain("secret");
  expect(saved).not.toContain("https://");
});
