import { PUBLIC_DEPLOY_COMMIT } from "$app/env/public";
import { createConnectionDiagnostics } from "../../../../../../shared/connection-diagnostics";

export const connectionDiagnostics = createConnectionDiagnostics(
  "fishbound",
  PUBLIC_DEPLOY_COMMIT,
  "fishbound.pageLifecycle",
);
