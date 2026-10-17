export function browserStorage() {
  try {
    return globalThis.localStorage ?? null;
  } catch {
    return null;
  }
}

// Tools own their settings schema; this owns persistence and profile permissions.
export function createTrackerSetup({
  key,
  normalize,
  form,
  filters,
  payload,
  kind,
  saveDraft,
  legacy,
  storage = browserStorage,
}) {
  let ready = false;
  return {
    enable() {
      ready = true;
    },
    loadSetup() {
      try {
        const store = storage();
        if (!store) return null;
        const saved = store.getItem(key);
        const value = saved ? JSON.parse(saved) : legacy?.(store);
        return value ? normalize(value) : null;
      } catch {
        return null;
      }
    },
    saveSetup(persistProfile = true) {
      const current = filters();
      if (!ready || current.widgetEditable === false || !current.setup) return;
      try {
        storage()?.setItem(key, JSON.stringify(normalize(form)));
      } catch {
        /* Profile saving can still work when local storage is blocked. */
      }
      if (persistProfile && current.profile)
        saveDraft(kind, payload(true), current.profile);
    },
  };
}
