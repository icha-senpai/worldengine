const region = 8;
const tables = ['sell_order_state', 'buy_order_state', 'marketplace_state', 'trade_order_state'];
await new Promise((resolve, reject) => {
  const socket = new WebSocket(`wss://relay.bitjita.com/v1/database/bitcraft-live-${region}/subscribe?compression=None`, 'v1.json.spacetimedb');
  const timer = setTimeout(() => { socket.close(); reject(new Error('Subscription timed out')); }, 25000);
  let subscribed = false;
  socket.addEventListener('error', () => { clearTimeout(timer); socket.close(); reject(new Error('WebSocket connection failed')); });
  socket.addEventListener('message', async event => {
    const data = typeof event.data === 'string' ? event.data : await event.data.text();
    let message;
    try { message = JSON.parse(data); } catch { return; }
    if (message.IdentityToken && !subscribed) {
      subscribed = true;
      socket.send(JSON.stringify({Subscribe:{request_id:1,query_strings:tables.map(table=>`SELECT * FROM ${table}`)}}));
    } else if (message.InitialSubscription) {
      const result = message.InitialSubscription.database_update.tables.map(table=>({table:table.table_name, rows:table.num_rows, inserts:(table.updates??[]).reduce((sum,update)=>sum+(update.inserts?.length??0),0)}));
      console.log(JSON.stringify({region,protocol:socket.protocol,snapshot:result}));
      clearTimeout(timer); socket.close(); resolve();
    } else if (message.SubscriptionError || message.error) {
      clearTimeout(timer); socket.close(); reject(new Error(JSON.stringify(message)));
    }
  });
});
