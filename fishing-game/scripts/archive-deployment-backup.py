"""Compress a verified cold backup and check every archived file against its manifest."""
import hashlib
import json
from pathlib import Path
import sys
import zipfile

project = Path(__file__).resolve().parent.parent
deployment = Path(sys.argv[1]).resolve()
if deployment.parent != project / ".local" / "deployments":
    raise SystemExit("Backup must be directly inside this project's deployments directory")
cold = deployment / "cold-data"
manifest = json.loads((deployment / "cold-data-manifest.json").read_text(encoding="utf-8-sig"))
output = deployment / "cold-data.partial.zip"
if output.exists() or (deployment / "cold-data.zip").exists():
    raise SystemExit("Archive already exists; refusing to replace it")

def digest(stream):
    result = hashlib.sha256()
    for chunk in iter(lambda: stream.read(1024 * 1024), b""):
        result.update(chunk)
    return result.hexdigest()

names = []
with zipfile.ZipFile(output, "w", compression=zipfile.ZIP_DEFLATED, compresslevel=9, allowZip64=True) as archive:
    for entry in manifest:
        relative = entry["path"].replace("\\", "/")
        source = (cold / relative).resolve()
        if not source.is_relative_to(cold.resolve()) or not source.is_file():
            raise SystemExit("Invalid or missing backup file")
        names.append(relative)
        archive.write(source, relative)
    print(f"Compressed {deployment.name}; verifying {len(names)} archived files.", flush=True)

with zipfile.ZipFile(output) as archive:
    if sorted(archive.namelist()) != sorted(names):
        raise SystemExit("Archive file list does not match backup manifest")
    for entry, name in zip(manifest, names):
        with archive.open(name) as stream:
            if digest(stream).upper() != entry["sha256"].upper():
                raise SystemExit("Archived content differs from verified backup")
        if archive.getinfo(name).file_size != entry["bytes"]:
            raise SystemExit("Archived file size differs")
with output.open("rb") as stream:
    checksum = digest(stream)
report = {"verified": True, "files": len(names), "archiveSha256": checksum,
          "archiveBytes": output.stat().st_size, "originalBytes": sum(entry["bytes"] for entry in manifest)}
(deployment / "archive-verification.json").write_text(json.dumps(report, indent=2), encoding="utf-8")
print(json.dumps(report), flush=True)
