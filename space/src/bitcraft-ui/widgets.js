import { connectBitcraft } from "../bitcraft";
import { pageState } from "./navigation";
const kinds = ["activity", "inventory", "passive-crafts", "tasks"];
const saveQueues = new Map();
const draftTimers = new Map();
const draftKey = (kind, token) => `space.bitcraft.widgetDraft:${kind}:${token}`;
const saveError =
  "Widget settings could not be saved. Your edits are kept in this browser.";

function cleanSettings(settings) {
  const clean = { ...settings };
  delete clean.user;
  delete clean.profile;
  delete clean.widgetEditable;
  return clean;
}
export function widgetKind(path) {
  const kind = path.split("/")[2];
  return kinds.includes(kind) ? kind : null;
}
export async function readWidget(kind, token, { draft = true } = {}) {
  const conn = await connectBitcraft();
  const payload = JSON.parse(await conn.procedures.readWidget({ kind, token }));
  let settings = payload.settings;
  if (draft && payload.editable) {
    try {
      const pending = localStorage.getItem(draftKey(kind, token));
      if (pending) {
        settings = JSON.parse(pending);
        persistWidgetDraft(kind, settings, token);
      }
    } catch {
      /* Keep the committed profile when browser storage is unavailable. */
    }
  }
  return { ...settings, widgetEditable: payload.editable };
}
export function persistWidgetDraft(kind, settings, token) {
  if (!token) return;
  const key = draftKey(kind, token);
  localStorage.setItem(key, JSON.stringify(cleanSettings(settings)));
  clearTimeout(draftTimers.get(key));
  draftTimers.set(
    key,
    setTimeout(() => {
      draftTimers.delete(key);
      void saveWidget(kind, settings, token).catch(() => {
        pageState.error = saveError;
      });
    }, 300),
  );
}
export async function saveWidget(kind, settings, token) {
  const storageKey = `space.bitcraft.widget:${kind}:${settings.source || "default"}`;
  token = token || localStorage.getItem(storageKey) || crypto.randomUUID();
  const key = draftKey(kind, token);
  clearTimeout(draftTimers.get(key));
  draftTimers.delete(key);
  const pendingDraft = localStorage.getItem(key);
  const serialized = JSON.stringify(cleanSettings(settings));
  const work = (saveQueues.get(key) || Promise.resolve())
    .catch(() => {})
    .then(async () => {
      const conn = await connectBitcraft();
      await conn.reducers.saveWidget({ kind, token, settings: serialized });
      localStorage.setItem(storageKey, token);
      if (pendingDraft && localStorage.getItem(key) === pendingDraft)
        localStorage.removeItem(key);
      if (pageState.error === saveError) pageState.error = "";
    });
  saveQueues.set(key, work);
  try {
    await work;
  } finally {
    if (saveQueues.get(key) === work) saveQueues.delete(key);
  }
  return token;
}
export async function widgetUrl(url, { settings, token } = {}) {
  const next = new URL(url, location.origin),
    kind = widgetKind(next.pathname);
  const profile = await saveWidget(
    kind,
    { ...Object.fromEntries(next.searchParams), ...settings },
    token,
  );
  return `${next.origin}${next.pathname}?profile=${encodeURIComponent(profile)}&presentation=${encodeURIComponent(next.searchParams.get("presentation") || "obs")}`;
}
export function openWidget(url, { popup = false, settings, token } = {}) {
  const tab = window.open(
    "about:blank",
    "_blank",
    popup
      ? "popup=yes,width=430,height=760,resizable=yes,scrollbars=yes"
      : undefined,
  );
  if (!tab) return;
  tab.opener = null;
  const next = new URL(url, location.origin),
    kind = widgetKind(next.pathname);
  widgetUrl(next.href, { settings, token })
    .then((href) => {
      tab.location.href = href;
    })
    .catch(() => {
      tab.location.href = next.href;
    });
  return tab;
}
