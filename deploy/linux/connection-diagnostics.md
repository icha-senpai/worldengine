# Browser connection diagnostics

Space (BitCraft and Evergather) and Fishbound record connection events in their
browser tab and send batches to Fishbound's existing web service in production.
Baseline event reporting needs no extra service or database table. An optional,
72-hour transport capture adds independent probes and safe proxy/TCP metadata.
Neither mode records gameplay or sends account credentials to the probes.

Events include the original UTC time, a random tab session, deployed Git revision,
connection channel, last accepted socket's random ConnectionId (to match database
service logs), close code when supplied by the SDK, safe error category,
retry attempt/delay, online state, tab visibility, and time until data is ready
again. Page reload, tab restore, visibility and online/offline events provide
context. Tokens, cookies, account/player IDs, URLs, raw error messages and
WebSocket close-reason text are omitted. These reports are untrusted observations.

Browser `freeze` and `resume` events are recorded when the browser exposes them,
with the original timestamps retained across delayed uploads. Their absence does
not rule out suspension or tab discarding. A rejected reconnect whose previous
session is still closing is classified as `session_busy`; Fishbound reports failed
reconnect attempts separately from established-socket disconnects.

The receiver logs its receipt time, checks the three production database module
metadata endpoints over loopback, and adds the maintenance-flag state. These
checks describe receipt time, which may be later than a queued disconnect event.
The optional Cloudflare Ray ID belongs to the reporting HTTP request, not the
original WebSocket. An abnormal close such as 1006 alone does not identify which
network component interrupted the connection.

## Read the production logs

```sh
# Live reports from both sites (after deploying and reloading browser tabs):
journalctl --user -u dataverse-fish-web -f -o cat | grep '"type":"browser-connection"'

# Compare with both database services around an event's UTC timestamp:
journalctl --user -u dataverse-space-db -u dataverse-fish-db --since '30 minutes ago' -o short-iso

# Explicit updater maintenance windows:
journalctl -t dataverse-update --since '1 day ago' -o cat

# Storage maintenance and collector events:
tail -n 100 ~/dataverse/space/.runtime/diagnostics/space-bitcraft-tools/events.jsonl
```

Reports use the existing system journal's configured size/age retention; no new
archive is created. The operational journal is separate from the Fishbound catch
journal shown on the website. Database/service start and stop events help
distinguish runtime restarts from browser/network interruptions. Upstream relay
errors are a separate connection layer and remain in collector diagnostics.

## Browser evidence and limits

Fishbound, BitCraft and Evergather share freeze-aware connection lifecycle
handling. Healthy hidden tabs stay connected. A freeze or hidden transport loss
pauses the application connection and retries until the page becomes visible;
brief background resumes do not reopen it. Returning restores the same identity,
subscriptions and selected view. Last displayed data is retained and actions
remain blocked until fresh data is ready. An intentional freeze pause is recorded
by the page lifecycle events rather than a fabricated network-failure event.
The optional independent diagnostic sockets are separate from this application
lifecycle and can still report background probe failures.

The latest 200 safe events remain in `sessionStorage` under `space.pageLifecycle`
or `fishbound.pageLifecycle`. The pending-upload queue holds at most 100 events
and survives reloads in the same tab. Uploads contain at most ten events, omit
credentials, time out after five seconds, and retry pending reports at thirty
second intervals. Events older than one day are dropped. Development hosts keep
the local trail without automatic production uploads.

The endpoint accepts only the two known production origins (plus explicit local
origins in development), validates and strips fields, limits bodies to 16 KiB,
and caps traffic at 120 requests/minute globally and 12/minute per tab. Its session
state is capped at 256 entries, with thirty-minute expiry. Retransmitted event
sequences are deduplicated within that window. Local storage can be blocked or
lost when a tab closes; the trail is diagnostic evidence, not a guaranteed audit.

If Fishbound's web service or the public connection is unavailable, Space also
retains its reports locally and retries later. Missing reports do not prove a
healthy connection. Server retention, rate limits, suspended tabs, blocked storage
and browser extensions may all limit available evidence.

`dataverse-update` embeds the build revision and rebuilds both websites when the
shared diagnostic code changes. No database publication or native rebuild is
needed for this instrumentation. Refresh existing tabs after deployment so they
load the reporting code.

## Temporary transport capture

Run the reviewed installer as the deployment user first, then activate the
privileged layers in an interactive SSH terminal:

```sh
python3 deploy/linux/install-connection-diagnostics.py --user-service
sudo python3 deploy/linux/install-connection-diagnostics.py --apply
```

The installer previews its NGINX changes, validates the configuration before
reloading, and restores the previous configuration in memory if validation or
reload fails. It preserves application routing, authentication and timeouts.
It does not restart applications or databases. It refuses unmanaged targets.
The root-owned expiry timer removes capture routes/logging after 72 hours and
stops the diagnostic services. An early stop is:

```sh
sudo python3 /usr/local/lib/dataverse-connection-diagnostics/install-connection-diagnostics.py --disable
```

