<script setup>
import {
  computed,
  nextTick,
  onBeforeUnmount,
  ref,
  shallowRef,
  useId,
  watch,
} from "vue";
import SelectInput from "./SelectInput.vue";
import EmojiGlyph from "./EmojiGlyph.vue";
import {
  filterEmojis,
  loadEmojiCatalog,
  selectedEmojis,
  toggleEmoji,
} from "../emoji";

const props = defineProps({ modelValue: { type: String, default: "" } });
const emit = defineEmits(["update:modelValue"]);
const id = useId();
const trigger = ref(null),
  panel = ref(null),
  searchInput = ref(null),
  resultsPanel = ref(null);
const open = ref(false),
  loading = ref(false),
  error = ref(""),
  notice = ref("");
const catalog = shallowRef(null),
  query = ref(""),
  category = ref("all"),
  tone = ref("default"),
  recent = ref([]),
  page = ref(0);
const pageSize = 120;
const recentKey = "space.emoji.recent";
const selected = computed(() => selectedEmojis(props.modelValue));
const results = computed(() =>
  filterEmojis(catalog.value?.entries ?? [], {
    query: query.value,
    category: category.value,
    tone: tone.value,
    recent: recent.value,
  }),
);
const pages = computed(() =>
  Math.max(1, Math.ceil(results.value.length / pageSize)),
);
const visible = computed(() =>
  results.value.slice(page.value * pageSize, (page.value + 1) * pageSize),
);
const tones = [
  { value: "default", label: "Default skin tone" },
  { value: "all", label: "All skin tones" },
  { value: "🏻", label: "🏻 Light" },
  { value: "🏼", label: "🏼 Medium-light" },
  { value: "🏽", label: "🏽 Medium" },
  { value: "🏾", label: "🏾 Medium-dark" },
  { value: "🏿", label: "🏿 Dark" },
];

watch([query, category, tone], () => {
  page.value = 0;
});
watch(results, () => {
  page.value = Math.min(page.value, pages.value - 1);
});
watch(page, () => {
  resultsPanel.value?.scrollTo(0, 0);
});

function emojiName(emoji) {
  return (
    catalog.value?.entries.find((entry) => entry.emoji === emoji)?.name ?? emoji
  );
}
function choose(emoji) {
  const next = toggleEmoji(props.modelValue, emoji);
  if (next === null) {
    notice.value = "Your emoji space is full. Remove an emoji to add another.";
    return;
  }
  notice.value = "";
  if (!selected.value.includes(emoji)) {
    recent.value = [
      emoji,
      ...recent.value.filter((item) => item !== emoji),
    ].slice(0, 30);
    try {
      localStorage.setItem(recentKey, JSON.stringify(recent.value));
    } catch {
      /* Optional history. */
    }
  }
  emit("update:modelValue", next);
}
function clear() {
  notice.value = "";
  emit("update:modelValue", "");
}
async function load() {
  loading.value = true;
  error.value = "";
  try {
    catalog.value = await loadEmojiCatalog();
  } catch (failure) {
    error.value = failure.message;
  } finally {
    loading.value = false;
  }
}
async function show() {
  open.value = true;
  notice.value = "";
  try {
    const stored = JSON.parse(localStorage.getItem(recentKey) ?? "[]");
    recent.value = Array.isArray(stored)
      ? stored.filter((value) => typeof value === "string").slice(0, 30)
      : [];
  } catch {
    recent.value = [];
  }
  await nextTick();
  searchInput.value?.focus();
  if (!catalog.value) void load();
}
function close() {
  open.value = false;
  trigger.value?.focus();
}
function panelKey(event) {
  if (event.key === "Escape") {
    event.preventDefault();
    event.stopPropagation();
    close();
  }
  if (event.key !== "Tab") return;
  const controls = Array.from(
    panel.value.querySelectorAll('button, input, [tabindex="0"]'),
  ).filter((element) => !element.disabled && element.getClientRects().length);
  const first = controls[0],
    last = controls.at(-1);
  if (event.shiftKey && document.activeElement === first) {
    event.preventDefault();
    last?.focus();
  } else if (!event.shiftKey && document.activeElement === last) {
    event.preventDefault();
    first?.focus();
  }
}
function keepFocus(event) {
  if (
    open.value &&
    !panel.value?.contains(event.target) &&
    event.target.closest?.('[role="listbox"]') === null
  )
    searchInput.value?.focus();
}
watch(open, (value) => {
  if (value) document.addEventListener("focusin", keepFocus);
  else document.removeEventListener("focusin", keepFocus);
});
onBeforeUnmount(() => document.removeEventListener("focusin", keepFocus));
</script>

