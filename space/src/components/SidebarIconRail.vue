<script setup lang="ts">
import { nextTick, onBeforeUnmount, onMounted, ref, watch } from "vue";
import { RouterLink } from "vue-router";

interface RailLink {
  key: string;
  label: string;
  href: string;
  active?: boolean;
}
interface RailItem {
  key: string;
  label: string;
  icon: string;
  active?: boolean;
  href?: string;
  sections?: { key: string; label?: string; links: RailLink[] }[];
}
const props = withDefaults(
  defineProps<{
    items: RailItem[];
    footerItems?: RailItem[];
    currentPath: string;
  }>(),
  { footerItems: () => [] },
);

const rail = ref<HTMLElement | null>(null);
const openKey = ref<string | null>(null);
const flyoutTop = ref(12);
let activeTrigger: HTMLElement | null = null;

// Match the shell's existing outlined icon style without adding a dependency.
const icons: Record<string, string> = {
  home: "M3 10l9-7 9 7M5 9v12h5v-7h4v7h5V9",
  search: "M19 10a7 7 0 1 1-14 0 7 7 0 0 1 14 0M15 15l6 6",
  trash: "M3 6h18M9 6V3h6v3M5 6l1 15h12l1-15M10 10v7M14 10v7",
  world:
    "M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0M3 12h18M12 3c-5 5-5 13 0 18M12 3c5 5 5 13 0 18",
  craft: "M4 21l11-11M12 4l4-2 6 6-2 4-8-8M14 6l-3 3 4 4 3-3",
  gather:
    "M12 21V10M12 16C5 16 3 12 3 7c6 0 9 3 9 9M12 12c0-6 3-9 9-9 0 6-3 9-9 9",
  admin: "M12 3l8 3v6c0 5-8 9-8 9s-8-4-8-9V6l8-3M8 12l3 3 5-6",
  profile: "M16 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0M4 21v-2a8 8 0 0 1 16 0v2",
  logout: "M9 3H4v18h5M10 12h11M17 8l4 4-4 4",
};

function closeFlyout(restoreFocus = false) {
  openKey.value = null;
  if (restoreFocus) activeTrigger?.focus();
}

function toggleFlyout(item: RailItem, event: MouseEvent) {
  if (openKey.value === item.key) {
    closeFlyout();
    return;
  }

  activeTrigger = event.currentTarget as HTMLElement;
  flyoutTop.value = Math.max(
    12,
    Math.min(
      activeTrigger.getBoundingClientRect().top,
      window.innerHeight - 420,
    ),
  );
  openKey.value = item.key;
}

function onOutsidePointer(event: PointerEvent) {
  if (!(event.target instanceof Node) || !rail.value?.contains(event.target))
    closeFlyout();
}
function onFocusOut(event: FocusEvent) {
  if (
    !(event.relatedTarget instanceof Node) ||
    !(event.currentTarget as HTMLElement).contains(event.relatedTarget)
  )
    closeFlyout();
}

function onKeydown(event: KeyboardEvent) {
  if (event.key === "Escape" && openKey.value) {
    event.preventDefault();
    event.stopPropagation();
    closeFlyout(true);
  }
}

async function focusFlyout(item: RailItem, event: KeyboardEvent) {
  if (event.key !== "ArrowDown") return;
  event.preventDefault();
  if (openKey.value !== item.key) (event.currentTarget as HTMLElement).click();
  await nextTick();
  rail.value
    ?.querySelector<HTMLElement>(".sidebar-icon-rail__flyout a")
    ?.focus();
}

watch(
  () => props.currentPath,
  () => closeFlyout(),
);

onMounted(() => {
  document.addEventListener("pointerdown", onOutsidePointer);
  window.addEventListener("resize", closeOnResize);
});
onBeforeUnmount(() => {
  document.removeEventListener("pointerdown", onOutsidePointer);
  window.removeEventListener("resize", closeOnResize);
});

function closeOnResize() {
  closeFlyout();
}
</script>

