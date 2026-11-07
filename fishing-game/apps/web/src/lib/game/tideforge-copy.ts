const actions: Record<string, string> = {
  camp_copy: 'Buy gear',
  wandering_gamble: 'Try the wandering trader',
  add_affix: 'Add an affix',
  change_affix: 'Change an affix',
  upgrade_affix: 'Upgrade an affix',
  refine_affix: 'Improve affix strength',
  dismantle: 'Dismantle gear',
  extract_signature: 'Extract a signature',
  fillet: 'Fillet fish',
  convert_normal: 'Make F-grade materials',
  refine_material: 'Refine materials',
};

const materials: Record<string, string> = {
  normal_fillets: 'Basic fillets',
  normal_scales: 'Basic scales',
};
for (const grade of ['F', 'D', 'C', 'B', 'A', 'S', 'SS', 'SSS', 'UR', 'UUR']) {
  for (const material of ['fillets', 'scales']) {
    materials[`raid_${material}_${grade.toLowerCase()}`] = `${grade}-grade ${material}`;
  }
}

export const masteryHelp = 'Matching gear shares mastery. Quality and reinforcement stay with each item. Slots unlock at levels 1, 10, 25 and 50.';
export const strengthHelp = 'Strength shows how powerful an affix is within its tier, not your chance of a perfect catch.';
export const tideforgeUnconfirmed = 'We have not confirmed the last action yet. Check again safely; you will not pay twice.';
export const tideforgeConfirmed = 'Action confirmed. Updating your gear and materials…';

export function tideforgeActionLabel(action: string): string {
  return Object.hasOwn(actions, action) ? actions[action] : 'Review action';
}

export function tideforgeMaterialLabel(material: string | undefined): string {
  return material && Object.hasOwn(materials, material) ? materials[material] : 'Unknown material';
}

export function processingProtectionLabel(protection: string): string {
  switch (protection) {
    case 'ordinary': return 'Ready to fillet';
    case 'favorite': return 'Favorite';
    case 'bonus': return 'Bonus fish: protected';
    case 'trophy': return 'Rank maximum or perfect catch';
    default: return 'Protected: fish details unavailable';
  }
}

export function tideforgeValidationMessage(message: string): string {
  const known: Record<string, string> = {
    'Choose a water.': 'Choose a water.',
    'Choose a secondary water only for Secondary Affinity.': 'Choose a secondary water only for Secondary Affinity.',
    'Choose 1–1000 batches.': 'Choose 1–1000 batches.',
    'Choose a higher destination grade.': 'Choose a higher destination grade.',
    'Choose up to 50 different fish.': 'Choose up to 50 different fish.',
    'Invalid affix slot.': 'Choose an available affix slot.',
    'Invalid affix tier.': 'Choose an available affix tier.',
    'Invalid affix.': 'Choose an available affix.',
    'Invalid material.': 'Choose an available material.',
  };
  return Object.hasOwn(known, message) ? known[message] : 'Review your selection and try again.';
}

export function tideforgeErrorMessage(message: string): string {
  if (message.includes('UnknownCallResultError')) return 'The connection dropped before confirmation. Reconnect and review the saved state.';
  if (message.includes('TIDEFORGE_WANDERING_CLOSED')) return 'The Wandering trader is away. See its monthly schedule.';
  if (message.includes('TIDEFORGE_MARKS_REQUIRED')) return 'You need more Dredge Marks for this action.';
  if (message.includes('INSUFFICIENT_COINS')) return 'You need more coins for this action.';
  if (message.includes('INSUFFICIENT_MATERIALS') || message.includes('RAID_MATERIALS_REQUIRED') || message.includes('TIDEFORGE_SHARD')) return 'You need the required materials and shards.';
  if (message.includes('RAID_GRADE_NOT_CLEARED')) return 'Clear the required raid grade first.';
  if (message.includes('ACTION_EXPIRED')) return 'This preview expired. Open a new preview.';
  if (message.includes('QUOTE_CHANGED')) return 'This preview changed. Open a new preview.';
  if (message.includes('ACTION_RATE_LIMITED')) return 'Give the previous action a moment before trying again.';
  if (message.includes('GEAR_IN_USE') || message.includes('GEAR_LOCKED') || message.includes('RAID_EQUIPMENT_LOCKED')) return 'This gear is being used on a fishing trip or raid. Wait until it finishes.';
  if (message.includes('TIDEFORGE_GEAR_PROTECTED')) return 'This gear is protected and cannot be used for this action.';
  if (message.includes('GEAR_INSTANCE_UNAVAILABLE')) return 'This gear is no longer available. Review your selection.';
  if (message.includes('TIDEFORGE_AFFIX_NOT_DISCOVERED')) return 'Find this affix on newly acquired gear before using it in the Forge.';
  if (message.includes('TIDEFORGE_AFFIX_NO_SLOT') || message.includes('TIDEFORGE_AFFIX_SLOT_LOCKED')) return 'Unlock an available slot through mastery first.';
  if (message.includes('TIDEFORGE_AFFIX_NO_CHANGE')) return 'Choose a different affix or secondary water.';
  if (message.includes('TIDEFORGE_AFFIX_DUPLICATE')) return 'This item already has that affix.';
  if (message.includes('TIDEFORGE_BIOME_AFFIX_LIMIT')) return 'An item can have at most two water affixes, including inactive ones.';
  if (message.includes('TIDEFORGE_SECONDARY_BIOME_INVALID')) return 'Choose another available water.';
  if (message.includes('TIDEFORGE_REFINEMENT_UNAVAILABLE')) return 'That affix strength is already fully improved.';
  if (message.includes('TIDEFORGE_REFINING_INVALID') || message.includes('TIDEFORGE_REFINING_TARGET_UNREACHABLE')) return 'Choose valid grades and 1–1000 batches.';
  if (message.includes('PREVIOUS_LICENCE_REQUIRED')) return 'Unlock the previous water before purchasing this gear.';
  if (message.includes('SHOP_LEVEL_REQUIRED') || message.includes('ROD_LOCKED')) return 'Reach the required angler level for this gear.';
  if (message.includes('CATCH_UNAVAILABLE') || message.includes('CATCH_NOT_OWNED')) return 'A selected fish is no longer available. Review your selection.';
  if (message.includes('FAVORITE_PROTECTED')) return 'Favorites are protected. Unfavorite the item before using it.';
  if (message.includes('TIDEFORGE_EXCEPTION_PROTECTED')) return 'Bonus fish cannot be filleted.';
  if (message.includes('TIDEFORGE_TROPHY_PROTECTED')) return 'This selection includes a protected trophy.';
  if (message.includes('TIDEFORGE_SIGNATURE_ALREADY_KNOWN')) return 'That signature is already in your Reliquary.';
  if (message.includes('TIDEFORGE_SIGNATURE_UNAVAILABLE')) return 'This gear no longer has an extractable signature.';
  return 'This action could not be completed. Check your gear and materials, then try again.';
}
