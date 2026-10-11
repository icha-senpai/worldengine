import { ref } from "vue";

export const quickAction = ref(null);
export const autoRepeat = ref(false);
export const autoRepeatNotice = ref("");
export const favorites = ref(readList("evergather.favorites"));
export const recentCommands = ref(readList("evergather.recent-commands"));

// Action identities are replaced with the economy; retire saved old commands.
export function applyEconomyVersion(version) {
  if (!version) return;
  try {
    if (localStorage.getItem("evergather.command-economy") === version) return;
    localStorage.setItem("evergather.command-economy", version);
  } catch {
    return;
  }
  favorites.value = [];
  recentCommands.value = [];
  quickAction.value = null;
  autoRepeat.value = false;
  autoRepeatNotice.value = "";
  save("evergather.favorites", []);
  save("evergather.recent-commands", []);
}

function readList(key) {
  try {
    const value = JSON.parse(localStorage.getItem(key) ?? "[]");
    return Array.isArray(value) ? value.filter(validCommand).slice(0, 12) : [];
  } catch {
    return [];
  }
}
function validCommand(value) {
  return (
    value &&
    ["gather", "activity", "craft", "job", "expedition"].includes(
      value.action,
    ) &&
    typeof value.key === "string" &&
    typeof value.label === "string"
  );
}
function save(key, value) {
  try {
    localStorage.setItem(key, JSON.stringify(value));
  } catch {
    /* Storage is optional. */
  }
}
export function commandId(command) {
  return `${command.action}:${command.key}`;
}
export function selectQuickAction(action, entry) {
  if (!entry) return;
  const command = {
    action,
    key: entry.key,
    label: entry.label,
    skill: entry.skill,
  };
  if (quickAction.value && commandId(quickAction.value) !== commandId(command))
    autoRepeat.value = false;
  quickAction.value = command;
  autoRepeatNotice.value = "";
  recentCommands.value = [
    command,
    ...recentCommands.value.filter(
      (value) => commandId(value) !== commandId(command),
    ),
  ].slice(0, 8);
  save("evergather.recent-commands", recentCommands.value);
}
export function toggleFavorite(command) {
  if (!validCommand(command)) return;
  const id = commandId(command);
  favorites.value = favorites.value.some((value) => commandId(value) === id)
    ? favorites.value.filter((value) => commandId(value) !== id)
    : [...favorites.value, command].slice(-12);
  save("evergather.favorites", favorites.value);
}
export function preferredEntries(entries, action) {
  const pinned = new Set(
    favorites.value
      .filter((value) => value.action === action)
      .map((value) => value.key),
  );
  const recent = recentCommands.value
    .filter((value) => value.action === action)
    .map((value) => value.key);
  return [...entries].sort(
    (a, b) =>
      Number(pinned.has(b.key)) - Number(pinned.has(a.key)) ||
      (recent.includes(a.key) ? recent.indexOf(a.key) : 99) -
        (recent.includes(b.key) ? recent.indexOf(b.key) : 99),
  );
}