<template>
  <div class="emoji-input">
    <span class="emoji-input__label">Emojis</span>
    <div class="emoji-input__controls">
      <button
        ref="trigger"
        type="button"
        aria-haspopup="dialog"
        :aria-expanded="open"
        :aria-controls="`${id}-picker`"
        @click="show"
      >
        Add emojis <span aria-hidden="true">☺</span>
      </button>
      <button
        v-if="selected.length"
        type="button"
        aria-label="Clear emojis"
        @click="clear"
      >
        Clear
      </button>
    </div>
    <div
      v-if="selected.length"
      class="emoji-input__selected"
      aria-label="Selected emojis"
    >
      <button
        v-for="emoji in selected"
        :key="emoji"
        type="button"
        :aria-label="`Remove ${emojiName(emoji)}`"
        :title="`Remove ${emojiName(emoji)}`"
        @click="choose(emoji)"
      >
        <EmojiGlyph :emoji="emoji" /> <small aria-hidden="true">×</small>
      </button>
    </div>
    <Teleport to="body">
      <div v-if="open" class="emoji-picker-backdrop" @pointerdown.self="close">
        <section
          :id="`${id}-picker`"
          ref="panel"
          class="emoji-picker"
          role="dialog"
          aria-modal="true"
          aria-label="Choose emojis"
          @keydown="panelKey"
          @submit.prevent
        >
          <header>
            <div>
              <strong>Choose emojis</strong>
              <p>Find a little personality for your widget.</p>
            </div>
            <button
              type="button"
              aria-label="Close emoji picker"
              @click="close"
            >
              ✕
            </button>
          </header>
          <input
            ref="searchInput"
            v-model="query"
            type="search"
            aria-label="Search emojis"
            placeholder="Search names, animals, flags…"
            @keydown.enter.prevent
          />
          <div class="emoji-picker__filters">
            <SelectInput v-model="category" aria-label="Emoji category"
              ><option value="all">All categories</option>
              <option value="recent">Recently used</option>
              <option
                v-for="(group, index) in catalog?.groups ?? []"
                :key="group"
                :value="String(index)"
              >
                {{ group }}
              </option></SelectInput
            >
            <SelectInput v-model="tone" aria-label="Emoji skin tone"
              ><option
                v-for="option in tones"
                :key="option.value"
                :value="option.value"
              >
                {{ option.label }}
              </option></SelectInput
            >
          </div>
          <div
            v-if="selected.length"
            class="emoji-input__selected"
            aria-label="Selected emojis"
          >
            <button
              v-for="emoji in selected"
              :key="emoji"
              type="button"
              :aria-label="`Remove ${emojiName(emoji)}`"
              :title="`Remove ${emojiName(emoji)}`"
              @click="choose(emoji)"
            >
              <EmojiGlyph :emoji="emoji" />
              <small aria-hidden="true">×</small></button
            ><button type="button" aria-label="Clear emojis" @click="clear">
              Clear
            </button>
          </div>
          <p v-if="notice" class="emoji-picker__notice" role="status">
            {{ notice }}
          </p>
          <div
            ref="resultsPanel"
            class="emoji-picker__results"
            :aria-busy="loading"
          >
            <p v-if="loading" role="status">Loading emojis…</p>
            <div v-else-if="error" role="alert">
              <p>{{ error }}</p>
              <button type="button" @click="load">Try again</button>
            </div>
            <p v-else-if="!results.length">
              {{
                category === "recent" && !query
                  ? "Choose an emoji to start your recent list."
                  : "No emojis found. Try another search or skin tone."
              }}
            </p>
            <div v-else class="emoji-picker__grid" aria-label="Emoji results">
              <button
                v-for="entry in visible"
                :key="entry.emoji"
                type="button"
                :aria-label="entry.name"
                :title="entry.name"
                :aria-pressed="selected.includes(entry.emoji)"
                @click="choose(entry.emoji)"
              >
                <EmojiGlyph :emoji="entry.emoji" />
              </button>
            </div>
          </div>
          <footer>
            <span role="status"
              >{{ results.length.toLocaleString() }} emojis · {{ page + 1 }} /
              {{ pages }}</span
            >
            <div>
              <button
                type="button"
                aria-label="Previous emoji page"
                :disabled="page === 0"
                @click="page--"
              >
                ←</button
              ><button
                type="button"
                aria-label="Next emoji page"
                :disabled="page + 1 >= pages"
                @click="page++"
              >
                →
              </button>
            </div>
          </footer>
        </section>
      </div>
    </Teleport>
  </div>
