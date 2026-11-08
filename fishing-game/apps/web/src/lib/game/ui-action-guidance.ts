import type { AffixRoll } from './tideforge';
import type { Game } from './game.svelte';
import type { GuideBalances } from './tideforge-cost-guide';
import { hasOrdinaryRaidClearForGrade } from './raid-progress';

export function availableGuideBalances(game: Game): GuideBalances | null {
  if (!game.ready || !game.player || game.tideforgeAvailability !== 'active' || game.tideforgeLoading || game.tideforgeDetailsUnavailable || !game.tideforgeDetailsReady) return null;
  const playerId = game.player.playerId;
  const ordinary = new Set(game.species.filter(row => row.countsForOrdinaryCollectionCompletion).map(row => row.speciesId));
  return {
    coins: game.player.coins,
    items: game.items.filter(row => row.playerId === playerId),
    shards: game.tideforgeVault.filter(row => row.playerId === playerId),
    clear: grade => hasOrdinaryRaidClearForGrade(game.raidBadges, ordinary, playerId, grade),
  };
}

type Availability = { ready: boolean; busy: boolean; pending: boolean };
type ForgeAction = 'add_affix' | 'change_affix' | 'upgrade_affix' | 'refine_affix';

type ForgeContext = Availability & {
  hasItem: boolean;
  detailsUnavailable: boolean;
  rolls: readonly AffixRoll[] | null;
  mastery: { level: number; slots: number } | null;
  booked: boolean;
  action: ForgeAction;
  slot: number;
  choices: readonly string[];
  affix: string;
  waterValid: boolean;
  changeAllowed: boolean;
  tier: number;
};

const masteryThresholds = [1, 10, 25, 50];
const grades = ['F', 'D', 'C', 'B', 'A', 'S', 'SS', 'SSS', 'UR', 'UUR'];

function availabilityProblem({ ready, busy, pending }: Availability): string {
  if (!ready) return 'Reconnect to continue.';
  if (busy) return 'Wait for the current action to finish.';
  if (pending) return 'Check the previous action before starting another.';
  return '';
}

export function forgeActionProblem(context: ForgeContext): string {
  const availability = availabilityProblem(context);
  if (availability) return availability;
  if (!context.hasItem) return 'Choose gear.';
  if (context.detailsUnavailable || context.rolls === null) return 'Affix details could not load. Retry Tideforge before changing this gear.';
  if (!context.mastery) return 'Gear mastery is still loading. Please wait.';
  if (context.booked) return 'This gear is being used on a fishing trip or raid. Wait until it finishes.';

  const { rolls, mastery, action, slot } = context;
  const selected = rolls.find(row => row.slot === slot);
  if (action === 'add_affix') {
    if (!Array.from({ length: mastery.slots }, (_, index) => index).some(index => !rolls.some(row => row.slot === index))) {
      const next = masteryThresholds.find(threshold => threshold > mastery.level);
      return next
        ? `No empty unlocked slots. Reach mastery ${next} to open another slot, or choose Change to replace an affix.`
        : 'All unlocked slots are filled. Choose Change to replace an affix.';
    }
  } else {
    if (!selected) return 'Choose an occupied unlocked slot.';
    if (slot < 0 || slot >= mastery.slots) {
      const required = masteryThresholds[slot];
      return required ? `This slot is locked. Reach mastery ${required} to use it.` : 'Choose an occupied unlocked slot.';
    }
  }

  if (action === 'add_affix' || action === 'change_affix') {
    if (!context.choices.length) return 'No discovered compatible affixes are available for this selection. Find affixes on newly acquired gear; check Codex for discoveries.';
    if (!context.choices.includes(context.affix)) return 'Choose a discovered compatible affix.';
    if (!context.waterValid) return 'Choose an available secondary water.';
    if (!context.changeAllowed) return 'Choose a different affix or secondary water.';
  } else if (action === 'upgrade_affix' && selected) {
    if (selected.tier >= 10) return 'This affix is already at tier 10; there is no higher tier.';
    if (!Number.isSafeInteger(context.tier) || context.tier <= selected.tier || context.tier > 10) return 'Choose a higher tier up to tier 10.';
  } else if (action === 'refine_affix' && selected && selected.finishBp >= 10000) {
    return 'This affix strength is already fully improved.';
  }
  return '';
}

type ProcessingContext = Availability & {
  action: 'fillet' | 'convert_normal' | 'refine_material';
  selectedCount: number;
  validSelectedCount: number;
  material: number;
  batches: number;
  from: number;
  to: number;
  clear: (grade: number) => boolean;
};

export function processingActionProblem(context: ProcessingContext): string {
  const availability = availabilityProblem(context);
  if (availability) return availability;
  if (context.action === 'fillet') {
    if (context.selectedCount !== context.validSelectedCount) return 'A selected fish is no longer available or is protected. Review your selection.';
    if (!context.validSelectedCount) return 'Choose fish to fillet.';
    if (context.validSelectedCount > 50) return 'Choose up to 50 fish.';
    return '';
  }
  if (![0, 1].includes(context.material)) return 'Choose an available material.';
  if (!Number.isSafeInteger(context.batches) || context.batches < 1 || context.batches > 1000) return 'Choose 1–1000 batches.';
  if (context.action === 'convert_normal') {
    return context.clear(0) ? '' : 'A regular-fish raid clear at F grade is required.';
  }
  if (!Number.isInteger(context.from) || !Number.isInteger(context.to)
    || context.from < 0 || context.from >= grades.length - 1 || context.to <= context.from || context.to >= grades.length) {
    return 'Choose valid grades with a higher destination grade.';
  }
  for (let grade = context.from + 1; grade <= context.to; grade++) {
    if (!context.clear(grade)) return `A regular-fish raid clear at ${grades[grade]} grade is required.`;
  }
  return '';
}
