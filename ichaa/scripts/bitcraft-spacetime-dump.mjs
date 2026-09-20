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

const CLIENT_MESSAGE_SUBSCRIBE = 0;
const SERVER_MESSAGE_INITIAL_CONNECTION = 0;
const SERVER_MESSAGE_SUBSCRIBE_APPLIED = 1;
const SERVER_MESSAGE_SUBSCRIPTION_ERROR = 3;
const COMPRESSION_UNCOMPRESSED = 0;
const V3_PROTOCOL = 'v3.bsatn.spacetimedb';
const V2_PROTOCOL = 'v2.bsatn.spacetimedb';

async function main() {
  const args = parseArgs(process.argv.slice(2));
  const env = { ...readEnvFile(args.env ?? '.env'), ...process.env };

  const token = args.token ?? env.BITCRAFT_AUTH_TOKEN;
  const host = websocketHost(args.host ?? env.BITCRAFT_SPACETIME_HOST ?? 'wss://bitcraft-early-access.spacetimedb.com');
  const database = args.database ?? env.BITCRAFT_SPACETIME_REGION_DATABASE ?? 'bitcraft-live-19';
  const outputPath = path.resolve(args.out ?? env.BITCRAFT_SPACETIME_STATIC_SNAPSHOT ?? 'storage/app/bitcraft/spacetime-static.json');
  const timeoutMs = Number(args.timeout ?? env.BITCRAFT_SPACETIME_SYNC_TIMEOUT ?? 45) * 1000;
  const tables = args.table?.length ? args.table : env.BITCRAFT_SPACETIME_TABLES?.split(',').map((table) => table.trim()).filter(Boolean) ?? DEFAULT_TABLES;
  const authMode = args.auth_mode ?? env.BITCRAFT_SPACETIME_AUTH_MODE ?? 'websocket-token';
  const includeConnectionId = (args.connection_id ?? env.BITCRAFT_SPACETIME_CONNECTION_ID ?? '0') === '1';
  const debug = env.BITCRAFT_SPACETIME_DEBUG === '1';

  if (!token) {
    fail('BITCRAFT_AUTH_TOKEN is required.');
  }

  if (!tables.length) {
    fail('At least one table is required.');
  }

  const schema = await fetchSchema({ host, database });
  const snapshot = await querySnapshot({ host, database, token, tables, timeoutMs, schema, authMode, includeConnectionId, debug });

  fs.mkdirSync(path.dirname(outputPath), { recursive: true });
  fs.writeFileSync(outputPath, JSON.stringify(snapshot, null, 2) + '\n');

  console.log(`Wrote ${outputPath}`);
  for (const [table, tableSnapshot] of Object.entries(snapshot.tables)) {
    console.log(`${table}: ${tableSnapshot.count}`);
  }
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

function httpHost(value) {
  return value
    .replace(/^wss:/i, 'https:')
    .replace(/^ws:/i, 'http:')
    .replace(/\/$/, '');
}

async function fetchSchema({ host, database }) {
  const url = new URL(`${httpHost(host)}/v1/database/${database}/schema`);
  url.searchParams.set('version', '9');

  const response = await fetch(url);

  if (!response.ok) {
    fail(`Failed to fetch SpacetimeDB schema for ${database}: ${response.status} ${response.statusText}`);
  }

  return normalizeSchema(await response.json());
}

async function querySnapshot({ host, database, token, tables, timeoutMs, schema, authMode, includeConnectionId, debug }) {
  const url = new URL(`${host}/v1/database/${database}/subscribe`);

  const headers = {};

  if (authMode === 'websocket-token') {
    url.searchParams.set('token', await websocketToken(host, token));
  } else if (authMode === 'header') {
    headers.Authorization = `Bearer ${token}`;
  } else {
    throw new Error(`Unknown SpacetimeDB auth mode: ${authMode}`);
  }

  url.searchParams.set('compression', 'None');

  if (includeConnectionId) {
    url.searchParams.set('connection_id', randomConnectionIdHex());
  }

  return new Promise((resolve, reject) => {
    const ws = new WebSocket(url.toString(), [V3_PROTOCOL, V2_PROTOCOL], {
      headers,
      handshakeTimeout: Math.min(timeoutMs, 10000),
      perMessageDeflate: false,
    });

    const writer = new BinaryWriter();
    let settled = false;
    let subscribed = false;

    const timer = setTimeout(() => {
      settled = true;
      ws.close();
      reject(new Error(`Timed out after ${timeoutMs / 1000}s waiting for SpacetimeDB subscription results.`));
    }, timeoutMs);

    const subscribe = () => {
      if (subscribed) {
        return;
      }

      try {
        for (const table of tables) {
          if (!schema.tables.has(table)) {
            throw new Error(`Table ${table} was not found in the SpacetimeDB schema.`);
          }
        }

        subscribed = true;
        debugLog(debug, `sending Subscribe for ${tables.length} table(s)`);
        ws.send(encodeSubscribe(writer, tables.map((table) => `SELECT * FROM ${identifier(table)}`)));
      } catch (error) {
        clearTimeout(timer);
        settled = true;
        ws.close();
        reject(error);
      }
    };

    ws.on('open', () => {
      debugLog(debug, `socket open via ${ws.protocol}`);
      subscribe();
    });

    ws.on('message', (data) => {
      try {
        for (const message of decodeServerMessages(data)) {
          debugLog(debug, `received ${message.type}${message.tag === undefined ? '' : ` (${message.tag})`}`);

          if (message.type === 'initialConnection') {
            continue;
          }

          if (message.type === 'subscriptionError') {
            throw new Error(`SpacetimeDB subscription failed: ${message.error}`);
          }

          if (message.type === 'subscribeApplied') {
            clearTimeout(timer);
            settled = true;
            ws.close();
            const received = decodeQueryRowsTables(message.rows, tables, schema);

            resolve({
              source: 'bitcraft-spacetimedb',
              generatedAt: new Date().toISOString(),
              host,
              database,
              tables: Object.fromEntries(tables.map((name) => {
                const rows = received[name] ?? [];

                return [name, {
                  count: rows.length,
                  rows,
                }];
              })),
            });
          }
        }
      } catch (error) {
        clearTimeout(timer);
        settled = true;
        ws.close();
        reject(error);
      }
    });

    ws.on('error', (error) => {
      if (settled) {
        return;
      }

      clearTimeout(timer);
      settled = true;
      reject(error);
    });

    ws.on('close', (code, reason) => {
      debugLog(debug, `socket close ${code}: ${reason.toString()}`);

      if (settled) {
        return;
      }

      clearTimeout(timer);
      reject(new Error(`SpacetimeDB socket closed before the snapshot completed (${code}: ${reason.toString()}).`));
    });
  });
}

function debugLog(enabled, message) {
  if (enabled) {
    console.error(`[bitcraft-spacetime] ${message}`);
  }
}

function identifier(value) {
  if (!/^[a-z_][a-z0-9_]*$/i.test(value)) {
    throw new Error(`Unsafe SpacetimeDB table identifier: ${value}`);
  }

  return value;
}

function encodeSubscribe(writer, queries) {
  writer.clear();
  writer.writeU8(CLIENT_MESSAGE_SUBSCRIBE);
  writer.writeU32(0);
  writer.writeU32(0);
  writer.writeU32(queries.length);

  for (const query of queries) {
    writer.writeString(query);
  }

  return writer.bytes();
}

function decodeServerMessages(data) {
  const bytes = toBytes(data);
  const compression = bytes[0];

  if (compression !== COMPRESSION_UNCOMPRESSED) {
    const label = compression === 1 ? 'Brotli' : compression === 2 ? 'Gzip' : `unknown tag ${compression}`;
    throw new Error(`SpacetimeDB returned a ${label} server message even though compression=None was requested.`);
  }

  const reader = new BinaryReader(bytes.slice(1));
  const messages = [];

  do {
    messages.push(decodeServerMessage(reader));
  } while (reader.remaining > 0);

  return messages;
}

function decodeServerMessage(reader) {
  const tag = reader.readU8();

  if (tag === SERVER_MESSAGE_INITIAL_CONNECTION) {
    reader.skip(32);
    reader.skip(16);
    reader.readString();

    return { type: 'initialConnection' };
  }

  if (tag === SERVER_MESSAGE_SUBSCRIPTION_ERROR) {
    return {
      type: 'subscriptionError',
      requestId: reader.readOptionU32(),
      querySetId: reader.readU32(),
      error: reader.readString(),
    };
  }

  if (tag === SERVER_MESSAGE_SUBSCRIBE_APPLIED) {
    const requestId = reader.readU32();
    const querySetId = reader.readU32();
    const rows = decodeQueryRowsMessage(reader);

    return {
      type: 'subscribeApplied',
      requestId,
      querySetId,
      rows,
    };
  }

  return { type: 'other', tag };
}

function decodeQueryRowsMessage(reader) {
  const count = reader.readU32();
  const tables = [];

  for (let index = 0; index < count; index += 1) {
    tables.push({
      tableName: reader.readString(),
      rows: decodeBsatnRowList(reader),
    });
  }

  return { tables };
}

function decodeBsatnRowList(reader) {
  const hintTag = reader.readU8();
  let sizeHint;

  if (hintTag === 0) {
    sizeHint = {
      tag: 'FixedSize',
      value: reader.readU16(),
    };
  } else if (hintTag === 1) {
    const count = reader.readU32();
    const offsets = [];

    for (let index = 0; index < count; index += 1) {
      offsets.push(reader.readU64());
    }

    sizeHint = {
      tag: 'RowOffsets',
      value: offsets,
    };
  } else {
    throw new Error(`Unknown SpacetimeDB row size hint tag ${hintTag}.`);
  }

  return {
    sizeHint,
    rowsData: reader.readBytes(reader.readU32()),
  };
}

function decodeRowsData(expectedTable, rowList, schema) {
  const tableDef = schema.tables.get(expectedTable);
  const rowType = schema.types[tableDef.productTypeRef];
  const reader = new BinaryReader(rowList.rowsData);
  const rows = [];

  while (reader.remaining > 0) {
    rows.push(decodeProduct(rowType.value, reader, 'row', schema));
  }

  return rows;
}

function decodeQueryRowsTables(queryRows, expectedTables, schema) {
  const expected = new Set(expectedTables);
  const result = Object.fromEntries(expectedTables.map((table) => [table, []]));

  for (const table of queryRows.tables) {
    if (!expected.has(table.tableName)) {
      continue;
    }

    result[table.tableName].push(...decodeRowsData(table.tableName, table.rows, schema));
  }

  return result;
}

function normalizeSchema(raw) {
  const typespace = raw.typespace ?? raw.sections?.find((section) => section.Typespace)?.Typespace;
  const tables = raw.tables ?? raw.sections?.find((section) => section.Tables)?.Tables;

  if (!typespace?.types || !tables) {
    fail('SpacetimeDB schema did not include typespace and tables.');
  }

  return {
    types: typespace.types.map(normalizeAlgebraicType),
    tables: new Map(tables.map((table) => [
      table.source_name ?? table.name,
      {
        productTypeRef: table.product_type_ref,
      },
    ])),
  };
}

function normalizeAlgebraicType(raw) {
  const [tag, value] = Object.entries(raw)[0];

  if (tag === 'Product') {
    return {
      tag,
      value: {
        elements: value.elements.map((element) => ({
          name: optionValue(element.name),
          algebraicType: normalizeAlgebraicType(element.algebraic_type),
        })),
      },
    };
  }

  if (tag === 'Sum') {
    return {
      tag,
      value: {
        variants: value.variants.map((variant) => ({
          name: optionValue(variant.name),
          algebraicType: normalizeAlgebraicType(variant.algebraic_type),
        })),
      },
    };
  }

  if (tag === 'Array') {
    return {
      tag,
      value: normalizeAlgebraicType(value),
    };
  }

  if (tag === 'Ref') {
    return {
      tag,
      value,
    };
  }

  return { tag };
}

function optionValue(option) {
  return Object.hasOwn(option, 'some') ? option.some : undefined;
}

function decodeType(type, reader, context, schema) {
  while (type.tag === 'Ref') {
    type = schema.types[type.value];
  }

  switch (type.tag) {
    case 'Product':
      return decodeProduct(type.value, reader, context, schema);
    case 'Sum':
      return decodeSum(type.value, reader, context, schema);
    case 'Array':
      return decodeArray(type.value, reader, schema);
    case 'String':
      return reader.readString();
    case 'Bool':
      return reader.readBool();
    case 'I8':
      return reader.readI8();
    case 'U8':
      return reader.readU8();
    case 'I16':
      return reader.readI16();
    case 'U16':
      return reader.readU16();
    case 'I32':
      return reader.readI32();
    case 'U32':
      return reader.readU32();
    case 'I64':
      return bigintToJson(reader.readI64());
    case 'U64':
      return bigintToJson(reader.readU64());
    case 'I128':
      return reader.readI128().toString();
    case 'U128':
      return reader.readU128().toString();
    case 'I256':
      return reader.readI256().toString();
    case 'U256':
      return reader.readU256().toString();
    case 'F32':
      return reader.readF32();
    case 'F64':
      return reader.readF64();
    default:
      throw new Error(`Unsupported SpacetimeDB algebraic type ${type.tag}.`);
  }
}

function decodeProduct(product, reader, context, schema) {
  if (context === 'array') {
    return product.elements.map((element) => decodeType(element.algebraicType, reader, context, schema));
  }

  const result = {};

  for (const element of product.elements) {
    result[element.name] = decodeType(element.algebraicType, reader, 'object', schema);
  }

  return result;
}

function decodeSum(sum, reader, context, schema) {
  const tag = reader.readU8();
  const variant = sum.variants[tag];

  if (!variant) {
    throw new Error(`Unknown SpacetimeDB sum tag ${tag}.`);
  }

  return [
    tag,
    decodeType(variant.algebraicType, reader, context, schema),
  ];
}

function decodeArray(elementType, reader, schema) {
  const length = reader.readU32();
  const result = [];

  for (let index = 0; index < length; index += 1) {
    result.push(decodeType(elementType, reader, 'array', schema));
  }

  return result;
}

function bigintToJson(value) {
  return value <= BigInt(Number.MAX_SAFE_INTEGER) && value >= BigInt(Number.MIN_SAFE_INTEGER)
    ? Number(value)
    : value.toString();
}

async function websocketToken(host, token) {
  const url = new URL(`${httpHost(host)}/v1/identity/websocket-token`);

  const response = await fetch(url, {
    method: 'POST',
    headers: {
      Authorization: `Bearer ${token}`,
    },
  });

  if (!response.ok) {
    fail(`Failed to verify BITCRAFT_AUTH_TOKEN for SpacetimeDB websocket access: ${response.status} ${response.statusText}`);
  }

  const payload = await response.json();

  if (!payload?.token) {
    fail('SpacetimeDB did not return a websocket token.');
  }

  return payload.token;
}

function randomConnectionIdHex() {
  return [...crypto.getRandomValues(new Uint8Array(16)).reverse()]
    .map((byte) => byte.toString(16).padStart(2, '0'))
    .join('');
}

function toBytes(data) {
  if (data instanceof ArrayBuffer) {
    return new Uint8Array(data);
  }

  if (ArrayBuffer.isView(data)) {
    return new Uint8Array(data.buffer, data.byteOffset, data.byteLength);
  }

  return new Uint8Array(data);
}

class BinaryReader {
  constructor(bytes) {
    this.reset(bytes);
  }

  reset(bytes) {
    this.bytes = toBytes(bytes);
    this.view = new DataView(this.bytes.buffer, this.bytes.byteOffset, this.bytes.byteLength);
    this.offset = 0;
  }

  get remaining() {
    return this.view.byteLength - this.offset;
  }

  skip(length) {
    this.ensure(length);
    this.offset += length;
  }

  ensure(length) {
    if (this.offset + length > this.view.byteLength) {
      throw new RangeError(`Tried to read ${length} byte(s) at offset ${this.offset}, but only ${this.remaining} byte(s) remain.`);
    }
  }

  readBytes(length) {
    this.ensure(length);
    const value = this.bytes.slice(this.offset, this.offset + length);
    this.offset += length;

    return value;
  }

  readString() {
    return new TextDecoder().decode(this.readBytes(this.readU32()));
  }

  readBool() {
    return this.readU8() !== 0;
  }

  readOptionU32() {
    const tag = this.readU8();

    if (tag === 0) {
      return this.readU32();
    }

    if (tag === 1) {
      return undefined;
    }

    throw new Error(`Unknown SpacetimeDB option tag ${tag}.`);
  }

  readI8() {
    this.ensure(1);
    const value = this.view.getInt8(this.offset);
    this.offset += 1;

    return value;
  }

  readU8() {
    this.ensure(1);
    const value = this.view.getUint8(this.offset);
    this.offset += 1;

    return value;
  }

  readI16() {
    this.ensure(2);
    const value = this.view.getInt16(this.offset, true);
    this.offset += 2;

    return value;
  }

  readU16() {
    this.ensure(2);
    const value = this.view.getUint16(this.offset, true);
    this.offset += 2;

    return value;
  }

  readI32() {
    this.ensure(4);
    const value = this.view.getInt32(this.offset, true);
    this.offset += 4;

    return value;
  }

  readU32() {
    this.ensure(4);
    const value = this.view.getUint32(this.offset, true);
    this.offset += 4;

    return value;
  }

  readI64() {
    this.ensure(8);
    const value = this.view.getBigInt64(this.offset, true);
    this.offset += 8;

    return value;
  }

  readU64() {
    this.ensure(8);
    const value = this.view.getBigUint64(this.offset, true);
    this.offset += 8;

    return value;
  }

  readI128() {
    const lower = this.readU64();
    const upper = this.readI64();

    return (upper << BigInt(64)) + lower;
  }

  readU128() {
    const lower = this.readU64();
    const upper = this.readU64();

    return (upper << BigInt(64)) + lower;
  }

  readI256() {
    const p0 = this.readU64();
    const p1 = this.readU64();
    const p2 = this.readU64();
    const p3 = this.readI64();

    return (p3 << BigInt(192)) + (p2 << BigInt(128)) + (p1 << BigInt(64)) + p0;
  }

  readU256() {
    const p0 = this.readU64();
    const p1 = this.readU64();
    const p2 = this.readU64();
    const p3 = this.readU64();

    return (p3 << BigInt(192)) + (p2 << BigInt(128)) + (p1 << BigInt(64)) + p0;
  }

  readF32() {
    this.ensure(4);
    const value = this.view.getFloat32(this.offset, true);
    this.offset += 4;

    return value;
  }

  readF64() {
    this.ensure(8);
    const value = this.view.getFloat64(this.offset, true);
    this.offset += 8;

    return value;
  }
}

class BinaryWriter {
  constructor() {
    this.buffer = new Uint8Array(1024);
    this.offset = 0;
  }

  clear() {
    this.offset = 0;
  }

  bytes() {
    return this.buffer.slice(0, this.offset);
  }

  ensure(length) {
    const minimum = this.offset + length;

    if (minimum <= this.buffer.length) {
      return;
    }

    let nextLength = this.buffer.length * 2;

    while (nextLength < minimum) {
      nextLength *= 2;
    }

    const next = new Uint8Array(nextLength);
    next.set(this.buffer);
    this.buffer = next;
  }

  writeU8(value) {
    this.ensure(1);
    this.buffer[this.offset] = value;
    this.offset += 1;
  }

  writeU32(value) {
    this.ensure(4);
    new DataView(this.buffer.buffer).setUint32(this.offset, value, true);
    this.offset += 4;
  }

  writeString(value) {
    const bytes = new TextEncoder().encode(value);
    this.writeU32(bytes.length);
    this.ensure(bytes.length);
    this.buffer.set(bytes, this.offset);
    this.offset += bytes.length;
  }
}

function fail(message) {
  console.error(message);
  process.exit(1);
}

await main();
