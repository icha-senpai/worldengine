import { describe, it, expect } from "vitest";
import { BossProjection, type BossMutation } from "../scripts/boss-projection";
const boss = (entity_id = 100n, tag = "sentinel") => ({
  entity_id,
  enemy_type: { tag },
});
const credit = (
  player_entity_id = 10n,
  contribution = 50,
  enemy_entity_id = 100n,
) => ({
  entity_id: player_entity_id,
  player_entity_id,
  contribution,
  enemy_entity_id,
});
const dead = (died_timestamp = 1800000000, entity_id = 100n) => ({
  entity_id,
  health: 0,
  died_timestamp,
});
const change = (
  table: string,
  row: any,
  kind: "insert" | "delete" = "insert",
): BossMutation => ({ table, row, kind });
function setup() {
  const projection = new BossProjection((id) =>
    id === "10" ? "Krutch" : "Other player",
  );
  projection.baseline(
    [boss(), boss(101n, "sentinelDungeonJakyl")],
    [credit()],
    [],
  );
  return projection;
}
describe("confirmed boss death projection", () => {
  it.each([
    "distractedSagiBird",
    "distractedNubiGoat",
    "distractedCervus",
    "distractedScrofa",
    "distractedOx",
    "distractedYagiGoat",
    "distractedJungleTerrorBird",
    "distractedElderScrofa",
    "distractedTundraOx",
    "distractedDesertTerrorbird",
  ])("ignores %s while recording a boss in the same transaction", (tag) => {
    const p = setup();
    const defeats = p.apply([
      change("enemy_state", boss(200n, tag)),
      change("contribution_state", credit(10n, 100, 200n)),
      change("health_state", dead(1800000001, 200n)),
      change("health_state", dead()),
    ]);
    expect(defeats).toHaveLength(1);
    expect(defeats[0].enemyType).toBe(32);
    expect(defeats[0].enemyId).toBe("100");
  });
  it("captures cumulative contributors and a final attack in the death transaction, excluding trash", () => {
    const p = setup();
    const result = p.apply([
      change("health_state", dead()),
      change("attack_event", {
        attacker_entity_id: 10n,
        defender_entity_id: 100n,
        damage: 100,
        is_dodge: false,
      }),
      change("contribution_state", credit(), "delete"),
      change("contribution_state", credit(10n, 75)),
      change("contribution_state", credit(20n, 25)),
      change("health_state", dead(1800000000, 101n)),
    ]);
    expect(result).toEqual([
      {
        enemyId: "100",
        enemyType: 32,
        diedAt: "1800000000",
        killerId: "10",
        participants: [
          { id: "10", name: "Krutch", contribution: 75 },
          { id: "20", name: "Other player", contribution: 25 },
        ],
      },
    ]);
    expect(p.apply([change("health_state", dead())])).toEqual([]);
  });
  it("does not infer kills from resets, despawns, an old attack or an initial corpse", () => {
    const p = setup();
    expect(
      p.apply([
        change("attack_event", {
          attacker_entity_id: 10n,
          defender_entity_id: 100n,
          damage: 100,
        }),
      ]),
    ).toEqual([]);
    expect(p.apply([change("health_state", dead())])[0].killerId).toBe("");
    p.baseline([boss()], [credit()], [dead()]);
    expect(p.apply([change("health_state", dead())])).toEqual([]);
    expect(p.apply([change("contribution_state", credit(), "delete")])).toEqual(
      [],
    );
    expect(p.apply([change("enemy_state", boss(), "delete")])).toEqual([]);
  });
  it("excludes contribution from an earlier reset and supports T7 and new boss instances", () => {
    const p = setup();
    p.apply([change("contribution_state", credit(), "delete")]);
    const result = p.apply([
      change("enemy_state", boss(200n, "spiderDungeonSpiderNest")),
      change("contribution_state", credit(20n, 40, 200n)),
      change("health_state", dead(1800000001, 200n)),
    ]);
    expect(result[0].enemyType).toBe(40);
    expect(result[0].participants.map((p) => p.id)).toEqual(["20"]);
    expect(p.apply([change("health_state", dead())])[0].participants).toEqual(
      [],
    );
  });
});
