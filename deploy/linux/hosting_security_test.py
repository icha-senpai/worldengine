import importlib.util
from pathlib import Path
import re
import unittest
from hosting_security import DOMAINS, headers, http_config, include, patch_site


def load(name):
    spec = importlib.util.spec_from_file_location(name, Path(__file__).parent / (name + '.py'))
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


class HostingSecurity(unittest.TestCase):
    def site(self):
        bootstrap = load('bootstrap-host')
        return bootstrap.HEADER + bootstrap.http_site(True) + ''.join(
            bootstrap.https_site(d, '''    location / { proxy_set_header X-Forwarded-Proto https; }
    location = /index.html { add_header Cache-Control "no-cache"; }
    location = /_connection-health {
        add_header X-Dataverse-Request $request_id always;
        return 204;
    }
''') for d in DOMAINS)

    def test_headers_survive_location_overrides_and_repeat_application(self):
        secured = patch_site(self.site())
        self.assertEqual(patch_site(secured), secured)
        for domain in DOMAINS:
            self.assertEqual(secured.count(include(domain)), 4)
            for header in ['Strict-Transport-Security', 'X-Content-Type-Options', 'X-Frame-Options', 'Referrer-Policy', 'Permissions-Policy', 'Content-Security-Policy']:
                self.assertIn('add_header ' + header, headers(domain))
            self.assertIn("script-src 'self'", headers(domain))
            self.assertIn("'unsafe-eval'", headers(domain))
        self.assertEqual(secured.count('proxy_set_header X-Real-IP $remote_addr;'), 2)
        self.assertIn('location / { return 301 https://$host$request_uri; }', secured)

    def test_only_standalone_space_widgets_can_be_framed(self):
        rule = re.search(r'~(\^/bitcraft/[^ ]+) "\*"', http_config())[1]
        for path in ['/bitcraft/activity/widget?profile=x', '/bitcraft/inventory/widget/', '/bitcraft/tasks/widget']:
            self.assertRegex(path, rule)
        for path in ['/evergather', '/bitcraft/guides/1/edit', '/bitcraft/tasks/setup', '/bitcraft/tasks/widget/evil']:
            self.assertNotRegex(path, rule)
        self.assertIn("frame-ancestors 'none'", headers(DOMAINS[1]))

    def test_unmanaged_or_missing_hosts_are_rejected(self):
        with self.assertRaises(RuntimeError):
            patch_site('server {}')
        with self.assertRaises(RuntimeError):
            patch_site(self.site().replace('server_name fishbound.ichaa.dev;', 'server_name unrelated.test;'))


if __name__ == '__main__':
    unittest.main()
