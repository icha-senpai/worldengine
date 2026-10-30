export function createBrowserRequestId(
  nowMs = Date.now(),
  random16 = () => {
    const values = new Uint16Array(1);
    crypto.getRandomValues(values);
    return values[0];
  }
) {
  if (!Number.isSafeInteger(nowMs) || nowMs < 0) throw new Error('Invalid request time');
  const random = random16();
  if (!Number.isInteger(random) || random < 0 || random > 0xffff) throw new Error('Invalid request entropy');
  return (BigInt(nowMs) << 16n) | BigInt(random);
}

export function raidActionFingerprint(
  action:
    | { kind: 'prepare'; speciesId: number; grade: number }
    | { kind: 'prepareCommunity'; eventId: bigint }
    | { kind: 'deploy' | 'cancel' | 'claim'; attemptId: bigint }
    | { kind: 'refine'; materialId: number; fromGrade: number; batches: number }
    | { kind: 'reinforce'; rodId: number; expectedLevel: number }
) {
  switch (action.kind) {
    case 'prepare': return `prepare_raid:${action.speciesId}:${action.grade}`;
    case 'prepareCommunity': return `prepare_community_raid:${action.eventId}`;
    case 'deploy': return `deploy_raid:${action.attemptId}`;
    case 'cancel': return `cancel_raid:${action.attemptId}`;
    case 'claim': return `claim_raid:${action.attemptId}`;
    case 'refine': return `refine_raid_material:${action.materialId}:${action.fromGrade}:${action.batches}`;
    case 'reinforce': return `reinforce_rod:${action.rodId}:${action.expectedLevel}`;
  }
}

export function canRetryRaidIntent(
  pending: { fingerprint: string; playerId: bigint } | null,
  fingerprint: string,
  playerId: bigint
) {
  return !pending || pending.fingerprint === fingerprint && pending.playerId === playerId;
}

export function matchesRaidAction(
  pending: { requestId: bigint; fingerprint: string; playerId: bigint } | null,
  action: { requestId: bigint; fingerprint: string; playerId: bigint }
) {
  return pending?.requestId === action.requestId && pending.fingerprint === action.fingerprint
    && pending.playerId === action.playerId;
}
