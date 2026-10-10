const host = "https://relay.bitjita.com";
const region = 9;
const schema = await fetch(`${host}/v1/database/bitcraft-live-${region}/schema?version=9`).then(r => r.json());
const names = ["world_region_state", "terrain_chunk_state"];
const fields = new Map(names.map(name => {
  const table = schema.tables.find(t => t.name === name);
  return [name, schema.typespace.types[table.product_type_ref].Product.elements.map(e => e.name.some)];
}));
await new Promise((resolve, reject) => {
  const socket = new WebSocket(`${host.replace('https:', 'wss:')}/v1/database/bitcraft-live-${region}/subscribe?compression=None`, 'v1.json.spacetimedb');
  const timer = setTimeout(() => { socket.close(); reject(Error('Relay timeout')); }, 15000);
  socket.onmessage = async e => {
    const message = JSON.parse(typeof e.data === 'string' ? e.data : await e.data.text());
    if (message.IdentityToken) socket.send(JSON.stringify({ Subscribe: { request_id: 1, query_strings: [
      "SELECT * FROM world_region_state", "SELECT * FROM terrain_chunk_state WHERE chunk_index = 115269",
    ] } }));
    if (!message.InitialSubscription) return;
    for (const table of message.InitialSubscription.database_update.tables) {
      for (const update of table.updates) for (const raw of update.inserts) {
        const decoded = JSON.parse(raw);
        const row = Array.isArray(decoded) ? Object.fromEntries(fields.get(table.table_name).map((key, i) => [key, decoded[i]])) : decoded;
        if (table.table_name === 'terrain_chunk_state') {
          const index = (3698 % 32) * 32 + 8606 % 32;
          console.log(JSON.stringify({ table: table.table_name, chunk: row.chunk_index, x: row.chunk_x, z: row.chunk_z, elevation: row.elevations[index], water: row.water_levels[index], depth: row.water_levels[index] - row.elevations[index] }));
        } else console.log(JSON.stringify({ table: table.table_name, row }));
      }
    }
    clearTimeout(timer); socket.close(); resolve();
  };
  socket.onerror = reject;
});
