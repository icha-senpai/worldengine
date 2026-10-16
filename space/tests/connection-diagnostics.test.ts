import { afterEach, expect, it, vi } from "vitest";
import { recordConnectionLifecycle } from "../src/pageLifecycle";
afterEach(() => vi.unstubAllGlobals());
it("bounds connection diagnostics and excludes credentials, URLs, close reasons and arbitrary names", () => {
  let saved = "[]";
  vi.stubGlobal("sessionStorage", {
    getItem: () => saved,
    setItem: (_key: string, value: string) => {
      saved = value;
    },
  });
  vi.stubGlobal("navigator", { onLine: false });
  vi.stubGlobal("document", { hidden: true });
  for (let i = 0; i < 40; i++) {
    const error = new Error(
      "WebSocket closed (code 1006: https://secret.invalid/?token=secret)",
    );
    error.name = "secret error name";
    recordConnectionLifecycle("bitcraft-disconnected", error, 2, 1500);
  }
  const rows = JSON.parse(saved);
  expect(rows).toHaveLength(30);
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
