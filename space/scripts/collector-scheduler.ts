export function pruneSchedule(
  due: Map<string, number>,
  active: Set<string>,
  limit = 5000,
) {
  for (const key of due.keys()) if (!active.has(key)) due.delete(key);
  while (due.size > limit) due.delete(due.keys().next().value!);
}
