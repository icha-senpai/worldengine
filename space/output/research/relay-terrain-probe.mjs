const hosts = ['https://relay.bitjita.com', 'https://relay.bitcraftsync.app:3008'];
for (const host of hosts) {
  const schema = await fetch(`${host}/v1/database/bitcraft-live-8/schema?version=9`).then(r => r.json());
  const table = schema.tables.find(t => t.name === 'terrain_chunk_state');
  const fields = schema.typespace.types[table.product_type_ref].Product.elements.map(e => e.name.some);
  console.log(JSON.stringify({host, fields}));
  await new Promise(resolve => {
    const ws = new WebSocket(`${host.replace(/^http/, 'ws')}/v1/database/bitcraft-live-8/subscribe?compression=None`, 'v1.json.spacetimedb');
    let finished = false;
    let phase = 'locations';
    function finish(result) {
      if (finished) return;
      finished = true;
      console.log(JSON.stringify({host, ...result}));
      clearTimeout(timer);
      ws.close();
      resolve();
    }
    const timer = setTimeout(() => finish({result:'timeout'}), 15000);
    ws.onerror = () => finish({result:'connection error'});
    ws.onmessage = async event => {
      try {
        const raw = typeof event.data === 'string' ? event.data : await event.data.text();
        const message = JSON.parse(raw);
        if (message.IdentityToken) {
          ws.send(JSON.stringify({Subscribe:{request_id:1,query_strings:['SELECT location_state.* FROM location_state JOIN resource_state ON location_state.entity_id = resource_state.entity_id WHERE resource_state.resource_id = 1110002']}}));
        }
        if (message.InitialSubscription) {
          const updates = message.InitialSubscription.database_update.tables;
          const rows = updates.flatMap(t => t.updates.flatMap(u => u.inserts ?? []));
          const first = rows[0] ? JSON.parse(rows[0]) : null;
          if (phase === 'locations') {
            const chunk = Array.isArray(first) ? first[1] : first?.chunk_index;
            console.log(JSON.stringify({host, phase, rows:rows.length, chunk}));
            if (!chunk) { finish({result:'no sample school location'}); return; }
            phase = 'terrain';
            ws.send(JSON.stringify({Subscribe:{request_id:2,query_strings:[`SELECT * FROM terrain_chunk_state WHERE chunk_index = ${chunk}`]}}));
            return;
          }
          const elevations = Array.isArray(first) ? first[6] : first?.elevations;
          const water = Array.isArray(first) ? first[7] : first?.water_levels;
          finish({result:'terrain subscription',rows:rows.length,cellCount:elevations?.length,waterCellCount:water?.length,sample:elevations ? {elevation:elevations[0],waterLevel:water[0],depth:Math.max(0,water[0]-elevations[0])} : null});
        }
        if (message.SubscriptionError) finish({result:'subscription error',error:message.SubscriptionError.error});
        if (message.TransactionUpdate?.status?.Failed) finish({result:'failed',error:message.TransactionUpdate.status.Failed});
      } catch(error) { finish({result:'decode error',error:error.message}); }
    };
  });
}
