import { describe, expect, it } from "vitest";
import { resolveSpacetimeHost } from "../src/spacetimeHost";

describe("SpaceTimeDB address behind the public tunnel", () => {
  it.each([
    undefined,
    "ws://127.0.0.1:3100",
    "ws://localhost:3100",
    "ws://[::1]:3100",
  ])("routes loopback configuration %s through public HTTPS", (configured) => {
    expect(
      resolveSpacetimeHost(configured, new URL("https://space.ichaa.dev")),
    ).toBe("wss://space.ichaa.dev");
  });

  it.each([
    "https://space.test",
    "http://127.0.0.1:5181",
    "http://localhost:5180",
  ])("preserves the local database for %s", (origin) => {
    expect(resolveSpacetimeHost("ws://127.0.0.1:3100", new URL(origin))).toBe(
      "ws://127.0.0.1:3100",
    );
  });

  it("honors an explicitly configured remote database", () => {
    expect(
      resolveSpacetimeHost(
        "wss://db.example.com",
        new URL("https://space.ichaa.dev"),
      ),
    ).toBe("wss://db.example.com");
  });

  it("keeps a public HTTP origin and its port", () => {
    expect(
      resolveSpacetimeHost(undefined, new URL("http://demo.example.com:5180")),
    ).toBe("ws://demo.example.com:5180");
  });
});
