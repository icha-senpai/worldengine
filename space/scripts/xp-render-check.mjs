import assert from "node:assert/strict";
import { readFile, writeFile, mkdir, mkdtemp, rm } from "node:fs/promises";
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
  bitcraft: data(
    `import {ref} from ${JSON.stringify(import.meta.resolve("vue"))};export const bitcraftConnected=ref(true);export const bitcraftReconnecting=ref(false);`,
  ),
  widgets: data(
    'export const openWidget=()=>{};export const widgetUrl=async()=>"";export const persistWidgetDraft=()=>{};',
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
await mkdir(".runtime", { recursive: true });
const renderDirectory = await mkdtemp(resolve(".runtime/xp-render-"));
try {
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
        else if (source === "../bitcraft") target = fixtures.bitcraft;
        else if (source === "/src/bitcraft-ui/navigation")
          target = fixtures.navigation;
        else if (source === "./widgets") target = fixtures.widgets;
        else if (source.endsWith("WidgetPageShell.vue"))
          target = fixtures.shell;
        else if (
          [
            "WidgetSetupDrawer.vue",
            "WidgetThemeControls.vue",
            "SitePlayerDefaultButton.vue",
            "SelectInput.vue",
            "EmojiInput.vue",
            "EmojiDisplay.vue",
          ].some((n) => source.endsWith(n))
        )
          target = fixtures.empty;
        else if (source.endsWith("TrackerRefreshStatus.vue"))
          target = pathToFileURL(resolve(renderDirectory, "status.mjs")).href;
        else if (source.endsWith("BitcraftTierBadge.vue"))
          target = pathToFileURL(
            resolve(renderDirectory, "tier-badge.mjs"),
          ).href;
        else if (source === "/src/bitcraft-ui/bitjitaAssets.js")
          target = pathToFileURL(
            resolve("src/bitcraft-ui/bitjitaAssets.js"),
          ).href;
        else
          target = pathToFileURL(
            resolve("src/bitcraft-ui", source + ".js"),
          ).href;
        return "from " + JSON.stringify(target);
      },
    );
    const path = resolve(renderDirectory, name + ".mjs");
    await writeFile(path, code);
    return (await import(pathToFileURL(path).href + "?v=" + Date.now()))
      .default;
  }
  await compile(
    "src/bitcraft-ui/Components/TrackerRefreshStatus.vue",
    "status",
  );
  await compile(
    "src/bitcraft-ui/Components/BitcraftTierBadge.vue",
    "tier-badge",
  );
  const Activity = await compile("src/bitcraft-ui/Activity.vue", "activity");
  const progressCases = [
    { name: "ordinary progress", xp: 1500, targetXp: 2000, percent: 75 },
    {
      name: "5515 XP remaining",
      xp: 1994485,
      targetXp: 2000000,
      percent: 99.7,
    },
    { name: "1 XP remaining", xp: 1999999, targetXp: 2000000, percent: 99.9 },
    { name: "goal reached", xp: 2000000, targetXp: 2000000, percent: 100 },
    { name: "goal exceeded", xp: 2000001, targetXp: 2000000, percent: 100 },
  ];
  for (const { mode, name, xp, targetXp, percent, reconnecting = false } of [
    ...["full", "popout", "obs"].flatMap((mode) =>
      progressCases.map((scenario) => ({ mode, ...scenario })),
    ),
    { mode: "full", ...progressCases[0], reconnecting: true },
  ]) {
    const connection = await import(fixtures.bitcraft);
    connection.bitcraftConnected.value = !reconnecting;
    connection.bitcraftReconnecting.value = reconnecting;
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
                  stamina: { current: 40, max: 100 },
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
    assert(html.includes("Stamina 40 / 100"));
    assert(html.includes(reconnecting ? "Last known activity" : "Signed in"));
    if (reconnecting) assert(html.includes("Refresh delayed"));
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
    console.log(
      `XP ${mode}: ${name}${reconnecting ? " during reconnect" : ""} renders ${percent}% successfully.`,
    );
  }
  const connection = await import(fixtures.bitcraft);
  connection.bitcraftConnected.value = false;
  const item = {
    key: "item:1",
    name: "Fixture Planks",
    quantity: 40,
    need: 100,
    remaining: 60,
    sources: [],
    tier: 2,
  };
  for (const [name, tracker, text] of [
    [
      "InventoryTracker",
      { player: { entityId: "20", username: "Icha" }, items: [item] },
      "40 / 100",
    ],
    [
      "PassiveCraftTracker",
      {
        player: { entityId: "20", username: "Icha" },
        crafts: [],
        groups: [
          {
            key: "craft",
            name: "Fixture Planks",
            output: item,
            totalOutputQuantity: 5,
            estimatedRemainingSeconds: 120,
            timerSource: "recipe",
            outputs: [],
          },
        ],
      },
      "2m 0s left",
    ],
  ]) {
    const component = await compile(`src/bitcraft-ui/${name}.vue`, name);
    const html = await renderToString(
      createSSRApp({
        render: () =>
          h(component, {
            filters: { setup: false, character: "Icha", widgetEditable: false },
            snapshot: {
              tracker,
              sampledAt: "2026-10-16T18:00:00Z",
              refresh: {},
            },
            snapshotUrl: "/fixture-snapshot",
          }),
      }),
    );
    assert(html.includes("Refresh delayed."), `${name}: disconnected status`);
    assert(html.includes("Fixture Planks"), `${name}: last known item/craft`);
    assert(html.includes(text), `${name}: last known quantity/timer`);
    assert(
      html.includes('datetime="2026-10-16T18:00:00Z"'),
      `${name}: observation timestamp`,
    );
    console.log(
      `${name}: retained data and delayed status render successfully.`,
    );
  }
} finally {
  await rm(renderDirectory, { recursive: true, force: true });
}
