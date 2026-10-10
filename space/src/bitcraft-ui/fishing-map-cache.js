// Camera position is saved per region; terrain comes from the permanent atlas.
export function readMapView(region, storage) {
  try {
    storage ??= globalThis.localStorage;
    const view = JSON.parse(
      storage?.getItem(`space.fishing-view.${region}`) ?? "null",
    );
    return view &&
      Number.isFinite(view.x) &&
      view.x >= 0 &&
      view.x < 38400 &&
      Number.isFinite(view.y) &&
      view.y >= 0 &&
      view.y < (38400 * Math.sqrt(3)) / 2 &&
      Number.isFinite(view.zoom) &&
      view.zoom >= -6 &&
      view.zoom <= 5
      ? view
      : null;
  } catch {
    return null;
  }
}
export function saveMapView(region, view, storage) {
  try {
    storage ??= globalThis.localStorage;
    storage?.setItem(`space.fishing-view.${region}`, JSON.stringify(view));
  } catch {
    /* Private mode or a full browser store must not stop the map. */
  }
}
