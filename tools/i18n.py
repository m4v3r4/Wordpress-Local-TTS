#!/usr/bin/env python3
"""Local TTS translation tool.

  python3 tools/i18n.py extract      -> prints msgids found in the plugin (for review)
  python3 tools/i18n.py build        -> writes languages/local-tts.pot and, for every
                                        tools/translations/<locale>.json, a .po and .mo file

Translations live in tools/translations/<locale>.json as {"English msgid": "translation"}.
Missing entries fall back to English at run time; `build` lists them.
"""
import glob, json, os, re, struct, sys, datetime

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
PLUGIN = os.path.join(ROOT, "local-tts")
LANG_DIR = os.path.join(PLUGIN, "languages")
TR_DIR = os.path.join(ROOT, "tools", "translations")
FUNCS = r"(?:__|_e|esc_html__|esc_html_e|esc_attr__|esc_attr_e)"
CALL = re.compile(FUNCS + r"\(\s*'((?:[^'\\]|\\.)*)'\s*,\s*'local-tts'\s*\)")

def php_unescape(s):
    return s.replace("\\'", "'").replace("\\\\", "\\")

def extract():
    found = {}
    for path in sorted(glob.glob(os.path.join(PLUGIN, "**", "*.php"), recursive=True)):
        src = open(path, encoding="utf-8").read()
        for m in CALL.finditer(src):
            msg = php_unescape(m.group(1))
            line = src.count("\n", 0, m.start()) + 1
            found.setdefault(msg, []).append("%s:%d" % (os.path.relpath(path, PLUGIN), line))
    return found

def po_escape(s):
    return s.replace("\\", "\\\\").replace('"', '\\"').replace("\n", "\\n")

def header(locale):
    return ("Project-Id-Version: Local TTS\n"
            "Report-Msgid-Bugs-To: \n"
            "POT-Creation-Date: %s\n"
            "MIME-Version: 1.0\n"
            "Content-Type: text/plain; charset=UTF-8\n"
            "Content-Transfer-Encoding: 8bit\n"
            "Language: %s\n"
            "Plural-Forms: nplurals=2; plural=(n != 1);\n"
            "X-Domain: local-tts\n") % (datetime.date.today().isoformat(), locale)

def write_po(path, locale, msgs, trans):
    out = ['msgid ""', 'msgstr ""']
    out += ['"%s\\n"' % po_escape(l) for l in header(locale).strip("\n").split("\n")]
    out.append("")
    for msg, refs in msgs.items():
        out.append("#: " + " ".join(refs[:4]))
        out.append('msgid "%s"' % po_escape(msg))
        out.append('msgstr "%s"' % po_escape(trans.get(msg, "")))
        out.append("")
    open(path, "w", encoding="utf-8").write("\n".join(out))

def write_mo(path, locale, trans):
    entries = {"": header(locale)}
    entries.update({k: v for k, v in trans.items() if v})
    keys = sorted(entries)
    ids = b"".join(k.encode("utf-8") + b"\0" for k in keys)
    strs = b"".join(entries[k].encode("utf-8") + b"\0" for k in keys)
    n = len(keys)
    koff, voff = 7 * 4, 7 * 4 + n * 8
    start = voff + n * 8
    ko, vo, pos = [], [], 0
    for k in keys:
        b = k.encode("utf-8"); ko.append((len(b), start + pos)); pos += len(b) + 1
    sstart = start + len(ids); pos = 0
    for k in keys:
        b = entries[k].encode("utf-8"); vo.append((len(b), sstart + pos)); pos += len(b) + 1
    data = struct.pack("<7I", 0x950412DE, 0, n, koff, voff, 0, 0)
    data += b"".join(struct.pack("<2I", l, o) for l, o in ko)
    data += b"".join(struct.pack("<2I", l, o) for l, o in vo)
    open(path, "wb").write(data + ids + strs)

def write_l10n_php(path, locale, trans):
    # WordPress 6.5+ prefers this format (faster than .mo).
    def q(s): return "'" + s.replace("\\", "\\\\").replace("'", "\\'") + "'"
    body = ",".join("%s=>%s" % (q(k), q(v)) for k, v in sorted(trans.items()) if v)
    open(path, "w", encoding="utf-8").write(
        "<?php\nreturn ['project-id-version'=>'Local TTS','language'=>%s,'domain'=>'local-tts','messages'=>[%s]];\n" % (q(locale), body))

def build():
    msgs = extract()
    os.makedirs(LANG_DIR, exist_ok=True)
    write_po(os.path.join(LANG_DIR, "local-tts.pot"), "", msgs, {})
    for tf in sorted(glob.glob(os.path.join(TR_DIR, "*.json"))):
        locale = os.path.splitext(os.path.basename(tf))[0]
        trans = json.load(open(tf, encoding="utf-8"))
        missing = [m for m in msgs if not trans.get(m)]
        unused = [m for m in trans if m not in msgs]
        trans = {k: v for k, v in trans.items() if k in msgs}
        base = os.path.join(LANG_DIR, "local-tts-" + locale)
        write_po(base + ".po", locale, msgs, trans)
        write_mo(base + ".mo", locale, trans)
        write_l10n_php(base + ".l10n.php", locale, trans)
        print("%s: %d/%d translated%s%s" % (locale, len(msgs) - len(missing), len(msgs),
              ("; missing: " + " | ".join(missing)) if missing else "",
              ("; unused: %d" % len(unused)) if unused else ""))

if __name__ == "__main__":
    cmd = sys.argv[1] if len(sys.argv) > 1 else "build"
    if cmd == "extract":
        for m in extract():
            print(json.dumps(m, ensure_ascii=False))
    else:
        build()
