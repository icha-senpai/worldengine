// Shared progress and small catalogs stay live across tabs. Large collections
// and public directories follow the active tab's lifetime.
export const coreQueries = [
  'my_player', 'my_profile', 'my_collection', 'my_link_challenge', 'my_action',
  'my_shop_quote', 'my_upgrade_quote', 'my_rods', 'my_licences', 'my_baits',
  'my_bait_loadout', 'my_items', 'species_definition', 'game_config',
  'biome_definition', 'rod_definition', 'rod_bonuses', 'rarity_definition',
  'bait_definition', 'quality_definition', 'shop_listing',
  'haul_equipment_definition', 'my_haul_equipment', 'my_haul_loadout', 'my_haul_upgrade_quote', 'my_haul_trips',
].map(table => `SELECT * FROM ${table}`);

export const tideforgeProbeQueries = ['SELECT * FROM tideforge_economy_state'];
export const gearNameQueries = ['SELECT * FROM my_gear_instance_names'];
export const tideforgeCoreQueries = [
  'my_gear_instances', 'my_gear_loadout', 'my_gear_family_progress', 'my_tideforge_gear',
  'my_tideforge_loadout', 'my_tideforge_economy_wallet', 'my_tideforge_economy_quote',
  'my_tideforge_economy_receipts',
].map(table => `SELECT * FROM ${table}`);
export const tideforgeViewQueries = [
  'my_tideforge_vault', 'my_tideforge_codex', 'my_tideforge_library',
  'my_tideforge_specimen_modifiers', 'my_tideforge_fractional_credits', 'my_gear_activity_snapshots',
].map(table => `SELECT * FROM ${table}`);

export function viewQueries(view: string, playerId?: bigint, version = 0): string[] {
  const tables: Record<string, string[]> = {
    camp: ['my_recent_catches'],
    quests: ['quest_definition', 'quest_calendar', 'my_quests', 'my_dockside', 'my_inventory', 'achievement_definition'],
    inventory: ['my_inventory'], tideforge: ['my_inventory'], journal: ['my_journal'],
    collection: ['my_collection_focus'], compendium: ['my_collection_focus'],
    trader: version >= 9 ? ['rod_upgrade_recipe'] : [],
    records: ['species_record', 'angler_standing', 'angler_statistics', 'legendary_find'],
    anglers: ['public_profile', 'angler_portrait', 'angler_standing', 'angler_statistics', 'species_record',
      'achievement_definition', 'earned_achievement', 'perfect_species', 'rank_maximum', 'angler_title'],
    achievements: ['achievement_definition', 'achievement_collection', 'my_achievement_progress'],
  };
  const queries = (tables[view] ?? []).map(table => `SELECT * FROM ${table}`);
  if (playerId !== undefined && ['collection', 'compendium', 'achievements'].includes(view)) {
    for (const table of ['earned_achievement', 'perfect_species', 'rank_maximum', 'angler_title'])
      queries.push(`SELECT * FROM ${table} WHERE player_id = ${playerId}`);
  }
  if (version >= 17) {
    queries.push(
      'SELECT * FROM raid_grade_definition',
      'SELECT * FROM community_raid_event',
      'SELECT * FROM my_raid_attempts',
      'SELECT * FROM my_raid_actions',
      'SELECT * FROM my_rod_reinforcements',
    );
    if (playerId !== undefined) queries.push(`SELECT * FROM raid_badge WHERE player_id = ${playerId}`);
    if (['anglers', 'records'].includes(view)) queries.push('SELECT * FROM raid_statistics');
    else if (playerId !== undefined) queries.push(`SELECT * FROM raid_statistics WHERE player_id = ${playerId}`);
  }
  if (view === 'inventory' && version >= 9) queries.push('SELECT * FROM rod_upgrade_recipe');
  return queries;
}
