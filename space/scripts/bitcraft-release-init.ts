import { execFileSync } from "node:child_process";
import { readdir, writeFile, rename } from "node:fs/promises";
import { resolve } from "node:path";
import publishedContent from "../bitcraft/src/content/guides.json";
import {
  ownerCall,
  replicaIdentity,
  registerBinding,
  canonicalState,
} from "./bitcraft-storage";
import {
  spacetimeCli,
  spacetimeServer,
  spacetimeData,
  spacetimeConfig,
} from "./spacetime-config";
import {
  releaseAppState,
  refreshReleaseGuides,
} from "./bitcraft-release-state";
import { parseRelayJson } from "./bitcraft-relay";

// Provision only: the caller stops the managed collector and activates its new
// target after verifying the result. This never publishes to or writes the old DB.
const source = "space-bitcraft-tools",
  target = "space-bitcraft-release";
const resume = process.argv.includes("--resume");
const present =
  (await fetch(`${spacetimeServer}/v1/database/${target}`)).status !== 404;
if (present && !resume)
  throw Error("Release database already exists; refusing to overwrite it.");
const before = parseRelayJson(await ownerCall(source, "export_app_state", []));
let state = releaseAppState(before);
const baseline = process.argv
  .find((value) => value.startsWith("--guides-baseline="))
  ?.split("=")[1];
if (baseline) {
  if (!/^[a-f0-9]{7,40}$/.test(baseline))
    throw Error("Choose a committed guide baseline.");
  const previous = JSON.parse(
    execFileSync(
      "git",
      ["show", `${baseline}:space/bitcraft/src/content/guides.json`],
      { encoding: "utf8" },
    ),
  );
  state = refreshReleaseGuides(state, previous, publishedContent);
}
const replicas = resolve(spacetimeData, "replicas");
const existing = new Set(await readdir(replicas));
if (!present)
  execFileSync(
    spacetimeCli,
    [
      "--config-path",
      spacetimeConfig,
      "publish",
      target,
      "--module-path",
      "bitcraft",
      "--delete-data=never",
      "--server",
      spacetimeServer,
      "--yes",
      "--no-config",
    ],
    { stdio: "inherit", windowsHide: true },
  );
if (present) {
  const current = parseRelayJson(
    await ownerCall(target, "export_app_state", []),
  );
  const seeded = publishedContent.map((g) => ({
    id: String(g.id),
    title: g.title,
    summary: g.summary ?? "",
    category: g.category ?? "General",
    content: JSON.stringify(g.content),
    published: true,
  }));
  if (
    canonicalState(current) !== canonicalState(state) &&
    ([
      "widget_profile",
      "guide_administrator",
      "provider_settings",
      "collection_sample",
      "collection_watch",
      "collection_feed",
    ].some((k) => current[k].length) ||
      canonicalState({ guide: current.guide }) !==
        canonicalState({ guide: seeded }))
  )
    throw Error(
      "Existing release database contains user data; refusing to replace it.",
    );
}
await ownerCall(target, "storage_lease", [0]);
await ownerCall(target, "restore_app_state", [JSON.stringify(state)]);
await ownerCall(target, "storage_lease", [300]);
await ownerCall(target, "prepare_claim_revisions", []);
const restored = parseRelayJson(
  await ownerCall(target, "export_app_state", []),
);
if (canonicalState(restored) !== canonicalState(state))
  throw Error("Release application-state verification failed.");
if (
  canonicalState(
    parseRelayJson(await ownerCall(source, "export_app_state", [])),
  ) !== canonicalState(before)
)
  throw Error("Existing-world application state changed during provisioning.");
const info = (await fetch(`${spacetimeServer}/v1/database/${target}`).then(
  (r) => r.json(),
)) as any;
const identity = info.database_identity.__identity__.replace(/^0x/, "");
const matches = [];
for (const replica of (await readdir(replicas)).filter(
  (r) => present || !existing.has(r),
)) {
  if ((await replicaIdentity(replica)) === identity) matches.push(replica);
}
if (matches.length !== 1)
  throw Error("Release replica could not be identified unambiguously.");
await registerBinding(target, matches[0]!);
await ownerCall(target, "storage_lease", [0]);
const binding = resolve(".runtime/bitcraft-release-ready.json");
await writeFile(
  binding + ".tmp",
  JSON.stringify({ database: target, identity, source, world: "release" }) +
    "\n",
);
await rename(binding + ".tmp", binding);
console.log(
  "Release database provisioned; application ownership, guides, widgets and configuration verified. Existing-world data untouched.",
);
