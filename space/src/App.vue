<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from "vue";
import { useRoute } from "vue-router";
import { ChevronDown, ChevronLeft, Menu, X, LogOut } from "@lucide/vue";
import { localPlay, user, signOut } from "./auth";
import { evergather, revision } from "./evergather";
import SidebarIconRail from "./components/SidebarIconRail.vue";

const route = useRoute();
const home = computed(() => route.path === "/");
const widget = computed(() => route.path.startsWith('/bitcraft/') && (route.params.mode === 'widget' || route.query.setup === '0'));
const navKey = "space.shellNavState";
function readNavState() {
  try {
    return JSON.parse(localStorage.getItem(navKey) ?? "{}") as Record<
      string,
      unknown
    >;
  } catch {
    return {};
  }
}
const savedNav = readNavState();
const collapsed = ref(savedNav.collapsed === true);
const bitcraftOpen = ref(savedNav.bitcraftOpen !== false);
const mobileOpen = ref(false);
const mobileToggle = ref<HTMLButtonElement | null>(null);
const tools = [
  ["guides", "Guides"],
  ["market", "Market Finder"],
  ["barter-stalls", "Barter Stalls"],
  ["crafting", "Crafting Calculator"],
  ["open-crafts", "Open Crafts"],
  ["tool-rates", "Tool Rate Calculator"],
  ["hunting-calculator", "Hunting XP Calculator"],
  ["activity", "EXP Tracker"],
  ["inventory", "Inventory Tracker"],
  ["passive-crafts", "Passive Crafts"],
  ["tasks", "Task Tracker"],
];
const inBitcraft = computed(() => route.path.startsWith("/bitcraft"));
const inEvergather = computed(
  () => route.path.startsWith("/evergather") || route.path === "/auth/callback",
);
const activeLabel = computed(() =>
  inBitcraft.value
    ? (tools.find(([key]) => key === route.params.tool)?.[1] ??
      "Bitcraft Tools")
    : "Evergather",
);
const accountName = computed(() => {
  revision.value;
  const character = [...(evergather.value?.db.myPlayer.iter() ?? [])][0];
  return (
    character?.name ??
    (localPlay ? "Local play" : (user.value?.profile.name ?? "BitCraft tools"))
  );
});
const railItems = computed(() => [
  { key: "home", label: "Home", icon: "home", href: "/", active: false },
  {
    key: "bitcraft",
    label: "Bitcraft Tools",
    icon: "craft",
    active: inBitcraft.value,
    sections: [
      {
        key: "tools",
        links: tools.map(([key, label]) => ({
          key,
          label,
          href: `/bitcraft/${key}`,
          active: route.params.tool === key,
        })),
      },
    ],
  },
  {
    key: "evergather",
    label: "Evergather",
    icon: "gather",
    href: "/evergather",
    active: inEvergather.value,
  },
]);
watch([collapsed, bitcraftOpen], () => {
  try {
    localStorage.setItem(
      navKey,
      JSON.stringify({
        collapsed: collapsed.value,
        bitcraftOpen: bitcraftOpen.value,
      }),
    );
  } catch {
    /* Navigation remains usable when storage is unavailable. */
  }
});
watch(
  () => route.path,
  () => {
    mobileOpen.value = false;
  },
);
function escapeMobile(event: KeyboardEvent) {
  if (event.key === "Escape" && mobileOpen.value) {
    mobileOpen.value = false;
    mobileToggle.value?.focus();
  }
}
onMounted(() => document.addEventListener("keydown", escapeMobile));
onUnmounted(() => document.removeEventListener("keydown", escapeMobile));
</script>

