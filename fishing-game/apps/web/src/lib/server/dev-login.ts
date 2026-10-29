const EXPECTED_URI = 'wss://fishbound.test';
const EXPECTED_DATABASE = 'fishbound-dev-local';
const EXPECTED_IDENTITY = 'c2002230cb292d622fb9716c64c8c73f84b1934e4eb6e6db546fb271ab32e488';
const ALLOWED_ORIGINS = new Set(['https://fishbound.test', 'http://127.0.0.1:5174', 'http://localhost:5174']);
const PROXY_ORIGIN = 'http://fishbound.test';
const MAX_BODY_BYTES = 1024;
const U64_MAX = (1n << 64n) - 1n;
const U128_MAX = (1n << 128n) - 1n;

type DevLoginDeps = {
  development: boolean;
  uri: string;
  database: string;
  readIdentity: () => Promise<string>;
  link: (challengeId: bigint, proof: bigint) => Promise<void>;
};

const respond = (status: number) => new Response(null, { status, headers: { 'Cache-Control': 'no-store' } });
const decimal = (value: unknown, max: bigint): bigint | null => {
  if (typeof value !== 'string' || !/^(0|[1-9][0-9]*)$/.test(value)) return null;
  const n = BigInt(value);
  return n <= max ? n : null;
};

async function readBoundedJson(request: Request): Promise<{ ok: true; body: unknown } | { ok: false }> {
  const length = Number(request.headers.get('content-length') ?? '');
  if (Number.isFinite(length) && length > MAX_BODY_BYTES) return { ok: false };
  const reader = request.body?.getReader();
  if (!reader) return { ok: false };
  const chunks: Uint8Array[] = [];
  let total = 0;
  try {
    while (true) {
      const { done, value } = await reader.read();
      if (done) break;
      total += value.byteLength;
      if (total > MAX_BODY_BYTES) { await reader.cancel(); return { ok: false }; }
      chunks.push(value);
    }
  } finally { reader.releaseLock(); }
  const merged = new Uint8Array(total);
  let offset = 0;
  for (const chunk of chunks) { merged.set(chunk, offset); offset += chunk.byteLength; }
  const text = new TextDecoder().decode(merged);
  try { return { ok: true, body: JSON.parse(text) }; } catch { return { ok: false }; }
}

export function createDevLogin({ development, uri, database, readIdentity, link }: DevLoginDeps) {
  return async (request: Request): Promise<Response> => {
    if (!development || uri !== EXPECTED_URI || database !== EXPECTED_DATABASE) return respond(404);
    const origin = new URL(request.url).origin;
    if (!ALLOWED_ORIGINS.has(origin) && origin !== PROXY_ORIGIN) return respond(404);
    if (request.method !== 'POST') return respond(405);
    const originHeader = request.headers.get('origin') ?? '';
    if (origin === PROXY_ORIGIN) {
      if (originHeader !== 'https://fishbound.test') return respond(403);
    } else if (originHeader !== origin) return respond(403);
    const fetchSite = request.headers.get('sec-fetch-site');
    if (fetchSite && fetchSite !== 'same-origin') return respond(403);
    const contentType = (request.headers.get('content-type') ?? '').split(';')[0].trim().toLowerCase();
    if (contentType !== 'application/json') return respond(400);
    const parsed = await readBoundedJson(request);
    if (!parsed.ok) return respond(400);
    const body = parsed.body;
    if (!body || typeof body !== 'object' || Array.isArray(body)) return respond(400);
    const keys = Object.keys(body).sort();
    if (keys.length !== 2 || keys[0] !== 'challengeId' || keys[1] !== 'proof') return respond(400);
    const record = body as Record<string, unknown>;
    const challengeId = decimal(record.challengeId, U64_MAX);
    const proof = decimal(record.proof, U128_MAX);
    if (challengeId === null || proof === null) return respond(400);
    try {
      if (await readIdentity() !== EXPECTED_IDENTITY) return respond(503);
      await link(challengeId, proof);
    } catch { return respond(503); }
    return respond(204);
  };
}
