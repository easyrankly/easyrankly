(function (ER) {
  "use strict";

  // Every bound panel registers its last-chance flush here so the shared
  // page-visibility listeners below can reach all of them. Those listeners
  // must live at window level (pagehide/visibilitychange never reach panel
  // elements) but must exist exactly once: bindSettingsReplacement() re-binds
  // reloadOnSave panels after every refresh, so per-panel window listeners
  // would accumulate as closures over detached panels, each able to replay a
  // detached panel's stale serialized values over fresher saves. Pruning
  // disconnected entries at flush time keeps the registry in step with the
  // DOM swaps.
  var activeAutosaveFlushers = [];
  var visibilityFlushBound = false;

  function flushPendingAutosaves() {
    var stillConnected = [];

    activeAutosaveFlushers.forEach(function (flush) {
      if (flush()) {
        stillConnected.push(flush);
      }
    });

    activeAutosaveFlushers = stillConnected;
  }

  function bindVisibilityFlushListeners() {
    if (visibilityFlushBound) {
      return;
    }

    visibilityFlushBound = true;

    // pagehide covers navigation/refresh/close; visibilitychange covers tab
    // switches and app backgrounding (where the page survives and its timers
    // keep running). Closing a tab fires both, which just sends one redundant
    // identical save the server treats as a no-op.
    window.addEventListener("pagehide", flushPendingAutosaves);
    document.addEventListener("visibilitychange", function () {
      if (document.visibilityState === "hidden") {
        flushPendingAutosaves();
      }
    });
  }

  // Top-level diff of two serialize() snapshots. JSON comparison instead of
  // === so nested values (checkbox groups, entity maps) compare structurally.
  function changedTopLevelKeys(next, previous) {
    var keys = {};
    var key;

    for (key in next) {
      keys[key] = true;
    }
    for (key in previous) {
      keys[key] = true;
    }

    var changed = [];

    for (key in keys) {
      if (JSON.stringify(next[key]) !== JSON.stringify(previous[key])) {
        changed.push(key);
      }
    }

    return changed;
  }

  function bindEach(root, selector, callbackName) {
    if (typeof ER[callbackName] !== "function") {
      return;
    }

    root.querySelectorAll(selector).forEach(ER[callbackName]);
  }

  function bindSettingsReplacement(root) {
    ["bindTabs", "bindSettingsTabs"].forEach(
      function (callbackName) {
        if (typeof ER[callbackName] === "function") {
          ER[callbackName](root);
        }
      },
    );
    bindEach(root, "[data-erankly-media-url-field]", "bindMediaUrlField");
    bindEach(root, ".erankly-counted-field", "bindCharacterCounter");
    if (typeof ER.bindVariablePickers === "function") {
      ER.bindVariablePickers(root);
    }
    bindEach(root, "[data-erankly-linked-defaults]", "bindLinkedDefaults");
    bindEach(root, "[data-erankly-schema-builder]", "bindSchemaBuilder");
    bindEach(root, "[data-erankly-post-schema]", "bindPostSchemaPanel");
    bindEach(root, "[data-erankly-schema-identity]", "bindSchemaIdentityField");
    bindEach(root, "[data-erankly-user-search-wrap]", "bindUserSearch");
    bindEach(root, "[data-erankly-local-business]", "bindLocalBusiness");
    bindEach(root, "[data-erankly-file-dropzone]", "bindFileDropzone");

    bindAllSettingsAutosave(root);
  }

  function refreshSettingsRoot(root) {
    if (
      !root ||
      !root.parentNode ||
      typeof window.fetch !== "function" ||
      typeof window.DOMParser !== "function"
    ) {
      window.location.reload();
      return;
    }

    fetch(window.location.href, {
      method: "GET",
      credentials: "same-origin",
      headers: {
        "X-Requested-With": "XMLHttpRequest",
      },
    })
      .then(function (res) {
        if (!res.ok) {
          throw new Error("erankly-settings-refresh-failed");
        }

        return res.text();
      })
      .then(function (html) {
        var doc = new DOMParser().parseFromString(html, "text/html");
        var nextRoot = doc.querySelector(".erankly-settings");

        if (!nextRoot) {
          throw new Error("erankly-settings-refresh-missing-root");
        }

        root.replaceWith(nextRoot);
        bindSettingsReplacement(nextRoot);
      })
      .catch(function () {
        window.location.reload();
      });
  }

  function bindSettingsAutosave(panel, config) {
    if (!config || !config.restUrl || !config.nonce) {
      return;
    }

    var settingsRoot = panel.closest(".erankly-settings");
    // Shared across every panel: it lives once in the sidebar nav, not
    // per-panel, so switching tabs never orphans an in-flight toast.
    var status =
      settingsRoot &&
      settingsRoot.querySelector("[data-erankly-autosave-status]");
    var i18n = config.i18n || {};
    var fieldRoot = config.fieldRoot || "erankly_settings";
    var fieldNamePattern = new RegExp(
      "^" +
        fieldRoot.replace(/[.*+?^${}()|[\]\\]/g, "\\$&") +
        "((?:\\[[^\\]]*\\])+)$",
    );
    var debounceTimer = null;
    var retryTimer = null;
    var reloadTimer = null;
    var statusHideTimer = null;
    var retryCount = 0;
    var abortController = null;
    var MAX_RETRIES = 5;
    var STATUS_VISIBLE_MS = 4000;
    // Ownership token for the in-flight slot: an aborted save's response
    // handler must not clear saveInFlight on behalf of the newer save that
    // aborted it.
    var saveSeq = 0;
    var saveInFlight = false;
    // What this panel believes the server holds: the DOM snapshot taken at
    // bind (the render already reflects persisted settings), moved forward
    // only by confirmed saves and completed flushes. Diffs against it decide
    // whether a reloadOnSave panel needs refreshSettingsRoot(); because it
    // only ever moves forward, a diff can flag a change spuriously (harmless
    // extra refresh) but never miss one.
    var lastSavedValues = JSON.parse(JSON.stringify(serialize()));

    // Walks/creates object nodes for every path segment except the last,
    // so setPath()/array-push only ever have to handle the final segment.
    function navigateTo(root, path) {
      var target = root;

      path.forEach(function (key) {
        if (typeof target[key] !== "object" || target[key] === null) {
          target[key] = {};
        }

        target = target[key];
      });

      return target;
    }

    function setPath(root, path, value) {
      var last = path.length - 1;
      navigateTo(root, path.slice(0, last))[path[last]] = value;
    }

    // fieldRoot[a][b][c] -> ['a','b','c']; a trailing empty segment
    // (fieldRoot[a][b][]) marks a repeatable checkbox group, not an object key.
    function parseName(name) {
      var match = name.match(fieldNamePattern);

      if (!match) {
        return null;
      }

      var parts = [];
      var re = /\[([^\]]*)\]/g;
      var m;

      while ((m = re.exec(match[1])) !== null) {
        parts.push(m[1]);
      }

      return parts;
    }

    function serialize() {
      var data = {};
      var fields = Array.prototype.slice.call(
        panel.querySelectorAll('[name^="' + fieldRoot + '["]'),
      );

      // Pass 1: pre-seed every "[]" group as an empty array, even when
      // nothing ends up checked. Otherwise, an all-unchecked group would
      // omit its key entirely, and the server-side merge would silently
      // keep whatever was selected last time.
      fields.forEach(function (field) {
        var path = parseName(field.name);

        if (!path || path[path.length - 1] !== "") {
          return;
        }

        var parent = navigateTo(data, path.slice(0, -2));
        var key = path[path.length - 2];

        if (!Array.isArray(parent[key])) {
          parent[key] = [];
        }
      });

      // Pass 2: fill in every field's actual value. Checkboxes always send
      // an explicit value (checked -> '1'/pushed, unchecked -> '') instead
      // of being omitted like native HTML form submission would. Omitting
      // them would make the server-side merge treat "unchecked" the same
      // as "not part of this payload" and silently keep the old value.
      // Radios correctly keep the native skip-when-unchecked behavior:
      // exactly one radio in the group is checked, and that one write wins.
      fields.forEach(function (field) {
        var path = parseName(field.name);

        if (!path) {
          return;
        }

        var isGroup = path[path.length - 1] === "";

        if (field.type === "checkbox") {
          if (isGroup) {
            if (field.checked) {
              navigateTo(data, path.slice(0, -2))[path[path.length - 2]].push(
                field.value,
              );
            }
            return;
          }

          setPath(data, path, field.checked ? "1" : "");
          return;
        }

        if (field.type === "radio") {
          if (field.checked) {
            setPath(data, path, field.value);
          }
          return;
        }

        setPath(data, path, field.value);
      });

      return data;
    }

    function setStatus(text, state) {
      if (!status) {
        return;
      }

      window.clearTimeout(statusHideTimer);

      status.textContent = text;
      status.classList.toggle("is-success", state === "success");
      status.classList.toggle("is-error", state === "error");
      status.classList.toggle("is-warning", state === "warning");
      status.classList.add("is-visible");

      // The saved/warning confirmation is a brief toast, not a persistent
      // label: it fades out on its own so it doesn't linger over the menu.
      // Errors stay until the next state change since they need attention.
      if (state === "success" || state === "warning") {
        statusHideTimer = window.setTimeout(function () {
          status.classList.remove("is-visible");
        }, STATUS_VISIBLE_MS);
      }
    }

    function save() {
      window.clearTimeout(retryTimer);

      if (abortController) {
        abortController.abort();
      }
      abortController = new AbortController();
      var seq = ++saveSeq;
      saveInFlight = true;

      setStatus(i18n.saving || "Saving…", null);

      var payload = serialize();

      fetch(config.restUrl, {
        method: "POST",
        credentials: "same-origin",
        signal: abortController.signal,
        headers: {
          "Content-Type": "application/json",
          "X-WP-Nonce": config.nonce,
        },
        body: JSON.stringify({ settings: payload }),
      })
        .then(function (res) {
          // 4xx (expired nonce, permission, invalid payload) can't be
          // fixed by retrying the same request. Stop and ask for a reload.
          if (!res.ok) {
            if (res.status >= 400 && res.status < 500) {
              if (seq === saveSeq) {
                saveInFlight = false;
              }
              setStatus(
                i18n.error || "Could not save. Reload the page.",
                "error",
              );
              return null;
            }
            throw new Error("erankly-autosave-transient");
          }

          return res.json();
        })
        .then(function (body) {
          if (body === null) {
            return;
          }

          retryCount = 0;
          var warnings = (body && body.warnings) || [];
          var errors = (body && body.errors) || [];
          var incomplete = !!(body && body.incomplete);

          if (errors.length || incomplete) {
            setStatus(
              errors[0] || i18n.incomplete || "Saved, but the configuration is incomplete.",
              "error",
            );
            return;
          }

          setStatus(
            warnings.length
              ? i18n.warning || "Saved with warnings"
              : i18n.saved || "Saved",
            warnings.length ? "warning" : "success",
          );

          // window.eranklyVariablePreview is only ever localized once, at
          // the page's initial render. Toggling this field via autosave
          // never re-runs wp_localize_script, so bindVariablePreview() would
          // otherwise keep using the stale value (even across the
          // reloadOnSave DOM refresh below) until a real browser reload.
          if (
            Object.prototype.hasOwnProperty.call(
              payload,
              "resolve_placeholders",
            ) &&
            window.eranklyVariablePreview
          ) {
            window.eranklyVariablePreview.resolvePlaceholders =
              "1" === payload.resolve_placeholders;
          }

          // Some panels' fields affect PHP-rendered tabs and controls
          // elsewhere on the page. Refresh only the EasyRankly settings
          // wrapper after the save lands, instead of reloading the whole
          // browser page. scheduleSave() cancels this if another edit
          // comes in before it fires, so quick successive toggles are not
          // dropped by the refresh.
          // Record what the server now holds, then refresh only when a key
          // that actually drives PHP-rendered UI differs from the last
          // persisted state. Panels without a refreshKeys map keep the
          // legacy always-refresh behavior.
          var changedKeys = changedTopLevelKeys(payload, lastSavedValues);
          lastSavedValues = JSON.parse(JSON.stringify(payload));

          if (
            config.reloadOnSave &&
            !warnings.length &&
            refreshNeeded(changedKeys)
          ) {
            reloadTimer = window.setTimeout(function () {
              refreshSettingsRoot(settingsRoot);
            }, 700);
          }
        })
        .catch(function (err) {
          if (err && "AbortError" === err.name) {
            return;
          }

          if (seq === saveSeq) {
            saveInFlight = false;
          }

          retryCount += 1;

          if (retryCount > MAX_RETRIES) {
            setStatus(
              i18n.error || "Could not save. Reload the page.",
              "error",
            );
            return;
          }

          setStatus(i18n.retry || "Saving failed. Retrying…", "error");
          retryTimer = window.setTimeout(save, 4000);
        });
    }

    function refreshNeeded(changedKeys) {
      if (!config.refreshKeys || !config.refreshKeys.length) {
        return true;
      }

      return changedKeys.some(function (key) {
        return config.refreshKeys.indexOf(key) !== -1;
      });
    }

    // Last-chance flush when the page is being hidden (tab switch,
    // navigation, refresh, app backgrounding): a pending debounced edit
    // would otherwise be silently dropped with the page's timers. Sends a
    // fire-and-forget keepalive POST of the panel's current values — the
    // same full-panel payload the regular autosave sends, so it lands
    // through the same whitelisted merge and is idempotent when nothing
    // changed. Returns whether this panel is still in the document; the
    // shared flusher uses that to prune entries orphaned by
    // refreshSettingsRoot() DOM swaps.
    function flushPendingSave() {
      if (!panel.isConnected) {
        return false;
      }

      if (debounceTimer === null && retryTimer === null && !saveInFlight) {
        return true;
      }

      window.clearTimeout(debounceTimer);
      debounceTimer = null;
      window.clearTimeout(retryTimer);

      if (abortController) {
        abortController.abort();
        saveInFlight = false;
      }

      var payload = serialize();

      fetch(config.restUrl, {
        method: "POST",
        credentials: "same-origin",
        keepalive: true,
        headers: {
          "Content-Type": "application/json",
          "X-WP-Nonce": config.nonce,
        },
        body: JSON.stringify({ settings: payload }),
      })
        .then(function () {
          // Runs only when the page is still alive (a mere tab switch):
          // keeps the snapshot in step with what the server now holds.
          lastSavedValues = JSON.parse(JSON.stringify(payload));
        })
        .catch(function () {
          // The page is going away; there is nothing left to try.
        });

      return true;
    }

    function scheduleSave() {
      window.clearTimeout(debounceTimer);
      window.clearTimeout(reloadTimer);
      debounceTimer = window.setTimeout(save, 900);
    }

    panel.addEventListener("input", scheduleSave);
    panel.addEventListener("change", scheduleSave);

    activeAutosaveFlushers.push(flushPendingSave);
    bindVisibilityFlushListeners();
  }

  // Binds every settings panel that has a matching entry in the config
  // PHP localized (see eranklySettingsAutosave in erankly_admin_enqueue_assets()).
  // A panel with no entry there, because its tier hasn't landed yet, is
  // simply left alone, so this loop never needs to change as panels are
  // wired up one at a time.
  function bindAllSettingsAutosave(root) {
    var settingsConfig = window.eranklySettingsAutosave;

    if (!settingsConfig || !settingsConfig.panels) {
      return;
    }

    root
      .querySelectorAll("[data-erankly-settings-panel]")
      .forEach(function (panelEl) {
        var slug = (
          panelEl.getAttribute("data-erankly-settings-panel") || ""
        ).replace(/^settings-/, "");
        var panelConfig = settingsConfig.panels[slug];

        if (!panelConfig) {
          return;
        }

        bindSettingsAutosave(panelEl, {
          restUrl: panelConfig.restUrl,
          nonce: settingsConfig.nonce,
          i18n: settingsConfig.i18n,
          reloadOnSave: !!panelConfig.reloadOnSave,
          refreshKeys: panelConfig.refreshKeys,
          fieldRoot: panelConfig.fieldRoot,
        });
      });
  }

  ER.bindSettingsReplacement = bindSettingsReplacement;
  ER.refreshSettingsRoot = refreshSettingsRoot;
  ER.bindSettingsAutosave = bindSettingsAutosave;
  ER.bindAllSettingsAutosave = bindAllSettingsAutosave;
})(window.ERanklyAdmin = window.ERanklyAdmin || {});
