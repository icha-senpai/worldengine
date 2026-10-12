// Read Discord's user API and refresh only the new public portrait projection.
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { spawnSync } from 'node:child_process';
import { DbConnection } from '../packages/generated/src/index';
const database = process.argv[2];
assert.equal(database, 'fishbound-dev-local');
const uri = 'http://127.0.0.1:3127';
assert.equal(process.env.SPACETIMEDB_URI, uri);
assert.equal(process.env.SPACETIMEDB_DATABASE, database);
const botToken = process.env.DISCORD_BOT_TOKEN;
assert(botToken, 'Discord bot credentials are required');
const gameToken = process.env.SPACETIMEDB_BOT_TOKEN?.trim() || (await readFile(process.env.SPACETIMEDB_BOT_TOKEN_FILE!, 'utf8')).trim();
assert(gameToken, 'Configured bot service credentials are required');
const result = spawnSync('spacetime', ['sql','--server',uri,'--no-config','--format','json',database,'SELECT discord_user_id FROM player'], {encoding:'utf8',windowsHide:true});
assert.equal(result.status, 0, 'Could not read existing players');
// Node 24 supplies the original JSON number text so Discord snowflakes never
// pass through lossy JavaScript numeric rounding.
const blocks = JSON.parse(result.stdout, (_key, value, context?: {source?: string}) => {
  if (typeof value === 'number' && !Number.isSafeInteger(value)) {
    assert(context?.source, 'Exact snowflake text is required'); return context.source;
  }
  return value;
}) as {rows: (string | number)[][]}[];
const ids = blocks.flatMap(block => block.rows.map(row => String(row[0])));
const connection = await new Promise<DbConnection>((resolve,reject) => DbConnection.builder().withUri(uri).withDatabaseName(database).withToken(gameToken).onConnect(c => resolve(c)).onConnectError(() => reject(Error('Portrait service connection failed'))).build());
let animated = 0;
try {
  for (const id of ids) {
    let response: Response | undefined;
    for (let attempt = 0; attempt < 3; attempt++) {
      response = await fetch(`https://discord.com/api/v10/users/${id}`, {headers:{Authorization:`Bot ${botToken}`, 'User-Agent':'DiscordBot (https://fish.ichaa.dev, 0.1.0)'}, signal:AbortSignal.timeout(15000)});
      if (response.status !== 429) break;
      const limit = await response.json() as {retry_after:number};
      assert(limit.retry_after <= 30, 'Discord retry window exceeded');
      await new Promise(resolve => setTimeout(resolve, Math.ceil(limit.retry_after * 1000)));
    }
    assert(response?.ok, 'Discord profile could not be retrieved');
    const user = await response.json() as {id:string;avatar:string|null;discriminator:string};
    assert.equal(user.id, id, 'Discord identity mismatch');
    await connection.reducers.syncDiscordPortrait({discordUserId:BigInt(id),avatarHash:user.avatar ?? undefined,discriminator:Number(user.discriminator ?? '0')});
    if (user.avatar?.startsWith('a_')) animated++;
  }
  console.log(`Refreshed ${ids.length} Discord portraits, including ${animated} animated avatars. Game progress was not modified.`);
} finally { connection.disconnect(); }
