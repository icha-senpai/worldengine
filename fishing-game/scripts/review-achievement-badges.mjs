// Read-only local art review. No gameplay access or database credentials.
import { createServer } from 'node:http';
import { readFile, readdir } from 'node:fs/promises';
import { resolve } from 'node:path';

const root=resolve('.');
const plan=JSON.parse(await readFile(resolve(root,'content/achievement-badge-art.json'),'utf8'));
const paths=new Map(plan.assets.map(asset=>[asset.url,resolve(root,`assets${asset.url}`)]));
const html=`<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Fishbound badge art review</title><style>
*{box-sizing:border-box}body{margin:0;padding:24px;background:#17342c;color:#f6e7c3;font-family:system-ui,sans-serif}h1{font-size:24px;margin:0 0 10px}header{display:flex;gap:24px;align-items:center;flex-wrap:wrap}label{font-size:14px}select{font:inherit;padding:9px;background:#f6e7c3;border:2px solid #a48458;color:#244036}#count{font-size:14px}main{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:12px;margin-top:20px}article{background:#f2e5bf;color:#283b30;border:2px solid #a48458;padding:14px;text-align:center;min-height:198px}article img{width:120px;height:120px;object-fit:contain;image-rendering:pixelated;display:block;margin:0 auto 8px}.pending{height:120px;display:grid;place-items:center;color:#8a7858;font-size:12px}.name{font-size:13px;font-weight:600}.id{font-size:11px;color:#8a6c45;margin-top:6px}@media(max-width:1000px){main{grid-template-columns:repeat(4,minmax(0,1fr))}}@media(max-width:600px){body{padding:12px}main{grid-template-columns:repeat(2,minmax(0,1fr))}article{padding:10px}article img{width:100px;height:100px}}
</style><header><div><h1>Fishbound · Badge artwork</h1><div id="count" aria-live="polite">Loading saved artwork…</div></div><label>Batch <select id="batch"><option value="all">All badges</option></select></label></header><main></main><script>
const assets=${JSON.stringify(plan.assets.map(({achievementId,name,batch,url})=>({achievementId,name,batch,url})))};
const select=document.querySelector('#batch');
for(const batch of new Set(assets.map(a=>a.batch))){const option=document.createElement('option');option.value=batch;option.textContent=batch;select.append(option);}
let saved=[];let signature='';
function render(){const main=document.querySelector('main');main.replaceChildren();const shown=assets.filter(a=>select.value==='all'||a.batch===select.value);for(const a of shown){const card=document.createElement('article');card.dataset.id=a.achievementId;if(saved.includes(a.achievementId)){const img=document.createElement('img');img.src=a.url;img.alt=a.name;card.append(img);}else{const waiting=document.createElement('div');waiting.className='pending';waiting.textContent='Awaiting artwork';card.append(waiting);}const name=document.createElement('div');name.className='name';name.textContent=a.name;card.append(name);const id=document.createElement('div');id.className='id';id.textContent='Badge '+a.achievementId;card.append(id);main.append(card);}document.querySelector('#count').textContent=saved.length+' / '+assets.length+' generated · '+shown.length+' in this view';}
select.addEventListener('change',render);
async function refresh(){const next=await (await fetch('/status')).json();if(JSON.stringify(next)!==signature){saved=next;signature=JSON.stringify(next);render();}}
refresh();setInterval(refresh,5000);
</script></html>`;
const server=createServer(async(request,response)=>{
  try {
    const url=new URL(request.url,'http://127.0.0.1:5185').pathname;
    if(url==='/favicon.ico'){response.writeHead(204);response.end();return;}
    if(url==='/'){response.writeHead(200,{'Content-Type':'text/html; charset=utf-8'});response.end(html);return;}
    if(url==='/status'){const files=await readdir(resolve(root,'assets/achievements'));const ids=files.filter(file=>/^badge-\d+\.png$/.test(file)).map(file=>Number(file.match(/\d+/)[0])).sort((a,b)=>a-b);response.writeHead(200,{'Content-Type':'application/json','Cache-Control':'no-store'});response.end(JSON.stringify(ids));return;}
    if(paths.has(url)){response.writeHead(200,{'Content-Type':'image/png','Cache-Control':'no-cache'});response.end(await readFile(paths.get(url)));return;}
    response.writeHead(404);response.end('Not found');
  } catch {response.writeHead(404);response.end('Not found');}
});
server.listen(5185,'127.0.0.1',()=>console.log('Read-only badge review: http://127.0.0.1:5185'));
