import { access, mkdir, readFile, writeFile } from "node:fs/promises";

// Noto Emoji's Unicode 18 release; no third-party requests are made by visitors.
const revision = "e20cbc2bbec1926686be9f9bee7d1d2cfa1fea0e";
const origin = `https://raw.githubusercontent.com/googlefonts/noto-emoji/${revision}/`;
async function download(path) {
  for (let attempt = 0; attempt < 3; attempt++) {
    const response = await fetch(origin + path);
    if (response.ok) return response.text();
    if (attempt === 2) throw new Error(`${path}: ${response.status}`);
  }
}
const treeResponse = await fetch(
  `https://api.github.com/repos/googlefonts/noto-emoji/git/trees/${revision}?recursive=1`,
);
if (!treeResponse.ok)
  throw new Error(`Noto asset list: ${treeResponse.status}`);
const tree = await treeResponse.json();
if (tree.truncated) throw new Error("Incomplete Noto asset list");
const paths = new Set(tree.tree.map((entry) => entry.path));
const aliases = new Map(
  (await download("emoji_aliases.txt"))
    .split(/\r?\n/)
    .filter((line) => !line.startsWith("#") && line.includes(";"))
    .map((line) => line.split("#")[0].trim().split(";")),
);
const catalog = JSON.parse(
  await readFile(
    new URL("../public/assets/emoji/18.0.json", import.meta.url),
    "utf8",
  ),
);
const directory = new URL("../public/assets/emoji/noto-18.0/", import.meta.url);
await mkdir(directory, { recursive: true });
const jobs = catalog.entries.map(([emoji]) => {
  const points = Array.from(emoji)
    .map((character) => character.codePointAt(0))
    .filter((point) => point !== 0xfe0f);
  const code = points
    .map((point) => point.toString(16).padStart(4, "0"))
    .join("_");
  let source = `2D/svg/emoji_u${code}.svg`;
  if (!paths.has(source)) {
    const aliased = (aliases.get(code) ?? code)
      .split("_")
      .map((point) => parseInt(point, 16));
    const flag =
      aliased.length === 2 &&
      aliased.every((point) => point >= 0x1f1e6 && point <= 0x1f1ff)
        ? String.fromCharCode(...aliased.map((point) => point - 0x1f1e6 + 65))
        : {
            "1f3f4_e0067_e0062_e0065_e006e_e0067_e007f": "GB-ENG",
            "1f3f4_e0067_e0062_e0073_e0063_e0074_e007f": "GB-SCT",
            "1f3f4_e0067_e0062_e0077_e006c_e0073_e007f": "GB-WLS",
          }[code];
    source = `third_party/region-flags/svg/${flag}.svg`;
  }
  if (!paths.has(source)) throw new Error(`No artwork for ${code}`);
  return { source, destination: new URL(`${code}.svg`, directory) };
});
let cursor = 0,
  completed = 0;
await Promise.all(
  Array.from({ length: 8 }, async () => {
    while (cursor < jobs.length) {
      const job = jobs[cursor++];
      try {
        await access(job.destination);
      } catch {
        const svg = await download(job.source);
        if (!svg.includes("<svg"))
          throw new Error(`Invalid SVG: ${job.source}`);
        await writeFile(job.destination, svg);
      }
      completed++;
      if (completed % 500 === 0)
        console.log(`${completed}/${jobs.length} emoji images ready`);
    }
  }),
);
await writeFile(
  new URL("NOTICES.txt", directory),
  `Noto Emoji Unicode 18 artwork\nSource: https://github.com/googlefonts/noto-emoji/tree/${revision}\n\n${await download("2D/svg/LICENSE")}\nRegion flags:\n${await download("third_party/region-flags/LICENSE")}`,
);
const apache = await fetch("https://www.apache.org/licenses/LICENSE-2.0.txt");
if (!apache.ok) throw new Error(`Apache license: ${apache.status}`);
await writeFile(new URL("Apache-2.0.txt", directory), await apache.text());
console.log(`Ready: ${completed} locally hosted emoji SVGs.`);
