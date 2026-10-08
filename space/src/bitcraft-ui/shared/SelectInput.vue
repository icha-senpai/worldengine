<script setup>
import {
  computed,
  nextTick,
  onBeforeUnmount,
  onMounted,
  ref,
  useAttrs,
  useId,
  useSlots,
  watch,
} from "vue";

defineOptions({ inheritAttrs: false });
const props = defineProps({
  modelValue: { default: undefined },
  value: { default: undefined },
  disabled: Boolean,
  name: String,
  modelModifiers: { type: Object, default: () => ({}) },
});
const emit = defineEmits(["update:modelValue", "change"]);
const attrs = useAttrs();
const slots = useSlots();
const id = useId();
const root = ref(null);
const trigger = ref(null);
const menu = ref(null);
const open = ref(false);
const activeIndex = ref(-1);
const label = ref("");
const menuStyle = ref({});
let search = "";
let searchAt = 0;

// Preserve the existing <option> API, including numeric and boolean values.
function textContent(nodes) {
  if (!Array.isArray(nodes)) return typeof nodes === "string" ? nodes : "";
  return nodes.map((node) => textContent(node.children)).join("");
}
function readOptions(nodes, result = []) {
  for (const node of nodes ?? []) {
    if (node.type === "option") {
      const text = textContent(node.children).trim().replace(/\s+/g, " ");
      result.push({
        value: Object.hasOwn(node.props ?? {}, "value")
          ? node.props.value
          : text,
        label: text,
        disabled: node.props?.disabled === "" || Boolean(node.props?.disabled),
      });
    } else if (Array.isArray(node.children)) readOptions(node.children, result);
  }
  return result;
}
// Refresh slot options during rendering; event handlers use that same snapshot.
const optionCache = { items: [] };
const currentValue = computed(() =>
  props.modelValue !== undefined ? props.modelValue : props.value,
);
function selectedIndex() {
  return optionCache.items.findIndex(
    (option) =>
      option.value === currentValue.value ||
      String(option.value) === String(currentValue.value),
  );
}
function selectedLabel() {
  optionCache.items = readOptions(slots.default?.());
  return optionCache.items[selectedIndex()]?.label ?? "Select an option";
}
function buttonAttrs() {
  return Object.fromEntries(
    Object.entries(attrs).filter(([key]) => key !== "class" && key !== "style"),
  );
}

