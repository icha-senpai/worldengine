import datetime
import base64
import hashlib
import contextlib
import io
import http.client
import http.server
import importlib.util
import json
import os
from pathlib import Path
import re
import subprocess
import socket
import tempfile
import threading
import time
import unittest
from unittest.mock import patch


def load(name):
    spec = importlib.util.spec_from_file_location(name, Path(__file__).parent / (name + '.py'))
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


tcp = load('connection-tcp')


class TcpTests(unittest.TestCase):
    def test_inbound_origin_reset_without_addresses(self):
        row = tcp.parse('1792717200.125 eth0 In IP 172.64.1.2.54321 > 5.189.187.85.443: Flags [R.], seq 0, length 0')
        self.assertEqual(row['direction'], 'inbound')
        self.assertEqual(row['peerPort'], 54321)
        self.assertNotIn('172.64', str(row))

    def test_database_fin_and_ipv6(self):
        row = tcp.parse('1792717200.125 lo In IP 127.0.0.1.3100 > 127.0.0.1.32100: Flags [F.], length 0')
        self.assertEqual(row['direction'], 'from-database')
        row = tcp.parse('1792717200.125 eth0 Out IP6 2001:db8::1.443 > 2001:db8::2.12345: Flags [F.], length 0', {'2001:db8::1'})
        self.assertEqual(row['family'], 6)
        self.assertEqual(row['direction'], 'outbound')

    def test_excludes_observer_outgoing_https_and_non_control_packets(self):
        self.assertIsNone(tcp.parse('1792717200.125 eth0 In IP 172.64.1.2.443 > 5.189.187.85.54321: Flags [R.], length 0'))
        self.assertIsNone(tcp.parse('1792717200.125 lo In IP 127.0.0.1.3100 > 127.0.0.1.32100: Flags [.], length 0'))


class ReportTests(unittest.TestCase):
    def test_matches_established_socket_instead_of_later_rejected_retry(self):
        report = load('connection-report')
        connection = 'a' * 32
        events = [
            {'type': 'browser-connection', 'at': '2026-10-23T01:00:00Z', 'event': 'bitcraft-disconnected', 'session': 'tab', 'connectionId': connection, 'closeCode': 1006},
            {'type': 'nginx-websocket', 'at': '2026-10-23T01:00:01Z', 'connectionId': connection, 'status': 101, 'endedAtMs': 1792717201000, 'peerPort': 54321},
            {'type': 'nginx-websocket', 'at': '2026-10-23T01:00:02Z', 'connectionId': connection, 'status': 409, 'endedAtMs': 1792717202000, 'peerPort': 9999},
            {'type': 'tcp-control', 'at': '2026-10-23T01:00:00Z', 'serverPort': 443, 'peerPort': 54321, 'direction': 'inbound', 'flag': 'reset'},
            {'type': 'connection-diagnostics-sample', 'at': '2026-10-23T00:59:40Z', 'observer': 'server', 'routes': [{'state': 'open'}]},
        ]
        class Journal:
            stdout = [json.dumps({'MESSAGE': json.dumps(row)}) for row in events] + [json.dumps({'_SYSTEMD_USER_UNIT': 'dataverse-space-db.service', 'MESSAGE': connection + ' session still held PRIVATE-IDENTITY'})]
            def wait(self):
                return 0
        output = io.StringIO()
        with patch.object(report.subprocess, 'Popen', return_value=Journal()), patch.object(report, 'ZoneInfo', return_value=datetime.timezone.utc), patch('sys.argv', ['connection-report.py']), contextlib.redirect_stdout(output):
            report.main()
        result = json.loads(output.getvalue().splitlines()[1])
        self.assertEqual(result['proxy']['status'], 101)
        self.assertEqual(result['tcpBoundary'][0]['flag'], 'reset')
        self.assertTrue(result['database']['sessionBusy'])
        self.assertEqual(len(result['independentSamples']), 1)
        self.assertNotIn('PRIVATE-IDENTITY', output.getvalue())


