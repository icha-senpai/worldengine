const loopbackHosts = new Set(["localhost", "127.0.0.1", "::1", "[::1]"]);

export function resolveSpacetimeHost(
  configuredHost: string | undefined,
  page: Pick<Location, "hostname" | "host" | "protocol">,
) {
  const host = configuredHost || "ws://127.0.0.1:3100";
  const localPage =
    loopbackHosts.has(page.hostname) || page.hostname.endsWith(".test");
  // A public visitor's localhost is their own computer. Use the site's proxy.
  if (!localPage && loopbackHosts.has(new URL(host).hostname)) {
    return `${page.protocol === "https:" ? "wss:" : "ws:"}//${page.host}`;
  }
  return host;
}

export const spacetimeHost =
  typeof window === "undefined"
    ? import.meta.env.VITE_SPACETIMEDB_HOST || "ws://127.0.0.1:3100"
    : resolveSpacetimeHost(
        import.meta.env.VITE_SPACETIMEDB_HOST,
        window.location,
      );
