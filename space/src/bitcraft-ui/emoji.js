const tonePattern = /[\u{1F3FB}-\u{1F3FF}]/gu;
let catalogPromise;

export function loadEmojiCatalog() {
  if (!catalogPromise) {
    catalogPromise = fetch("/assets/emoji/18.0.json")
      .then((response) => {
        if (!response.ok)
          throw Error("Could not load emojis. Please try again.");
        return response.json();
      })
      .then((data) => ({
        ...data,
        entries: data.entries.map(([emoji, name, category, subgroup]) => ({
          emoji,
          name,
          category,
          search: `${name} ${subgroup} ${data.groups[category]}`.toLowerCase(),
          tones: emoji.match(tonePattern) ?? [],
        })),
      }))
      .catch((error) => {
        catalogPromise = undefined;
        throw error;
      });
  }
  return catalogPromise;
}

export const selectedEmojis = (value) => [
  ...new Set(
    String(value ?? "")
      .split(/\s+/)
      .filter(Boolean),
  ),
];

// Match the existing widget profile's 40-character capacity without splitting sequences.
export function toggleEmoji(value, emoji) {
  const selected = selectedEmojis(value);
  if (selected.includes(emoji))
    return selected.filter((item) => item !== emoji).join(" ");
  const next = [...selected, emoji].join(" ");
  return next.length <= 40 ? next : null;
}

export function filterEmojis(
  entries,
  { query = "", category = "all", tone = "default", recent = [] } = {},
) {
  const terms = query.trim().toLowerCase().split(/\s+/).filter(Boolean);
  const filtered = entries.filter(
    (entry) =>
      (category === "all" ||
        (category === "recent"
          ? recent.includes(entry.emoji)
          : entry.category === Number(category))) &&
      (tone === "all" ||
        (tone === "default"
          ? entry.tones.length === 0
          : entry.tones.length === 0 ||
            entry.tones.every((value) => value === tone))) &&
      terms.every(
        (term) => entry.search.includes(term) || entry.emoji.includes(term),
      ),
  );
  return category === "recent"
    ? filtered.sort((a, b) => recent.indexOf(a.emoji) - recent.indexOf(b.emoji))
    : filtered;
}
