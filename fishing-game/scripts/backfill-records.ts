import { spawnSync } from 'node:child_process';
import { DbConnection } from '../packages/generated/src/index';

const database = process.argv[2];
if (!database || !/^fishbound-[a-z0-9-]+$/.test(database)) throw new Error('Pass the explicit Fishbound database name.');
const uri = 'http://127.0.0.1:3127';
let connection: DbConnection | undefined;
const timer = setTimeout(() => { console.error('Timed out connecting for records backfill.'); connection?.disconnect(); process.exit(1); }, 15000);
try {
  await new Promise<void>((resolve, reject) => {
    connection = DbConnection.builder().withUri(uri).withDatabaseName(database)
      .onConnect(connection => connection.subscriptionBuilder().onApplied(() => resolve()).onError(ctx => reject(ctx.event)).subscribe(['SELECT * FROM public_profile']))
      .onConnectError((_, error) => reject(error)).build();
  });
  clearTimeout(timer);
  const ids = [...connection!.db.publicProfile.iter()].map(row => row.playerId);
  for (const playerId of ids) {
    const result = spawnSync('spacetime', ['call', '--server', uri, '--no-config', '--yes', database, 'rebuild_player_records', playerId.toString()], { encoding: 'utf8', windowsHide: true, timeout: 15000 });
    if (result.status !== 0) throw new Error(`Backfill failed for player ${playerId}: ${result.stderr || result.stdout}`);
  }
  console.log(`Backfilled ${ids.length} anglers from durable lifetime progress in ${database}.`);
} finally { clearTimeout(timer); connection?.disconnect(); }
