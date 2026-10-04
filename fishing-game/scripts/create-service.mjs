import { DbConnection } from '../packages/generated/src/index.ts';
import { existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { spawnSync } from 'node:child_process';

if (existsSync('.env')) process.loadEnvFile('.env');
const role = process.argv[2];
if (!['discordAdapter', 'accountLinker'].includes(role)) throw new Error('Use discordAdapter or accountLinker');
const uri = process.env.SPACETIMEDB_URI;
const database = process.env.SPACETIMEDB_DATABASE;
if (!uri || !database) throw new Error('Set SPACETIMEDB_URI and SPACETIMEDB_DATABASE');
if (new URL(uri).hostname !== '127.0.0.1') throw new Error('This helper is for isolated loopback development only');
mkdirSync('.local', { recursive: true });
const path = `.local/service-${role}.token`;
const token = existsSync(path) ? readFileSync(path, 'utf8').trim() : undefined;
const connection = DbConnection.builder().withUri(uri).withDatabaseName(database).withToken(token)
  .onConnect((connection, identity, token) => {
    clearTimeout(timer);
    if (!existsSync(path)) writeFileSync(path, token + '\n', { mode: 0o600, flag: 'wx' });
    console.log(`Service identity: ${identity.toHexString()}`);
    console.log(`Token stored in ignored ${path}; its value is not printed.`);
    if (process.argv.includes('--grant')) {
      const result = spawnSync('spacetime', ['call', '--server', uri, '--no-config', '--yes', database,
        'configure_service', JSON.stringify(identity.toHexString()), JSON.stringify({ [role]: {} }), 'true'], { stdio: 'inherit', windowsHide: true });
      if (result.status !== 0) process.exitCode = 1;
    }
    connection.disconnect();
  }).onConnectError((_, error) => { clearTimeout(timer); console.error('Service identity connection failed:', error.message); process.exitCode = 1; }).build();
const timer = setTimeout(() => { console.error('Service identity connection timed out'); connection.disconnect(); process.exitCode = 1; }, 12000);
