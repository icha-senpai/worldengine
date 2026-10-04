import type { DbConnection } from "../bindings/evergather";
import type { Catalog } from "../bindings/evergather/types";

export const definitionTables = [
  "coreDefinitions",
  "gatheringDefinitions",
  "activityDefinitions",
  "recipeDefinitions",
  "equipmentDefinitions",
  "jobDefinitions",
  "expeditionDefinitions",
  "shopDefinitions",
  "achievementDefinitions",
  "inventoryGuideDefinitions",
  "myReferenceCatalog",
] as const;

const panels: Record<string, string[]> = {
  "overview:progression": ["achievement_definitions"],
  "gather:actions": ["gathering_definitions"],
  "gather:activities": ["activity_definitions"],
  "craft:equipment": ["equipment_definitions"],
  "craft:recipes": ["recipe_definitions"],
  "craft:jobs": ["job_definitions"],
  "craft:expeditions": ["expedition_definitions"],
  "trade:shop": ["shop_definitions"],
  "trade:inventory": ["inventory_guide_definitions"],
  "progress:skills": ["gathering_definitions", "activity_definitions"],
};
export function panelDefinitionQueries(workspace: string, panel: string) {
  return (panels[`${workspace}:${panel}`] ?? []).map(
    (name) => `SELECT * FROM ${name}`,
  );
}

export function definitionRowsFor(connection: DbConnection | null) {
  const rows = new Map<string, Catalog>();
  if (connection)
    for (const name of definitionTables)
      for (const row of connection.db[name].iter()) rows.set(row.key, row);
  return [...rows.values()];
}
