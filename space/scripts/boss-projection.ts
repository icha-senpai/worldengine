import { BOSSES, type BossDefeat } from "../bitcraft/src/bosses";

export type BossMutation = {
  table: string;
  kind: "insert" | "delete";
  row: Record<string, any>;
};
const id = (value: unknown) => String(value);
export function bossType(row: Record<string, any>): number {
  const wire = row.enemy_type?.tag;
  return Number(
    Object.keys(BOSSES).find((key) => BOSSES[Number(key)].wire === wire) ?? 0,
  );
}

// This cache contains only bosses and their CURRENT combat contributions.
// Each call is one complete upstream transaction, including event-table rows.
export class BossProjection {
  private bosses = new Map<string, number>();
  private contributions = new Map<string, Map<string, number>>();
  private dead = new Map<string, string>();
  constructor(private name: (id: string) => string) {}
  reset() {
    this.bosses.clear();
    this.contributions.clear();
    this.dead.clear();
  }
  baseline(
    enemies: Iterable<any>,
    contributions: Iterable<any>,
    health: Iterable<any>,
  ) {
    this.reset();
    for (const row of enemies) {
      const type = bossType(row);
      if (type) this.bosses.set(id(row.entity_id), type);
    }
    for (const row of contributions) this.addContribution(row);
    // Bodies already present on reconnect are not newly observed defeats.
    for (const row of health)
      if (this.bosses.has(id(row.entity_id)))
        this.dead.set(id(row.entity_id), id(row.died_timestamp));
  }
  private addContribution(row: any) {
    const enemy = id(row.enemy_entity_id);
    if (!this.bosses.has(enemy)) return;
    const players = this.contributions.get(enemy) ?? new Map();
    players.set(id(row.player_entity_id), Number(row.contribution));
    this.contributions.set(enemy, players);
  }
  apply(changes: BossMutation[]): BossDefeat[] {
    // Preserve the previous type for deaths/deletions in the same transaction.
    const types = new Map(this.bosses);
    for (const c of changes)
      if (c.table === "enemy_state" && c.kind === "insert") {
        const type = bossType(c.row);
        if (type) {
          types.set(id(c.row.entity_id), type);
          this.bosses.set(id(c.row.entity_id), type);
        }
      }
    // Inserts are the final cumulative values, rather than extra damage to add.
    const inserts = new Set(
      changes
        .filter((c) => c.table === "contribution_state" && c.kind === "insert")
        .map((c) => `${c.row.enemy_entity_id}:${c.row.player_entity_id}`),
    );
    for (const c of changes)
      if (c.table === "contribution_state") {
        if (c.kind === "insert") this.addContribution(c.row);
        else if (
          !inserts.has(`${c.row.enemy_entity_id}:${c.row.player_entity_id}`)
        ) {
          this.contributions
            .get(id(c.row.enemy_entity_id))
            ?.delete(id(c.row.player_entity_id));
        }
      }
    const defeats: BossDefeat[] = [];
    for (const c of changes) {
      if (c.table !== "health_state" || c.kind !== "insert") continue;
      const enemy = id(c.row.entity_id),
        type = types.get(enemy),
        at = id(c.row.died_timestamp);
      if (
        !type ||
        Number(c.row.health) !== 0 ||
        Number(at) <= 0 ||
        this.dead.get(enemy) === at
      )
        continue;
      this.dead.set(enemy, at);
      const participants = [
        ...(this.contributions.get(enemy) ?? new Map()).entries(),
      ]
        .filter(([, value]) => Number.isFinite(value) && value > 0)
        .map(([playerId, value]) => ({
          id: playerId,
          name: this.name(playerId) || playerId,
          contribution: value,
        }));
      // Never infer a final blow from an earlier hit, a top contributor, or a
      // reducer caller. The fatal attack must be in this death transaction.
      const attacks = changes.filter(
        (x) =>
          x.table === "attack_event" &&
          x.kind === "insert" &&
          id(x.row.defender_entity_id) === enemy &&
          x.row.damage > 0 &&
          !x.row.is_dodge,
      );
      const last = attacks.at(-1)?.row,
        attacker = last ? id(last.attacker_entity_id) : "";
      const killerId = participants.some((p) => p.id === attacker)
        ? attacker
        : "";
      const defeat = {
        enemyId: enemy,
        enemyType: type,
        diedAt: at,
        participants,
        killerId,
      };
      defeats.push(defeat);
    }
    for (const c of changes)
      if (c.table === "enemy_state" && c.kind === "delete") {
        const enemy = id(c.row.entity_id);
        if (
          !changes.some(
            (x) =>
              x.table === "enemy_state" &&
              x.kind === "insert" &&
              id(x.row.entity_id) === enemy,
          )
        ) {
          this.bosses.delete(enemy);
          this.contributions.delete(enemy);
          this.dead.delete(enemy);
        }
      }
    return defeats;
  }
}