The independent service opens one unauthenticated, unsubscribed socket for each
of BitCraft, Evergather and Fishbound through public IPv4, public IPv6, direct
origin HTTPS and database loopback. TLS verification stays enabled. If a host
has no IPv6 route, that route is explicitly recorded as unavailable and excluded
from failure incidents until the next monitor start. Samples every minute show
socket state, ping/pong counts, RTT, reconnect count, host memory/load, cumulative
TCP counters and service restart state. On a failure it checks HTTP through both
Cloudflare and the origin. Protocol pings run every 30 seconds; these probes test
transport continuity, not subscriptions or gameplay correctness.

NGINX sends allowlisted JSON to loopback UDP, collected by the diagnostic service
in the existing bounded system journal. Records contain the actual socket's
random connection ID, original WebSocket CF-Ray, request ID, handshake status,
duration, upstream timing/byte counts and peer port. Full URLs, query strings,
IP addresses, cookies and authorization headers are excluded. These records
arrive when the origin request ends, possibly after the browser reports failure.
No broad access logging is enabled on credential-bearing routes.
Those routes also suppress NGINX's free-text error log during capture, because
it can include a token-bearing request URL. Structured status/timing records
remain available, including unsuccessful handshakes.

The TCP helper runs as the deployment user with only `CAP_NET_RAW`, reads tcpdump
text internally and emits only FIN/RST timestamps, direction, address family and
ports for owned server endpoints. No PCAP, packet body or address is written.
Capture output is rate limited at the receiver. A single reset is evidence of a
TCP boundary event; it does not by itself identify the original application cause.

After deploying the shared browser code, a page starts a separate native socket
only if `/_connection-health` advertises an active capture deadline. It records
raw close codes/cleanliness, frame counts/age and event-loop delay, with a bounded
HTTP control check after failures. This does not wrap or alter SDK sockets. A
successful native probe beside a failing SDK narrows the investigation toward
the SDK/session/subscription path; it does not prove which code is responsible.

For an independent PC observation, use the existing Node runtime:

```sh
node deploy/linux/connection-monitor.mjs --root /path/to/dataverse --pc --until 2026-10-26T01:50:00Z --output /path/to/dataverse/space/.runtime/connection-diagnostics/pc.jsonl
```

Choose a future UTC deadline no more than seven days away. The observer exits
at that deadline. Its JSONL rotates at 2 MB with two retained segments (roughly
6 MB total); it creates no dated archives. PC probes include both public address
families and direct origin, with no database-loopback route. They remain
independent of the browser, which helps separate browser-only behavior from a
machine/network-wide interruption.

Read a correlated incident report, without dumping raw identity-bearing DB logs:

```sh
python3 ~/.local/share/dataverse-connection-diagnostics/connection-report.py --since '30 minutes ago'
journalctl --user -u dataverse-connection-diagnostics -f -o cat
```

The report matches actual connection IDs, original handshake records, nearby
independent probe samples/failures, same-tab lifecycle events and matching TCP
peer ports. It limits each journal read to 50,000 records. A PC JSONL copied to a
temporary server location can be included with `--pc-log`; remove that transfer
after reading it. The probes and TCP watcher cannot see the browser-to-Cloudflare
TCP boundary. Browser-only low-level network errors may still need a short browser
NetLog capture if this evidence does not narrow the cause sufficiently.

The optional helper installation is separate from routine `dataverse-update`.
Do not rerun the base hosting bootstrap during an active capture; it regenerates
the managed site. Expiry leaves small managed helper/unit files for inspection
and reuse, but creates no backup or recovery archive. Staged installer copies
are disposable after activation; preserve the root-owned expiry helper until
the capture has been disabled.

## Compare routes and transport settings

`connection-route-test.mjs` runs a finite comparison against every advertised
Cloudflare address. It tests TLS 1.2/1.3, passive automatic pong replies and
15/30-second client pings, with direct-origin controls for both sites. It records
TCP errors such as `ECONNRESET` independently of the WebSocket close code, along
with event-loop delay and time since the last server ping. No authentication,
table subscriptions or gameplay calls are sent.

```sh
node deploy/linux/connection-route-test.mjs --root /path/to/dataverse --output /path/to/owned/route-test.jsonl --minutes 15
```

The output must not already exist. Use `--server` on the production host to
compare its IPv4 Cloudflare routes and loopback origin. That mode deliberately
omits IPv6 because this host has no outbound IPv6 route. Captures stop after
1–30 minutes, do not reconnect failed sockets, and write a bounded number of
metadata records. Keep one current evidence set; remove or replace it after
review rather than accumulating dated copies.

For the account-side audit, `inspect-cloudflare.ps1` prompts securely for a
temporary, zone-scoped read-only API token and saves only sanitized findings
under the existing PC diagnostic directory. It never saves the token or raw
request headers/rule expressions. Grant Zone Read, DNS Read, Zone Settings Read,
Analytics Read, Firewall Services Read, Zone WAF Read and Workers Routes Read
for `ichaa.dev` only. Unsupported plan features or insufficient permissions are
reported as incomplete checks, not empty/healthy results. GraphQL POST requests
contain queries only; this helper never changes Cloudflare configuration.

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File deploy/linux/inspect-cloudflare.ps1
```

Cloudflare raw per-request logs and termination details may require additional
plan/access capabilities. A successful settings audit cannot guarantee that
Cloudflare exposes the internal reason for a connection reset. Preserve original
Ray IDs and UTC timestamps for provider escalation. Direct-origin controls can
validate a potential bypass without changing public DNS or user sessions.
