# Load proof results — 24 October 2026

One full local run on Windows, Intel Core i7-14700KF, approximately 64 GiB RAM,
Node 24.21.0, SpacetimeDB 2.12.0. The current WASM and optimized native test
driver were built with locked dependencies. Game rules and the production bot's
32-pending-game-request limit were unchanged. See [method](load-fishing.md).

Each row used as many independently linked website SDK subscribers as anglers.
The views were split between camp, inventory, records and angler directory.
Fresh anglers were used in every row, while the database/public directory grew
through the run. These are sequential observations, not controlled cold/warm
comparisons; the slower 100-user burst does not establish a scaling curve.

| Anglers / website clients | Arrival pattern | Accepted | Busy | Game p95 | Request-to-website p95 |
| ---: | --- | ---: | ---: | ---: | ---: |
| 25 | Simultaneous | 25 | 0 | 1,337 ms | 932 ms |
| 25 | 10 per second | 25 | 0 | 145 ms | 104 ms |
| 100 | Simultaneous | 32 | 68 | 3,181 ms | 2,855 ms |
| 100 | 10 per second | 100 | 0 | 22 ms | 23 ms |
| 250 | Simultaneous | 32 | 218 | 590 ms | 546 ms |
| 250 | 10 per second | 250 | 0 | 26 ms | 29 ms |

Across all 750 submitted commands: **464 accepted, 286 busy, zero unexpected game
errors**. Busy commands were explicitly rejected before casting. Every accepted
command reached its matching private website player view; inventory views
reconciled. No unexpected subscriber disconnects, portrait errors, artwork
fallbacks or cross-account results were observed. Three accepted commands per
scenario were replayed and each raced against two new cooldown requests; the
replays did not pay twice and new requests rejected correctly.

Final owner queries reconciled **464 lifetime casts, 464 command receipts and
464 journal pulls**, with zero duplicate journal keys. All participants used
fresh-account starting equipment; this run therefore does not prove advanced
multi-pull or mature-inventory capacity.

At the largest paced stage, artwork p95 was 60 ms, the measured game/portrait
path p95 was 26 ms, and request-to-website-update p95 was 29 ms (maximum 51 ms).
Native-driver observed working set peaked at 72.6 MiB; the shared local database
process at 345.4 MiB, including its existing dev database. The SDK load generator
peaked at 673.8 MiB, with approximately 25 ms event-loop p99. These short samples
are not a memory-leak or overnight-soak proof.

The largest measured game-request burst was still **32 accepted operations**.
The test demonstrates the configured admission protection and update fan-out;
it does not demonstrate acceptance of 250 simultaneous casts. A bounded waiting
queue or a changed cap would need its own design and test before increasing this
limit.

## Discord delivery comparison

A separate read-only production journal review covered the preceding 24 hours:

| Stage | Samples | Median | p95 | Maximum |
| --- | ---: | ---: | ---: | ---: |
| Acknowledgement | 414 | 230 ms | 419 ms | 2,975 ms |
| Game | 413 | 84 ms | 104 ms | 136 ms |
| Rendering | 388 | 85 ms | 165 ms | 205 ms |
| Card delivery | 388 | 1,300 ms | 3,055 ms | 4,562 ms |

No explicit rate-limit messages were found in that journal window. This does
not prove that the HTTP library never waited on a bucket internally. The single
slowest acknowledgement was close to Discord's three-second initial-response
deadline; it warrants continued timing observation, rather than assuming backend
capacity alone guarantees Discord delivery. [Discord interaction timing](https://docs.discord.com/developers/interactions/receiving-and-responding)

Discord's per-route limits are dynamic and should be handled through response
headers and retry information; this test did not send messages or attempt to
discover a live bucket ceiling. [Discord rate limits](https://docs.discord.com/developers/topics/rate-limits)

The load proof used synthetic 50 ms acknowledgement / 250 ms send delays, real
Rust client and artwork code, and real SDK subscription fan-out. It did **not**
run the Discord gateway/HTTP path or 250 rendered browser tabs. No
rendered-browser load result is claimed. Real production data,
identities and services were not changed. The proof database, transient web
listener and managed Rust build outputs were removed after testing.

Full latest machine-readable measurements are kept in the single ignored
`.local/load-test-results.json` file; future runs replace it.
