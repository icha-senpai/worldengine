export type CampView = 'camp' | 'quests' | 'inventory' | 'tideforge' | 'collection' | 'journal'
  | 'waters' | 'records' | 'compendium' | 'trader' | 'achievements' | 'anglers';
export type CampRoute = { view: CampView; section?: string };

const views: CampView[] = ['camp', 'quests', 'inventory', 'tideforge', 'collection', 'journal',
  'waters', 'records', 'compendium', 'trader', 'achievements', 'anglers'];
const sections: Partial<Record<CampView, readonly string[]>> = {
  tideforge: ['gear', 'forge', 'processing', 'materials', 'codex', 'reliquary'],
  trader: ['camp', 'additional', 'wandering'],
  compendium: ['collection', 'field-guide'],
};

export function parseCampHash(hash: string, fallback: CampView = 'camp'): CampRoute {
  const [base, requested, ...extra] = hash.replace(/^#/, '').split('/');
  if (!views.includes(base as CampView)) return parseCampHash(`#${fallback}`, fallback);
  const view = base as CampView;
  if (view === 'collection') return { view: 'compendium', section: 'collection' };
  if (extra.length) return parseCampHash(`#${view}`, fallback);
  if (view === 'tideforge' && requested === 'trader') return { view: 'trader', section: 'additional' };
  if (view === 'tideforge' && requested === 'vault') return { view, section: 'materials' };
  const section = requested && sections[view]?.includes(requested) ? requested
    : view === 'tideforge' ? 'gear' : view === 'trader' ? 'camp' : undefined;
  return { view, section };
}

export function formatCampRoute(view: CampView, section?: string): string {
  if (view === 'collection') return '#compendium/collection';
  const route = parseCampHash(`#${view}${section ? `/${section}` : ''}`);
  return `#${route.view}${section && route.section ? `/${route.section}` : ''}`;
}
