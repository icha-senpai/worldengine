import { writeFile } from 'node:fs/promises';
const schema = await fetch('https://relay.bitjita.com/v1/database/bitcraft-live-8/schema?version=9').then(r=>r.json());
const names=['sell_order_state','buy_order_state','marketplace_state','trade_order_state','claim_state','claim_local_state','player_username_state','experience_state','player_state','inventory_state','progressive_action_state','passive_craft_state','player_housing_state','player_action_state','active_buff_state','stamina_state'];
const definitions=Object.fromEntries(names.map(name=>{const t=schema.tables.find(t=>t.name===name);return [name,t?{table:t,type:schema.typespace.types[t.product_type_ref]}:null]}));
await writeFile('output/research/relay-schema-inspection.json',JSON.stringify({definitions,typespace:schema.typespace},null,2));
for(const [name,d] of Object.entries(definitions))console.log(name,d?.type?.Product?.elements.map(e=>[e.name.some,e.algebraic_type]));
await new Promise(resolve=>{
 const ws=new WebSocket('wss://relay.bitjita.com/v1/database/bitcraft-live-8/subscribe?compression=None','v1.json.spacetimedb');
 const timer=setTimeout(()=>{ws.close();resolve()},20000);
 ws.onmessage=e=>{const m=JSON.parse(e.data);if(m.IdentityToken)ws.send(JSON.stringify({Subscribe:{request_id:1,query_strings:names.slice(0,7).map(n=>`SELECT * FROM ${n}`)}}));if(m.InitialSubscription){for(const t of m.InitialSubscription.database_update.tables)console.log(t.table_name,JSON.stringify(t.updates[0]?.inserts?.[0]));clearTimeout(timer);ws.close();resolve()}};
});
