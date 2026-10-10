import { afterEach, describe, it, expect, vi } from "vitest";
import { subscribeMap } from "../src/bitcraft-ui/fishing-map-relay";

afterEach(() => {
  vi.useRealTimers();
  vi.unstubAllGlobals();
});
describe("Read-only fishing map subscription", () => {
  it("decodes lossless snapshots and changes, skips empty transactions and closes cleanly", async () => {
    vi.useFakeTimers();
    const names = ["resource_state", "location_state", "terrain_chunk_state"];
    vi.stubGlobal(
      "fetch",
      vi.fn(async () => ({
        ok: true,
        json: async () => ({
          tables: names.map((name, i) => ({ name, product_type_ref: i })),
          typespace: {
            types: names.map(() => ({
              Product: {
                elements: ["entity_id", "x"].map((name) => ({
                  name: { some: name },
                })),
              },
            })),
          },
        }),
      })),
    );
    const sockets = [];
    class Socket {
      constructor() {
        sockets.push(this);
      }
      send = vi.fn();
      close = vi.fn();
      emit(message) {
        this.onmessage({ data: JSON.stringify(message) });
      }
    }
    vi.stubGlobal("WebSocket", Socket);
    const rows = vi.fn(),
      status = vi.fn();
    const stop = subscribeMap(8, ["SELECT * FROM location_state"], {
      rows,
      status,
    });
    await vi.advanceTimersByTimeAsync(0);
    const socket = sockets[0];
    socket.emit({ IdentityToken: {} });
    expect(
      JSON.parse(socket.send.mock.calls[0][0]).Subscribe.query_strings,
    ).toEqual(["SELECT * FROM location_state"]);
    const changes = (inserts) => ({
      tables: [{ table_name: "location_state", updates: [{ inserts }] }],
    });
    socket.emit({
      InitialSubscription: {
        database_update: changes(["[1297036692719788627,20768]"]),
      },
    });
    expect(rows).toHaveBeenLastCalledWith(
      [
        {
          table: "location_state",
          action: "inserts",
          row: { entity_id: "1297036692719788627", x: 20768 },
        },
      ],
      true,
    );
    socket.emit({
      TransactionUpdate: { status: { Committed: { tables: [] } } },
    });
    expect(rows).toHaveBeenCalledTimes(1);
    socket.emit({
      TransactionUpdateLight: {
        database_update: changes([
          '{"entity_id":1297036692719788628,"x":20769}',
        ]),
      },
    });
    expect(rows.mock.calls[1][0][0].row.entity_id).toBe("1297036692719788628");
    socket.emit({
      TransactionUpdate: {
        status: {
          Committed: {
            tables: [
              {
                table_name: "location_state",
                updates: [{ deletes: ["[1297036692719788627,20768]"] }],
              },
            ],
          },
        },
      },
    });
    expect(rows.mock.calls[2][0][0].action).toBe("deletes");
    socket.readyState = 1;
    stop.replace([
      "SELECT * FROM terrain_chunk_state WHERE chunk_index = 119217",
    ]);
    expect(sockets).toHaveLength(1);
    expect(
      JSON.parse(socket.send.mock.calls.at(-1)[0]).Subscribe.request_id,
    ).toBe(2);
    socket.emit({
      InitialSubscription: {
        request_id: 1,
        database_update: changes(["[2,123]"]),
      },
    });
    expect(rows).toHaveBeenCalledTimes(3);
    socket.emit({
      InitialSubscription: {
        request_id: 2,
        database_update: changes(["[3,456]"]),
      },
    });
    expect(rows).toHaveBeenCalledTimes(4);
    stop.replace([]);
    expect(
      JSON.parse(socket.send.mock.calls.at(-1)[0]).Subscribe.query_strings,
    ).toEqual([]);
    stop();
    expect(socket.close).toHaveBeenCalledOnce();
    await vi.advanceTimersByTimeAsync(30000);
    expect(sockets).toHaveLength(1);
    expect(status).toHaveBeenCalledWith("live");
  });
  it("runs decoding in a worker and stops accepting messages after termination", () => {
    const workers = [];
    class WorkerStub {
      constructor() {
        workers.push(this);
      }
      postMessage = vi.fn();
      terminate = vi.fn();
    }
    vi.stubGlobal("Worker", WorkerStub);
    const rows = vi.fn(),
      status = vi.fn();
    const stop = subscribeMap(9, ["SELECT * FROM location_state"], {
      rows,
      status,
    });
    expect(workers).toHaveLength(1);
    const worker = workers[0];
    expect(worker.postMessage).toHaveBeenCalledWith({
      type: "start",
      region: 9,
      queries: ["SELECT * FROM location_state"],
    });
    stop.replace([]);
    expect(worker.postMessage).toHaveBeenLastCalledWith({
      type: "queries",
      queries: [],
    });
    worker.onmessage({ data: { type: "rows", changes: [], initial: true } });
    expect(rows).toHaveBeenCalledOnce();
    stop();
    expect(worker.terminate).toHaveBeenCalledOnce();
    worker.onmessage({ data: { type: "rows", changes: [], initial: false } });
    expect(rows).toHaveBeenCalledOnce();
  });
});
