import fs from "node:fs";
import { fileURLToPath } from "node:url";

const root = new URL("../", import.meta.url);
const content = JSON.parse(
  fs.readFileSync(
    new URL("spacetimedb/src/content/evergather.json", root),
    "utf8",
  ),
);
const items = new Map();
const title = (value) =>
  String(value ?? "")
    .replaceAll("_", " ")
    .replace(/\b\w/g, (letter) => letter.toUpperCase());
function item(value) {
  const key = value.item_key ?? value.key;
  if (!key) throw new Error("Missing item key");
  if (!items.has(key))
    items.set(key, {
      key,
      name: value.item_name ?? value.name ?? key,
      classes: new Set(),
      tiers: new Set(),
      sources: [],
      uses: [],
      equipment: false,
    });
  return items.get(key);
}
for (const value of Object.values(content.item_metadata)) {
  const row = item(value);
  row.classes.add(value.item_class);
  row.tiers.add(value.item_tier);
}
function source(value, category, definition, key) {
  item(value).sources.push({
    category,
    name: definition.label ?? definition.item_name ?? value.item_name,
    skill: definition.skill ?? value.equipment_skill ?? value.skill,
    level: definition.required_level ?? 1,
    key,
  });
}
function use(values, kind, definition, key) {
  for (const value of values ?? [])
    if (value.quantity > 0) {
      item(value).uses.push({
        kind,
        name: definition.label ?? definition.item_name ?? key,
        skill: definition.skill,
        key,
      });
    }
}
for (const [group, category] of [
  ["gathering_actions", "Gathering drops"],
  ["skill_activities", "Activity rewards"],
]) {
  for (const [key, definition] of Object.entries(content[group])) {
    use(definition.supplies, "Activity supplies", definition, key);
    for (const value of definition.loot ?? [])
      if ((value.quantity ?? value.max) > 0 && (value.chance ?? 100) > 0)
        source(value, category, definition, key);
  }
}
for (const [key, definition] of Object.entries(content.crafting_recipes)) {
  use(definition.ingredients, "Crafting / processing", definition, key);
  for (const value of definition.outputs ?? []) {
    source(
      value,
      value.equipment_skill ? "Crafted equipment" : "Crafted items",
      definition,
      key,
    );
    if (value.equipment_skill) item(value).equipment = true;
  }
}
for (const [key, definition] of Object.entries(content.job_contracts)) {
  use(definition.requirements, "Job delivery", definition, key);
  for (const value of definition.rewards ?? [])
    source(value, "Commission rewards", definition, key);
}
for (const [key, definition] of Object.entries(content.expeditions)) {
  use(definition.supplies, "Expedition supplies", definition, key);
  for (const value of definition.rewards ?? [])
    source(value, "Expedition rewards", definition, key);
}
for (const [key, definition] of Object.entries(content.tool_upgrades)) {
  use(definition.ingredients, "Tier upgrade", definition, key);
  use(definition.ingredients?.slice(0, 1), "Repair", definition, key);
  source(
    definition.output,
    "Tier upgrade output",
    { ...definition, skill: definition.equipment_skill },
    key,
  );
  item(definition.output).equipment = true;
  if (definition.ingredients?.[0])
    source(
      definition.ingredients[0],
      "Equipment salvage",
      { ...definition, skill: definition.equipment_skill },
      key,
    );
}
for (const key of Object.keys(content.rarity_rules))
  use(
    content.rarity_materials[key],
    "Rarity upgrade",
    { label: `${title(key)} → ${title(content.rarity_rules[key].target)}` },
    key,
  );
for (const [key, values] of Object.entries(
  content.rarity_materials_by_tier ?? {},
))
  use(values, "Rarity upgrade", { label: `Tier ${key} rarity attempts` }, key);
for (const [key, value] of Object.entries(content.consumables ?? {}))
  use(
    [{ item_key: key, quantity: 1 }],
    "Consumable buff",
    { label: value.description },
    key,
  );
for (const [key, value] of Object.entries(content.shop_offers)) {
  source(value, "Shop purchase", value, key);
  if (value.kind === "tool") item(value).equipment = true;
}
for (const [key, value] of Object.entries(content.starter_tools)) {
  source(value, "Starter equipment", value, key);
  item(value).equipment = true;
}
const categories = [
  "Gathering drops",
  "Activity rewards",
  "Crafted items",
  "Crafted equipment",
  "Commission rewards",
  "Expedition rewards",
  "Starter equipment",
];
const rows = [...items.values()]
  .map((row) => {
    const origins = [...new Set(row.sources.map((entry) => entry.category))];
    const primary =
      categories.find((category) => origins.includes(category)) ??
      origins[0] ??
      "No known source";
    const uses = [
      ...new Set([
        ...(row.equipment ? ["Equippable equipment"] : []),
        ...row.uses.map((entry) => entry.kind),
      ]),
    ];
    return {
      ...row,
      primary,
      origins,
      classes: [...row.classes].filter(Boolean),
      tiers: [...row.tiers].filter(Boolean).sort((a, b) => a - b),
      usesSummary: uses,
      useful: uses.length > 0,
    };
  })
  .sort(
    (a, b) =>
      categories.indexOf(a.primary) - categories.indexOf(b.primary) ||
      a.name.localeCompare(b.name) ||
      a.key.localeCompare(b.key),
  );
