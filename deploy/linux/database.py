"""Private, temporary retained-row fingerprints for production module updates.

Stores hashes, column names and database identity, never player/config payloads.
Compatible additive columns/new rows are allowed; existing protected values may
not change. Release-specific metadata transformations are explicit in release.json.
"""
import collections
import hashlib
import json
import os
from pathlib import Path
import re
import sys
import tomllib
import urllib.request


def fingerprint(rows, positions):
    return collections.Counter(hashlib.sha256(json.dumps(
        [row[i] for i in positions], ensure_ascii=False, separators=(',', ':'),
        sort_keys=True).encode()).hexdigest() for row in rows)


def capture_table(data, omitted=()):
    columns = [element['name']['some'] for element in data['schema']['elements']]
    selected = [name for name in columns if name not in omitted]
    return {'columns': selected, 'rows': dict(fingerprint(
        data['rows'], [columns.index(name) for name in selected]))}


def verify_table(before, after, table):
    columns = [element['name']['some'] for element in after['schema']['elements']]
    if not set(before['columns']).issubset(columns):
        raise RuntimeError(table + ': a retained column was removed')
    actual = fingerprint(after['rows'], [columns.index(name) for name in before['columns']])
    missing = collections.Counter(before['rows']) - actual
    if missing:
        raise RuntimeError(table + ': existing retained records changed or disappeared')


def main():
    mode, key, output = sys.argv[1:]
    release = json.loads((Path(__file__).parent / 'release.json').read_text())
    module = next(x for x in release['modules'] if x['key'] == key)
    credentials = Path.home() / '.config/spacetime-production/cli.toml'
    token = tomllib.loads(credentials.read_text())['spacetimedb_token']
    base = f"http://127.0.0.1:{module['port']}/v1/database/{module['database']}"

    def sql(query):
        request = urllib.request.Request(base + '/sql', data=query.encode(),
            headers={'Authorization': 'Bearer ' + token, 'Content-Type': 'text/plain'})
        return json.load(urllib.request.urlopen(request, timeout=180))[0]

    metadata = json.load(urllib.request.urlopen(base, timeout=15))
    identity = metadata['database_identity']['__identity__'].removeprefix('0x')
    if identity != module['identity']:
        raise RuntimeError('Production database identity does not match the release target')
    views = {row[0] for row in sql('SELECT view_name FROM st_view')['rows']}
    names = sorted(row[0] for row in sql('SELECT table_name FROM st_table')['rows']
        if not row[0].startswith('st_') and row[0] not in views)
    tables = {}
    for name in names:
        if name in module['mutableTables']:
            continue
        if not re.fullmatch(r'[a-z_][a-z0-9_]*', name):
            raise RuntimeError('Unexpected table identifier')
        tables[name] = sql('SELECT * FROM ' + name)
    proof_path = Path(output)
    if proof_path.is_symlink():
        raise RuntimeError('Linked preservation proof refused')
    if mode == 'capture':
        if proof_path.exists():
            raise RuntimeError('An unfinished preservation proof must not be overwritten')
        proof = {'identity': identity, 'owner': metadata['owner_identity'], 'tables': {
            name: capture_table(data, module['mutableColumns'].get(name, []))
            for name, data in tables.items()}}
        with proof_path.open('x') as file:
            json.dump(proof, file)
        proof_path.chmod(0o600)
        print(module['database'], ': retained records fingerprinted')
    elif mode == 'verify':
        proof = json.loads(proof_path.read_text())
        if identity != proof['identity'] or metadata['owner_identity'] != proof['owner']:
            raise RuntimeError('Database identity or ownership changed')
        for name, before in proof['tables'].items():
            if name not in tables:
                raise RuntimeError(name + ': a protected table disappeared or changed classification')
            verify_table(before, tables[name], name)
        print(module['database'], ': PASS identity, ownership and', len(proof['tables']), 'retained tables')
    else:
        raise RuntimeError('Choose capture or verify')


if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        # HTTP errors omit response bodies, which may contain private payloads.
        print('Preservation check failed:', str(error), file=sys.stderr)
        sys.exit(1)
