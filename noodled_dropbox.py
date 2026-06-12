#!/usr/bin/env python3
"""
noodled drop folder -> auto-upload to noodled.ca

Watches a desktop folder. Any file dropped in gets uploaded to your noodled web
app as a new note (text files become the note body; everything else is attached),
then moved into _uploaded/. Rejected files go to _failed/ with a logged reason.

Setup:
  1. Copy noodled_dropbox.example.json -> noodled_dropbox.json (next to this file)
  2. In noodled: open the menu -> Web clipper, copy the token, paste it into the
     json along with your site URL.
  3. Run:  pythonw noodled_dropbox.py     (or noodled_dropbox.bat for no window)

No third-party deps beyond `requests` (already installed for the noodled app).
"""

import base64
import json
import os
import shutil
import sys
import time
from datetime import datetime
from pathlib import Path

try:
    import requests
except ImportError:
    print("This needs the 'requests' package:  pip install requests")
    sys.exit(1)

HERE = Path(__file__).resolve().parent
CONFIG_PATH = HERE / "noodled_dropbox.json"

# Files we never touch: our own subfolders, hidden/system files, and the
# half-written temp files browsers and copy operations leave behind.
SKIP_DIRS = {"_uploaded", "_failed"}
SKIP_SUFFIXES = (".tmp", ".crdownload", ".part", ".partial", ".download")


def load_config():
    if not CONFIG_PATH.exists():
        print(f"Missing config: {CONFIG_PATH}")
        print("Copy noodled_dropbox.example.json -> noodled_dropbox.json and fill it in.")
        sys.exit(1)
    cfg = json.loads(CONFIG_PATH.read_text(encoding="utf-8"))
    if not cfg.get("token") or not cfg.get("site"):
        print("Config needs both 'site' and 'token'. Get the token from noodled -> menu -> Web clipper.")
        sys.exit(1)
    folder = cfg.get("folder") or str(Path.home() / "Desktop" / "Noodled Drop")
    cfg["folder"] = os.path.expandvars(os.path.expanduser(folder))
    cfg.setdefault("notebook", "Inbox")
    cfg.setdefault("poll_seconds", 4)
    cfg["site"] = cfg["site"].rstrip("/")
    return cfg


def ensure_dirs(folder: Path):
    folder.mkdir(parents=True, exist_ok=True)
    (folder / "_uploaded").mkdir(exist_ok=True)
    (folder / "_failed").mkdir(exist_ok=True)


def log(folder: Path, msg: str):
    line = f"{datetime.now():%Y-%m-%d %H:%M:%S}  {msg}"
    print(line)
    try:
        with (folder / "_uploaded" / "_log.txt").open("a", encoding="utf-8") as f:
            f.write(line + "\n")
    except OSError:
        pass


def is_candidate(p: Path) -> bool:
    if not p.is_file():
        return False
    name = p.name
    if name.startswith(".") or name.startswith("~$"):
        return False
    if name.lower().endswith(SKIP_SUFFIXES):
        return False
    if name in ("noodled_dropbox.json", "noodled_dropbox.example.json"):
        return False
    return True


def move_aside(p: Path, dest_dir: Path):
    """Move a file into dest_dir, avoiding clobber with a timestamp suffix."""
    dest = dest_dir / p.name
    if dest.exists():
        stamp = datetime.now().strftime("%Y%m%d-%H%M%S")
        dest = dest_dir / f"{p.stem}_{stamp}{p.suffix}"
    try:
        shutil.move(str(p), str(dest))
    except OSError as e:
        return f"(could not move: {e})"
    return ""


def upload(cfg, p: Path) -> tuple:
    """Returns (status, message). status: 'ok' | 'reject' | 'retry'."""
    try:
        data = p.read_bytes()
    except OSError as e:
        return ("retry", f"read failed: {e}")
    b64 = base64.b64encode(data).decode("ascii")
    try:
        r = requests.post(
            cfg["site"] + "/wp-json/noodled/v1/clip/file",
            data={
                "token": cfg["token"],
                "filename": p.name,
                "notebook": cfg["notebook"],
                "data": b64,
            },
            timeout=60,
        )
    except requests.RequestException as e:
        return ("retry", f"network error: {e}")

    if r.status_code == 200:
        try:
            j = r.json()
        except ValueError:
            j = {}
        return ("ok", f"note #{j.get('id', '?')}{' (attached)' if j.get('attached') else ''}")

    # Pull the server's error message if there is one.
    try:
        err = r.json().get("error", "")
    except ValueError:
        err = (r.text or "")[:200]

    if r.status_code == 401:
        return ("retry", "bad token (fix 'token' in noodled_dropbox.json) — leaving file in place")
    if r.status_code == 429:
        return ("retry", "rate limited — will retry")
    if 400 <= r.status_code < 500:
        return ("reject", f"HTTP {r.status_code}: {err}")
    return ("retry", f"HTTP {r.status_code}: {err}")


def main():
    cfg = load_config()
    folder = Path(cfg["folder"])
    ensure_dirs(folder)
    log(folder, f"watching {folder}  ->  {cfg['site']}  (notebook: {cfg['notebook']})")

    last_sizes = {}   # path -> size seen on the previous poll (stable-size guard)
    poll = max(1, int(cfg.get("poll_seconds", 4)))

    while True:
        try:
            entries = [p for p in folder.iterdir() if p.name not in SKIP_DIRS and is_candidate(p)]
        except OSError:
            entries = []

        current = {}
        for p in entries:
            try:
                size = p.stat().st_size
            except OSError:
                continue
            current[p] = size
            # Only act once the size has settled (unchanged since last poll and
            # non-zero), so a still-copying file is never uploaded half-written.
            if size > 0 and last_sizes.get(p) == size:
                status, msg = upload(cfg, p)
                if status == "ok":
                    moved = move_aside(p, folder / "_uploaded")
                    log(folder, f"uploaded {p.name}  ->  {msg} {moved}".rstrip())
                elif status == "reject":
                    moved = move_aside(p, folder / "_failed")
                    log(folder, f"REJECTED {p.name}  ->  {msg}  -> _failed/ {moved}".rstrip())
                else:  # retry: leave in place
                    log(folder, f"retry {p.name}  ->  {msg}")

        last_sizes = current
        time.sleep(poll)


if __name__ == "__main__":
    try:
        main()
    except KeyboardInterrupt:
        pass
