# Browser connection diagnostics

Space (BitCraft and Evergather) and Fishbound record connection events in their
browser tab and send batches to Fishbound's existing web service in production.
No new service, database table, Cloudflare setting or NGINX route is required.
This is event logging; it does not poll users continuously or record gameplay.

Events include the original UTC time, a random tab session, deployed Git revision,
connection channel, last accepted socket's random ConnectionId (to match database
service logs), close code when supplied by the SDK, safe error category,
retry attempt/delay, online state, tab visibility, and time until data is ready
again. Page reload, tab restore, visibility and online/offline events provide
context. Tokens, cookies, account/player IDs, URLs, raw error messages and
WebSocket close-reason text are omitted. These reports are untrusted observations.

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
