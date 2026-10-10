"""Seed notification channels from retained casts; read-only database access.

Run while the bot is stopped. Python retains exact 64-bit Discord snowflakes.
Existing saved channels take precedence over older fishing receipts.
"""
import json
import re
import subprocess
import sys
from pathlib import Path

database = sys.argv[1] if len(sys.argv) == 2 else ""
if database != "fishbound-dev-local" and not re.fullmatch(r"fishbound-proof-[\w-]+", database):
    raise SystemExit("Specify a local Fishbound database.")
result = subprocess.run(
    ["spacetime", "sql", "--server", "http://127.0.0.1:3127", "--no-config",
     "--format", "json", database,
     "SELECT player_id, discord_user_id, channel_id, interaction_id FROM command_receipt WHERE command = 'fish'"],
    capture_output=True, text=True, check=True,
)
latest = {}
for block in json.loads(result.stdout):
    for player, user, channel, interaction in block["rows"]:
        if player > 0 and user > 0 and channel > 0:
            previous = latest.get(user)
            if previous is None or interaction > previous[0]:
                latest[user] = (interaction, {"player_id": player, "channel_id": channel})
path = Path(".local/achievement-notification-routes.json")
saved = json.loads(path.read_text(encoding="utf-8-sig")) if path.exists() else {}
if saved.get("database") != database:
    saved = {"database": database, "users": {}}
added = 0
for user, (_, route) in latest.items():
    if str(user) not in saved["users"]:
        saved["users"][str(user)] = route
        added += 1
path.parent.mkdir(parents=True, exist_ok=True)
temporary = path.with_suffix(".tmp")
temporary.write_text(json.dumps(saved, separators=(",", ":")), encoding="utf-8")
temporary.replace(path)
print(f"Saved {len(saved['users'])} achievement channel routes ({added} from retained casts). Database was only read.")
