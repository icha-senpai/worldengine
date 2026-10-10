"""Read-only badge transparency and framing checks (requires Pillow)."""
import json
from pathlib import Path
from PIL import Image

root = Path(__file__).resolve().parent.parent
plan = json.loads((root / "content/achievement-badge-art.json").read_text(encoding="utf-8"))
checked = []
missing = []
for asset in plan["assets"]:
    path = root / "assets" / asset["url"].lstrip("/")
    if not path.exists():
        missing.append(asset["achievementId"])
        continue
    with Image.open(path) as image:
        assert image.mode == "RGBA", f"{path.name}: RGBA required"
        assert image.width == image.height, f"{path.name}: square canvas required"
        alpha = image.getchannel("A")
        assert alpha.getextrema() == (0, 255), f"{path.name}: real transparency required"
        opaque = alpha.point(lambda value: 255 if value >= 128 else 0).getbbox()
        assert opaque, f"{path.name}: empty artwork"
        assert opaque[0] > 0 and opaque[1] > 0 and opaque[2] < image.width and opaque[3] < image.height, f"{path.name}: subject touches canvas edge"
        checked.append({"achievementId": asset["achievementId"], "size": image.size, "opaqueBounds": opaque})

report = {"checked": len(checked), "missing": missing, "assets": checked}
(root / ".local/achievement-badge-alpha-proof.json").write_text(json.dumps(report, indent=2), encoding="utf-8")
print(f"PASS {len(checked)} square RGBA badges with real transparency and complete subject margins; {len(missing)} missing.")
if "--complete" in __import__("sys").argv:
    assert not missing, "Finish every achievement badge before marking the art set complete"
