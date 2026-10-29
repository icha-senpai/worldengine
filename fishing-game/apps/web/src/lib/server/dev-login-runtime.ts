import { execFile } from 'node:child_process';
import { readFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import { homedir } from 'node:os';
import { join } from 'node:path';
import { DbConnection } from '@fishing-game/generated';

const DB_URI = 'http://127.0.0.1:3127';
const EXPECTED_IDENTITY = 'c2002230cb292d622fb9716c64c8c73f84b1934e4eb6e6db546fb271ab32e488';
const PLAYER_SQL = "SELECT discord_user_id FROM player WHERE player_id = 1 AND display_name = 'icha_'";
const CLI = join(process.env.LOCALAPPDATA ?? join(homedir(), 'AppData', 'Local'), 'SpacetimeDB', 'bin', '2.12.0', 'spacetimedb-cli.exe');
const ROOT = fileURLToPath(new URL('../../../../../', import.meta.url));

export async function readIdentity(): Promise<string> {
  const response = await fetch(`${DB_URI}/v1/database/fishbound-dev-local/identity`, { signal: AbortSignal.timeout(5000) });
  return (await response.text()).trim();
}

const parseLossless = (text: string): unknown => JSON.parse(text, ((_key: string, value: unknown, context?: { source?: string }) =>
  context?.source && /^-?\d+$/.test(context.source) && !Number.isSafeInteger(Number(context.source)) ? BigInt(context.source) : value) as (key: string, value: unknown) => unknown);

export function parseDevPlayerId(stdout: string): bigint {
  const result = parseLossless(stdout);
  const table = Array.isArray(result) ? result[0] as { schema?: { elements?: { name?: { some?: string } }[] }, rows?: unknown[][] } : null;
  const names = table?.schema?.elements?.map(element => element?.name?.some) ?? [];
  const column = names.indexOf('discord_user_id');
  const rows = Array.isArray(table?.rows) ? table.rows : [];
  if (column < 0 || rows.length !== 1 || !Array.isArray(rows[0])) throw new Error('player lookup returned unexpected rows');
  const value = rows[0][column];
  const id = typeof value === 'bigint' ? value : typeof value === 'number' && Number.isSafeInteger(value) ? BigInt(value) : typeof value === 'string' && /^\d+$/.test(value) ? BigInt(value) : null;
  if (id === null || id <= 0n || id > (1n << 64n) - 1n) throw new Error('player lookup returned no usable id');
  return id;
}

async function lookupDiscordUserId(): Promise<bigint> {
  const stdout = await new Promise<string>((resolve, reject) => {
    execFile(CLI, ['sql', '--server', DB_URI, '--no-config', '--format', 'json', EXPECTED_IDENTITY, PLAYER_SQL],
      { timeout: 10_000, maxBuffer: 256 * 1024 },
      (error, out) => error ? reject(error) : resolve(out));
  });
  return parseDevPlayerId(stdout);
}

export function runAccountLink<C extends { disconnect(): void }>(
  connect: (onConnect: (connection: C) => void, onError: (error: unknown) => void) => C,
  complete: (connection: C) => Promise<void>,
  deadlineMs = 10_000,
): Promise<void> {
  return new Promise((resolve, reject) => {
    let cancelled = false;
    let channel: C | null = null;
    const deadline = setTimeout(() => { cancelled = true; channel?.disconnect(); reject(new Error('account link timed out')); }, deadlineMs);
    const finish = (error?: unknown) => {
      if (cancelled) return;
      cancelled = true;
      clearTimeout(deadline);
      channel?.disconnect();
      if (error === undefined) resolve(); else reject(error);
    };
    const onConnect = async (connection: C) => {
      if (cancelled) { connection.disconnect(); return; }
      try { await complete(connection); finish(); } catch (e) { finish(e); }
    };
    try { channel = connect(onConnect, finish); }
    catch (e) { finish(e); }
    if (cancelled) channel?.disconnect();
  });
}

export async function link(challengeId: bigint, proof: bigint): Promise<void> {
  const pkg = JSON.parse(await readFile(join(ROOT, 'package.json'), 'utf8'));
  if (pkg?.name !== 'fishing-game') throw new Error('unexpected project root');
  const token = (await readFile(join(ROOT, '.local', 'service-accountLinker.token'), 'utf8')).trim();
  const verifiedDiscordUserId = await lookupDiscordUserId();
  await runAccountLink(
    (onConnect, onError): DbConnection => DbConnection.builder()
      .withUri(DB_URI).withDatabaseName(EXPECTED_IDENTITY).withToken(token)
      .onConnect(onConnect)
      .onConnectError((_ctx, e) => onError(e instanceof Error ? e : new Error('connection failed')))
      .build(),
    connection => connection.reducers.completeAccountLink({ challengeId, verifiedDiscordUserId, proof }),
  );
}
