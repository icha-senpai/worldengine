import * as env from "$app/env/public";
import { createConnectionDiagnostics } from "../../../../../../shared/connection-diagnostics";

export const connectionDiagnostics = createConnectionDiagnostics(
  "fishbound",
  Object.entries(env).find(([key]) => key === "PUBLIC_DEPLOY_COMMIT")?.[1] ??
    "development",
  "fishbound.pageLifecycle",
);