function positionMenu() {
  if (!open.value || !trigger.value) return;
  const rect = trigger.value.getBoundingClientRect();
  const width = Math.min(Math.max(rect.width, 200), window.innerWidth - 24);
  const below = window.innerHeight - rect.bottom - 12;
  const above = rect.top - 12;
  const placeAbove = below < 180 && above > below;
  menuStyle.value = {
    width: `${width}px`,
    left: `${Math.max(12, Math.min(rect.left, window.innerWidth - width - 12))}px`,
    maxHeight: `${Math.max(60, Math.min(280, placeAbove ? above - 6 : below - 6))}px`,
    ...(placeAbove
      ? { bottom: `${window.innerHeight - rect.top + 6}px` }
      : { top: `${rect.bottom + 6}px` }),
  };
}
function revealActive() {
  nextTick(() =>
    document
      .getElementById(`${id}-option-${activeIndex.value}`)
      ?.scrollIntoView({ block: "nearest" }),
  );
}
function showMenu() {
  if (props.disabled) return;
  open.value = true;
  activeIndex.value =
    selectedIndex() >= 0 && !optionCache.items[selectedIndex()]?.disabled
      ? selectedIndex()
      : optionCache.items.findIndex((option) => !option.disabled);
  positionMenu();
  revealActive();
}
function closeMenu(restoreFocus = false) {
  open.value = false;
  search = "";
  if (restoreFocus) trigger.value?.focus();
}
function choose(index) {
  const option = optionCache.items[index];
  if (!option || option.disabled || props.disabled) return;
  let value = option.value;
  if (
    props.modelModifiers.number &&
    typeof value === "string" &&
    value !== "" &&
    Number.isFinite(Number(value))
  )
    value = Number(value);
  emit("update:modelValue", value);
  emit("change", { target: { value } });
  closeMenu(true);
}
function move(direction) {
  let index = activeIndex.value;
  for (let count = 0; count < optionCache.items.length; count++) {
    index =
      (index + direction + optionCache.items.length) % optionCache.items.length;
    if (!optionCache.items[index].disabled) {
      activeIndex.value = index;
      revealActive();
      return;
    }
  }
}
function onKeydown(event) {
  if (props.disabled) return;
  if (event.key === "Escape" && open.value) {
    event.preventDefault();
    event.stopPropagation();
    closeMenu(true);
  } else if (event.key === "Tab") closeMenu();
  else if (["Enter", " "].includes(event.key)) {
    event.preventDefault();
    if (open.value) choose(activeIndex.value);
    else showMenu();
  } else if (["ArrowDown", "ArrowUp", "Home", "End"].includes(event.key)) {
    event.preventDefault();
    if (!open.value) showMenu();
    else if (event.key === "ArrowDown" || event.key === "ArrowUp")
      move(event.key === "ArrowDown" ? 1 : -1);
    if (event.key === "Home" || event.key === "End") {
      const indexes = optionCache.items
        .map((option, index) => (!option.disabled ? index : -1))
        .filter((index) => index >= 0);
      activeIndex.value = event.key === "Home" ? indexes[0] : indexes.at(-1);
      revealActive();
    }
  } else if (
    event.key.length === 1 &&
    !event.ctrlKey &&
    !event.metaKey &&
    !event.altKey
  ) {
    event.preventDefault();
    if (!open.value) showMenu();
    search = Date.now() - searchAt > 700 ? event.key : search + event.key;
    searchAt = Date.now();
    const index = optionCache.items.findIndex(
      (option) =>
        !option.disabled &&
        option.label.toLowerCase().startsWith(search.toLowerCase()),
    );
    if (index >= 0) {
      activeIndex.value = index;
      revealActive();
    }
  }
}
function outsidePointer(event) {
  if (
    !root.value?.contains(event.target) &&
    !menu.value?.contains(event.target)
  )
    closeMenu();
}
watch(
  () => props.disabled,
  (disabled) => {
    if (disabled) closeMenu();
  },
);
onMounted(() => {
  const parentLabel = root.value?.closest("label");
  label.value = parentLabel
    ? Array.from(parentLabel.childNodes)
        .filter((node) => node !== root.value && node.nodeName !== "BUTTON")
        .map((node) => node.textContent)
        .join(" ")
        .trim()
        .replace(/\s+/g, " ")
    : "Select an option";
  if (attrs.autofocus !== undefined) trigger.value?.focus();
  document.addEventListener("pointerdown", outsidePointer);
  window.addEventListener("resize", positionMenu);
  window.addEventListener("scroll", positionMenu, true);
});
onBeforeUnmount(() => {
  document.removeEventListener("pointerdown", outsidePointer);
  window.removeEventListener("resize", positionMenu);
  window.removeEventListener("scroll", positionMenu, true);
});
defineExpose({ focus: (options) => trigger.value?.focus(options) });
</script>

