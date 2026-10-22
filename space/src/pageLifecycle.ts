import { createConnectionDiagnostics } from "../../shared/connection-diagnostics";

const diagnostics = createConnectionDiagnostics(
  "space",
  import.meta.env.VITE_DEPLOY_COMMIT ?? "development",
  "space.pageLifecycle",
);
export const recordConnectionLifecycle = diagnostics.failure;
export const recordPageLifecycle = diagnostics.record;
export function initializePageLifecycle() {
  const cleanup = diagnostics.initialize();
  import.meta.hot?.on("vite:beforeFullReload", () =>
    recordPageLifecycle("development-reload"),
  );
  import.meta.hot?.dispose(cleanup);
}
