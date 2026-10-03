import { createApp } from "vue";
import App from "./App.vue";
import { router } from "./router";
import { initializeAuth } from "./auth";
import { initializeEvergather } from "./evergather";
import "./style.css";
await initializeAuth();
initializeEvergather();
createApp(App).use(router).mount("#app");