<template>
  <RouterView v-if="home || widget" />
  <div v-else class="ichaa-shell">
    <header class="shell-mobile-header">
      <div class="shell-mobile-bar">
        <RouterLink to="/" class="desktop-shell-sidebar__wordmark"
          ><span class="wordmark-data">Data</span
          ><span class="wordmark-verse">verse</span></RouterLink
        >
        <div class="shell-mobile-current">
          <span>Current</span><strong>{{ activeLabel }}</strong>
        </div>
        <button
          ref="mobileToggle"
          class="shell-mobile-toggle"
          aria-label="Toggle navigation"
          aria-controls="mobile-navigation"
          :aria-expanded="mobileOpen"
          @click="mobileOpen = !mobileOpen"
        >
          <X v-if="mobileOpen" :size="18" /><Menu v-else :size="18" />
        </button>
      </div>
      <nav
        v-if="mobileOpen"
        id="mobile-navigation"
        class="shell-mobile-navigation"
        aria-label="Primary"
      >
        <p class="shell-section-label">Workspace</p>
        <button
          class="desktop-shell-nav__workspace"
          :class="{ active: inBitcraft }"
          :aria-expanded="bitcraftOpen"
          aria-controls="mobile-bitcraft-tools"
          @click="bitcraftOpen = !bitcraftOpen"
        >
          <span>Bitcraft Tools</span
          ><ChevronDown
            :size="14"
            class="desktop-shell-nav__chevron"
            :class="{ open: bitcraftOpen }"
          />
        </button>
        <div
          v-if="bitcraftOpen"
          id="mobile-bitcraft-tools"
          class="desktop-shell-nav__workspace-children"
        >
          <RouterLink
            v-for="[key, label] in tools"
            :key="key"
            :to="`/bitcraft/${key}`"
            class="desktop-shell-nav__child"
            :class="{ active: route.params.tool === key }"
            :aria-current="route.params.tool === key ? 'page' : undefined"
            @click="mobileOpen = false"
            >{{ label }}</RouterLink
          >
        </div>
        <RouterLink
          to="/evergather"
          class="desktop-shell-nav__workspace"
          :class="{ active: inEvergather }"
          :aria-current="inEvergather ? 'page' : undefined"
          @click="mobileOpen = false"
          >Evergather</RouterLink
        >
        <div class="desktop-shell-account">
          <p>{{ accountName }}</p>
          <button
            v-if="user && !localPlay"
            class="desktop-shell-account-link"
            @click="signOut"
          >
            Log Out
          </button>
        </div>
      </nav>
    </header>

    <aside
      class="desktop-shell-sidebar"
      :class="{ 'desktop-shell-sidebar--collapsed': collapsed }"
    >
      <div
        v-show="!collapsed"
        id="desktop-sidebar-content"
        class="desktop-shell-sidebar__content"
        :inert="collapsed || undefined"
      >
        <div class="desktop-shell-sidebar__brand">
          <RouterLink to="/" class="desktop-shell-sidebar__wordmark"
            ><span class="wordmark-data">Data</span
            ><span class="wordmark-verse">verse</span></RouterLink
          >
          <p class="desktop-shell-sidebar__current">{{ activeLabel }}</p>
        </div>
        <nav class="desktop-shell-nav" aria-label="Primary">
          <button
            class="desktop-shell-nav__workspace"
            :class="{ active: inBitcraft }"
            :aria-expanded="bitcraftOpen"
            aria-controls="desktop-bitcraft-tools"
            @click="bitcraftOpen = !bitcraftOpen"
          >
            <span>Bitcraft Tools</span
            ><ChevronDown
              :size="14"
              class="desktop-shell-nav__chevron"
              :class="{ open: bitcraftOpen }"
            />
          </button>
          <div
            v-if="bitcraftOpen"
            id="desktop-bitcraft-tools"
            class="desktop-shell-nav__workspace-children"
          >
            <RouterLink
              v-for="[key, label] in tools"
              :key="key"
              :to="`/bitcraft/${key}`"
              class="desktop-shell-nav__child"
              :class="{ active: route.params.tool === key }"
              :aria-current="route.params.tool === key ? 'page' : undefined"
              >{{ label }}</RouterLink
            >
          </div>
          <RouterLink
            to="/evergather"
            class="desktop-shell-nav__workspace"
            :class="{ active: inEvergather }"
            :aria-current="inEvergather ? 'page' : undefined"
            >Evergather</RouterLink
          >
        </nav>
        <div class="desktop-shell-sidebar__footer">
          <div class="desktop-shell-account">
            <p>{{ accountName }}</p>
            <div class="desktop-shell-account__links">
              <RouterLink to="/" class="desktop-shell-account-link"
                >Home</RouterLink
              >
              <button
                v-if="user && !localPlay"
                class="desktop-shell-account-link"
                @click="signOut"
              >
                Log Out
              </button>
              <RouterLink
                v-else
                to="/evergather"
                class="desktop-shell-account-link"
                >Evergather</RouterLink
              >
            </div>
          </div>
        </div>
      </div>
      <SidebarIconRail
        v-if="collapsed"
        :items="railItems"
        :current-path="route.path"
      />
      <button
        v-if="collapsed && user && !localPlay"
        class="shell-rail-account"
        aria-label="Log Out"
        title="Log Out"
        @click="signOut"
      >
        <LogOut :size="20" />
      </button>
      <button
        class="desktop-shell-sidebar__toggle"
        :aria-label="collapsed ? 'Expand sidebar' : 'Collapse sidebar'"
        :title="collapsed ? 'Expand sidebar' : 'Collapse sidebar'"
        :aria-expanded="!collapsed"
        aria-controls="desktop-sidebar-content"
        @click="collapsed = !collapsed"
      >
        <span
          class="desktop-shell-sidebar__grip"
          aria-hidden="true"
        /><ChevronLeft
          :size="16"
          class="desktop-shell-sidebar__toggle-chevron"
          aria-hidden="true"
        />
      </button>
    </aside>
    <main id="main-content" class="shell-main"><RouterView /></main>
  </div>
</template>
