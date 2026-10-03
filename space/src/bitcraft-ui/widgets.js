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
export function openWidget(url) {
  const tab = window.open("about:blank", "_blank");
  if (!tab) return;
  tab.opener = null;
  const next = new URL(url, location.origin),
    kind = widgetKind(next.pathname);
  saveWidget(kind, Object.fromEntries(next.searchParams))
    .then((token) => {
      tab.location.href = `${next.origin}${next.pathname}?profile=${encodeURIComponent(token)}`;
    })
    .catch(() => {
      tab.location.href = next.href;
    });
}
