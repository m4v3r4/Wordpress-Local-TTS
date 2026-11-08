#!/usr/bin/env python3
"""Builds the installable plugin ZIP: dist/local-tts-<version>.zip.

    python3 tools/build.py            # build
    python3 tools/build.py --check    # also fail if the version headers disagree (used by CI)
    python3 tools/build.py --tag v0.5.0   # also fail if the tag does not match the version

The ZIP contains one folder, local-tts/, so WordPress installs and updates it in place.
Voice models and runtimes are never part of it. Run `python3 tools/i18n.py build` first if
strings changed. tools/build.ps1 does the same on Windows without Python.
"""
import argparse
import os
import re
import sys
import zipfile

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
PLUGIN = os.path.join(ROOT, "local-tts")
DIST = os.path.join(ROOT, "dist")
INCLUDE = ["local-tts.php", "uninstall.php", "readme.txt", "LICENSE", "NOTICE",
           "assets", "includes", "languages", "licenses"]
SKIP = re.compile(r"(^|/)(\.DS_Store|Thumbs\.db|\.git\w*|.*\.(part|chunk|map|log))$")


def read(path):
    with open(path, encoding="utf-8") as f:
        return f.read()


def versions():
    main = read(os.path.join(PLUGIN, "local-tts.php"))
    readme = read(os.path.join(PLUGIN, "readme.txt"))
    found = {
        "Version header": re.search(r"^\s*\*\s*Version:\s*(\S+)", main, re.M),
        "LTTS_VERSION": re.search(r"define\( 'LTTS_VERSION', '([^']+)' \)", main),
        "readme Stable tag": re.search(r"^Stable tag:\s*(\S+)", readme, re.M),
    }
    missing = [k for k, m in found.items() if not m]
    if missing:
        sys.exit("not found: " + ", ".join(missing))
    return {k: m.group(1) for k, m in found.items()}


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--check", action="store_true")
    ap.add_argument("--tag", default="")
    args = ap.parse_args()

    v = versions()
    version = v["LTTS_VERSION"]
    if (args.check or args.tag) and len(set(v.values())) != 1:
        sys.exit("version mismatch: " + ", ".join(f"{k}={x}" for k, x in v.items()))
    if args.tag and args.tag.lstrip("vV") != version:
        sys.exit(f"tag {args.tag} does not match version {version}")

    os.makedirs(DIST, exist_ok=True)
    out = os.path.join(DIST, f"local-tts-{version}.zip")
    count = 0
    with zipfile.ZipFile(out, "w", zipfile.ZIP_DEFLATED, compresslevel=9) as z:
        for name in INCLUDE:
            path = os.path.join(PLUGIN, name)
            if not os.path.exists(path):
                sys.exit("missing: " + path)
            files = [path] if os.path.isfile(path) else sorted(
                os.path.join(d, f) for d, _, fs in os.walk(path) for f in fs)
            for f in files:
                rel = os.path.relpath(f, PLUGIN).replace(os.sep, "/")
                if SKIP.search(rel):
                    continue
                z.write(f, "local-tts/" + rel)
                count += 1
    print(f"{out} ({count} files, {os.path.getsize(out) / 1024:.0f} KB)")


if __name__ == "__main__":
    main()