<template>
  <nav
    ref="rail"
    class="sidebar-icon-rail"
    aria-label="Primary"
    @keydown="onKeydown"
    @focusout="onFocusOut"
  >
    <div class="sidebar-icon-rail__items">
      <div
        v-for="item in items"
        :key="item.key"
        class="sidebar-icon-rail__item"
      >
        <button
          v-if="item.sections"
          type="button"
          class="sidebar-icon-rail__control"
          :class="{ active: item.active, open: openKey === item.key }"
          :aria-label="item.label"
          :aria-describedby="`rail-tooltip-${item.key}`"
          :aria-expanded="openKey === item.key"
          :aria-controls="`rail-flyout-${item.key}`"
          @click="toggleFlyout(item, $event)"
          @keydown="focusFlyout(item, $event)"
        >
          <svg
            width="20"
            height="20"
            viewBox="0 0 24 24"
            fill="none"
            aria-hidden="true"
          >
            <path
              :d="icons[item.icon]"
              stroke="currentColor"
              stroke-width="1.6"
              stroke-linecap="round"
              stroke-linejoin="round"
            />
          </svg>
          <span class="sidebar-icon-rail__menu-dot" aria-hidden="true" />
        </button>
        <RouterLink
          v-else
          :to="item.href ?? '/'"
          class="sidebar-icon-rail__control"
          :class="{ active: item.active }"
          :aria-label="item.label"
          :aria-current="item.active ? 'page' : undefined"
          :aria-describedby="`rail-tooltip-${item.key}`"
        >
          <svg
            width="20"
            height="20"
            viewBox="0 0 24 24"
            fill="none"
            aria-hidden="true"
          >
            <path
              :d="icons[item.icon]"
              stroke="currentColor"
              stroke-width="1.6"
              stroke-linecap="round"
              stroke-linejoin="round"
            />
          </svg>
        </RouterLink>
        <span
          v-show="openKey !== item.key"
          :id="`rail-tooltip-${item.key}`"
          role="tooltip"
          class="sidebar-icon-rail__tooltip"
          >{{ item.label }}</span
        >
        <div
          v-if="openKey === item.key"
          :id="`rail-flyout-${item.key}`"
          class="sidebar-icon-rail__flyout"
          :style="{
            top: `${flyoutTop}px`,
            maxHeight: `calc(100vh - ${flyoutTop + 12}px)`,
          }"
          role="region"
          :aria-label="item.label"
        >
          <div class="sidebar-icon-rail__flyout-heading">{{ item.label }}</div>
          <div class="sidebar-icon-rail__flyout-links">
            <section v-for="section in item.sections" :key="section.key">
              <h3 v-if="section.label">{{ section.label }}</h3>
              <RouterLink
                v-for="link in section.links"
                :key="link.key"
                :to="link.href"
                class="desktop-shell-nav__child"
                :class="{ active: link.active }"
                :aria-current="link.active ? 'page' : undefined"
                @click="closeFlyout()"
                >{{ link.label }}</RouterLink
              >
            </section>
          </div>
        </div>
      </div>
    </div>
    <div class="sidebar-icon-rail__footer">
      <div
        v-for="item in footerItems"
        :key="item.key"
        class="sidebar-icon-rail__item"
      >
        <RouterLink
          :to="item.href ?? '/'"
          class="sidebar-icon-rail__control"
          :class="{ active: item.active }"
          :aria-label="item.label"
          :aria-current="item.active ? 'page' : undefined"
          :aria-describedby="`rail-tooltip-${item.key}`"
        >
          <svg
            width="20"
            height="20"
            viewBox="0 0 24 24"
            fill="none"
            aria-hidden="true"
          >
            <path
              :d="icons[item.icon]"
              stroke="currentColor"
              stroke-width="1.6"
              stroke-linecap="round"
              stroke-linejoin="round"
            />
          </svg>
        </RouterLink>
        <span
          :id="`rail-tooltip-${item.key}`"
          role="tooltip"
          class="sidebar-icon-rail__tooltip"
          >{{ item.label }}</span
        >
      </div>
    </div>
  </nav>
