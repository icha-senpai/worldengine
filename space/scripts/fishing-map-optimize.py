"""Offline, lossless raster optimization. Requires Pillow with WebP support."""

import argparse
from concurrent.futures import ThreadPoolExecutor
from io import BytesIO
import hashlib
import json
from pathlib import Path
import time

from PIL import Image, features


def atomic_write(path, data):
    temporary = path.with_name(path.name + ".tmp")
    temporary.write_bytes(data)
    temporary.replace(path)


def optimize(source, destination=None):
    destination = destination or source.with_suffix(".webp")
    with Image.open(source) as opened:
        image = opened.convert("RGB")
    original = image.tobytes()
    if destination.exists():
        encoded = destination.read_bytes()
    else:
        output = BytesIO()
        image.save(output, format="WEBP", lossless=True, quality=100, method=4)
        encoded = output.getvalue()
    with Image.open(BytesIO(encoded)) as opened:
        if opened.size != image.size or opened.convert("RGB").tobytes() != original:
            raise ValueError(f"Lossless verification failed: {source}")
    if not destination.exists():
        atomic_write(destination, encoded)
    return source.stat().st_size, len(encoded)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--workers", type=int, default=4)
    args = parser.parse_args()
    if not features.check("webp"):
        raise RuntimeError("Pillow was built without WebP support")
    root = Path(__file__).resolve().parents[1] / "public/bitcraft-map/detail"
    published = json.loads((root / "manifest.json").read_text())
    directory = root / published["version"]
    manifest = json.loads((directory / "manifest.json").read_text())
    files, depth_bytes = [], 0
    for level in manifest["levels"]:
        folder = directory / f'l{level["level"]}'
        for x, z, digest in level["present"]:
            stem = f"{x}_{z}.{digest}"
            files.extend([folder / f"{stem}.png", folder / f"{stem}.depth.png"])
            if level["level"] == 0:
                depth_bytes += (folder / f"{stem}.depth.bin.gz").stat().st_size
    started = time.perf_counter()
    png_bytes = webp_bytes = 0
    with ThreadPoolExecutor(max_workers=max(1, min(args.workers, 8))) as executor:
        for count, (before, after) in enumerate(executor.map(optimize, files), 1):
            png_bytes += before
            webp_bytes += after
            if count % 200 == 0 or count == len(files):
                print(f"Verified {count}/{len(files)} lossless images", flush=True)
    overview_path = root.parents[2] / "output/research/world.webp"
    overview_path.parent.mkdir(parents=True, exist_ok=True)
    overview_png, overview_webp = optimize(root.parent / "world.png", overview_path)
    overview = overview_path.read_bytes()
    overview_name = f"world.{hashlib.sha256(overview).hexdigest()[:12]}.webp"
    if not (root.parent / overview_name).exists():
        atomic_write(root.parent / overview_name, overview)
    manifest.update(
        imageFormat="webp",
        overviewImage=overview_name,
        bytes=webp_bytes + depth_bytes,
    )
    manifest.pop("manifestFile", None)
    digest = hashlib.sha256(json.dumps(manifest, sort_keys=True).encode()).hexdigest()[:12]
    manifest["manifestFile"] = f"manifest.webp.{digest}.json"
    # Publish only after every image has been decoded and compared pixel for pixel.
    # The original immutable PNG manifest and assets remain for existing clients.
    payload = (json.dumps(manifest, indent=2) + "\n").encode()
    atomic_write(directory / manifest["manifestFile"], payload)
    atomic_write(root / "manifest.json", payload)
    report = {
        "snapshot": manifest["version"],
        "imagesVerified": len(files) + 1,
        "pngBytes": png_bytes,
        "webpBytes": webp_bytes,
        "depthBytes": depth_bytes,
        "overviewPngBytes": overview_png,
        "overviewWebpBytes": overview_webp,
        "imageReductionPercent": round((1 - webp_bytes / png_bytes) * 100, 2),
        "previousActiveBytes": png_bytes + depth_bytes + overview_png,
        "optimizedActiveBytes": webp_bytes + depth_bytes + overview_webp,
        "seconds": round(time.perf_counter() - started, 2),
    }
    report_path = root.parents[2] / "output/research/fishing-map-image-optimization.json"
    report_path.parent.mkdir(parents=True, exist_ok=True)
    atomic_write(report_path, (json.dumps(report, indent=2) + "\n").encode())
    print(json.dumps(report, indent=2), flush=True)


if __name__ == "__main__":
    main()
