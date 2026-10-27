#!/usr/bin/env python3
"""Install a 72-hour diagnostic capture; preserve routing and application data."""
import argparse
import datetime
import difflib
import json
import os
from pathlib import Path
import pwd
import re
import shutil
import subprocess
from hosting_security import patch_site as patch_security

HEADER = '# Managed Dataverse connection diagnostics.\n'
ACCESS = 'access_log syslog:server=127.0.0.1:5514,tag=dataverse_ws,nohostname dataverse_socket;'
ROUTE_LOGGING = ACCESS + '\n        error_log /dev/null;\n        add_header X-Dataverse-Request $request_id always;'


def http_config(until):
    return HEADER + r'''map $uri $dataverse_diagnostic_channel {
    default "unknown";
    /v1/database/space-bitcraft-tools/subscribe "bitcraft";
    /v1/database/space-evergather/subscribe "evergather";
    /v1/database/fishbound-dev-local/subscribe "fishbound";
    ~^/v1/identity/websocket-token$ "auth";
    /_connection-health "check";
}
map "$host:$dataverse_diagnostic_channel" $dataverse_channel {
    default $dataverse_diagnostic_channel;
    "bitcraft.ichaa.dev:auth" "space-auth";
    "fishbound.ichaa.dev:auth" "fishbound-auth";
    "bitcraft.ichaa.dev:check" "space-check";
    "fishbound.ichaa.dev:check" "fishbound-check";
}
map $arg_connection_id $dataverse_socket_id {
    default "";
    "~^[a-f0-9]{32}$" $arg_connection_id;
}
map $http_cf_ray $dataverse_cf_ray {
    default "";
    "~^[a-f0-9]{8,32}-[A-Z]{3}$" $http_cf_ray;
}
log_format dataverse_socket escape=json
    '{"type":"nginx-websocket","endedAtSeconds":"$msec","channel":"$dataverse_channel",'
    '"connectionId":"$dataverse_socket_id","requestId":"$request_id","cfRay":"$dataverse_cf_ray",'
    '"status":"$status","upstreamStatus":"$upstream_status","durationSeconds":"$request_time",'
    '"upstreamConnectSeconds":"$upstream_connect_time","upstreamHeaderSeconds":"$upstream_header_time",'
    '"upstreamSeconds":"$upstream_response_time","bytesToClient":"$bytes_sent",'
    '"bytesFromUpstream":"$upstream_bytes_received","bytesToUpstream":"$upstream_bytes_sent",'
    '"nginxConnection":"$connection","peerPort":"$remote_port","completed":"$request_completion"}';
'''


def patch_site(text, until):
    if not text.startswith('# Managed Dataverse Linux hosting.\n'):
        raise RuntimeError('Unmanaged NGINX site preserved')
    if '# BEGIN Dataverse connection diagnostics' in text:
        text = re.sub(r'    # BEGIN Dataverse connection diagnostics\n.*?    # END Dataverse connection diagnostics\n', '', text, flags=re.S)
    health = '''    # BEGIN Dataverse connection diagnostics
    location = /_connection-health {
        %s
        add_header Cache-Control "no-store" always;
        add_header X-Dataverse-Request $request_id always;
        add_header X-Dataverse-Time $msec always;
        add_header X-Dataverse-Capture-Until "%s" always;
        return 204;
    }
    # END Dataverse connection diagnostics
''' % (ACCESS, until.isoformat())
    # Only the three owned, token-bearing locations. Never enable combined logs.
    for location in [r'location ~ \^/v1/database/\(space-bitcraft-tools\|space-evergather\)/subscribe\$ \{',
                     r'location = /v1/database/fishbound-dev-local/subscribe \{',
                     r'location = /v1/identity/websocket-token \{']:
        pattern = '(' + location + r'\n\s*)(?:access_log off;|' + re.escape(ACCESS) + r')(?:\n        error_log /dev/null;)?(?:\n        add_header X-Dataverse-Request \$request_id always;)*'
        text, count = re.subn(pattern, lambda m: m[1] + ROUTE_LOGGING, text)
        expected = 2 if 'websocket-token' in location else 1
        if count != expected:
            raise RuntimeError('Expected owned route not found: ' + location)
    # Remove duplicate diagnostic headers on repeat installs.
    text = re.sub(r'(        add_header X-Dataverse-Request \$request_id always;\n)\1+', r'\1', text)
    text, count = re.subn(r'(    if \(-f /var/www/dataverse/\.maintenance\) \{ return 503; \}\n)', lambda m: m[1] + health, text)
    if count != 2:
        raise RuntimeError('Expected exactly two HTTPS virtual hosts')
    # Preserve security headers in diagnostic locations with their own add_header.
    if '/etc/nginx/snippets/dataverse-' in text:
        text = patch_security(text)
    return text


