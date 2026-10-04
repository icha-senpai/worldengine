// Read the local server's counters outside the timed workload. Scope every
// counter to this run's database identity, excluding other apps and fixtures.
export async function readServerMetrics(
  server: string,
  databaseIdentity: string,
) {
  const response = await fetch(`${server}/v1/metrics`);
  if (!response.ok)
    throw new Error(`Server metrics returned ${response.status}.`);
  const counters = new Map<string, number>();
  for (const line of (await response.text()).split("\n")) {
    if (!line.includes(`db="${databaseIdentity}"`)) continue;
    const match = /^(\w+\{[^}]*\})\s+(\S+)$/.exec(line);
    if (match) counters.set(match[1], Number(match[2]));
  }
  return counters;
}

export function summarizeServerMetrics(
  before: Map<string, number>,
  after: Map<string, number>,
) {
  const views = new Map<string, Record<string, number>>();
  const reducers = new Map<string, Record<string, number>>();
  for (const [key, value] of after) {
    const delta = value - (before.get(key) ?? 0);
    if (!delta) continue;
    const view = /view="([^"]+)"/.exec(key)?.[1];
    const reducer = /reducer="([^"]+)"/.exec(key)?.[1];
    const metric = key.slice(0, key.indexOf("{"));
    if (
      view &&
      ["view_calls", "view_total_time_usec", "view_call_time_usec"].includes(
        metric,
      )
    ) {
      const fields = views.get(view) ?? {};
      fields[metric] = delta;
      views.set(view, fields);
    }
    if (
      reducer &&
      [
        "reducer_wasm_time_usec",
        "spacetime_reducer_plus_query_duration_sec_sum",
        "spacetime_reducer_plus_query_duration_sec_count",
        "spacetime_reducer_wait_time_sec_sum",
        "spacetime_reducer_wait_time_sec_count",
      ].includes(metric)
    ) {
      const fields = reducers.get(reducer) ?? {};
      fields[metric] = delta;
      reducers.set(reducer, fields);
    }
  }
  const rounded = (value: number) => Math.round(value * 100) / 100;
  return {
    views: Object.fromEntries(
      [...views].map(([name, row]) => [
        name,
        {
          calls: row.view_calls ?? 0,
          computationMs: rounded((row.view_total_time_usec ?? 0) / 1000),
          functionMs: rounded((row.view_call_time_usec ?? 0) / 1000),
        },
      ]),
    ),
    reducers: Object.fromEntries(
      [...reducers]
        .filter(
          ([, row]) => row.spacetime_reducer_plus_query_duration_sec_count > 0,
        )
        .map(([name, row]) => {
          const calls =
            row.spacetime_reducer_plus_query_duration_sec_count ?? 0;
          return [
            name,
            {
              calls,
              moduleRuntimeMeanMs: rounded(
                (row.reducer_wasm_time_usec ?? 0) / 1000 / (calls || 1),
              ),
              executionAndQueriesMeanMs: rounded(
                ((row.spacetime_reducer_plus_query_duration_sec_sum ?? 0) *
                  1000) /
                  (calls || 1),
              ),
              queueSamples: row.spacetime_reducer_wait_time_sec_count ?? 0,
              queueMeanMs: row.spacetime_reducer_wait_time_sec_count
                ? rounded(
                    ((row.spacetime_reducer_wait_time_sec_sum ?? 0) * 1000) /
                      row.spacetime_reducer_wait_time_sec_count,
                  )
                : null,
            },
          ];
        }),
    ),
  };
}
