import { describe, it, expect } from "vitest";
import { readMapView, saveMapView } from "../src/bitcraft-ui/fishing-map-cache";

describe("Saved fishing camera", () => {
  it("restores a per-region camera and ignores malformed or out-of-world positions", () => {
    const values = new Map(),
      storage = {
        getItem: (k) => values.get(k),
        setItem: (k, v) => values.set(k, v),
      };
    const view = { x: 20768, y: 9954, zoom: 2 };
    saveMapView(9, view, storage);
    expect(readMapView(9, storage)).toEqual(view);
    expect(readMapView(8, storage)).toBeNull();
    saveMapView(9, { ...view, zoom: 10 }, storage);
    expect(readMapView(9, storage)).toBeNull();
    storage.setItem("space.fishing-view.9", "{");
    expect(readMapView(9, storage)).toBeNull();
    const disabled = {
      getItem() {
        throw new Error("disabled");
      },
      setItem() {
        throw new Error("full");
      },
    };
    expect(readMapView(9, disabled)).toBeNull();
    expect(() => saveMapView(9, view, disabled)).not.toThrow();
  });
});
