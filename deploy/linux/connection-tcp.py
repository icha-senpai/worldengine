#!/usr/bin/env python3
"""Emit only TCP FIN/RST metadata. No packet files, IPs, headers or payload logs."""
import datetime
import json
import re
import socket
import subprocess
import sys
import time


def parse(line, local_addresses=None):
    local_addresses = local_addresses or {'127.0.0.1', '::1', '5.189.187.85'}
    match = re.search(r'^(\d+\.\d+) .*?\b(IP6?) (\S+) > (\S+): Flags \[([^]]+)\]', line)
    if not match:
        return None
    at, family, source, destination, flags = match.groups()
    if 'F' not in flags and 'R' not in flags:
        return None
    try:
        source_port = int(source.rsplit('.', 1)[1])
        destination_port = int(destination.rsplit('.', 1)[1])
    except (ValueError, IndexError):
        return None
    ports = {443, 3100, 3127}
    if source_port in ports and source.rsplit('.', 1)[0] in local_addresses:
        server_port, peer_port = source_port, destination_port
        direction = 'outbound' if server_port == 443 else 'from-database'
    elif destination_port in ports and destination.rsplit('.', 1)[0] in local_addresses:
        server_port, peer_port = destination_port, source_port
        direction = 'inbound' if server_port == 443 else 'to-database'
    else:
        return None
    return {'type': 'tcp-control', 'at': datetime.datetime.fromtimestamp(float(at), datetime.timezone.utc).isoformat(),
            'family': 6 if family == 'IP6' else 4, 'serverPort': server_port, 'peerPort': peer_port,
            'direction': direction, 'flag': 'reset' if 'R' in flags else 'fin'}


def main():
    until = datetime.datetime.fromisoformat(sys.argv[1].replace('Z', '+00:00')).timestamp()
    if time.time() >= until:
        return
    output = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
    addresses = json.loads(subprocess.check_output(['/usr/sbin/ip', '-j', 'address', 'show'], text=True))
    local_addresses = {entry['local'] for interface in addresses for entry in interface.get('addr_info', [])}
    command = ['/usr/bin/tcpdump', '-p', '-nn', '-tt', '-l', '-i', 'any', '-s', '128',
               'tcp and (port 443 or port 3100 or port 3127) and ((tcp[tcpflags] & (tcp-fin|tcp-rst) != 0) or ip6)']
    process = subprocess.Popen(command, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, text=True)
    import threading
    timer = threading.Timer(max(0, until - time.time()), process.terminate)
    timer.daemon = True
    timer.start()
    try:
        for line in process.stdout:
            row = parse(line, local_addresses)
            if row:
                output.sendto(json.dumps(row).encode(), ('127.0.0.1', 5514))
        code = process.wait()
        if code and time.time() < until - 2:
            raise RuntimeError('TCP metadata capture stopped; check capture permissions')
    finally:
        timer.cancel()
        if process.poll() is None:
            process.terminate()
        output.close()


if __name__ == '__main__':
    main()
