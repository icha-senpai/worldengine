import { computed, ref, watch } from "vue";

// Keep the last successful observation until a replacement actually arrives.
export function useTrackerSnapshot(snapshot, connected) {
  const tracker = ref(snapshot.tracker ?? null);
  const options = ref(snapshot.options ?? []);
  const error = ref(snapshot.error ?? null);
  const sampledAt = ref(snapshot.sampledAt ?? null);
  const refresh = ref(snapshot.refresh ?? {});
  const refreshStatus = computed(() => ({
    ...refresh.value,
    delayed: Boolean(refresh.value.delayed || !connected.value),
  }));

  function markDelayed(message) {
    refresh.value = { ...refresh.value, delayed: true };
    if (message !== undefined) error.value = message;
  }
  function applySnapshot(next) {
    const previousUpdatedAt = refresh.value.updatedAt ?? sampledAt.value;
    if (next.tracker) {
      tracker.value = next.tracker;
      sampledAt.value = next.sampledAt;
      options.value = next.options ?? options.value;
    } else if (!tracker.value) options.value = next.options ?? options.value;
    error.value = next.error ?? null;
    refresh.value = {
      ...next.refresh,
      ...(!next.tracker ? { updatedAt: previousUpdatedAt } : {}),
      delayed: Boolean(next.refresh?.delayed || !next.tracker),
    };
  }
  watch(
    connected,
    (ready) => {
      if (!ready) markDelayed();
    },
    { flush: "sync" },
  );

  return {
    tracker,
    options,
    error,
    sampledAt,
    refreshStatus,
    applySnapshot,
    markDelayed,
  };
}