</template>

<style scoped>
.emoji-input {
  display: grid;
  gap: 6px;
  min-width: 0;
}
.emoji-input__label {
  color: var(--text-muted-3);
  font-size: 10px;
  font-weight: 800;
  text-transform: uppercase;
}
.emoji-input__controls {
  display: flex;
  gap: 6px;
}
.emoji-input__controls > :first-child {
  flex: 1;
  display: flex;
  align-items: center;
  justify-content: space-between;
}
.emoji-input button,
.emoji-picker button {
  border: 1px solid var(--border-color);
  border-radius: 8px;
  background: var(--bg-surface-2);
  color: var(--text-primary);
  min-height: 36px;
  padding: 6px 10px;
  cursor: pointer;
}
.emoji-input button:hover,
.emoji-picker button:hover {
  border-color: var(--accent-cyan);
  background: var(--bg-surface-3);
}
.emoji-input button:focus-visible,
.emoji-picker button:focus-visible,
.emoji-picker input:focus-visible {
  outline: 2px solid var(--accent-cyan);
  outline-offset: 2px;
}
.emoji-input__selected {
  display: flex;
  flex-wrap: wrap;
  gap: 5px;
}
.emoji-input__selected button {
  font-family: "Segoe UI Emoji", "Apple Color Emoji", sans-serif;
  font-size: 20px;
}
.emoji-input__selected small {
  font-size: 12px;
  color: var(--text-muted);
}
.emoji-picker-backdrop {
  position: fixed;
  inset: 0;
  z-index: 1200;
  display: grid;
  place-items: center;
  padding: 12px;
  background: rgb(0 0 0 / 0.6);
}
.emoji-picker {
  display: flex;
  flex-direction: column;
  gap: 12px;
  width: min(560px, 100%);
  max-height: calc(100dvh - 24px);
  overflow-y: auto;
  padding: 18px;
  border: 1px solid var(--border-color);
  border-radius: 16px;
  background: var(--bg-surface);
  color: var(--text-primary);
  box-shadow: 0 20px 80px rgb(0 0 0 / 0.5);
}
.emoji-picker header,
.emoji-picker footer {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
}
.emoji-picker header p {
  margin: 3px 0 0;
  color: var(--text-muted-3);
  font-size: 12px;
}
.emoji-picker input {
  width: 100%;
  min-height: 40px;
  padding: 8px 12px;
  border: 1px solid var(--border-color);
  border-radius: 8px;
  background: var(--bg-canvas);
  color: var(--text-primary);
}
.emoji-picker__filters {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 8px;
}
.emoji-picker__results {
  min-height: 160px;
  height: min(320px, 40dvh);
  overflow-y: auto;
  flex-shrink: 0;
}
.emoji-picker__grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(44px, 1fr));
  gap: 4px;
}
.emoji-picker__grid button {
  padding: 4px;
  height: 44px;
  border-color: transparent;
  background: transparent;
  font:
    27px "Segoe UI Emoji",
    "Apple Color Emoji",
    sans-serif;
}
.emoji-picker__grid button[aria-pressed="true"] {
  border-color: var(--accent-cyan);
  background: var(--bg-surface-3);
}
.emoji-picker__notice {
  margin: 0;
  color: var(--text-muted-3);
  font-size: 13px;
}
.emoji-picker footer {
  font-size: 12px;
  color: var(--text-muted-3);
}
.emoji-picker footer > div {
  display: flex;
  gap: 6px;
}
.emoji-picker button:disabled {
  opacity: 0.4;
  cursor: default;
}
@media (max-width: 440px) {
  .emoji-picker {
    padding: 12px;
  }
  .emoji-picker__filters {
    grid-template-columns: 1fr;
  }
}
</style>
