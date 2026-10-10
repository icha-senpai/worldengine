const root = 'https://bitjita.com/_app/immutable/chunks/';
const js = await (await fetch(root + 'D7KEF6_P.js')).text();
const names = [...new Set([...js.matchAll(/"\.\/([^"/]+\.js)"/g)].map(m => m[1]))];
for (let i = 0; i < names.length; i += 4) await Promise.all(names.slice(i, i + 4).map(async name => {
  const text = await (await fetch(root + name)).text();
  if (/supertile|DecompressionStream|\.bin\.gz/.test(text)) console.log(name,
    [...text.matchAll(/.{0,180}(?:supertile|DecompressionStream|Int16Array|Uint16Array|\.bin\.gz).{0,250}/g)].slice(0,25).map(m => m[0]).join('\n'));
}));
