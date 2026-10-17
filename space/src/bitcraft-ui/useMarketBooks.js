import { ref, watch, onScopeDispose } from "vue";
import { bitcraftFetch } from "./api";
import { route } from "./navigation";
import { normalizeOrderBook } from "./market";
export function useMarketBooks({
  props,
  isBarter,
  resultScope,
  payload,
  selected,
  selectedKey,
  popupOpen,
  detailsTab,
  rowKey,
  remember,
}) {
  const selectedBook = ref(null),
    bookLoading = ref(false),
    lookupWarning = ref("");
  const books = new Map();
  let lookupGeneration = 0,
    selectedBookScope = "",
    destroyed = false;
  const itemKey = (item) => `${item.kind ?? item.type ?? "item"}:${item.id}`;
  const rememberBook = (key, book) => {
    books.delete(key);
    books.set(key, book);
    if (books.size > 128) books.delete(books.keys().next().value);
  };
  function knownBook(item) {
    const cached = books.get(`${resultScope()}:${itemKey(item)}`);
    if (
      cached &&
      (!props.market.storage ||
        String(cached.observedAt) === String(item.observedAt))
    )
      return cached;
    const provided = props.market.orderBooks?.[itemKey(item)];
    if (provided) return provided;
    if (
      props.market.orderBook &&
      itemKey(props.market.orderBook.item) === itemKey(item)
    )
      return props.market.orderBook;
    if (
      !props.market.storage &&
      (props.market.orderScope === "claims" || props.market.claim)
    ) {
      const listings = (props.market.listings ?? []).filter(
        (row) =>
          String(row.itemId) === String(item.id) &&
          String(row.itemType ?? row.itemKind ?? "item") ===
            String(item.kind ?? "item"),
      );
      return normalizeOrderBook({
        item,
        sellOrders: listings.filter((row) => row.side === "sell"),
        buyOrders: listings.filter((row) => row.side === "buy"),
      });
    }
    return null;
  }
  async function selectBook(row) {
    const request = ++lookupGeneration;
    const changed = selectedKey.value !== rowKey(row);
    const scope = resultScope();
    const retainedBook =
      !changed && selectedBookScope === scope ? selectedBook.value : null;
    selectedKey.value = rowKey(row);
    lookupWarning.value = "";
    if (changed) {
      popupOpen.value = false;
      detailsTab.value = "offers";
    }
    bookLoading.value = false;
    if (isBarter.value) {
      selectedBook.value = null;
      remember();
      return;
    }
    const cachedBook = knownBook(row);
    // Stored revisions refresh the same selection in place. Only a new item or
    // filter scope should replace its details with the initial loading state.
    selectedBook.value = cachedBook ?? retainedBook;
    selectedBookScope = scope;
    if (cachedBook) {
      if (detailsTab.value === "history" || popupOpen.value) loadHistory();
      remember();
      return;
    }
    bookLoading.value = true;
    try {
      const response = await bitcraftFetch(
        route("bitcraft.market.order-book", {
          ...payload(),
          itemId: row.id,
          itemKind: row.kind ?? "item",
          history: "0",
        }),
      );
      const data = await response.json();
      if (request !== lookupGeneration || scope !== resultScope() || destroyed)
        return;
      if (!response.ok || !data.orderBook)
        throw new Error(data.error || "Order details could not refresh.");
      const book = {
        ...data.orderBook,
        ...(retainedBook?.historyLoaded
          ? {
              history: retainedBook.history,
              historyDelayed: retainedBook.historyDelayed,
            }
          : {}),
      };
      rememberBook(`${scope}:${itemKey(row)}`, book);
      selectedBook.value = book;
      if (data.refresh?.delayed)
        lookupWarning.value =
          "Refresh delayed. Showing previously fetched orders.";
      if (detailsTab.value === "history" || popupOpen.value)
        await loadHistory();
    } catch (error) {
      if (request === lookupGeneration && !destroyed)
        lookupWarning.value =
          error.message || "Order details could not refresh.";
    } finally {
      if (request === lookupGeneration && !destroyed) bookLoading.value = false;
      remember();
    }
  }
  async function loadHistory() {
    if (isBarter.value || !selected() || selectedBook.value?.historyLoaded)
      return;
    const row = selected();
    const scope = resultScope();
    const request = ++lookupGeneration;
    bookLoading.value = true;
    try {
      const response = await bitcraftFetch(
        route("bitcraft.market.order-book", {
          ...payload(),
          itemId: row.id,
          itemKind: row.kind ?? "item",
        }),
      );
      const data = await response.json();
      if (request !== lookupGeneration || scope !== resultScope() || destroyed)
        return;
      if (!response.ok || !data.orderBook)
        throw new Error(data.error || "History could not refresh.");
      const book = {
        ...selectedBook.value,
        history: data.orderBook.history,
        historyDelayed: data.orderBook.historyDelayed,
        historyLoaded: true,
      };
      rememberBook(`${scope}:${itemKey(row)}`, book);
      selectedBook.value = book;
    } catch (error) {
      if (request === lookupGeneration && !destroyed)
        lookupWarning.value = error.message;
    } finally {
      if (request === lookupGeneration && !destroyed) bookLoading.value = false;
    }
  }
  watch(detailsTab, (tab) => {
    if (tab === "history") loadHistory();
  });

  function invalidate() {
    lookupGeneration++;
    bookLoading.value = false;
  }
  onScopeDispose(() => {
    destroyed = true;
    invalidate();
    books.clear();
  });
  return {
    selectedBook,
    bookLoading,
    lookupWarning,
    knownBook,
    selectBook,
    loadHistory,
    invalidate,
    clearBooks: () => {
      invalidate();
      books.clear();
    },
  };
}