def unit_environment(account):
    return dict(os.environ, XDG_RUNTIME_DIR=f'/run/user/{account.pw_uid}', DBUS_SESSION_BUS_ADDRESS=f'unix:path=/run/user/{account.pw_uid}/bus')


def user_run(account, *args):
    command = list(args)
    if os.geteuid() == 0:
        command = ['runuser', '-u', account.pw_name, '--', 'env',
                   f'XDG_RUNTIME_DIR=/run/user/{account.pw_uid}',
                   f'DBUS_SESSION_BUS_ADDRESS=unix:path=/run/user/{account.pw_uid}/bus'] + command
    subprocess.run(command, env=unit_environment(account), check=True)


def owned_file(path, text, account, mode=0o600):
    if path.is_symlink():
        raise RuntimeError('Linked diagnostic target refused: ' + str(path))
    if path.exists() and path.stat().st_uid != account.pw_uid:
        raise RuntimeError('Diagnostic target has another owner: ' + str(path))
    path.write_text(text)
    path.chmod(mode)
    if os.geteuid() == 0:
        os.chown(path, account.pw_uid, account.pw_gid)


def install_user(account, root, node, until):
    home = Path(account.pw_dir)
    active = home / '.local/share/dataverse-connection-diagnostics'
    if active.is_symlink():
        raise RuntimeError('Linked diagnostic installation refused')
    marker = active / '.managed'
    if active.exists() and (not marker.exists() or marker.read_text() != HEADER):
        raise RuntimeError('Unmanaged diagnostic installation preserved')
    active.mkdir(parents=True, exist_ok=True)
    if os.geteuid() == 0:
        os.chown(active, account.pw_uid, account.pw_gid)
    owned_file(marker, HEADER, account)
    source = Path(__file__).resolve().parent
    for name in ['connection-monitor.mjs', 'connection-report.py']:
        owned_file(active / name, (source / name).read_text(), account)
    unit = HEADER + f'''[Unit]
Description=Dataverse bounded connection diagnostics
After=network-online.target
[Service]
Type=simple
WorkingDirectory={root}
ExecStart={node} {active}/connection-monitor.mjs --root {root} --until {until.isoformat()}
Restart=on-failure
RestartSec=10
TimeoutStopSec=5
MemoryMax=256M
NoNewPrivileges=true
[Install]
WantedBy=default.target
'''
    path = home / '.config/systemd/user/dataverse-connection-diagnostics.service'
    if path.exists() and not path.read_text().startswith(HEADER):
        raise RuntimeError('Unmanaged service preserved')
    path.parent.mkdir(parents=True, exist_ok=True)
    owned_file(path, unit, account)
    user_run(account, 'systemctl', '--user', 'daemon-reload')
    user_run(account, 'systemctl', '--user', 'enable', 'dataverse-connection-diagnostics')
    user_run(account, 'systemctl', '--user', 'restart', 'dataverse-connection-diagnostics')
    user_run(account, 'systemctl', '--user', 'is-active', 'dataverse-connection-diagnostics')
    print('Independent transport monitor active; automatic capture deadline:', until.isoformat())


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--user', default='icha')
    parser.add_argument('--root', default='/home/icha/dataverse')
    parser.add_argument('--node', default='/home/icha/.nvm/versions/node/v24.21.0/bin/node')
    parser.add_argument('--apply', action='store_true')
    parser.add_argument('--user-service', action='store_true')
    parser.add_argument('--disable', action='store_true')
    args = parser.parse_args()
    account = pwd.getpwnam(args.user)
    if args.disable:
        if os.geteuid() != 0:
            raise RuntimeError('Removing proxy capture requires sudo')
        site = Path('/etc/nginx/sites-available/dataverse-apps')
        config = Path('/etc/nginx/conf.d/dataverse-connection-diagnostics.conf')
        if site.is_symlink() or config.is_symlink() or not site.read_text().startswith('# Managed Dataverse Linux hosting.'):
            raise RuntimeError('Unmanaged or linked NGINX configuration preserved')
        if config.exists() and not config.read_text().startswith(HEADER):
            raise RuntimeError('Unmanaged diagnostic config preserved')
        before = site.read_text()
        previous_config = config.read_text() if config.exists() else None
        after = re.sub(r'    # BEGIN Dataverse connection diagnostics\n.*?    # END Dataverse connection diagnostics\n', '', before, flags=re.S)
        after = after.replace(ROUTE_LOGGING, 'access_log off;')
        try:
            site.write_text(after)
            config.unlink(missing_ok=True)
            subprocess.run(['nginx', '-t'], check=True)
            subprocess.run(['systemctl', 'reload', 'nginx'], check=True)
        except Exception:
            site.write_text(before)
            if previous_config is not None:
                config.write_text(previous_config)
            subprocess.run(['nginx', '-t'], check=True)
            subprocess.run(['systemctl', 'reload', 'nginx'], check=True)
            raise
        user_run(account, 'systemctl', '--user', 'disable', '--now', 'dataverse-connection-diagnostics')
        subprocess.run(['systemctl', 'disable', '--now', 'dataverse-connection-tcp', 'dataverse-connection-diagnostics-expire.timer'], check=True)
        print('Diagnostic capture disabled; original routing and credential-safe access-log exclusions restored.')
        return
    root, node = Path(args.root).resolve(), Path(args.node).resolve()
    if not re.fullmatch(r'/[A-Za-z0-9_./-]+', str(root)) or not node.is_file() or not (root / 'space/node_modules/ws').exists():
        raise RuntimeError('Expected the existing checkout and Node runtime')
    until = datetime.datetime.now(datetime.timezone.utc) + datetime.timedelta(hours=72)
    if args.user_service:
        if os.geteuid() not in [0, account.pw_uid]:
            raise RuntimeError('Run as the deployment user')
        install_user(account, root, node, until)
        return
    site = Path('/etc/nginx/sites-available/dataverse-apps')
    config = Path('/etc/nginx/conf.d/dataverse-connection-diagnostics.conf')
    if site.is_symlink() or config.is_symlink():
        raise RuntimeError('Linked managed config refused')
    before = site.read_text()
    after = patch_site(before, until)
    print(''.join(difflib.unified_diff(before.splitlines(True), after.splitlines(True), fromfile=str(site), tofile=str(site))))
    if not args.apply:
        print('Preview only. Apply with sudo and --apply after review.')
        return
    if os.geteuid() != 0:
        raise RuntimeError('NGINX/TCP capture activation requires sudo')
    if config.exists() and not config.read_text().startswith(HEADER):
        raise RuntimeError('Unmanaged NGINX diagnostics preserved')
    previous_config = config.read_text() if config.exists() else None
    tcp_unit = Path('/etc/systemd/system/dataverse-connection-tcp.service')
    expiry_service = Path('/etc/systemd/system/dataverse-connection-diagnostics-expire.service')
    expiry_timer = Path('/etc/systemd/system/dataverse-connection-diagnostics-expire.timer')
    for path in [tcp_unit, expiry_service, expiry_timer]:
        if path.is_symlink() or (path.exists() and not path.read_text().startswith(HEADER)):
            raise RuntimeError('Unmanaged diagnostic unit preserved')
    if not shutil.which('tcpdump'):
        raise RuntimeError('tcpdump is required for TCP direction capture')
    install_user(account, root, node, until)
    active = Path('/usr/local/lib/dataverse-connection-diagnostics')
    marker = active / '.managed'
    if active.is_symlink() or (active.exists() and (active.stat().st_uid != 0 or marker.is_symlink() or not marker.exists() or marker.read_text() != HEADER)):
        raise RuntimeError('Unmanaged root helper directory preserved')
    active.mkdir(parents=True, exist_ok=True)
    active.chmod(0o755)
    (active / '.managed').write_text(HEADER)
    for name in ['connection-tcp.py', 'install-connection-diagnostics.py']:
        target = active / name
        if target.is_symlink():
            raise RuntimeError('Linked root helper refused')
        target.write_text((Path(__file__).resolve().parent / name).read_text())
        target.chmod(0o644)
    tcp_unit.write_text(HEADER + f'''[Unit]
Description=Dataverse TCP close/reset metadata capture
After=network-online.target
[Service]
Type=simple
User={account.pw_name}
ExecStart=/usr/bin/python3 {active}/connection-tcp.py {until.isoformat()}
AmbientCapabilities=CAP_NET_RAW
CapabilityBoundingSet=CAP_NET_RAW
NoNewPrivileges=true
ProtectSystem=strict
ProtectHome=read-only
PrivateTmp=true
Restart=on-failure
RestartSec=10
RuntimeMaxSec=72h
MemoryMax=64M
[Install]
WantedBy=multi-user.target
''')
    tcp_unit.chmod(0o644)
    subprocess.run(['systemctl', 'daemon-reload'], check=True)
    subprocess.run(['systemctl', 'enable', 'dataverse-connection-tcp'], check=True)
    subprocess.run(['systemctl', 'restart', 'dataverse-connection-tcp'], check=True)
    subprocess.run(['systemctl', 'is-active', 'dataverse-connection-tcp'], check=True)
    expiry_service.write_text(HEADER + f'''[Unit]
Description=Remove temporary Dataverse connection capture
[Service]
Type=oneshot
ExecStart=/usr/bin/python3 {active}/install-connection-diagnostics.py --user {account.pw_name} --disable
''')
    expiry_timer.write_text(HEADER + f'''[Unit]
Description=Expire Dataverse connection diagnostics after 72 hours
[Timer]
OnCalendar={until.strftime('%Y-%m-%d %H:%M:%S UTC')}
Persistent=true
Unit=dataverse-connection-diagnostics-expire.service
[Install]
WantedBy=timers.target
''')
    expiry_service.chmod(0o644); expiry_timer.chmod(0o644)
    subprocess.run(['systemctl', 'daemon-reload'], check=True)
    subprocess.run(['systemctl', 'enable', '--now', 'dataverse-connection-diagnostics-expire.timer'], check=True)
    try:
        config.write_text(http_config(until)); config.chmod(0o644)
        site.write_text(after); site.chmod(0o644)
        subprocess.run(['nginx', '-t'], check=True)
        subprocess.run(['systemctl', 'reload', 'nginx'], check=True)
    except Exception:
        site.write_text(before)
        if previous_config is None:
            config.unlink(missing_ok=True)
        else:
            config.write_text(previous_config)
        subprocess.run(['nginx', '-t'], check=True)
        subprocess.run(['systemctl', 'reload', 'nginx'], check=True)
        raise
    print('Proxy and TCP metadata capture active. No app/database restart was performed.')
    print('Capture automatically stops at', until.isoformat())


if __name__ == '__main__':
    main()
