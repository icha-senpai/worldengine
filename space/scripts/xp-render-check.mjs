import assert from "node:assert/strict";
import { readFile, writeFile, mkdir } from "node:fs/promises";
import { pathToFileURL } from "node:url";
import { resolve } from "node:path";
import { parse, compileScript } from "@vue/compiler-sfc";
import { createSSRApp, h } from "vue";
import { renderToString } from "@vue/server-renderer";
const values = new Map();
globalThis.localStorage = {
  getItem: (k) => values.get(k) ?? null,
  setItem: (k, v) => values.set(k, v),
  removeItem: (k) => values.delete(k),
};
globalThis.location = new URL("http://space.test/bitcraft/activity/setup");
globalThis.window = { location: globalThis.location };
const data = (source) =>
  "data:text/javascript;base64," + Buffer.from(source).toString("base64");
const fixtures = {
  api: data(
    'export const bitcraftFetch=()=>{throw Error("Unexpected network access during render")};',
  ),
  widgets: data(
    'export const openWidget=()=>{};export const widgetUrl=async()=>"";',
  ),
  navigation: data(
    'export const usePage=()=>({props:{bitcraft:{player:null}}});export const route=name=>"/"+name;export const router={};',
  ),
  shell: data(
    `import {h} from ${JSON.stringify(import.meta.resolve("vue"))};export default {setup:(_, {slots})=>()=>h('div',slots.default?.())};`,
  ),
  empty: data("export default {render:()=>null};"),
};
// Compile real SFCs with fixture adapters for network/navigation and the outer shell.
// This checks setup/computed/template behavior without controlling a browser.
await mkdir(".runtime/xp-render", { recursive: true });
async function compile(file, name) {
  const { descriptor } = parse(await readFile(file, "utf8"), {
    filename: file,
  });
  const compiled = compileScript(descriptor, {
    id: name,
    inlineTemplate: true,
    templateOptions: { ssr: true },
  });
  const code = compiled.content.replace(
    /from\s+['"]([^'"]+)['"]/g,
    (whole, source) => {
      let target;
      if (source === "vue" || source === "vue/server-renderer")
        target = import.meta.resolve(source);
      else if (source === "/src/bitcraft-ui/api") target = fixtures.api;
      else if (source === "/src/bitcraft-ui/navigation")
        target = fixtures.navigation;
      else if (source === "./widgets") target = fixtures.widgets;
      else if (source.endsWith("WidgetPageShell.vue")) target = fixtures.shell;
      else if (
        [
          "WidgetSetupDrawer.vue",
          "WidgetThemeControls.vue",
          "SitePlayerDefaultButton.vue",
          "SelectInput.vue",
        ].some((n) => source.endsWith(n))
      )
        target = fixtures.empty;
      else if (source.endsWith("TrackerRefreshStatus.vue"))
        target = pathToFileURL(resolve(".runtime/xp-render/status.mjs")).href;
      else
        target = pathToFileURL(resolve("src/bitcraft-ui", source + ".js")).href;
      return "from " + JSON.stringify(target);
    },
  );
  const path = resolve(".runtime/xp-render", name + ".mjs");
  await writeFile(path, code);
  return (await import(pathToFileURL(path).href + "?v=" + Date.now())).default;
}
await compile("src/bitcraft-ui/Components/TrackerRefreshStatus.vue", "status");
const Activity = await compile("src/bitcraft-ui/Activity.vue", "activity");
const progressCases = [
  { name: "ordinary progress", xp: 1500, targetXp: 2000, percent: 75 },
  { name: "5515 XP remaining", xp: 1994485, targetXp: 2000000, percent: 99.7 },
  { name: "1 XP remaining", xp: 1999999, targetXp: 2000000, percent: 99.9 },
  { name: "goal reached", xp: 2000000, targetXp: 2000000, percent: 100 },
  { name: "goal exceeded", xp: 2000001, targetXp: 2000000, percent: 100 },
];
for (const { mode, name, xp, targetXp, percent } of [
  "full",
  "popout",
  "obs",
].flatMap((mode) => progressCases.map((scenario) => ({ mode, ...scenario })))) {
  const html = await renderToString(
    createSSRApp({
      render: () =>
        h(Activity, {
          filters: {
            setup: mode === "full",
            presentation: mode,
            character: "Icha",
            characters: "Icha,Other",
            skill: "all",
            skillKeys: "3",
            widgetEditable: false,
          },
          snapshot: {
            sampledAt: new Date().toISOString(),
            sampleSourceKey: "fixture-native",
            refresh: { delayed: false },
            tracker: {
              player: { entityId: "20", username: "Icha" },
              skills: [
                {
                  id: 3,
                  name: "Carpentry",
                  xp,
                  xpKnown: true,
                  level: 4,
                  nextLevel: 5,
                  nextLevelXp: targetXp,
                  progressPercent: 50,
                },
              ],
              passiveCrafts: [
                {
                  entityId: "30",
                  outputName: "Planks",
                  progress: 4,
                  totalProgress: 10,
                },
              ],
              activity: {
                signed_in: true,
                actions: [
                  { action_type: "Craft", ends_at_ms: Date.now() - 6000 },
                ],
              },
            },
          },
          pollUrl: "/bitcraft/activity/snapshot",
        }),
    }),
  );
  assert(html.includes("Icha"));
  assert(html.includes(`>${percent}<small>%</small>`), `${mode}: ${name}`);
  assert(
    html.includes(`aria-valuenow="${Math.min(100, (xp / targetXp) * 100)}"`),
    `${mode}: ${name} accessible progress`,
  );
  assert(
    html.includes(
      `${Math.max(0, targetXp - xp).toLocaleString()} XP remaining`,
    ),
    `${mode}: ${name} remaining XP`,
  );
  assert(html.includes("Session XP"));
  assert(html.includes('class="xp-working">Craft</p>'));
  assert(!html.includes("Add tracked character"));
  if (mode === "obs") {
    assert(!html.includes("Reset session"));
    assert(!html.includes("Tracked characters"));
    assert(html.includes("activity-source--obs"));
  } else {
    assert(html.includes("Reset session"));
    assert(html.includes("Tracked characters"));
  }
  console.log(`XP ${mode}: ${name} renders ${percent}% successfully.`);
}
