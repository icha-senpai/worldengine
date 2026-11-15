import { isDeepStrictEqual } from "node:util";

// Refresh bundled guides only when production still has that exact baseline.
// Custom edits and drafts are copied unchanged into the release world.
export function refreshReleaseGuides(
  state: Record<string, any>,
  baseline: any[],
  current: any[],
) {
  const previous = new Map(baseline.map((row) => [String(row.id), row]));
  const next = new Map(current.map((row) => [String(row.id), row]));
  const updated = new Set<string>();
  const fields = (row: any) => ({
    title: row.title,
    summary: row.summary ?? "",
    category: row.category ?? "General",
    content: row.content,
  });
  const guide = state.guide.map((row: any) => {
    const old = previous.get(String(row.id)),
      fresh = next.get(String(row.id));
    const existing = { ...fields(row), content: JSON.parse(row.content) };
    if (
      !row.published ||
      !old ||
      !fresh ||
      !isDeepStrictEqual(existing, fields(old)) ||
      isDeepStrictEqual(fields(old), fields(fresh))
    )
      return row;
    updated.add(String(row.id));
    return { ...row, ...fields(fresh), content: JSON.stringify(fresh.content) };
  });
  const guide_metadata = state.guide_metadata.map((row: any) =>
    updated.has(String(row.id))
      ? {
          ...row,
          updatedAt: String(
            BigInt(Date.parse(next.get(String(row.id)).updated_at)) * 1000n,
          ),
        }
      : row,
  );
  return { ...state, guide, guide_metadata };
}

// Copy application-owned records into a separate world database. Game-derived
// samples, watches, feeds and checkpoints stay in the existing-world database.
export function releaseAppState(source: Record<string, any>) {
  if (source.version !== 3 || source.administrator?.length !== 1)
    throw Error(
      "Expected the current application state and its original owner.",
    );
  const result = {
    ...source,
    collection_sample: [],
    collection_watch: [],
    collection_feed: [],
    provider_settings: source.provider_settings.map((row: any) => ({
      ...row,
      config: JSON.stringify({
        ...JSON.parse(row.config),
        enabled: row.name === "relay",
      }),
    })),
  };
  for (const name of ["bitjita", "bitjuice", "relay"])
    if (!result.provider_settings.some((row: any) => row.name === name))
      result.provider_settings.push({
        name,
        config: JSON.stringify({ enabled: name === "relay" }),
      });
  return result;
}
