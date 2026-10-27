#!/usr/bin/env python3
"""Apply reviewed headers without rerunning bootstrap or interrupting gameplay."""
import argparse
import os
from pathlib import Path
import subprocess
from hosting_security import HEADER, DIRECTORY, MAP_PATH, DOMAINS, headers, http_config, patch_site


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--apply', action='store_true', help='Install configuration and reload NGINX')
    args = parser.parse_args()
    site = Path('/etc/nginx/sites-available/dataverse-apps')
    if site.is_symlink():
        raise RuntimeError('Linked site configuration refused')
    changes = {Path(MAP_PATH): http_config()}
    changes.update({Path(DIRECTORY) / f'dataverse-{d}-security.conf': headers(d) for d in DOMAINS})
    changes[site] = patch_site(site.read_text())
    originals = {}
    for path in changes:
        if path.is_symlink() or path.parent.is_symlink():
            raise RuntimeError(f'Linked configuration refused: {path}')
        originals[path] = path.read_text() if path.exists() else None
        if path != site and originals[path] is not None and not originals[path].startswith(HEADER):
            raise RuntimeError(f'Unmanaged configuration preserved: {path}')
    if not args.apply:
        print('Reviewed paths: ' + ', '.join(str(p) for p in changes))
        print('Use --apply with sudo to install headers and reload NGINX. No services or databases are restarted.')
        return
    if os.geteuid() != 0:
        raise SystemExit('Run the reviewed hardening command with sudo.')
    try:
        for path, text in changes.items():
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_text(text)
            path.chmod(0o644)
        subprocess.run(['nginx', '-t'], check=True)
        subprocess.run(['systemctl', 'reload', 'nginx'], check=True)
    except BaseException:
        for path, original in originals.items():
            if original is None:
                path.unlink(missing_ok=True)
            else:
                path.write_text(original)
        raise
    print('Security headers installed for Space and Fishbound; NGINX reloaded.')


if __name__ == '__main__':
    main()
