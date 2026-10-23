#!/usr/bin/env python3
"""Correlate browser failures, proxy connection records, TCP boundaries and probes."""
import argparse
import collections
import datetime
import json
import subprocess
from zoneinfo import ZoneInfo


def timestamp(row):
    try:
        return datetime.datetime.fromisoformat(row['at'].replace('Z', '+00:00')).timestamp()
    except (ValueError, KeyError, TypeError):
        return 0


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--since', default='30 minutes ago')
    parser.add_argument('--pc-log', help='Optional JSONL from the PC observer')
    args = parser.parse_args()
    command = ['journalctl', '--user', '--since', args.since, '-n', '50000', '--no-pager', '-o', 'json']
    for unit in ['dataverse-fish-web', 'dataverse-space-db', 'dataverse-fish-db', 'dataverse-connection-diagnostics']:
        command += ['-u', unit]
    process = subprocess.Popen(command, stdout=subprocess.PIPE, text=True)
    rows, database = [], []
    for line in process.stdout:
        try:
            journal = json.loads(line)
        except ValueError:
            continue
        message = journal.get('MESSAGE', '')
        try:
            row = json.loads(message)
        except (ValueError, TypeError):
            row = None
        if isinstance(row, dict) and row.get('type') in ['browser-connection', 'nginx-websocket', 'tcp-control', 'connection-probe-close', 'connection-probe-error', 'connection-probe-pong-timeout', 'connection-diagnostics-sample', 'connection-incident-health']:
            rows.append(row)
        elif journal.get('_SYSTEMD_USER_UNIT') in ['dataverse-space-db.service', 'dataverse-fish-db.service']:
            database.append(message)
    if process.wait():
        raise RuntimeError('Could not read the user journal')
    if args.pc_log:
        with open(args.pc_log) as file:
            for line in file:
                try:
                    row = json.loads(line)
                    if isinstance(row, dict) and isinstance(row.get('type'), str):
                        rows.append(row)
                except ValueError:
                    pass
    proxies = [r for r in rows if r['type'] == 'nginx-websocket' and r.get('connectionId')]
    incidents = [r for r in rows if r['type'] == 'browser-connection' and (r.get('event', '').endswith('-disconnected') or r.get('event', '').endswith('-connect-failed'))]
    print(json.dumps({'since': args.since, 'sources': dict(collections.Counter(r['type'] for r in rows)), 'browserFailures': len(incidents)}))
    for event in sorted(incidents, key=timestamp):
        at = timestamp(event)
        connection = event.get('connectionId')
        candidates = [r for r in proxies if r.get('connectionId') == connection]
        established = [r for r in candidates if r.get('status') == 101]
        proxy = min(established or candidates, key=lambda r: abs(timestamp(r) - at), default=None)
        matching = [m.lower() for m in database if connection and connection in m]
        nearby = [r for r in rows if abs(timestamp(r) - at) < 10]
        probes = [{k: r.get(k) for k in ['at', 'observer', 'type', 'channel', 'route', 'code', 'error', 'expected']} for r in nearby if r['type'].startswith('connection-probe-') and not r.get('expected')]
        tcp = [{k: r.get(k) for k in ['at', 'direction', 'flag', 'serverPort', 'peerPort']} for r in rows if r['type'] == 'tcp-control' and proxy and r.get('serverPort') == 443 and r.get('peerPort') == proxy.get('peerPort') and min(abs(timestamp(r) - at), abs(timestamp(r) - proxy['endedAtMs'] / 1000)) < 10]
        same_tab = [{k: r.get(k) for k in ['at', 'event', 'probeConnectionId', 'probeState', 'closeCode', 'wasClean', 'eventLoopLagMs', 'status', 'requestId', 'probeCfRay']} for r in nearby if r['type'] == 'browser-connection' and r.get('session') == event.get('session') and ('probe-' in r.get('event', '') or r.get('event') in ['page-frozen', 'page-resumed', 'browser-offline', 'browser-online'])]
        result = {k: event.get(k) for k in ['at', 'site', 'event', 'release', 'connectionId', 'reason', 'closeCode', 'visibility', 'eventLoopLagMs', 'health']}
        result['central'] = datetime.datetime.fromtimestamp(at, ZoneInfo('America/Chicago')).isoformat()
        result['proxy'] = proxy or 'No completed proxy record yet; refresh after proxy logging activation or wait for origin-side close'
        result['database'] = {'sessionBusy': any('busy' in m or 'still held' in m for m in matching), 'idleTimeout': any('timed out' in m for m in matching)}
        result['nearbyIndependentFailures'] = probes
        result['independentSamples'] = []
        for observer in ['server', 'pc']:
            samples = [r for r in rows if r.get('type') == 'connection-diagnostics-sample' and r.get('observer') == observer and abs(timestamp(r) - at) <= 90]
            nearest = min(samples, key=lambda r: abs(timestamp(r) - at), default=None)
            if nearest:
                result['independentSamples'].append(nearest)
        result['sameTabProbeAndLifecycle'] = same_tab
        result['tcpBoundary'] = tcp
        print(json.dumps(result))
    print('TCP matches use time and peer port; they are correlation evidence, not proof of which application initiated a close.')


if __name__ == '__main__':
    main()
