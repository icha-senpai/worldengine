import { expect, test } from "vitest";
import {
  releaseAppState,
  refreshReleaseGuides,
} from "../scripts/bitcraft-release-state";

test("guide refresh replaces only unchanged bundled content, preserving custom edits and drafts", () => {
  const old = {
    id: 1,
    title: "Guide",
    summary: "",
    category: "General",
    content: { type: "doc", content: [] },
  };
  const current = {
    ...old,
    content: { type: "doc", content: [{ type: "paragraph" }] },
    updated_at: "2026-11-14T00:00:00Z",
  };
  const row = {
    ...old,
    id: "1",
    content: JSON.stringify(old.content),
    published: true,
  };
  const state = {
    guide: [
      row,
      { ...row, id: "2", title: "Custom" },
      { ...row, id: "3", published: false },
    ],
    guide_metadata: [{ id: "1", authorName: "Boss", updatedAt: "1" }],
  };
  const result = refreshReleaseGuides(
    state,
    [old, { ...old, id: 2 }, { ...old, id: 3 }],
    [current, { ...current, id: 2 }, { ...current, id: 3 }],
  );
  expect(JSON.parse(result.guide[0].content)).toEqual(current.content);
  expect(result.guide.slice(1)).toEqual(state.guide.slice(1));
  expect(result.guide_metadata[0]).toEqual({
    id: "1",
    authorName: "Boss",
    updatedAt: "1794614400000000",
  });
  expect(state.guide[0]).toEqual(row);
});
test("new-world state preserves app ownership and user content, without old-world history or fallback providers", () => {
  const source = {
    version: 3,
    administrator: [{ id: 1, owner: "original" }],
    guide: [{ id: "16", content: "User edits" }],
    guide_metadata: [],
    guide_administrator: [],
    widget_profile: [{ token: "widget", owner: "original", settings: "{}" }],
    provider_settings: [
      { name: "bitjita", config: '{"token":"private","enabled":true}' },
    ],
    collection_sample: [{ key: "old XP" }],
    collection_watch: [{ key: "old player" }],
    collection_feed: [{ key: "old feed" }],
  };
  const before = JSON.stringify(source),
    next = releaseAppState(source);
  expect(JSON.stringify(source)).toBe(before);
  expect(next.administrator).toEqual(source.administrator);
  expect(next.guide).toEqual(source.guide);
  expect(next.widget_profile).toEqual(source.widget_profile);
  expect(next.collection_sample).toEqual([]);
  expect(next.collection_watch).toEqual([]);
  expect(next.collection_feed).toEqual([]);
  expect(
    JSON.parse(
      next.provider_settings.find((r: any) => r.name === "bitjita").config,
    ),
  ).toEqual({ token: "private", enabled: false });
  expect(
    JSON.parse(
      next.provider_settings.find((r: any) => r.name === "relay").config,
    ).enabled,
  ).toBe(true);
  expect(() => releaseAppState({ version: 2 })).toThrow();
});
