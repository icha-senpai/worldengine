const grades = ['f', 'd', 'c', 'b', 'a', 's', 'ss', 'sss', 'ur', 'uur'];

export function raidArtwork(kind: string, grade?: number): string | undefined {
  if (kind === 'supply') return '/baits/expedition-chum.png';
  if (!['seal', 'fillets', 'scales'].includes(kind)
    || grade === undefined
    || !Number.isInteger(grade)
    || grade < 0
    || grade >= grades.length) return undefined;

  const rank = grades[grade];
  if (kind === 'seal') return `/raid-seals/${rank}.png`;
  return `/raid-materials/${kind}-${rank}.png`;
}
