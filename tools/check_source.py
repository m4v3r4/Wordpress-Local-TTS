#!/usr/bin/env python3
"""Fails on invisible or bidirectional control characters in source files ("Trojan Source",
CVE-2021-42574) and on stray non-ASCII whitespace. Write such characters as escapes (\\u200b).
Translation files and docs are allowed any text."""
import os
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
EXT = (".php", ".js", ".mjs", ".css", ".py", ".ps1", ".sh", ".yml", ".html")
BAD = set(range(0x200B, 0x2010)) | set(range(0x202A, 0x202F)) | set(range(0x2066, 0x206A)) | {0x00AD, 0x061C, 0xFEFF, 0x00A0}
SKIP_DIRS = {".git", "node_modules", "dist", "languages"}

problems = 0
for d, dirs, files in os.walk(ROOT):
    dirs[:] = [x for x in dirs if x not in SKIP_DIRS]
    for name in files:
        if not name.endswith(EXT):
            continue
        path = os.path.join(d, name)
        with open(path, encoding="utf-8") as f:
            for no, line in enumerate(f, 1):
                found = sorted({f"U+{ord(c):04X}" for c in line if ord(c) in BAD})
                if found:
                    problems += 1
                    print(f"{os.path.relpath(path, ROOT)}:{no}: {', '.join(found)}")
sys.exit(1 if problems else 0)
