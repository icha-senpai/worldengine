import { writeFile } from 'node:fs/promises';
const targets = [
  { name: 'BITCONNECT', host: 'wss://relay.bitjita.com' },
  { name: 'BitCraftSync', host: 'wss://relay.bitcraftsync.app:3008' },
];
const market = ['sell_order_state', 'buy_order_state'];
async function probe(target, tables, holdMs = 0, region = 8) {
  return await new Promise(resolve => {
    const started = performance.now();
    const result = { provider: target.name, region, tables, snapshotBytes: 0, updateMessages: 0, updatedRows: 0 };
    const host = target.name === 'BitCraftSync' ? `wss://relay.bitcraftsync.app:${3000+region}` : target.host;
    const socket = new WebSocket(`${host}/v1/database/bitcraft-live-${region}/subscribe?compression=None`, 'v1.json.spacetimedb');
    let settled = false, subscribed = false;
    let holdTimer;
    const finish = error => {
      if (settled) return;
      settled = true; clearTimeout(timeout); clearTimeout(holdTimer); socket.close();
      if (error) result.error = error;
      resolve(result);
    };
    const timeout = setTimeout(() => finish('Timed out after 30s'), 30000);
    socket.addEventListener('error', () => finish('WebSocket error'));
    socket.addEventListener('close', () => { if (!settled) finish('Closed before completion'); });
    socket.addEventListener('message', async event => {
      const raw = typeof event.data === 'string' ? event.data : await event.data.text();
      let msg;
      try { msg = JSON.parse(raw); } catch { return; }
      if (msg.IdentityToken && !subscribed) {
        result.greetingMs = Math.round(performance.now() - started);
        subscribed = true;
        socket.send(JSON.stringify({Subscribe:{request_id:1,query_strings:tables.map(table=>`SELECT * FROM ${table}`)}}));
      } else if (msg.InitialSubscription) {
        result.snapshotMs = Math.round(performance.now() - started);
        result.snapshotBytes = Buffer.byteLength(raw);
        result.rows = Object.fromEntries(msg.InitialSubscription.database_update.tables.map(table => [table.table_name, table.num_rows]));
        result.observationMs = holdMs;
        if (holdMs) holdTimer = setTimeout(() => finish(), holdMs);
        else finish();
      } else if (msg.TransactionUpdate || msg.TransactionUpdateLight) {
        result.updateMessages++;
        const update = msg.TransactionUpdate ?? msg.TransactionUpdateLight;
        const state = update.status?.Committed ?? update.database_update ?? update;
        for (const table of state.tables ?? [])
          for (const change of table.updates ?? [])
            result.updatedRows += (change.inserts?.length ?? 0) + (change.deletes?.length ?? 0);
      } else if (msg.SubscriptionError || msg.error) finish('Subscription refused');
    });
  });
}
const results = [];
for (let trial = 1; trial <= 3; trial++) {
  const pair = await Promise.all(targets.map(target => probe(target, market, trial === 1 ? 10000 : 0)));
  for (const result of pair) { result.trial = trial; results.push(result); console.log(JSON.stringify(result)); }
}
const broad = await Promise.all(targets.map(target => probe(target, [...market, 'marketplace_state', 'trade_order_state'])));
for (const result of broad) { results.push(result); console.log(JSON.stringify(result)); }
const worlds = await Promise.all(targets.map(async target => {
  const started = performance.now();
  const regions = [];
  for (const region of [7,8,9,12,13,14,17,18,19]) regions.push(await probe(target, market, 0, region));
  const world = { provider:target.name, elapsedMs:Math.round(performance.now()-started), rows:regions.reduce((sum,result)=>sum+Object.values(result.rows??{}).reduce((n,count)=>n+count,0),0), snapshotBytes:regions.reduce((sum,result)=>sum+result.snapshotBytes,0), regions };
  console.log(JSON.stringify({provider:world.provider,worldMs:world.elapsedMs,rows:world.rows,snapshotBytes:world.snapshotBytes,failedRegions:regions.filter(result=>result.error).map(result=>result.region)}));
  return world;
}));
await writeFile('output/research/relay-flow-benchmark.json', JSON.stringify({ testedAt: new Date().toISOString(), protocol: 'v1.json.spacetimedb', results, worlds }, null, 2));
