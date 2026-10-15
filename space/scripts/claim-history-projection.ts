import {
  claimChanges,
  isTrackedClaim,
  type ClaimState,
  type HistoryChange,
} from "../bitcraft/src/claim-history";
import type { RelayTables, RelayChange } from "./bitcraft-relay";
import {
  calculateClaimUpkeep,
  type ClaimSupply,
} from "../bitcraft/src/claim-supplies";

export const CLAIM_TABLES = [
  "claim_state",
  "claim_local_state",
  "claim_member_state",
  "claim_tech_state",
  "claim_tech_desc",
  "player_username_state",
  "building_state",
  "building_desc",
  "claim_tile_cost",
  "parameters_desc",
];

export const CLAIM_STRUCTURE_IDS = {
  waystone: 205715693,
  banks: [985246037, 1615467546],
};
export function claimHistoryQueries() {
  const ids = [CLAIM_STRUCTURE_IDS.waystone, ...CLAIM_STRUCTURE_IDS.banks];
  return CLAIM_TABLES.map((table) => {
    if (table === "parameters_desc")
      return "SELECT * FROM parameters_desc WHERE version = 0";
    if (table === "building_state" || table === "building_desc") {
      const field =
        table === "building_state" ? "building_description_id" : "id";
      return `SELECT * FROM ${table} WHERE ${ids.map((id) => `${field} = ${id}`).join(" OR ")}`;
    }
    return `SELECT * FROM ${table}`;
  });
}