@unittest.skipUnless(os.name == 'posix' and Path('/etc/nginx/sites-available/dataverse-apps').exists(), 'Requires managed Linux NGINX site')
class ProxyTests(unittest.TestCase):
    def test_real_site_idempotency_restoration_and_nginx_syntax(self):
        installer = load('install-connection-diagnostics')
        before = Path('/etc/nginx/sites-available/dataverse-apps').read_text()
        until = datetime.datetime.now(datetime.timezone.utc) + datetime.timedelta(hours=72)
        after = installer.patch_site(before, until)
        self.assertEqual(installer.patch_site(after, until), after)
        self.assertEqual(after.count(installer.ACCESS), 6)
        self.assertIn('location ^~ /auth/discord/ {\n        access_log off;', after)
        restored = re.sub(r'    # BEGIN Dataverse connection diagnostics\n.*?    # END Dataverse connection diagnostics\n', '', after, flags=re.S)
        restored = restored.replace(installer.ROUTE_LOGGING, 'access_log off;')
        self.assertEqual(restored, before)
        with tempfile.TemporaryDirectory(prefix='dataverse-nginx-proof-') as tmp:
            directory = Path(tmp)
            site = after[after.index('server {\n    listen 443'):]
            site = re.sub(r'^    listen .*?;\n', '', site, flags=re.M)
            site = site.replace('server {\n', 'server {\n    listen 127.0.0.1:5198;\n')
            site = re.sub(r'^    ssl_.*?;\n', '', site, flags=re.M)
            config = directory / 'nginx.conf'
            config.write_text(f'pid {directory}/nginx.pid;\nerror_log stderr;\nevents {{}}\nhttp {{\naccess_log off;\nproxy_temp_path {directory}/proxy;\nclient_body_temp_path {directory}/body;\nmap $http_upgrade $dataverse_upgrade {{ default upgrade; "" close; }}\n' + installer.http_config(until) + site + '\n}')
            subprocess.run(['/usr/sbin/nginx', '-t', '-p', str(directory) + '/', '-c', str(config)], check=True)
            # Exercise a real upgrade through isolated NGINX, never a live database.
            class Upgrade(http.server.BaseHTTPRequestHandler):
                def do_GET(self):
                    self.protocol_version = 'HTTP/1.1'
                    key = self.headers.get('Sec-WebSocket-Key', '')
                    accept = base64.b64encode(hashlib.sha1((key + '258EAFA5-E914-47DA-95CA-C5AB0DC85B11').encode()).digest()).decode()
                    self.send_response(101)
                    self.send_header('Upgrade', 'websocket')
                    self.send_header('Connection', 'Upgrade')
                    self.send_header('Sec-WebSocket-Accept', accept)
                    self.end_headers()
                    self.wfile.write(b'\x88\x02\x03\xe8'); self.wfile.flush()
                    self.close_connection = True

                def log_message(self, *args):
                    pass

            upstream = http.server.ThreadingHTTPServer(('127.0.0.1', 0), Upgrade)
            worker = threading.Thread(target=upstream.serve_forever, daemon=True); worker.start()
            udp = socket.socket(socket.AF_INET, socket.SOCK_DGRAM); udp.bind(('127.0.0.1', 0)); udp.settimeout(5)
            available = socket.socket(); available.bind(('127.0.0.1', 0)); port = available.getsockname()[1]; available.close()
            text = config.read_text().replace('127.0.0.1:5198', f'127.0.0.1:{port}').replace('127.0.0.1:5514', f'127.0.0.1:{udp.getsockname()[1]}')
            text = text.replace('127.0.0.1:3100', f'127.0.0.1:{upstream.server_port}').replace('127.0.0.1:3127', f'127.0.0.1:{upstream.server_port}')
            config.write_text(text)
            nginx = subprocess.Popen(['/usr/sbin/nginx', '-p', str(directory) + '/', '-c', str(config), '-g', 'daemon off;'], stdout=subprocess.DEVNULL, stderr=subprocess.PIPE)
            try:
                for _ in range(50):
                    try:
                        check = socket.create_connection(('127.0.0.1', port), timeout=0.1); check.close(); break
                    except OSError:
                        time.sleep(0.05)
                client = http.client.HTTPConnection('127.0.0.1', port, timeout=5)
                client.request('GET', '/v1/database/space-bitcraft-tools/subscribe?connection_id=' + 'a' * 32 + '&token=DO-NOT-LOG', headers={'Host': 'bitcraft.ichaa.dev', 'Upgrade': 'websocket', 'Connection': 'Upgrade', 'Sec-WebSocket-Key': 'dGhlIHNhbXBsZSBub25jZQ==', 'Sec-WebSocket-Version': '13', 'CF-Ray': '123456789abcdef0-ORD'})
                response = client.getresponse()
                self.assertEqual(response.status, 101)
                self.assertRegex(response.getheader('X-Dataverse-Request'), r'^[a-f0-9]{32}$')
                client.close()
                raw = udp.recv(2048).decode()
                row = json.loads(raw[raw.index('{'):])
                self.assertEqual(row['connectionId'], 'a' * 32)
                self.assertEqual(row['cfRay'], '123456789abcdef0-ORD')
                self.assertEqual(row['status'], '101')
                self.assertNotIn('DO-NOT-LOG', raw)
                self.assertNotIn('127.0.0.1', raw)
            finally:
                nginx.terminate(); nginx.communicate(timeout=5)
                upstream.shutdown(); upstream.server_close(); worker.join(timeout=2); udp.close()


if __name__ == '__main__':
    unittest.main()
