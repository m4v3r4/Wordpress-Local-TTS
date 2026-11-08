/**
 * Local TTS admin: install, remove and check components; add voices from the Piper library or
 * from addresses. Talks to the plugin's REST routes under /wp-json/local-tts/v1/.
 */
(function () {
  "use strict";

  var cfg = window.lttsAdmin;
  if (!cfg) return;
  var T = cfg.i18n;
  var busy = false;

  /** JSON request to the plugin's REST API; rejects with the server's message. */
  function api(path, body) {
    return fetch(cfg.root + path, {
      method: body === undefined ? "GET" : "POST",
      credentials: "same-origin",
      headers: { "X-WP-Nonce": cfg.nonce, "Content-Type": "application/json" },
      body: body === undefined ? undefined : JSON.stringify(body),
    }).then(function (res) {
      return res.json().catch(function () { return {}; }).then(function (data) {
        if (!res.ok) throw new Error((data && data.message) || "HTTP " + res.status);
        return data;
      });
    });
  }

  function mb(bytes) {
    return (bytes / 1048576).toLocaleString(undefined, { maximumFractionDigits: 1 });
  }

  function setButtons(disabled) {
    document.querySelectorAll("[data-ltts-install],[data-ltts-remove],[data-ltts-verify],[data-ltts-delete-voice]")
      .forEach(function (b) { b.disabled = disabled; });
  }

  // ------------------------------------------------------------------ installing

  /** The progress box next to a button (its table row or the setup box). */
  function jobBox(button) {
    var scope = button.closest("tr, .ltts-setup, .notice") || document;
    return scope.querySelector(".ltts-job") || document.querySelector(".ltts-job");
  }

  function show(box, value, text) {
    box.hidden = false;
    var bar = box.querySelector("progress");
    if (value === null) bar.removeAttribute("value"); else bar.value = value;
    box.querySelector(".ltts-job-text").textContent = text;
  }

  /** Installs one component: start the job, then call /step until it is done. */
  function runOne(id, box, label) {
    return api("install", { component: id }).then(function loop(data) {
      var job = data.job;
      if (job.status === "error") throw new Error(job.error);
      var frac = job.total ? job.loaded / job.total : 0;
      show(box, job.total ? Math.min(frac, 1) : null,
        label + ": " + T.installing + " " + T.progress.replace("{loaded}", mb(job.loaded)).replace("{total}", mb(job.total)));
      if (job.status === "done") return job;
      return api("step", {}).then(loop);
    });
  }

  /** Installs the comma-separated components of a button in order (requirements first). */
  function install(button) {
    if (busy) return;
    var notice = button.getAttribute("data-ltts-license");
    if (notice && !window.confirm(notice)) return;
    busy = true;
    var ids = button.getAttribute("data-ltts-install").split(",").filter(Boolean);
    var box = jobBox(button);
    setButtons(true);
    var chain = Promise.resolve();
    ids.forEach(function (id, i) {
      chain = chain.then(function () { return runOne(id, box, (i + 1) + "/" + ids.length + " " + id); });
    });
    chain.then(function () {
      show(box, 1, T.done);
      busy = false;
      location.reload();
    }).catch(function (err) {
      busy = false;
      show(box, 0, T.failed + " " + err.message);
      box.classList.add("ltts-error");
      setButtons(false);
    });
  }

  // ------------------------------------------------------------------ Piper voice library

  var library = [];

  function renderLibrary(filter) {
    var select = document.getElementById("ltts-library-select");
    var q = (filter || "").toLowerCase().trim();
    select.innerHTML = "";
    var groups = {};
    library.forEach(function (v) {
      var hay = [v.key, v.name, v.language, v.native, v.country, v.locale].join(" ").toLowerCase();
      if (q && hay.indexOf(q) === -1) return;
      var g = groups[v.language] || (groups[v.language] = document.createElement("optgroup"));
      g.label = v.language + (v.native && v.native !== v.language ? " · " + v.native : "");
      var o = document.createElement("option");
      o.value = v.key;
      o.disabled = !!v.added;
      o.textContent = v.name + " — " + v.locale + ", " + v.quality + (v.bytes ? ", " + mb(v.bytes) + " MB" : "") +
        (v.speakers > 1 ? ", " + v.speakers + "×" : "") + (v.added ? " (" + T.inList + ")" : "");
      g.appendChild(o);
    });
    Object.keys(groups).sort().forEach(function (k) { select.appendChild(groups[k]); });
    document.querySelector("[data-ltts-library-add]").disabled = true;
  }

  function loadLibrary(button) {
    var wrap = document.querySelector(".ltts-library");
    var msg = document.querySelector(".ltts-library-msg");
    button.disabled = true;
    button.textContent = T.loading;
    api("voices/library").then(function (data) {
      library = data.voices || [];
      wrap.hidden = false;
      button.hidden = true;
      renderLibrary("");
      msg.textContent = T.voiceCount.replace("%d", library.length);
      document.getElementById("ltts-library-search").focus();
    }).catch(function (err) {
      button.disabled = false;
      button.textContent = err.message;
    });
  }

  function addVoice(body, msgEl, button) {
    button.disabled = true;
    msgEl.textContent = T.loading;
    api("voices/add", body).then(function () {
      msgEl.textContent = T.added;
      location.reload();
    }).catch(function (err) {
      button.disabled = false;
      msgEl.textContent = err.message;
    });
  }

  function val(id) { return (document.getElementById(id).value || "").trim(); }

  // ------------------------------------------------------------------ events

  document.addEventListener("input", function (e) {
    if (e.target.id === "ltts-library-search") renderLibrary(e.target.value);
  });

  document.addEventListener("change", function (e) {
    if (e.target.id === "ltts-library-select") {
      var opt = e.target.selectedOptions[0];
      document.querySelector("[data-ltts-library-add]").disabled = !opt || opt.disabled;
    }
  });

  document.addEventListener("click", function (e) {
    var b = e.target.closest("button");
    if (!b) return;

    if (b.hasAttribute("data-ltts-install")) {
      e.preventDefault();
      install(b);
    } else if (b.hasAttribute("data-ltts-remove")) {
      e.preventDefault();
      if (!window.confirm(T.confirmRemove)) return;
      b.disabled = true;
      api("remove", { component: b.getAttribute("data-ltts-remove") }).then(function () { location.reload(); })
        .catch(function (err) { b.disabled = false; window.alert(err.message); });
    } else if (b.hasAttribute("data-ltts-delete-voice")) {
      e.preventDefault();
      if (!window.confirm(T.confirmDelete)) return;
      b.disabled = true;
      api("voices/delete", { id: b.getAttribute("data-ltts-delete-voice") }).then(function () { location.reload(); })
        .catch(function (err) { b.disabled = false; window.alert(err.message); });
    } else if (b.hasAttribute("data-ltts-verify")) {
      e.preventDefault();
      b.disabled = true;
      api("verify", { component: b.getAttribute("data-ltts-verify") }).then(function () { location.reload(); })
        .catch(function (err) { b.disabled = false; window.alert(err.message); });
    } else if (b.hasAttribute("data-ltts-cancel")) {
      e.preventDefault();
      api("cancel", {}).then(function () { location.reload(); });
    } else if (b.hasAttribute("data-ltts-library-load")) {
      e.preventDefault();
      loadLibrary(b);
    } else if (b.hasAttribute("data-ltts-library-add")) {
      e.preventDefault();
      var key = document.getElementById("ltts-library-select").value;
      if (key) addVoice({ kind: "library", key: key }, document.querySelector(".ltts-library-msg"), b);
    } else if (b.hasAttribute("data-ltts-url-add")) {
      e.preventDefault();
      addVoice({
        kind: "url", name: val("ltts-url-name"), language: val("ltts-url-lang"),
        model_url: val("ltts-url-model"), config_url: val("ltts-url-config"), sha256: val("ltts-url-sha"),
        license: val("ltts-url-license"), credit: val("ltts-url-credit"), homepage: val("ltts-url-homepage"),
      }, document.querySelector(".ltts-url-msg"), b);
    } else if (b.hasAttribute("data-ltts-check")) {
      e.preventDefault();
      var table = document.querySelector(".ltts-check-table");
      table.hidden = false;
      table.querySelectorAll("td").forEach(function (td) { td.textContent = T.checking; });
      api("check-server", {}).then(function (data) {
        table.querySelectorAll("tr[data-check]").forEach(function (tr) {
          var r = data[tr.getAttribute("data-check")];
          var td = tr.querySelector("td");
          if (!r) { td.textContent = "—"; return; }
          td.innerHTML = "";
          var s = document.createElement("span");
          s.className = r.ok === null ? "ltts-warn" : r.ok ? "ltts-ok" : "ltts-bad";
          s.textContent = r.ok === null ? T.unknown : r.ok ? T.ok : T.bad;
          td.appendChild(s);
          td.appendChild(document.createTextNode(" " + (r.detail || "")));
        });
      }).catch(function (err) { table.querySelector("td").textContent = err.message; });
    }
  });

  window.addEventListener("beforeunload", function (e) {
    if (busy) { e.preventDefault(); e.returnValue = T.leave; return T.leave; }
  });
})();