const summary = categories.map((category) => {
  const group = rows.filter((row) => row.primary === category);
  return {
    category,
    total: group.length,
    useful: group.filter((row) => row.useful).length,
    saleOnly: group.filter((row) => !row.useful).length,
  };
});
if (rows.some((row) => !row.sources.length))
  throw new Error("Items with no acquisition source");
const sourceText = (entry) =>
  `${entry.category}: ${entry.name} (${title(entry.skill)}${entry.skill ? "; " : ""}level ${entry.level}) [${entry.key}]`;
const useText = (entry) => `${entry.kind}: ${entry.name} [${entry.key}]`;
const csvCell = (value) => `"${String(value).replaceAll('"', '""')}"`;
const header = [
  "Category",
  "Item",
  "Item key",
  "Catalog classes",
  "Item tiers",
  "Gameplay use",
  "Uses",
  "Source skills",
  "All acquisition methods",
  "All use requirements",
];
const csv = [
  header,
  ...rows.map((row) => [
    row.primary,
    row.name,
    row.key,
    row.classes.join("; "),
    row.tiers.join("; "),
    row.useful ? "Yes" : "No: selling/trading only",
    row.usesSummary.join("; "),
    [
      ...new Set(
        row.sources.map((entry) => title(entry.skill)).filter(Boolean),
      ),
    ].join("; "),
    row.sources.map(sourceText).join(" | "),
    row.uses.map(useText).join(" | "),
  ]),
]
  .map((cells) => cells.map(csvCell).join(","))
  .join("\r\n");
const escape = (value) =>
  String(value ?? "")
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;");
const list = (entries) =>
  `<ul>${entries.map((entry) => `<li>${escape(entry)}</li>`).join("")}</ul>`;
const body = rows
  .map((row) => {
    const skills = [
      ...new Set(
        row.sources.map((entry) => title(entry.skill)).filter(Boolean),
      ),
    ].join(", ");
    const search = [
      row.name,
      row.key,
      row.primary,
      skills,
      ...row.classes,
      ...row.sources.map(sourceText),
      ...row.uses.map(useText),
    ]
      .join(" ")
      .toLowerCase();
    return `<tr data-category="${escape(row.primary)}" data-useful="${row.useful}" data-search="${escape(search)}"><td><details><summary>${escape(row.name)}</summary><p class="key">${escape(row.key)}</p><p>Catalog class: ${escape(row.classes.join(", ") || "Starter tool")}. Item tier: ${escape(row.tiers.join(", ") || "Starter")}.</p><strong>Acquisition</strong>${list(row.sources.map(sourceText))}${row.uses.length ? `<strong>Consumed by</strong>${list(row.uses.map(useText))}` : ""}</details></td><td>${escape(row.primary)}</td><td>${escape(skills)}</td><td><span class="status ${row.useful ? "useful" : "sale"}">${row.useful ? "Gameplay use" : "Selling / trading only"}</span><div class="use-list">${escape(row.usesSummary.join(", "))}</div></td></tr>`;
  })
  .join("\n");
