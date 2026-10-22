import { existsSync } from "node:fs";
import {
  createConnectionEventReceiver,
  type ConnectionHealth,
} from "../../../lib/server/connection-events";

let cached: { until: number; value: Promise<ConnectionHealth> } | undefined;
async function database(port: number, name: string) {
  try {
    const response = await fetch(
      `http://127.0.0.1:${port}/v1/database/${name}`,
      { signal: AbortSignal.timeout(1500) },
    );
    return response.ok ? "ok" : `http_${response.status}`;
  } catch {
    return "unreachable";
  }
}
function health() {
  if (!cached || cached.until < Date.now())
    cached = {
      until: Date.now() + 2000,
      value: Promise.all([
        database(3100, "space-bitcraft-tools"),
        database(3100, "space-evergather"),
        database(3127, "fishbound-dev-local"),
      ]).then(([space, evergather, fishbound]) => ({
        space,
        evergather,
        fishbound,
        maintenance: existsSync("/var/www/dataverse/.maintenance"),
      })),
    };
  return cached.value;
}
const receive = createConnectionEventReceiver({
  development: import.meta.env.DEV,
  health,
});
export const POST = ({ request }: { request: Request }) => receive(request);
export const OPTIONS = POST;
