import { connectBitcraft } from "../bitcraft";
const kinds = ["activity", "inventory", "passive-crafts", "tasks"];
export function widgetKind(path) {
  const kind = path.split("/")[2];
  return kinds.includes(kind) ? kind : null;
}
export async function readWidget(kind, token) {
  const conn = await connectBitcraft();
  const payload = JSON.parse(await conn.procedures.readWidget({ kind, token }));
  return { ...payload.settings, widgetEditable: payload.editable };
}
export async function saveWidget(kind, settings, token) {
  const storageKey = `space.bitcraft.widget:${kind}:${settings.source || "default"}`;
  token = token || localStorage.getItem(storageKey) || crypto.randomUUID();
  const conn = await connectBitcraft();
  const clean = { ...settings };
  delete clean.user;
  delete clean.profile;
  delete clean.widgetEditable;
  await conn.reducers.saveWidget({
    kind,
    token,
    settings: JSON.stringify(clean),
  });
  localStorage.setItem(storageKey, token);
  return token;
}
export async function widgetUrl(url) {
  const next = new URL(url, location.origin),
    kind = widgetKind(next.pathname);
  const token = await saveWidget(kind, Object.fromEntries(next.searchParams));
  return `${next.origin}${next.pathname}?profile=${encodeURIComponent(token)}&presentation=${encodeURIComponent(next.searchParams.get("presentation") || "obs")}`;
}
export function openWidget(url, { popup = false } = {}) {
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
  widgetUrl(next.href)
    .then((href) => {
      tab.location.href = href;
    })
    .catch(() => {
      tab.location.href = next.href;
    });
  return tab;
}
