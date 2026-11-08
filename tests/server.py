#!/usr/bin/env python3
"""Fixture HTTP server for the PHP tests.

    python3 tests/server.py <root> [port]

Serves files under <root> at /<mode>/<path>, where <mode> imitates a kind of server:
  range    supports Range requests (206)
  norange  ignores Range and always sends the whole file (200)
  bad      supports Range but serves corrupted bytes
  dead     always 503
  gzforce  gzip-compresses the file and serves ranges of the compressed bytes, whatever the
           client accepts (a misbehaving CDN)
/hfapi/<dir> answers like the Hugging Face tree API for <root>/hf/<dir>: LFS entries (*.onnx)
carry a SHA-256, other files a git blob SHA-1.
"""
import gzip
import hashlib
import http.server
import json
import os
import re
import sys

ROOT = os.path.abspath(sys.argv[1])
PORT = int(sys.argv[2]) if len(sys.argv) > 2 else 8899


class Handler(http.server.BaseHTTPRequestHandler):
    def log_message(self, *args):
        pass

    def send(self, code, body=b"", headers=None):
        self.send_response(code)
        for k, v in (headers or {}).items():
            self.send_header(k, v)
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        if self.command != "HEAD":
            self.wfile.write(body)

    def do_HEAD(self):
        self.do_GET()

    def do_GET(self):
        path = self.path.split("?")[0]
        if path.startswith("/hfapi/"):
            rel = path[len("/hfapi/"):].strip("/")
            base = os.path.join(ROOT, "hf", rel)
            if not os.path.isdir(base):
                return self.send(404, b"[]")
            out = []
            for name in sorted(os.listdir(base)):
                data = open(os.path.join(base, name), "rb").read()
                entry = {"type": "file", "path": rel + "/" + name, "size": len(data)}
                if name.endswith(".onnx"):
                    entry["oid"] = "0" * 40
                    entry["lfs"] = {"oid": hashlib.sha256(data).hexdigest(), "size": len(data), "pointerSize": 133}
                else:
                    entry["oid"] = hashlib.sha1(b"blob %d\0" % len(data) + data).hexdigest()
                out.append(entry)
            return self.send(200, json.dumps(out).encode(), {"Content-Type": "application/json"})

        m = re.match(r"/(range|norange|bad|dead|gzforce)/(.+)", path)
        if not m:
            return self.send(404)
        mode, rel = m.groups()
        if mode == "dead":
            return self.send(503)
        file = os.path.normpath(os.path.join(ROOT, rel))
        if not file.startswith(ROOT) or not os.path.isfile(file):
            return self.send(404)
        data = open(file, "rb").read()
        if mode == "bad":
            data = b"x" * len(data)
        headers = {}
        if mode == "gzforce":
            data = gzip.compress(data)
            headers["Content-Encoding"] = "gzip"
        rng = self.headers.get("Range")
        if mode != "norange" and rng:
            a, b = re.match(r"bytes=(\d+)-(\d*)", rng).groups()
            a, b = int(a), (int(b) if b else len(data) - 1)
            b = min(b, len(data) - 1)
            if a >= len(data):
                return self.send(416, b"", {"Content-Range": f"bytes */{len(data)}"})
            part = data[a:b + 1]
            headers["Content-Range"] = f"bytes {a}-{b}/{len(data)}"
            return self.send(206, part, headers)
        return self.send(200, data, headers)


http.server.ThreadingHTTPServer(("127.0.0.1", PORT), Handler).serve_forever()
