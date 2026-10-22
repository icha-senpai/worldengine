import {
  cleanConnectionReport,
  type DiagnosticSite,
} from "../../../../../../shared/connection-events";

export type ConnectionHealth = {
  maintenance: boolean | null;
  space: string;
  evergather?: string;
  fishbound: string;
};
type Options = {
  development?: boolean;
  now?: () => number;
  health: () => Promise<ConnectionHealth>;
  write?: (record: Record<string, unknown>) => void;
};

export function createConnectionEventReceiver(options: Options) {
  const now = options.now ?? Date.now;
  const write = options.write ?? ((row) => console.info(JSON.stringify(row)));
  const sessions = new Map<
    string,
    { minute: number; requests: number; sequence: number; seenAt: number }
  >();
  let minute = 0,
    requests = 0;
  function originSite(origin: string): DiagnosticSite | undefined {
    if (origin === "https://bitcraft.ichaa.dev") return "space";
    if (origin === "https://fishbound.ichaa.dev") return "fishbound";
    if (options.development) {
      if (origin === "https://space.test" || origin === "http://localhost:5180")
        return "space";
      if (
        origin === "https://fishbound.test" ||
        origin === "http://localhost:5174"
      )
        return "fishbound";
    }
  }
  return async function receive(request: Request): Promise<Response> {
    const origin = request.headers.get("origin") ?? "";
    const site = originSite(origin);
    const headers: Record<string, string> = {
      "Cache-Control": "no-store",
      Vary: "Origin",
    };
    if (!site) return new Response(null, { status: 403, headers });
    headers["Access-Control-Allow-Origin"] = origin;
    if (request.method === "OPTIONS") {
      headers["Access-Control-Allow-Methods"] = "POST, OPTIONS";
      headers["Access-Control-Allow-Headers"] = "Content-Type";
      headers["Access-Control-Max-Age"] = "600";
      return new Response(null, { status: 204, headers });
    }
    if (request.method !== "POST")
      return new Response(null, { status: 405, headers });
    if (!request.headers.get("content-type")?.startsWith("application/json"))
      return new Response(null, { status: 415, headers });
    const currentMinute = Math.floor(now() / 60000);
    if (minute !== currentMinute) {
      minute = currentMinute;
      requests = 0;
    }
    if (requests >= 120)
      return new Response(null, {
        status: 429,
        headers: { ...headers, "Retry-After": "30" },
      });
    requests++;
    const reader = request.body?.getReader();
    if (!reader) return new Response(null, { status: 400, headers });
    const chunks: Uint8Array[] = [];
    let size = 0,
      expired = false;
    const deadline = setTimeout(() => {
      expired = true;
      void reader.cancel().catch(() => {});
    }, 5000);
    try {
      while (true) {
        const chunk = await reader.read();
        if (chunk.done) break;
        size += chunk.value.byteLength;
        if (size > 16384) {
          void reader.cancel().catch(() => {});
          return new Response(null, { status: 413, headers });
        }
        chunks.push(chunk.value);
      }
    } catch {
      return new Response(null, { status: 400, headers });
    } finally {
      clearTimeout(deadline);
    }
    if (expired) return new Response(null, { status: 408, headers });
    const bytes = new Uint8Array(size);
    let offset = 0;
    for (const chunk of chunks) {
      bytes.set(chunk, offset);
      offset += chunk.length;
    }
    let input: unknown;
    try {
      input = JSON.parse(new TextDecoder().decode(bytes));
    } catch {
      return new Response(null, { status: 400, headers });
    }
    const report = cleanConnectionReport(input, now());
    if (!report || report.site !== site)
      return new Response(null, { status: 400, headers });
    for (const [key, session] of sessions)
      if (now() - session.seenAt > 1800000) sessions.delete(key);
    const key = `${site}:${report.session}`;
    let session = sessions.get(key);
    if (!session) {
      if (sessions.size >= 256) sessions.delete(sessions.keys().next().value!);
      session = {
        minute: currentMinute,
        requests: 0,
        sequence: 0,
        seenAt: now(),
      };
      sessions.set(key, session);
    }
    if (session.minute !== currentMinute) {
      session.minute = currentMinute;
      session.requests = 0;
    }
    if (session.requests >= 12)
      return new Response(null, {
        status: 429,
        headers: { ...headers, "Retry-After": "30" },
      });
    session.requests++;
    session.seenAt = now();
    let health: ConnectionHealth;
    try {
      health = await options.health();
    } catch {
      health = { maintenance: null, space: "unknown", fishbound: "unknown" };
    }
    const rawRay = request.headers.get("cf-ray") ?? "";
    const ray = /^[a-f0-9]{8,32}-[A-Z]{3}$/.test(rawRay) ? rawRay : undefined;
    const serverAt = new Date(now()).toISOString();
    for (const event of [...report.events].sort(
      (a, b) => a.sequence - b.sequence,
    )) {
      if (event.sequence <= session.sequence) continue;
      write({
        type: "browser-connection",
        serverAt,
        site,
        session: report.session,
        release: report.release,
        browser: report.browser,
        ...event,
        health,
        ...(ray ? { cfRay: ray } : {}),
      });
      session.sequence = event.sequence;
    }
    return new Response(null, { status: 204, headers });
  };
}