const date = new Date().toISOString().slice(0, 10);
const html = `<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><link rel="icon" href="data:,"><title>Evergather — categorized item list</title>
<style>
:root{color-scheme:dark;font-family:system-ui,sans-serif;background:#101725;color:#e9eef8}*{box-sizing:border-box}body{margin:0 auto;padding:32px;max-width:1500px}h1{font-size:30px;margin:0 0 12px}p{line-height:1.6;color:#bac8dd}a{color:#87c8ff}table{border-collapse:collapse;width:100%;font-size:14px}th,td{text-align:left;vertical-align:top;padding:13px 15px;border-bottom:1px solid #2b3850}th{background:#1d293d;color:#dbe6f7}tbody tr:nth-child(even){background:#141e2e}summary{cursor:pointer;font-weight:650}details[open]{min-width:240px}li{margin-bottom:8px;line-height:1.5;overflow-wrap:anywhere}ul{padding-left:20px}.key{font-family:monospace;overflow-wrap:anywhere}.filters{display:flex;gap:14px;flex-wrap:wrap;padding:18px 0}label{font-size:13px;color:#bac8dd;display:flex;flex-direction:column;gap:6px}input,select{background:#1d293d;border:1px solid #465976;border-radius:6px;padding:11px;color:#fff;font:inherit}input{width:340px;max-width:100%}.status{display:inline-block;border-radius:5px;padding:4px 8px;font-size:12px;white-space:nowrap}.useful{background:#173e37;color:#a5efcc}.sale{background:#483822;color:#f7d495}.use-list{color:#bac8dd;font-size:13px;line-height:1.5;margin-top:8px}.table-wrap{overflow:auto}.summary{max-width:750px;margin:24px 0}#shown{font-weight:650;color:#dbe6f7}tr[hidden]{display:none}@media(max-width:650px){body{padding:18px}h1{font-size:24px}.filters label{width:100%}input,select{width:100%}th,td{padding:11px}}@media print{.filters{display:none}body{color:#111;background:white}p,th,.use-list{color:#111}th,tbody tr:nth-child(even){background:#eee}.status{color:#111;background:none}tr[hidden]{display:table-row}}
</style></head><body><h1>Evergather: every item, categorized</h1>
<p>Generated ${date} from the current Space game content. ${rows.length.toLocaleString()} unique item types; rarity variants count once. Includes the 38 starter tools stored outside the item metadata catalog.</p>
<p><strong>Gathering drops</strong> come from Gather actions. <strong>Activity rewards</strong> come from Activities, including activities labeled Processing. <strong>Crafted items/equipment</strong> come from recipes that consume ingredients. These are separate acquisition routes.</p>
<p>“Gameplay use” means equipped as a tool or consumed by a recipe, job, expedition, repair, tier upgrade, or rarity upgrade. Selling, trading, and collecting for achievements are excluded from that definition. Catalog class names such as “consumable” or “equipment” do not themselves prove that an item can be used or equipped.</p>
<p>Categories below count each item once by its first matching production source; every additional source (including shops, tier upgrades, and salvage) appears inside its expandable row. Uses identify implemented requirements; they do not simulate progression or guarantee usefulness at your current level.</p>
<div class="summary table-wrap"><table aria-label="Category totals"><thead><tr><th>Category</th><th>Total</th><th>Gameplay use</th><th>Selling only</th></tr></thead><tbody>${summary.map((entry) => `<tr><td>${entry.category}</td><td>${entry.total}</td><td>${entry.useful}</td><td>${entry.saleOnly}</td></tr>`).join("")}<tr><th>Total</th><th>${rows.length}</th><th>${rows.filter((row) => row.useful).length}</th><th>${rows.filter((row) => !row.useful).length}</th></tr></tbody></table></div>
<p><a href="evergather-items.csv" download>Download all items as CSV</a> · Click an item name to see every source action and use requirement.</p>
<div class="filters"><label>Search items, skills, actions, or uses<input id="search" type="search" placeholder="e.g. copper ore, mining, rarity upgrade"></label><label>Acquisition category<select id="category"><option value="">All categories</option>${categories.map((category) => `<option>${category}</option>`).join("")}</select></label><label>Gameplay use<select id="use"><option value="">All items</option><option value="true">Has gameplay use</option><option value="false">Selling / trading only</option></select></label><label>Source skill<select id="skill"><option value="">All skills</option>${[
  ...new Set(
    rows.flatMap((row) =>
      row.sources.map((entry) => title(entry.skill)).filter(Boolean),
    ),
  ),
]
  .sort()
  .map((skill) => `<option>${escape(skill)}</option>`)
  .join("")}</select></label></div>
<p id="shown" role="status" aria-live="polite"></p><div class="table-wrap"><table id="items" aria-label="All categorized items"><thead><tr><th>Item and details</th><th>Acquisition category</th><th>Source skills</th><th>Current gameplay use</th></tr></thead><tbody>${body}</tbody></table></div><p id="empty" hidden>No items match these filters.</p>
<script>
const rows = [...document.querySelectorAll('#items tbody tr')];
const search = document.getElementById('search');
const category = document.getElementById('category');
const use = document.getElementById('use');
const skill = document.getElementById('skill');
function filter() {
  const terms = search.value.trim().toLowerCase().split(/\\s+/).filter(Boolean);
  let count = 0;
  for (const row of rows) {
    const sourceSkills = row.cells[2].textContent.split(', ');
    row.hidden = (category.value && row.dataset.category !== category.value) || (use.value && row.dataset.useful !== use.value) || (skill.value && !sourceSkills.includes(skill.value)) || !terms.every((term) => row.dataset.search.includes(term));
    if (!row.hidden) count++;
  }
  document.getElementById('shown').textContent = count.toLocaleString() + ' of ' + rows.length.toLocaleString() + ' items shown';
  document.getElementById('empty').hidden = count !== 0;
}
for (const control of [search, category, use, skill]) control.addEventListener('input', filter);
filter();
</script></body></html>`;
fs.mkdirSync(new URL("docs/", root), { recursive: true });
fs.writeFileSync(
  new URL("docs/evergather-items.csv", root),
  "\ufeff" + csv + "\r\n",
);
fs.writeFileSync(new URL("docs/evergather-items.html", root), html);
console.log(
  JSON.stringify(
    {
      total: rows.length,
      useful: rows.filter((row) => row.useful).length,
      categories: summary,
      report: fileURLToPath(new URL("docs/evergather-items.html", root)),
    },
    null,
    2,
  ),
);
