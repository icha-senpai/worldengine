const discordFallback = 'https://discord.com/app';

export function discordPlayUrl(value: string | null | undefined): string {
  try {
    const url = new URL(value ?? '');
    if (url.protocol !== 'https:' || !['discord.com', 'discord.gg'].includes(url.hostname)
      || url.username || url.password || url.port) return discordFallback;
    return url.href;
  } catch {
    return discordFallback;
  }
}

export type OnboardingStep = { id: 'catch' | 'favorite' | 'gear'; text: string; view?: 'inventory' | 'tideforge' };

export function onboardingNextSteps(facts: {
  discoveryCount: number;
  inventoryCount: number;
  hasFavorite: boolean;
}): OnboardingStep[] {
  const steps: OnboardingStep[] = [];
  if (!facts.discoveryCount) steps.push({ id: 'catch', text: 'Catch your first fish with /fish in Discord.' });
  if (!facts.hasFavorite) steps.push({
    id: 'favorite',
    text: facts.inventoryCount ? 'Favorite a keeper in your tackle box.' : 'Keep a fish, then favorite it in your tackle box.',
    view: 'inventory',
  });
  steps.push({ id: 'gear', text: 'Review your gear and bait in Tideforge.', view: 'tideforge' });
  return steps;
}
