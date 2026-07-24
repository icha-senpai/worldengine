import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';
import { WebSocket } from 'ws';

const DEFAULT_TABLES = [
  'item_desc',
  'cargo_desc',
  'crafting_recipe_desc',
  'construction_recipe_desc',
  'extraction_recipe_desc',
  'building_desc',
  'building_type_desc',
  'tool_type_desc',
  'tool_desc',
  'skill_desc',
];

const args = parseArgs(process.argv.slice(2));
const env = { ...readEnvFile(args.env ?? '.env'), ...process.env };

const token = args.token ?? env.BITCRAFT_AUTH_TOKEN;
const host = websocketHost(args.host ?? env.BITCRAFT_SPACETIME_HOST ?? 'wss://bitcraft-early-access.spacetimedb.com');
const database = args.database ?? env.BITCRAFT_SPACETIME_REGION_DATABASE ?? 'bitcraft-live-19';
const outputPath = path.resolve(args.out ?? env.BITCRAFT_SPACETIME_STATIC_SNAPSHOT ?? 'storage/app/bitcraft/spacetime-static.json');
const timeoutMs = Number(args.timeout ?? env.BITCRAFT_SPACETIME_SYNC_TIMEOUT ?? 45) * 1000;
const holdMs = Number(args.hold ?? 0) * 1000;
const tables = args.table?.length ? args.table : env.BITCRAFT_SPACETIME_TABLES?.split(',').map((table) => table.trim()).filter(Boolean) ?? DEFAULT_TABLES;

if (!token) {
  fail('BITCRAFT_AUTH_TOKEN is required.');
}

if (!tables.length) {
  fail('At least one table is required.');
}

const snapshot = await subscribeSnapshot({ host, database, token, tables, timeoutMs, holdMs });

fs.mkdirSync(path.dirname(outputPath), { recursive: true });
fs.writeFileSync(outputPath, JSON.stringify(snapshot, null, 2) + '\n');

console.log(`Wrote ${outputPath}`);
for (const [table, tableSnapshot] of Object.entries(snapshot.tables)) {
  console.log(`${table}: ${tableSnapshot.count}`);
}

function parseArgs(argv) {
  const parsed = { table: [] };

  for (let index = 0; index < argv.length; index += 1) {
    const arg = argv[index];
    const next = argv[index + 1];

    if (arg === '--table') {
      parsed.table.push(requiredValue(arg, next));
      index += 1;
      continue;
    }

    if (arg.startsWith('--table=')) {
      parsed.table.push(arg.slice('--table='.length));
      continue;
    }

    if (arg.startsWith('--')) {
      const key = arg.slice(2).replaceAll('-', '_');
      parsed[key] = requiredValue(arg, next);
      index += 1;
    }
  }

  return parsed;
}

function requiredValue(flag, value) {
  if (!value || value.startsWith('--')) {
    fail(`${flag} requires a value.`);
  }

  return value;
}

function readEnvFile(envPath) {
  const resolved = path.resolve(envPath);

  if (!fs.existsSync(resolved)) {
    return {};
  }

  return Object.fromEntries(fs.readFileSync(resolved, 'utf8')
    .split(/\r?\n/)
    .map((line) => line.trim())
    .filter((line) => line && !line.startsWith('#') && line.includes('='))
    .map((line) => {
      const separator = line.indexOf('=');
      const key = line.slice(0, separator).trim();
      let value = line.slice(separator + 1).trim();

      if ((value.startsWith('"') && value.endsWith('"')) || (value.startsWith("'") && value.endsWith("'"))) {
        value = value.slice(1, -1);
      }

      return [key, value];
    }));
}

function websocketHost(value) {
  return value
    .replace(/^https:/i, 'wss:')
    .replace(/^http:/i, 'ws:')
    .replace(/\/$/, '');
}

async function subscribeSnapshot({ host, database, token, tables, timeoutMs, holdMs }) {
  const url = `${host}/v1/database/${database}/subscribe`;
  const queries = tables.map((table) => `SELECT * FROM ${table}`);

  return new Promise((resolve, reject) => {
    const ws = new WebSocket(url, 'v1.json.spacetimedb', {
      headers: {
        Authorization: `Bearer ${token}`,
      },
      handshakeTimeout: Math.min(timeoutMs, 10000),
    });

    let settled = false;
    let snapshot = null;

    const timer = setTimeout(() => {
      ws.close();
      reject(new Error(`Timed out after ${timeoutMs / 1000}s waiting for SpacetimeDB initial subscription.`));
    }, timeoutMs);

    ws.on('message', (data) => {
      const message = JSON.parse(data.toString());
      const type = Object.keys(message)[0];
      const payload = message[type];

      if (type === 'IdentityToken') {
        ws.send(JSON.stringify({
          Subscribe: {
            query_strings: queries,
            request_id: 1,
          },
        }));

        return;
      }

      if (type === 'SubscriptionError') {
        clearTimeout(timer);
        ws.close();
        reject(new Error(payload?.error ?? 'SpacetimeDB subscription failed.'));

        return;
      }

      if (type !== 'InitialSubscription') {
        return;
      }

      snapshot = {
        source: 'bitcraft-spacetimedb',
        generatedAt: new Date().toISOString(),
        host,
        database,
        tables: {},
      };

      for (const table of payload?.database_update?.tables ?? []) {
        const tableName = table.table_name;
        const rows = (table.updates ?? [])
          .flatMap((update) => update.inserts ?? [])
          .map((row) => typeof row === 'string' ? JSON.parse(row) : row);

        snapshot.tables[tableName] = {
          count: rows.length,
          rows,
        };
      }

      for (const table of tables) {
        snapshot.tables[table] ??= {
          count: 0,
          rows: [],
        };
      }

      clearTimeout(timer);
      settled = true;

      if (holdMs <= 0) {
        ws.close();
        resolve(snapshot);

        return;
      }

      console.log(`Initial subscription received. Holding socket open for ${holdMs / 1000}s...`);
      setTimeout(() => {
        ws.close();
        resolve(snapshot);
      }, holdMs);
    });

    ws.on('error', (error) => {
      clearTimeout(timer);
      reject(error);
    });

    ws.on('close', (code, reason) => {
      if (settled) {
        return;
      }

      if (code !== 1000 && code !== 1005) {
        clearTimeout(timer);
        reject(new Error(`SpacetimeDB socket closed before the snapshot completed (${code}: ${reason.toString()}).`));
      }
    });
  });
}

function fail(message) {
  console.error(message);
  process.exit(1);
}