// Called after an entire upstream transaction has been applied, so an owner
// joining in that transaction cannot rewrite the preceding membership evidence.
export class ClaimHistoryProjection {
  private states = new Map<string, ClaimState>();
  private members = new Map<string, Map<string, any>>();
  private memberClaims = new Map<string, string>();
  private buildings = new Map<string, Map<string, any>>();
  private buildingClaims = new Map<string, string>();
  supplies(
    tables: RelayTables,
    initial: boolean,
    changes: RelayChange[],
  ): Map<string, ClaimSupply | null> {
    const ids = new Set<string>();
    if (
      initial ||
      changes.some((c) =>
        ["claim_tile_cost", "parameters_desc", "claim_tech_desc"].includes(
          c.table,
        ),
      )
    )
      for (const id of this.states.keys()) ids.add(id);
    else
      for (const change of changes) {
        if (
          ["claim_state", "claim_local_state", "claim_tech_state"].includes(
            change.table,
          )
        )
          ids.add(String(change.row.entity_id));
        if (["building_state", "claim_member_state"].includes(change.table))
          ids.add(String(change.row.claim_entity_id));
      }
    const parameters = tables.get("parameters_desc")?.get("0"),
      tileCosts = [...(tables.get("claim_tile_cost")?.values() ?? [])];
    const result = new Map<string, ClaimSupply | null>();
    for (const id of ids) {
      const state = this.states.get(id),
        local = tables.get("claim_local_state")?.get(id);
      result.set(
        id,
        state && isTrackedClaim(state) && local
          ? {
              claimId: id,
              supplies: Number(local.supplies),
              upkeepPerHour: calculateClaimUpkeep(local, tileCosts, parameters),
              tileCount: Number(local.num_tiles),
              buildingMaintenance: Number(local.building_maintenance),
              treasury: String(local.treasury),
              tickMillis: parameters
                ? Number(parameters.building_decay_tick_millis)
                : null,
            }
          : null,
      );
    }
    return result;
  }
  reset() {
    this.states.clear();
    this.members.clear();
    this.memberClaims.clear();
    this.buildings.clear();
    this.buildingClaims.clear();
  }
  private building(row: any) {
    const id = String(row.entity_id),
      claimId = String(row.claim_entity_id);
    this.buildingClaims.set(id, claimId);
    if (!this.buildings.has(claimId)) this.buildings.set(claimId, new Map());
    this.buildings.get(claimId)!.set(id, row);
  }
  private member(row: any) {
    const id = String(row.entity_id),
      claimId = String(row.claim_entity_id);
    const old = this.memberClaims.get(id);
    if (old) this.members.get(old)?.delete(id);
    this.memberClaims.set(id, claimId);
    if (!this.members.has(claimId)) this.members.set(claimId, new Map());
    this.members.get(claimId)!.set(id, row);
  }
  private state(tables: RelayTables, id: string): ClaimState | null {
    const claim = tables.get("claim_state")?.get(id);
    if (!claim || claim.neutral || String(claim.owner_player_entity_id) === "0")
      return null;
    const local = tables.get("claim_local_state")?.get(id),
      location = local?.location?.some;
    const tech = tables.get("claim_tech_state")?.get(id);
    const tiers = (tech?.learned ?? []).map((id: any) =>
      Number(tables.get("claim_tech_desc")?.get(String(id))?.tier ?? 0),
    );
    const names = tables.get("player_username_state");
    const buildings = [...(this.buildings.get(id)?.values() ?? [])];
    return {
      id,
      name: String(claim.name).slice(0, 160),
      ownerId: String(claim.owner_player_entity_id),
      ownerName: String(
        names?.get(String(claim.owner_player_entity_id))?.username ?? "Unknown",
      ).slice(0, 160),
      totemId: String(claim.owner_building_entity_id),
      supplies: local ? Number(local.supplies) : null,
      tier: tech ? Math.max(1, ...tiers) : null,
      x: location?.x ?? null,
      z: location?.z ?? null,
      waystones: buildings.filter(
        (b) =>
          Number(b.building_description_id) === CLAIM_STRUCTURE_IDS.waystone,
      ).length,
      banks: buildings.filter((b) =>
        CLAIM_STRUCTURE_IDS.banks.includes(Number(b.building_description_id)),
      ).length,
      members: [...(this.members.get(id)?.values() ?? [])]
        .map((m) => ({
          id: String(m.player_entity_id),
          name: String(
            names?.get(String(m.player_entity_id))?.username ??
              m.user_name ??
              "Unknown",
          ).slice(0, 160),
          permissions: [
            m.inventory_permission,
            m.build_permission,
            m.officer_permission,
            m.co_owner_permission,
          ]
            .map(Boolean)
            .map(Number)
            .join(""),
        }))
        .sort((a, b) => a.id.localeCompare(b.id)),
    };
  }
  apply(
    initial: boolean,
    tables: RelayTables,
    changes: RelayChange[],
  ): HistoryChange[] {
    if (initial) {
      this.reset();
      for (const m of tables.get("claim_member_state")!.values())
        this.member(m);
      for (const b of tables.get("building_state")!.values()) this.building(b);
      const descriptions = tables.get("building_desc")!;
      for (const [id, name] of [
        [205715693, "Waystone"],
        [985246037, "Town Bank"],
        [1615467546, "Ancient Bank"],
      ] as const) {
        if (descriptions.get(String(id))?.name !== name)
          throw Error(
            "Claim structure definitions changed; review the tracking rule.",
          );
      }
      for (const id of tables.get("claim_state")!.keys()) {
        const state = this.state(tables, id);
        if (state) this.states.set(id, state);
      }
      return [...this.states.values()].map((after) => ({
        before: null,
        // Lower-tier discovery metadata lets the server distinguish a tier
        // downgrade from a missing claim on reconnect. It is never retained.
        after: isTrackedClaim(after) ? after : { ...after, members: [] },
      }));
    }
    const affected = new Set<string>(),
      memberIds = new Set<string>(),
      buildingIds = new Set<string>();
    for (const change of changes) {
      if (change.table === "building_state") {
        affected.add(String(change.row.claim_entity_id));
        buildingIds.add(String(change.row.entity_id));
      } else if (change.table === "claim_member_state") {
        affected.add(String(change.row.claim_entity_id));
        memberIds.add(String(change.row.entity_id));
      } else if (
        ["claim_state", "claim_local_state", "claim_tech_state"].includes(
          change.table,
        )
      )
        affected.add(String(change.row.entity_id));
      else if (
        ["player_username_state", "claim_tech_desc", "building_desc"].includes(
          change.table,
        )
      )
        for (const id of this.states.keys()) affected.add(id);
    }
    for (const id of buildingIds) {
      const old = this.buildingClaims.get(id);
      if (old) {
        affected.add(old);
        this.buildings.get(old)?.delete(id);
        this.buildingClaims.delete(id);
      }
      const current = tables.get("building_state")!.get(id);
      if (current) this.building(current);
    }
    for (const id of memberIds) {
      const old = this.memberClaims.get(id);
      if (old) {
        affected.add(old);
        this.members.get(old)?.delete(id);
        this.memberClaims.delete(id);
      }
      const current = tables.get("claim_member_state")!.get(id);
      if (current) this.member(current);
    }
    const result: HistoryChange[] = [];
    for (const id of affected) {
      const before = this.states.get(id) ?? null,
        after = this.state(tables, id);
      if (claimChanges(before, after).length) result.push({ before, after });
      if (after) this.states.set(id, after);
      else this.states.delete(id);
    }
    return result;
  }
}
