// Keep the last observation visible during a failed read, without carrying
// another character's activity into a newly selected tracker.
export function retainActivityTracker(previous, next) {
  if (!next) {
    if (!previous) return null;
    return {
      ...previous,
      activity: previous.activity
        ? { ...previous.activity, delayed: true }
        : null,
    };
  }
  if (
    !next.activity &&
    previous?.activity &&
    String(previous.player?.entityId) === String(next.player?.entityId) &&
    next.player?.entityId != null
  )
    return { ...next, activity: { ...previous.activity, delayed: true } };
  return next;
}
