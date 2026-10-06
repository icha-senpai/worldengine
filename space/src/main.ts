import { computed, createApp } from "vue";
import App from "./App.vue";
import { router } from "./router";
import { initializeAuth } from "./auth";
import { initializeEvergather } from "./evergather";
import { initializePageLifecycle } from "./pageLifecycle";
import "./style.css";
initializePageLifecycle();
await initializeAuth();
initializeEvergather(
  computed(() => router.currentRoute.value.path === "/evergather"),
);
createApp(App).use(router).mount("#app");
