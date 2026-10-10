import { mkdir, writeFile } from "node:fs/promises";

// Pin the Unicode release so catalog updates are intentional and reproducible.
const version = "18.0";
const source = `https://www.unicode.org/Public/${version}.0/emoji/emoji-test.txt`;
const response = await fetch(source);
if (!response.ok) throw new Error(`Unicode catalog: ${response.status}`);
const text = await response.text();
if (!text.includes(`# Version: ${version}`))
  throw new Error("Unexpected emoji version");
const groups = [],
  entries = [];
let group = -1,
  subgroup = "";
for (const line of text.split(/\r?\n/)) {
  if (line.startsWith("# group: ")) {
    groups.push(line.slice(9));
    group = groups.length - 1;
  } else if (line.startsWith("# subgroup: ")) {
    subgroup = line.slice(12).replaceAll("-", " ");
  } else {
    const match = line.match(
      /^([A-F0-9 ]+)\s*;\s*(?:fully-qualified|component)\s*#\s*\S+\s+E[\d.]+\s+(.+)$/,
    );
    if (!match) continue;
    const emoji = String.fromCodePoint(
      ...match[1]
        .trim()
        .split(/\s+/)
        .map((code) => parseInt(code, 16)),
    );
    entries.push([emoji, match[2], group, subgroup]);
  }
}
if (entries.length < 3900 || groups.length < 9)
  throw new Error("Incomplete emoji catalog");
const directory = new URL("../public/assets/emoji/", import.meta.url);
await mkdir(directory, { recursive: true });
const license = await fetch("https://www.unicode.org/license.txt");
if (!license.ok) throw new Error(`Unicode license: ${license.status}`);
await writeFile(new URL("LICENSE.txt", directory), await license.text());
await writeFile(
  new URL(`${version}.json`, directory),
  JSON.stringify({ version, source, groups, entries }) + "\n",
);
console.log(
  `Saved ${entries.length} Unicode ${version} emoji in ${groups.length} categories.`,
);