<template>
  <div ref="root" class="app-select" :class="attrs.class" :style="attrs.style">
    <input
      v-if="name"
      type="hidden"
      :name="name"
      :value="currentValue"
      :disabled="disabled"
    />
    <button
      ref="trigger"
      v-bind="buttonAttrs()"
      type="button"
      class="app-select__trigger"
      role="combobox"
      :disabled="disabled"
      :aria-label="attrs['aria-label'] || label"
      :aria-expanded="open"
      aria-haspopup="listbox"
      :aria-controls="`${id}-listbox`"
      :aria-activedescendant="
        open && activeIndex >= 0 ? `${id}-option-${activeIndex}` : undefined
      "
      @click="open ? closeMenu() : showMenu()"
      @keydown="onKeydown"
    >
      <span class="app-select__value">{{ selectedLabel() }}</span>
      <svg
        class="app-select__chevron"
        :class="{ 'is-open': open }"
        viewBox="0 0 16 16"
        aria-hidden="true"
      >
        <path d="m4 6 4 4 4-4" />
      </svg>
    </button>
    <Teleport to="body">
      <div
        v-if="open"
        :id="`${id}-listbox`"
        ref="menu"
        class="app-select-menu"
        role="listbox"
        :aria-label="attrs['aria-label'] || label"
        :style="menuStyle"
        @mousedown.prevent
      >
        <div
          v-for="(option, index) in optionCache.items"
          :id="`${id}-option-${index}`"
          :key="index"
          class="app-select-menu__option"
          :class="{
            'is-active': activeIndex === index,
            'is-selected': selectedIndex() === index,
          }"
          role="option"
          :aria-selected="selectedIndex() === index"
          :aria-disabled="option.disabled"
          @pointermove="!option.disabled && (activeIndex = index)"
          @click="choose(index)"
        >
          <span>{{ option.label }}</span
          ><span v-if="selectedIndex() === index" aria-hidden="true">✓</span>
        </div>
        <p v-if="!optionCache.items.length" class="app-select-menu__empty">
          No options available
        </p>
      </div>
    </Teleport>
  </div>
</template>

<style scoped>
.app-select {
  min-width: 0;
  width: 100%;
}
.app-select.input {
  height: auto;
  padding: 0;
  border: 0;
  background: none;
  box-shadow: none;
}
.app-select__trigger {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  width: 100%;
  min-height: 40px;
  padding: 9px 12px;
  border: 1px solid var(--border-color);
  border-radius: 8px;
  background: var(--bg-surface);
  color: var(--text-primary);
  font: inherit;
  font-size: 13px;
  text-align: left;
  cursor: pointer;
}
.app-select__trigger:hover,
.app-select__trigger[aria-expanded="true"] {
  border-color: var(--accent-cyan);
}
.app-select__trigger:focus-visible {
  outline: 2px solid var(--accent-cyan);
  outline-offset: 2px;
}
.app-select__trigger:disabled {
  cursor: not-allowed;
  opacity: 0.5;
}
.app-select__value {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  color: inherit;
  font: inherit;
  text-transform: none;
}
.app-select__chevron {
  flex: 0 0 16px;
  width: 16px;
  height: 16px;
  fill: none;
  stroke: var(--accent-cyan);
  stroke-width: 1.5;
  transition: transform 0.15s;
}
.app-select__chevron.is-open {
  transform: rotate(180deg);
}
.app-select-menu {
  position: fixed;
  z-index: 2000;
  overflow-y: auto;
  overscroll-behavior: contain;
  padding: 5px;
  border: 1px solid var(--border-color-2);
  border-radius: 10px;
  background: var(--bg-surface);
  color: var(--text-primary);
  box-shadow: 0 12px 35px rgb(0 0 0 / 0.45);
  font-family: var(--font-ui);
  font-size: 13px;
}
.app-select-menu__option {
  display: flex;
  justify-content: space-between;
  gap: 12px;
  padding: 10px;
  border-radius: 6px;
  cursor: pointer;
  line-height: 1.4;
  overflow-wrap: anywhere;
}
.app-select-menu__option.is-active {
  background: var(--bg-surface-3);
  outline: 1px solid var(--border-color);
}
.app-select-menu__option.is-selected {
  color: var(--accent-cyan);
}
.app-select-menu__option[aria-disabled="true"] {
  opacity: 0.45;
  cursor: not-allowed;
}
.app-select-menu__empty {
  padding: 10px;
  margin: 0;
  color: var(--text-muted-3);
}
</style>
