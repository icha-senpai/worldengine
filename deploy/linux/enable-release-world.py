"""Allow only the additional BitCraft database's public subscription route."""
import argparse
from pathlib import Path
import subprocess

parser = argparse.ArgumentParser()
parser.add_argument('--apply', action='store_true')
args = parser.parse_args()
site = Path('/etc/nginx/sites-available/dataverse-apps')
if site.is_symlink() or not site.is_file():
    raise RuntimeError('Expected the existing regular managed site configuration.')
old = site.read_text()
previous = '(space-bitcraft-tools|space-evergather)/subscribe$'
current = '(space-bitcraft-tools|space-bitcraft-release|space-evergather)/subscribe$'
if current in old:
    print('Release-world route already enabled.')
elif old.count(previous) != 1:
    raise RuntimeError('Unexpected subscription allowlist; existing configuration preserved.')
elif not args.apply:
    print('Would add space-bitcraft-release to the existing subscription allowlist.')
else:
    site.write_text(old.replace(previous, current))
    try:
        subprocess.run(['nginx', '-t'], check=True)
        subprocess.run(['systemctl', 'reload', 'nginx'], check=True)
    except BaseException:
        site.write_text(old)
        raise
    print('Enabled the release-world subscription route.')
