// Exercise the managed app without a browser: establish a normal XP watch,
// disconnect the reader, and recover the rate from independently collected history.
import assert from "node:assert/strict";
import { DbConnection } from "../src/bindings/bitcraft";
import {
  activitySkillRate,
  collectedActivitySamples,
  restoreActivitySamples,
} from "../src/bitcraft-ui/activitySamples";
import {
  restoreXpSession,
  updateXpSession,
} from "../src/bitcraft-ui/xpSession";

const character = process.argv[2] || "Icha";
const sleep = (ms: number) => new Promise((resolve) => setTimeout(resolve, ms));
const connect = () =>
  new Promise<DbConnection>((resolve, reject) =>
    DbConnection.builder()
      .withUri("ws://127.0.0.1:3100")
      .withDatabaseName("space-bitcraft-tools")
      .onConnect((conn) => resolve(conn))
      .onConnectError((_ctx, error) => reject(error))
      .build(),
  );
let conn = await connect();
try {
  const directory = await conn.procedures.requestData({
    resource: "relayPlayers",
    id: "",
    query: character,
    page: 1,
    options: "{}",
  });
  const player = JSON.parse(directory.payload).find(
    (row: any) => row.username.toLowerCase() === character.toLowerCase(),
  );
  assert(player, "The chosen player must exist.");
  const playerId = String(player.entity_id);
  const request = {
    resource: "relaySkills",
    id: playerId,
    query: "",
    page: 1,
    options: "{}",
  };
  let response = await conn.procedures.requestData(request);
  const readyBy = Date.now() + 55000;
  while (response.error || !response.key.includes("-native-")) {
    assert(Date.now() < readyBy, "The native XP collector must become ready.");
    await sleep(1000);
    response = await conn.procedures.requestData(request);
  }
  const first = {
    at: Number(response.updatedAt / 1000n),
    playerId,
    sourceKey: response.key,
    xpBySkill: Object.fromEntries(
      JSON.parse(response.payload).skills.map((row: any) => [
        String(row.skill_id),
        row.xp,
      ]),
    ),
  };
  const baseline = updateXpSession(null, { ...first, xp: first.xpBySkill });
  conn.disconnect();
  console.log(
    `Disconnected ${character}'s reader. Checking independent collection after 75 seconds.`,
  );
  for (let second = 0; second < 75; second += 15) await sleep(15000);
  conn = await connect();
  // This history read does not renew the watch or fetch a new provider snapshot.
  const stored = JSON.parse(
    await conn.procedures.collectionHistory({ playerId }),
  );
  assert(
    stored.fresh,
    "The collector must stay fresh without reader requests.",
  );
  const history = collectedActivitySamples(stored, playerId, first.sourceKey);
  const current = history.at(-1);
  assert(
    current && current.at - first.at >= 60000,
    "XP observations must advance while disconnected.",
  );
  const restored = restoreActivitySamples([first], history, current);
  const skillId = Object.keys(first.xpBySkill).find(
    (id) => current.xpBySkill[id] != null,
  );
  assert(skillId, "A continuous known skill must be available.");
  const rate = activitySkillRate(restored, skillId);
  assert(
    rate.minutesSampled >= 1,
    "Returning must restore the rate without another sampling minute.",
  );
  const session = restoreXpSession(
    baseline,
    { ...current, xp: current.xpBySkill },
    history,
  );
  assert(session.gained >= 0);
  console.log(
    JSON.stringify({
      character,
      collectorFresh: stored.fresh,
      observationAdvanceSeconds: (current.at - first.at) / 1000,
      minutesSampled: rate.minutesSampled,
      sessionXp: session.gained,
    }),
  );
} finally {
  conn.disconnect();
}
