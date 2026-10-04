import { createRouter, createWebHistory } from "vue-router";
import HomePage from "./pages/HomePage.vue";
import BitcraftPage from "./pages/BitcraftUiPage.vue";
const EvergatherPage = () => import("./pages/EvergatherPage.vue");
import { configureNavigation } from "./bitcraft-ui/navigation";
export const router = createRouter({
  history: createWebHistory(),
  routes: [
    { path: "/", component: HomePage },
    { path: "/bitcraft", redirect: "/bitcraft/market" },
    {
      path: "/bitcraft/guides/create",
      component: BitcraftPage,
      meta: { guideEditor: true },
    },
    {
      path: "/bitcraft/guides/:guide(\\d+)/edit",
      component: BitcraftPage,
      meta: { guideEditor: true },
    },
    { path: "/bitcraft/guides/:guide(\\d+)", component: BitcraftPage },
    {
      path: "/bitcraft/inventory-tracker/:mode?",
      redirect: (to) =>
        `/bitcraft/inventory${to.params.mode ? "/" + to.params.mode : ""}${to.fullPath.includes("?") ? "?" + to.fullPath.split("?")[1] : ""}`,
    },
    {
      path: "/bitcraft/task-tracker/:mode?",
      redirect: (to) =>
        `/bitcraft/tasks${to.params.mode ? "/" + to.params.mode : ""}${to.fullPath.includes("?") ? "?" + to.fullPath.split("?")[1] : ""}`,
    },
    {
      path: "/bitcraft/:tool(market|barter-stalls|crafting|tool-rates|hunting-calculator|open-crafts|activity|inventory|passive-crafts|tasks|guides)/:mode?",
      component: BitcraftPage,
    },
    { path: "/evergather", component: EvergatherPage },
    { path: "/auth/callback", component: EvergatherPage },
    { path: "/:pathMatch(.*)*", redirect: "/" },
  ],
});
configureNavigation(router);
