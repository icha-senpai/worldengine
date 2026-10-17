import { reactive, watch } from "vue";
import { browserStorage } from "./trackerSetup";

export function restoreCalculatorSettings(serialized, defaults, choices = {}) {
  let saved;
  try {
    const parsed = JSON.parse(serialized ?? "{}");
    saved = parsed.version === 1 ? parsed.settings : parsed;
  } catch {
    saved = {};
  }
  if (!saved || typeof saved !== "object" || Array.isArray(saved)) saved = {};
  return Object.fromEntries(
    Object.entries(defaults).map(([key, fallback]) => {
      const value = saved[key];
      const valid = choices[key]
        ? choices[key].includes(value)
        : typeof fallback === "number"
          ? value !== "" &&
            value != null &&
            Number.isFinite(Number(value)) &&
            Number(value) >= 0
          : typeof value === "string";
      return [
        key,
        valid
          ? typeof fallback === "number"
            ? Number(value)
            : value
          : fallback,
      ];
    }),
  );
}
export function useCalculatorSettings(key, defaults, choices = {}) {
  const store = browserStorage();
  let saved = null;
  try {
    saved = store?.getItem(key);
  } catch {
    /* Start with valid defaults. */
  }
  const settings = reactive(
    restoreCalculatorSettings(saved, defaults, choices),
  );
  watch(
    settings,
    () => {
      try {
        store?.setItem(
          key,
          JSON.stringify({ version: 1, settings: { ...settings } }),
        );
      } catch {
        /* Calculation remains available without browser storage. */
      }
    },
    { deep: true },
  );
  return settings;
}