</template>

<style scoped>
.sidebar-icon-rail {
  display: flex;
  flex-direction: column;
  align-items: center;
  width: 63px;
  height: 100%;
  min-height: 0;
  padding: 16px 0;
}
.sidebar-icon-rail__items,
.sidebar-icon-rail__footer {
  display: flex;
  flex-direction: column;
  gap: 8px;
}
.sidebar-icon-rail__footer {
  margin-top: auto;
  padding-top: 14px;
  border-top: 1px solid var(--border-color);
}
.sidebar-icon-rail__item {
  position: relative;
}
.sidebar-icon-rail__control {
  position: relative;
  display: flex;
  align-items: center;
  justify-content: center;
  width: 40px;
  height: 40px;
  border: 1px solid transparent;
  border-radius: 6px;
  color: var(--text-muted-2);
  background: transparent;
  cursor: pointer;
}
.sidebar-icon-rail__control:hover,
.sidebar-icon-rail__control.open {
  color: var(--text-primary);
  background: var(--bg-surface-3);
  border-color: var(--border-color);
}
.sidebar-icon-rail__control.active {
  color: var(--accent-cyan);
  background: rgb(var(--accent-cyan-rgb) / 0.1);
  border-color: rgb(var(--accent-cyan-rgb) / 0.25);
}
.sidebar-icon-rail__control:focus-visible {
  outline: 2px solid var(--accent-cyan);
  outline-offset: 2px;
}
.sidebar-icon-rail__menu-dot {
  position: absolute;
  bottom: 5px;
  right: 5px;
  width: 3px;
  height: 3px;
  border-radius: 50%;
  background: currentColor;
}
.sidebar-icon-rail__tooltip {
  position: absolute;
  left: calc(100% + 16px);
  top: 50%;
  transform: translateY(-50%);
  z-index: 3;
  display: none;
  width: max-content;
  max-width: 240px;
  padding: 7px 10px;
  border: 1px solid var(--border-color);
  border-radius: 4px;
  background: var(--bg-surface-3);
  color: var(--text-primary);
  font-size: 12px;
  pointer-events: none;
  box-shadow: 0 4px 12px rgb(0 0 0 / 0.25);
}
.sidebar-icon-rail__control:hover + .sidebar-icon-rail__tooltip,
.sidebar-icon-rail__control:focus-visible + .sidebar-icon-rail__tooltip {
  display: block;
}
.sidebar-icon-rail__flyout {
  position: fixed;
  left: 72px;
  z-index: 4;
  display: flex;
  flex-direction: column;
  width: 280px;
  max-height: calc(100vh - 24px);
  border: 1px solid var(--border-color);
  border-radius: 6px;
  background: var(--bg-surface);
  color: var(--text-primary);
  box-shadow: 0 10px 32px rgb(0 0 0 / 0.4);
}
.sidebar-icon-rail__flyout-heading {
  flex-shrink: 0;
  padding: 12px 14px;
  border-bottom: 1px solid var(--border-color);
  font-size: 13px;
  font-weight: 600;
}
.sidebar-icon-rail__flyout-links {
  flex: 1;
  overflow-y: auto;
  min-height: 0;
  padding: 8px;
}
.sidebar-icon-rail__flyout-links section + section {
  margin-top: 12px;
}
.sidebar-icon-rail__flyout-links h3 {
  margin: 6px 10px;
  color: var(--text-muted-2);
  font-size: 11px;
  font-weight: 500;
}
.sidebar-icon-rail__flyout-links a {
  display: block;
  white-space: normal;
}
@media (max-height: 560px) {
  .sidebar-icon-rail {
    padding: 8px 0;
  }
  .sidebar-icon-rail__items,
  .sidebar-icon-rail__footer {
    gap: 2px;
  }
  .sidebar-icon-rail__control {
    height: 32px;
  }
  .sidebar-icon-rail__footer {
    padding-top: 8px;
  }
}
</style>
