import { describe, expect, it, vi } from "vitest";
import { effectScope, ref } from "vue";
import { restoreCalculatorSettings } from "../src/bitcraft-ui/calculatorSettings";
import {
  huntingDefaults,
  nubiGoatPreset,
  calculateHuntingXp,
} from "../src/bitcraft-ui/huntingXp";
import { collectionResult } from "../src/bitcraft-ui/data/result";
import { useMarketBooks } from "../src/bitcraft-ui/useMarketBooks";
import { bitcraftFetch } from "../src/bitcraft-ui/api";
vi.mock("../src/bitcraft-ui/api", () => ({ bitcraftFetch: vi.fn() }));
vi.mock("../src/bitcraft-ui/navigation", () => ({
  route: (_name, params) => `/book/${params.itemId}`,
}));
describe("calculator and data conventions", () => {
  it("migrates legacy calculator settings and rejects malformed values without changing XP presets", () => {
    const result = restoreCalculatorSettings(
      JSON.stringify({
        power: -10,
        minutes: "15",
        mode: "invalid",
        unknown: 3,
      }),
      { power: 33, minutes: 30, mode: "sustained" },
      { mode: ["single", "sustained"] },
    );
    expect(result).toEqual({ power: 33, minutes: 15, mode: "sustained" });
    expect(
      restoreCalculatorSettings(
        JSON.stringify({ version: 1, settings: { currentLevel: 40 } }),
        huntingDefaults,
      ).currentLevel,
    ).toBe(40);
    expect(nubiGoatPreset).toMatchObject({ killXp: 386.1, processingXp: 198 });
    expect(
      calculateHuntingXp(
        [
          { level: 1, xp: 0 },
          { level: 2, xp: 100 },
        ],
        { ...huntingDefaults, currentLevel: 1, currentXp: 0, targetLevel: 2 },
      ).remainingXp,
    ).toBe(100);
  });
  it("preserves observation time, source identity and retry metadata on collected reads", () => {
    const response = {
      key: "player|123",
      payload: '{"items":[]}',
      updatedAt: 1000000n,
      retryAt: 5000000n,
      error: "Delayed",
    };
    expect(collectionResult(response, JSON.parse, 1000)).toEqual({
      data: { items: [] },
      sourceKey: "player|123",
      error: "Delayed",
      refresh: {
        updatedAt: "1970-01-01T00:00:01.000Z",
        delayed: true,
        retryAfter: 4,
      },
    });
    expect(() =>
      collectionResult({ ...response, payload: "" }, JSON.parse, 1000),
    ).toThrow("Delayed");
  });
  it("keeps the newest market selection when an older order-book request finishes later", async () => {
    const scope = effectScope();
    const pending = [];
    bitcraftFetch.mockImplementation(
      () => new Promise((resolve) => pending.push(resolve)),
    );
    const selectedKey = ref(""),
      rows = [
        { kind: "item", id: 1 },
        { kind: "item", id: 2 },
      ];
    const books = scope.run(() =>
      useMarketBooks({
        props: { market: {} },
        isBarter: ref(false),
        resultScope: () => "scope",
        payload: () => ({}),
        selected: () =>
          rows.find((row) => `item:${row.id}` === selectedKey.value),
        selectedKey,
        popupOpen: ref(false),
        detailsTab: ref("offers"),
        rowKey: (row) => `item:${row.id}`,
        remember: vi.fn(),
      }),
    );
    const old = books.selectBook(rows[0]),
      latest = books.selectBook(rows[1]);
    pending[1](Response.json({ orderBook: { item: rows[1] } }));
    await latest;
    pending[0](Response.json({ orderBook: { item: rows[0] } }));
    await old;
    expect(books.selectedBook.value.item.id).toBe(2);
    expect(books.bookLoading.value).toBe(false);
    scope.stop();
  });
});
