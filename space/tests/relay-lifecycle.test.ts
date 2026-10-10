import { afterEach, expect, test, vi } from "vitest";
import pinned from "../scripts/relay-wire-contract.json";
const mocks = vi.hoisted(() => ({ sockets: [] as any[] }));
vi.mock("ws", async () => {
  const { EventEmitter } = await import("node:events");
  class Socket extends EventEmitter {
    pongs = true;
    options: any;
    constructor(_url: string, _protocol: string, options: any) {
      super();
      this.options = options;
      mocks.sockets.push(this);
    }
    addEventListener(name: string, callback: any) {
      this.on(name, callback);
    }
    send() {}
    ping(_data: any, _mask: any, callback: any) {
      if (this.pongs) this.emit("pong");
      callback();
    }
    terminate() {
      this.emit("close");
    }
  }
  return { default: Socket };
});
import { RelaySubscription, decodeRelayValue } from "../scripts/bitcraft-relay";
const relays: RelaySubscription[] = [];
function schemaType(type: any): any {
  if (type.Product)
    return {
      Product: {
        elements: type.Product.elements.map((e: any) => ({
          name: { some: e.name },
          algebraic_type: schemaType(e.type),
        })),
      },
    };
  if (type.Sum)
    return {
      Sum: {
        variants: type.Sum.variants.map((e: any) => ({
          name: { some: e.name },
          algebraic_type: schemaType(e.type),
        })),
      },
    };
  if (type.Array) return { Array: schemaType(type.Array) };
  return type;
}
async function relay() {
  vi.useFakeTimers();
  const definition = pinned.bitconnect.player_username_state;
  vi.stubGlobal(
    "fetch",
    vi.fn(async () => ({
      ok: true,
      json: async () => ({
        tables: [
          {
            name: "player_username_state",
            primary_key: definition.key,
            product_type_ref: 0,
          },
        ],
        typespace: { types: [schemaType(definition.type)] },
      }),
    })),
  );
  const failed = vi.fn(),
    changed = vi.fn();
  const subscription = new RelaySubscription(
    "bitconnect",
    8,
    ["SELECT * FROM player_username_state"],
    changed,
    failed,
  );
  relays.push(subscription);
  await subscription.start();
  const socket = mocks.sockets.at(-1)!;
  socket.emit("open");
  return { subscription, socket, failed, changed };
}
const initial = (socket: any, inserts: any[]) =>
  socket.emit("message", {
    data: JSON.stringify({
      InitialSubscription: {
        database_update: {
          tables: [
            {
              table_name: "player_username_state",
              updates: [{ inserts, deletes: [] }],
            },
          ],
        },
      },
    }),
  });
afterEach(() => {
  relays.splice(0).forEach((r) => r.stop());
  mocks.sockets.length = 0;
  vi.unstubAllGlobals();
  vi.useRealTimers();
});
test("a quiet relay remains ready when transport pongs arrive", async () => {
  const { subscription, socket, failed } = await relay();
  initial(socket, [["1", "Player"]]);
  await vi.advanceTimersByTimeAsync(120000);
  expect(subscription.ready).toBe(true);
  expect(failed).not.toHaveBeenCalled();
  expect(socket.options.maxPayload).toBe(64_000_000);
  expect(socket.options.perMessageDeflate).toBe(false);
});
test("missing pong disconnects and releases cached rows", async () => {
  const { subscription, socket, failed } = await relay();
  initial(socket, [["1", "Player"]]);
  socket.pongs = false;
  await vi.advanceTimersByTimeAsync(60000);
  expect(subscription.ready).toBe(false);
  expect(subscription.tables.size).toBe(0);
  expect(failed).toHaveBeenCalledWith("Relay transport heartbeat timed out.");
});
test("oversized operation batches are rejected before row decoding", async () => {
  const { subscription, socket, failed, changed } = await relay();
  initial(socket, Array(300001).fill(["invalid width"]));
  expect(failed).toHaveBeenCalledWith(
    "Relay frame exceeded its operation cap.",
  );
  expect(changed).not.toHaveBeenCalled();
  expect(subscription.tables.size).toBe(0);
});
test("a malformed transaction releases state without publishing a partial frame", async () => {
  const { subscription, socket, failed, changed } = await relay();
  initial(socket, [["1", "Player"]]);
  changed.mockClear();
  socket.emit("message", {
    data: JSON.stringify({
      TransactionUpdateLight: {
        database_update: {
          tables: [
            {
              table_name: "player_username_state",
              updates: [{ inserts: [["2", "Valid"], ["bad"]], deletes: [] }],
            },
          ],
        },
      },
    }),
  });
  expect(failed).toHaveBeenCalledWith("Relay product width changed.");
  expect(changed).not.toHaveBeenCalled();
  expect(subscription.tables.size).toBe(0);
});
test("stopping cancels health and retry timers", async () => {
  const { subscription, socket, failed } = await relay();
  initial(socket, [["1", "Player"]]);
  subscription.stop();
  await vi.advanceTimersByTimeAsync(120000);
  expect(failed).not.toHaveBeenCalled();
  expect(subscription.tables.size).toBe(0);
  expect(vi.getTimerCount()).toBe(0);
});

test("replacements and cancellations preserve correct row and memory accounting", async () => {
  const { subscription, socket } = await relay();
  initial(socket, [
    ["1", "First"],
    ["2", "Second"],
  ]);
  const before = (subscription as any).cachedBytes;
  socket.emit("message", {
    data: JSON.stringify({
      TransactionUpdateLight: {
        database_update: {
          tables: [
            {
              table_name: "player_username_state",
              updates: [
                {
                  deletes: [
                    ["1", "First"],
                    ["2", "Second"],
                  ],
                  inserts: [["1", "New"]],
                },
              ],
            },
          ],
        },
      },
    }),
  });
  expect([...subscription.tables.get("player_username_state")!.keys()]).toEqual(
    ["1"],
  );
  expect(
    subscription.tables.get("player_username_state")!.get("1")!.username,
  ).toBe("New");
  expect((subscription as any).cachedBytes).toBeLessThan(before);
  expect((subscription as any).rowBytes.size).toBe(1);
});

test("nested decoded values cannot exceed the shared frame budget", () => {
  expect(() =>
    decodeRelayValue(
      { Array: { Array: { U32: {} } } },
      [
        [1, 2],
        [3, 4],
      ],
      { remaining: 5 },
    ),
  ).toThrow("decoded node cap");
});
