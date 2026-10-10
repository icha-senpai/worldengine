import { defineComponent, h, nextTick, reactive } from "vue";
let appRouter;
export function configureNavigation(router) {
  appRouter = router;
}

function readPlayer() {
  try {
    return JSON.parse(
      localStorage.getItem("space.bitcraft.selectedPlayer") || "null",
    );
  } catch {
    return null;
  }
}
export const pageState = reactive({
  props: { auth: { user: null }, bitcraft: { player: readPlayer() } },
  url: location.pathname + location.search,
  error: "",
});
let reloadPage = async () => {};
let loadingPage = Promise.resolve();
export function setPageLoader(loader) {
  reloadPage = loader;
}
export function trackPageLoad(promise) {
  loadingPage = promise;
  return promise;
}
export const usePage = () => pageState;
export function savePlayer(player) {
  pageState.props.bitcraft.player = player;
  try {
    localStorage.setItem(
      "space.bitcraft.selectedPlayer",
      JSON.stringify(player),
    );
    if (player)
      localStorage.setItem("space.bitcraft.player", String(player.entityId));
    else localStorage.removeItem("space.bitcraft.player");
  } catch {
    /* Selection still works without storage. */
  }
}
const names = {
  market: "market",
  "fishing-map": "fishing-map",
  "barter-stalls": "barter-stalls",
  crafting: "crafting",
  "tool-rates": "tool-rates",
  "hunting-calculator": "hunting-calculator",
  "open-crafts": "open-crafts",
  activity: "activity/widget",
  "activity.setup": "activity/setup",
  "activity.snapshot": "activity/snapshot",
  "inventory-tracker": "inventory/widget",
  "inventory-tracker.setup": "inventory/setup",
  "inventory-tracker.snapshot": "inventory/snapshot",
  "passive-crafts": "passive-crafts/widget",
  "passive-crafts.setup": "passive-crafts/setup",
  "passive-crafts.snapshot": "passive-crafts/snapshot",
  "task-tracker": "tasks/widget",
  "task-tracker.setup": "tasks/setup",
  "guides.index": "guides",
  "guides.create": "guides/create",
  "guides.store": "guides",
  "guides.edit": "guides/:id/edit",
  "guides.update": "guides/:id",
  "guides.show": "guides/:id",
  "guides.card-data": "guides/card-data",
  "guides.items": "guides/items",
  "guides.card-options": "guides/card-options",
  "market.order-book": "market/order-book",
  "barter-stalls.listings": "barter-stalls/listings",
  "crafting.branch": "crafting/branch",
  "players.search": "players/search",
  "player.update": "player",
};
export function route(name, params = {}) {
  const resource = name.replace(/^bitcraft\./, "");
  let path = names[resource];
  if (!path) throw new Error(`Unknown BitCraft route: ${name}`);
  if (path.includes(":id")) {
    const id =
      typeof params === "object" ? (params.guide ?? params.id) : params;
    path = path.replace(":id", encodeURIComponent(id));
    params = {};
  }
  const query = new URLSearchParams();
  for (const [key, value] of Object.entries(params ?? {}))
    if (value !== undefined && value !== null && value !== "")
      query.set(
        key,
        typeof value === "boolean" ? (value ? "1" : "0") : String(value),
      );
  return "/bitcraft/" + path + (query.size ? "?" + query : "");
}
async function navigate(url, data = {}, options = {}) {
  pageState.error = "";
  options.onStart?.();
  try {
    const next = new URL(url, location.origin);
    for (const [key, value] of Object.entries(data))
      if (value !== null && value !== undefined && value !== "")
        next.searchParams.set(
          key,
          typeof value === "boolean" ? (value ? "1" : "0") : String(value),
        );
    const { widgetKind, saveWidget } = await import("./widgets");
    const kind = widgetKind(next.pathname);
    if (kind && Object.keys(data).length) {
      const previous = appRouter.currentRoute.value.query.profile;
      const token = await saveWidget(
        kind,
        { ...Object.fromEntries(next.searchParams), ...data },
        typeof previous === "string" ? previous : undefined,
      );
      next.search = "";
      next.searchParams.set("profile", token);
    }
    const same =
      appRouter.currentRoute.value.fullPath === next.pathname + next.search;
    await (options.replace ? appRouter.replace : appRouter.push)(
      next.pathname + next.search,
    );
    await nextTick();
    if (same) await reloadPage();
    else await loadingPage;
    options.onSuccess?.(pageState);
  } catch (error) {
    pageState.error = error.message;
    options.onError?.({ _error: error.message });
  } finally {
    options.onFinish?.();
  }
}
export const router = {
  get: navigate,
  visit: (url, options = {}) => navigate(url, options.data ?? {}, options),
  reload: async (options = {}) => {
    options.onStart?.();
    try {
      await reloadPage();
      options.onSuccess?.(pageState);
    } finally {
      options.onFinish?.();
    }
  },
};
export function useForm(initial) {
  const defaults = JSON.parse(JSON.stringify(initial));
  const form = reactive({
    ...structuredClone(defaults),
    processing: false,
    errors: {},
    clearErrors() {
      form.errors = {};
    },
    reset() {
      Object.assign(form, structuredClone(defaults));
      form.errors = {};
    },
    async get(url, options = {}) {
      form.processing = true;
      try {
        await navigate(
          url,
          Object.fromEntries(
            Object.keys(initial).map((key) => [key, form[key]]),
          ),
          options,
        );
      } finally {
        form.processing = false;
      }
    },
    async put(url, options = {}) {
      if (
        new URL(url, location.origin).pathname.startsWith("/bitcraft/guides/")
      )
        return form.post(url, options);
      form.processing = true;
      form.errors = {};
      try {
        if (new URL(url, location.origin).pathname !== "/bitcraft/player")
          throw new Error("This change requires database-owner access.");
        const { lookupPlayer } = await import("./api");
        const player = form.entityId
          ? await lookupPlayer(String(form.entityId))
          : null;
        if (form.entityId && !player)
          throw new Error("That player could not be found.");
        savePlayer(player);
        await reloadPage();
        options.onSuccess?.();
      } catch (error) {
        form.errors = { entityId: error.message };
        options.onError?.(form.errors);
      } finally {
        form.processing = false;
        options.onFinish?.();
      }
    },
    async post(url, options = {}) {
      form.processing = true;
      form.errors = {};
      try {
        const path = new URL(url, location.origin).pathname;
        if (!/^\/bitcraft\/guides(?:\/\d+)?$/.test(path))
          throw new Error("This action is unavailable.");
        const { saveGuide } = await import("./api");
        const id = await saveGuide(path.split("/")[3], form);
        await navigate(route("bitcraft.guides.show", id), {}, options);
      } catch (error) {
        const field = /title/i.test(error.message)
          ? "title"
          : /summary/i.test(error.message)
            ? "summary"
            : /category/i.test(error.message)
              ? "category"
              : "content";
        form.errors = { [field]: error.message };
        options.onError?.(form.errors);
      } finally {
        form.processing = false;
        options.onFinish?.();
      }
    },
  });
  return form;
}
export const Head = defineComponent({
  props: { title: String },
  setup(props) {
    if (props.title) document.title = props.title + " · DataVerse Space";
    return () => null;
  },
});
export const Link = defineComponent({
  inheritAttrs: false,
  props: { href: String },
  setup(props, { attrs, slots }) {
    return () =>
      h(
        "a",
        {
          ...attrs,
          href: props.href,
          onClick: (event) => {
            if (
              !event.defaultPrevented &&
              event.button === 0 &&
              !event.ctrlKey &&
              !event.metaKey &&
              !event.shiftKey &&
              !event.altKey
            ) {
              event.preventDefault();
              navigate(props.href);
            }
          },
        },
        slots.default?.(),
      );
  },
});
