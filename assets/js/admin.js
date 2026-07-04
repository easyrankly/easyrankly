(function () {
  "use strict";

  function bindMediaUrlField(field) {
    var selectButton = field.querySelector("[data-erankly-select-media-url]");
    var clearButton = field.querySelector("[data-erankly-clear-media-url]");
    var input = field.querySelector("[data-erankly-media-url-input]");
    var idInput = field.querySelector("[data-erankly-media-url-id]");
    var preview = field.querySelector("[data-erankly-media-url-preview]");
    var frame;
    var isMediaSelection = false;

    if (
      !selectButton ||
      !clearButton ||
      !input ||
      !window.wp ||
      !window.wp.media
    ) {
      return;
    }

    function updatePreview(url) {
      if (!preview) {
        return;
      }

      preview.innerHTML = "";

      if (!url || url.indexOf("{{") !== -1) {
        return;
      }

      var image = document.createElement("img");

      image.src = url;
      image.alt = "";
      preview.appendChild(image);
    }

    selectButton.addEventListener("click", function () {
      if (frame) {
        frame.open();
        return;
      }

      frame = window.wp.media({
        title: selectButton.textContent,
        button: {
          text: selectButton.textContent,
        },
        multiple: false,
      });

      frame.on("select", function () {
        var attachment = frame.state().get("selection").first().toJSON();
        var url = attachment.url || "";

        isMediaSelection = true;
        input.value = url;

        if (idInput) {
          idInput.value = attachment.id || "";
        }

        updatePreview(url);
        input.dispatchEvent(new Event("input", { bubbles: true }));
        input.dispatchEvent(new Event("change", { bubbles: true }));
        isMediaSelection = false;
      });

      frame.open();
    });

    clearButton.addEventListener("click", function () {
      input.value = "";

      if (idInput) {
        idInput.value = "";
      }

      updatePreview("");
      input.dispatchEvent(new Event("input", { bubbles: true }));
      input.dispatchEvent(new Event("change", { bubbles: true }));
    });

    input.addEventListener("input", function () {
      if (idInput && !isMediaSelection) {
        idInput.value = "";
      }

      updatePreview(input.value);
    });
  }

  function bindTabs(container) {
    var tabLists = Array.prototype.slice.call(
      container.querySelectorAll(".erankly-tabs"),
    );

    tabLists.forEach(function (tabList) {
      var tabContainer =
        tabList.closest("[data-erankly-tabs-root]") || tabList.parentElement;
      var tabs = Array.prototype.slice.call(
        tabList.querySelectorAll("[data-erankly-tab]"),
      );
      var panels = tabContainer
        ? Array.prototype.filter.call(tabContainer.children, function (child) {
            return child.hasAttribute("data-erankly-panel");
          })
        : [];

      // setFocus=true moves keyboard focus to the newly activated tab (used for
      // keyboard navigation). setFocus=false leaves focus where it is (used for clicks).
      function activateTab(tab, setFocus) {
        if (tab.disabled || tab.getAttribute("aria-disabled") === "true") {
          return;
        }

        var target = tab.getAttribute("data-erankly-tab");

        tabs.forEach(function (item) {
          var isActive = item === tab;

          item.classList.toggle("is-active", isActive);
          item.classList.toggle("nav-tab-active", isActive);
          item.setAttribute("aria-selected", isActive ? "true" : "false");
          // Roving tabindex: only the active tab participates in the Tab-key sequence.
          item.setAttribute("tabindex", isActive ? "0" : "-1");
        });

        panels.forEach(function (panel) {
          var isActive = panel.getAttribute("data-erankly-panel") === target;

          panel.classList.toggle("is-active", isActive);
          panel.hidden = !isActive;
        });

        if (setFocus) {
          tab.focus();
        }
      }

      // Initialise roving tabindex from the server-rendered active state.
      tabs.forEach(function (tab) {
        tab.setAttribute(
          "tabindex",
          tab.classList.contains("is-active") ? "0" : "-1",
        );
      });

      tabs.forEach(function (tab) {
        tab.addEventListener("click", function () {
          activateTab(tab, false);
        });
      });

      // Keyboard navigation per the ARIA Tabs pattern:
      // ArrowRight / ArrowLeft move focus cyclically; Home / End jump to endpoints.
      tabList.addEventListener("keydown", function (e) {
        var key = e.key;

        if (
          key !== "ArrowLeft" &&
          key !== "ArrowRight" &&
          key !== "Home" &&
          key !== "End"
        ) {
          return;
        }

        var focusable = tabs.filter(function (t) {
          return (
            !t.hidden &&
            !t.disabled &&
            t.getAttribute("aria-disabled") !== "true"
          );
        });

        if (focusable.length === 0) {
          return;
        }

        var current = focusable.indexOf(document.activeElement);
        var next;

        if (key === "ArrowRight") {
          next = (current + 1) % focusable.length;
        } else if (key === "ArrowLeft") {
          next = (current - 1 + focusable.length) % focusable.length;
        } else if (key === "Home") {
          next = 0;
        } else {
          next = focusable.length - 1;
        }

        e.preventDefault();
        activateTab(focusable[next], true);
      });
    });
  }

  function bindSettingsTabs(root) {
    var tablist = root.querySelector("[data-erankly-settings-tablist]");

    if (!tablist) {
      return;
    }

    var tabs = Array.prototype.slice.call(
      tablist.querySelectorAll("[data-erankly-tab]"),
    );
    var panels = Array.prototype.slice.call(
      root.querySelectorAll("[data-erankly-settings-panel]"),
    );
    var referer = root.querySelector('input[name="_wp_http_referer"]');
    var settingsSubmit = root.querySelector("[data-erankly-settings-submit]");
    var sidebarNav = root.querySelector("[data-erankly-sidebar-nav]");
    var sidebarToggle = root.querySelector("[data-erankly-sidebar-toggle]");
    var sidebarToggleLabel = root.querySelector(
      "[data-erankly-sidebar-toggle-label]",
    );

    function getTabLabel(tab) {
      return tab.textContent;
    }

    function findInnerTab(target) {
      var innerTabs = Array.prototype.slice.call(
        root.querySelectorAll(".erankly-tabs [data-erankly-tab]"),
      );

      return (
        innerTabs.filter(function (tab) {
          return tab.getAttribute("data-erankly-tab") === target;
        })[0] || null
      );
    }

    function canActivateInnerTab(target) {
      var tab = findInnerTab(target);

      return !!(
        tab &&
        !tab.hidden &&
        !tab.disabled &&
        tab.getAttribute("aria-disabled") !== "true"
      );
    }

    function isTabFocusable(tab) {
      return (
        !tab.hidden &&
        !tab.disabled &&
        tab.getAttribute("aria-disabled") !== "true"
      );
    }

    function activateInnerTab(target) {
      var tab = findInnerTab(target);

      if (!tab || !canActivateInnerTab(target)) {
        return false;
      }

      tab.click();
      return true;
    }

    // Below the mobile breakpoint the tablist collapses into an accordion:
    // the toggle button shows the active section's label and expands the list on tap.
    function setSidebarExpanded(expanded) {
      if (!sidebarNav || !sidebarToggle) {
        return;
      }

      sidebarNav.classList.toggle("is-expanded", expanded);
      sidebarToggle.setAttribute("aria-expanded", expanded ? "true" : "false");
    }

    if (sidebarToggle) {
      sidebarToggle.addEventListener("click", function () {
        setSidebarExpanded(!sidebarNav.classList.contains("is-expanded"));
      });

      // Close the accordion on outside click or Escape, mirroring a native dropdown.
      document.addEventListener("click", function (e) {
        if (
          sidebarNav.classList.contains("is-expanded") &&
          !sidebarNav.contains(e.target)
        ) {
          setSidebarExpanded(false);
        }
      });

      sidebarNav.addEventListener("keydown", function (e) {
        if (
          e.key === "Escape" &&
          sidebarNav.classList.contains("is-expanded")
        ) {
          setSidebarExpanded(false);
          sidebarToggle.focus();
        }
      });
    }

    // Keep the Settings API redirect on the active tab after "Save Changes".
    function syncReferer(target, subtab) {
      if (!referer) {
        return;
      }

      var base = referer.value
        .split("#")[0]
        .replace(/([?&])erankly_tab=[^&]*&?/, "$1")
        .replace(/([?&])erankly_subtab=[^&]*&?/, "$1")
        .replace(/[?&]$/, "");

      // Keep the redirect on whatever tab is active so saving never bounces
      // back to General. "general" is the server default, so it needs no param.
      if (target && target !== "settings-general") {
        base +=
          (base.indexOf("?") === -1 ? "?" : "&") +
          "erankly_tab=" +
          target.replace("settings-", "");
      }

      if (subtab) {
        base +=
          (base.indexOf("?") === -1 ? "?" : "&") + "erankly_subtab=" + subtab;
      }

      referer.value = base;
    }

    // Keep the active-tab URL in sync so that F5 / reload restores the correct tab.
    // Uses replaceState (not pushState) to avoid polluting the browser history.
    function syncUrl(target, subtab) {
      if (
        !window.history ||
        typeof window.history.replaceState !== "function"
      ) {
        return;
      }

      var shortName = target.replace("settings-", "");
      var url = new URL(window.location.href);

      url.searchParams.set("erankly_tab", shortName);
      if (subtab) {
        url.searchParams.set("erankly_subtab", subtab);
      } else {
        url.searchParams.delete("erankly_subtab");
      }
      url.hash = "";
      history.replaceState(history.state, "", url.toString());
    }

    // setFocus=true moves keyboard focus to the tab button (for keyboard navigation).
    function activate(target, setFocus, subtab) {
      var activeMenuTab = null;

      subtab = subtab || "";

      if (subtab && !canActivateInnerTab(subtab)) {
        subtab = "";
      }

      tabs.forEach(function (tab) {
        var isActive = tab.getAttribute("data-erankly-tab") === target;

        tab.classList.toggle("is-active", isActive);
        tab.setAttribute("aria-selected", isActive ? "true" : "false");
        // Roving tabindex: only the active tab is reachable via Tab key.
        tab.setAttribute("tabindex", isActive ? "0" : "-1");

        if (isActive) {
          activeMenuTab = tab;
        }
      });

      if (activeMenuTab && sidebarToggleLabel) {
        sidebarToggleLabel.textContent = getTabLabel(activeMenuTab);
      } else {
        tabs.forEach(function (tab) {
          var isActive = tab.getAttribute("data-erankly-tab") === target;

          if (isActive && sidebarToggleLabel) {
            sidebarToggleLabel.textContent = getTabLabel(tab);
          }
        });
      }

      // Collapse the mobile accordion once a section has been chosen.
      setSidebarExpanded(false);

      panels.forEach(function (panel) {
        var isActive =
          panel.getAttribute("data-erankly-settings-panel") === target;

        panel.classList.toggle("is-active", isActive);
        panel.hidden = !isActive;
      });

      if (settingsSubmit) {
        // Panels that carry their own form (the core's external panels and any
        // extension tab) opt out of the shared "Save Changes" button.
        var activePanel = panels.filter(function (panel) {
          return panel.getAttribute("data-erankly-settings-panel") === target;
        })[0];
        var standalone = activePanel
          ? activePanel.hasAttribute("data-erankly-standalone-panel")
          : false;

        // Every built-in panel now has data-erankly-standalone-panel or matches
        // one of the four slugs below, so this always evaluates to hidden=true
        // today — kept as-is rather than simplified, since it's still what
        // correctly hides the button for a third-party extension tab (no
        // control over whether those carry the standalone attribute) and for
        // any future built-in panel that doesn't autosave.
        settingsSubmit.hidden =
          standalone ||
          target === "settings-health" ||
          target === "settings-import-export" ||
          target === "settings-redirects" ||
          target === "settings-multilingual";
      }

      if (subtab) {
        activateInnerTab(subtab);
      }

      syncReferer(target, subtab);

      if (setFocus && activeMenuTab) {
        activeMenuTab.focus();
      }
    }

    // Initialise roving tabindex from the server-rendered aria-selected state.
    tabs.forEach(function (tab) {
      tab.setAttribute(
        "tabindex",
        tab.getAttribute("aria-selected") === "true" ? "0" : "-1",
      );
    });

    tabs.forEach(function (tab) {
      tab.addEventListener("click", function () {
        var target = tab.getAttribute("data-erankly-tab");

        activate(target, false, "");
        syncUrl(target, "");
      });
    });

    // Keyboard navigation per the ARIA Tabs pattern (§3.23) for a vertically
    // oriented tablist: ArrowDown / ArrowUp cycle focus; Home / End jump to endpoints.
    tablist.addEventListener("keydown", function (e) {
      var key = e.key;

      if (
        key !== "ArrowUp" &&
        key !== "ArrowDown" &&
        key !== "Home" &&
        key !== "End"
      ) {
        return;
      }

      var focusable = tabs.filter(isTabFocusable);

      if (focusable.length === 0) {
        return;
      }

      var current = focusable.indexOf(document.activeElement);
      var next;

      if (key === "ArrowDown") {
        next = (current + 1) % focusable.length;
      } else if (key === "ArrowUp") {
        next = (current - 1 + focusable.length) % focusable.length;
      } else if (key === "Home") {
        next = 0;
      } else {
        next = focusable.length - 1;
      }

      e.preventDefault();
      var nextTarget = focusable[next].getAttribute("data-erankly-tab");

      activate(nextTarget, true, "");
      syncUrl(nextTarget, "");
    });

    // Activate the server-requested panel on init. For panels that PHP already
    // renders as active (general, features, health, redirects, import-export) this
    // is a no-op in terms of visibility. For JS-only panels (social, schema,
    // sitemap, settings, advanced, bloat) it removes the hardcoded `hidden`
    // attribute so that a direct URL reload restores the correct tab.
    var initialPanel = tablist.getAttribute("data-erankly-active-panel");
    var initialSubtab =
      tablist.getAttribute("data-erankly-active-subtab") || "";

    if (initialPanel) {
      activate(initialPanel, false, initialSubtab);
    }

    root.eranklyActivateSettingsTab = activate;
  }

  function bindSimplifiedMode(root) {
    var simplifiedMode = root.querySelector('input[name$="[simplified_mode]"]');
    var seoChecklist = root.querySelector(
      'input[name$="[enable_seo_checklist]"]',
    );
    var advancedTab = root.querySelector("[data-erankly-advanced-tab]");
    var advancedPanel = root.querySelector("[data-erankly-advanced-panel]");
    var customSchemaSection = root.querySelector(
      "[data-erankly-custom-schema-section]",
    );
    var multisiteSpecialPagesSection = root.querySelector(
      "[data-erankly-multisite-special-pages-section]",
    );
    var oembedJsonSection = root.querySelector(
      "[data-erankly-oembed-json-section]",
    );
    var postDateSettingsSection = root.querySelector(
      "[data-erankly-post-date-settings-section]",
    );

    if (simplifiedMode && seoChecklist) {
      var syncSeoChecklist = function () {
        seoChecklist.disabled = !simplifiedMode.checked;

        if (!simplifiedMode.checked && seoChecklist.checked) {
          seoChecklist.checked = false;
          seoChecklist.dispatchEvent(new Event("input", { bubbles: true }));
          seoChecklist.dispatchEvent(new Event("change", { bubbles: true }));
        }
      };

      simplifiedMode.addEventListener("change", syncSeoChecklist);
      syncSeoChecklist();
    }

    if (!simplifiedMode || !advancedTab || !advancedPanel) {
      return;
    }

    function syncAdvancedVisibility() {
      var isSimplified = advancedTab.hidden;

      if (customSchemaSection) {
        customSchemaSection.hidden = isSimplified;
      }

      if (multisiteSpecialPagesSection) {
        multisiteSpecialPagesSection.hidden = isSimplified;
      }

      if (oembedJsonSection) {
        oembedJsonSection.hidden = isSimplified;
      }

      if (postDateSettingsSection) {
        postDateSettingsSection.hidden = isSimplified;
      }

      if (
        isSimplified &&
        advancedPanel.classList.contains("is-active") &&
        typeof root.eranklyActivateSettingsTab === "function"
      ) {
        root.eranklyActivateSettingsTab("settings-settings");
      }
    }

    syncAdvancedVisibility();
  }

  // Linked fields are matched by an explicit data-erankly-linked-field
  // attribute (flat setting names, e.g. social defaults) or by the [title] /
  // [description] suffix of nested names (post type and taxonomy defaults).
  function getLinkedFieldName(field) {
    var explicit = field.getAttribute("data-erankly-linked-field");

    if (explicit) {
      return explicit;
    }

    if (field.name.indexOf("[description]") !== -1) {
      return "description";
    }

    if (field.name.indexOf("[title]") !== -1) {
      return "title";
    }

    return "";
  }

  function getLinkedDefaultFields(container, fieldName) {
    return Array.prototype.slice
      .call(
        container.querySelectorAll(
          ".erankly-default-tab-panel input, .erankly-default-tab-panel textarea",
        ),
      )
      .filter(function (field) {
        return getLinkedFieldName(field) === fieldName;
      });
  }

  function getLinkedDefaultSource(container) {
    var activePanel = container.querySelector(
      ".erankly-default-tab-panel.is-active",
    );

    if (activePanel) {
      return activePanel;
    }

    return container.querySelector(".erankly-default-tab-panel");
  }

  function syncLinkedDefaultFields(container, sourceField) {
    var fieldName = (sourceField && getLinkedFieldName(sourceField)) || "title";
    var fields = getLinkedDefaultFields(container, fieldName);
    var value = sourceField ? sourceField.value : "";

    fields.forEach(function (field) {
      if (field === sourceField || field.value === value) {
        return;
      }

      field.value = value;
      field.dispatchEvent(new Event("input", { bubbles: true }));
      field.dispatchEvent(new Event("change", { bubbles: true }));
    });
  }

  function setLinkedDefaultsState(container, isLinked, shouldSync) {
    var input = container.querySelector("[data-erankly-linked-input]");
    var toggle = container.querySelector("[data-erankly-linked-toggle]");
    var status = container.querySelector("[data-erankly-linked-status]");
    var action = container.querySelector("[data-erankly-linked-action]");
    var summary = container.querySelector(".erankly-linked-tabs-summary");
    var tabs = Array.prototype.slice.call(
      container.querySelectorAll(".erankly-tabs [data-erankly-tab]"),
    );
    var panels = Array.prototype.slice.call(
      container.querySelectorAll(".erankly-default-tab-panel"),
    );
    var source = getLinkedDefaultSource(container);
    var title = source
      ? source.querySelector(
          '[data-erankly-linked-field="title"], [name*="[title]"]',
        )
      : null;
    var description = source
      ? source.querySelector(
          '[data-erankly-linked-field="description"], [name*="[description]"]',
        )
      : null;
    var target = source ? source.getAttribute("data-erankly-panel") : "";
    var actionLabel = "";

    if (!target && panels.length > 0) {
      target = panels[0].getAttribute("data-erankly-panel");
    }

    container.classList.toggle("is-linked", isLinked);

    if (summary) {
      summary.hidden = !isLinked;
    }

    tabs.forEach(function (tab) {
      var isActive =
        !isLinked && tab.getAttribute("data-erankly-tab") === target;

      tab.hidden = isLinked;
      tab.disabled = isLinked;
      tab.setAttribute("aria-disabled", isLinked ? "true" : "false");
      tab.classList.toggle("is-active", isActive);
      tab.classList.toggle("nav-tab-active", isActive);
      tab.setAttribute("aria-selected", isActive ? "true" : "false");

      if (isLinked) {
        tab.setAttribute("tabindex", "-1");
      } else {
        tab.removeAttribute("tabindex");
      }
    });

    if (input) {
      var linkedValue = isLinked ? "1" : "0";

      // Only dispatch when the value actually changes: this function also
      // runs once at bind time to sync UI state from the existing value
      // (bindLinkedDefaults() below), and that call must not itself look
      // like a user edit and trigger an autosave.
      if (input.value !== linkedValue) {
        input.value = linkedValue;
        input.dispatchEvent(new Event("input", { bubbles: true }));
        input.dispatchEvent(new Event("change", { bubbles: true }));
      }
    }

    if (toggle) {
      actionLabel =
        toggle.getAttribute(
          isLinked
            ? "data-erankly-linked-action-on-label"
            : "data-erankly-linked-action-off-label",
        ) || "";
      toggle.setAttribute("aria-label", actionLabel);
      toggle.setAttribute("aria-pressed", isLinked ? "true" : "false");
      toggle.setAttribute("title", actionLabel);
    }

    if (action) {
      action.textContent = actionLabel;
    }

    if (status && toggle) {
      status.textContent = isLinked
        ? toggle.getAttribute("data-erankly-linked-on-label")
        : toggle.getAttribute("data-erankly-linked-off-label");
    }

    if (isLinked && shouldSync) {
      if (title) {
        syncLinkedDefaultFields(container, title);
      }

      if (description) {
        syncLinkedDefaultFields(container, description);
      }
    }
  }

  function bindLinkedDefaults(container) {
    var toggle = container.querySelector("[data-erankly-linked-toggle]");
    var input = container.querySelector("[data-erankly-linked-input]");

    if (!toggle || !input) {
      return;
    }

    toggle.addEventListener("click", function () {
      setLinkedDefaultsState(
        container,
        !container.classList.contains("is-linked"),
        true,
      );
    });

    container
      .querySelectorAll(
        ".erankly-default-tab-panel input, .erankly-default-tab-panel textarea",
      )
      .forEach(function (field) {
        if (!getLinkedFieldName(field)) {
          return;
        }

        field.addEventListener("input", function () {
          if (container.classList.contains("is-linked")) {
            syncLinkedDefaultFields(container, field);
          }
        });
      });

    setLinkedDefaultsState(container, input.value !== "0", true);
  }

  function bindCharacterCounter(field) {
    var limit = parseInt(field.getAttribute("data-erankly-limit"), 10);
    var counterId = field.getAttribute("data-erankly-counter");
    var warning = field.getAttribute("data-erankly-warning") || "too long";
    var counter = counterId ? document.getElementById(counterId) : null;

    if (!counter || !limit) {
      return;
    }

    function updateCounter() {
      var length = field.value.length;
      var isTooLong = length > limit;

      counter.textContent = isTooLong
        ? length + "/" + limit + " - " + warning
        : length + "/" + limit;
      counter.classList.toggle("is-warning", isTooLong);
    }

    field.addEventListener("input", updateCounter);
    updateCounter();
  }

  function bindFileDropzone(dropzone) {
    var input = dropzone.querySelector("[data-erankly-file-dropzone-input]");
    var textEl = dropzone.querySelector("[data-erankly-file-dropzone-text]");

    if (!input || !textEl) {
      return;
    }

    var defaultText = textEl.innerHTML;

    function showFileName() {
      var file = input.files && input.files[0];

      textEl.innerHTML = defaultText;

      if (file) {
        var nameEl = document.createElement("span");
        nameEl.className = "erankly-dropzone-filename";
        nameEl.textContent = file.name;
        textEl.appendChild(nameEl);
      }
    }

    input.addEventListener("change", showFileName);

    ["dragenter", "dragover"].forEach(function (type) {
      dropzone.addEventListener(type, function (event) {
        event.preventDefault();
        dropzone.classList.add("is-dragover");
      });
    });

    ["dragleave", "dragend", "drop"].forEach(function (type) {
      dropzone.addEventListener(type, function (event) {
        event.preventDefault();
        dropzone.classList.remove("is-dragover");
      });
    });

    dropzone.addEventListener("drop", function (event) {
      var files = event.dataTransfer && event.dataTransfer.files;

      if (files && files.length) {
        input.files = files;
        showFileName();
      }
    });
  }

  function closeVariablePicker(field) {
    var menu = field.querySelector("[data-erankly-variable-menu]");
    var control = field.querySelector('input:not([type="search"]), textarea');

    if (!menu) {
      return;
    }

    menu.hidden = true;

    if (control) {
      control.setAttribute("aria-expanded", "false");
      control.removeAttribute("aria-activedescendant");
    }
  }

  // Reads the "word" the caret currently sits in (from the previous whitespace
  // up to the caret) — the fragment the suggestions filter against, mirroring
  // the Redirect rules search filter behaviour.
  function getActiveVariableToken(control) {
    var value = control.value;
    var caret =
      typeof control.selectionStart === "number"
        ? control.selectionStart
        : value.length;
    var start = caret;

    while (start > 0 && !/\s/.test(value.charAt(start - 1))) {
      start--;
    }

    return { start: start, end: caret, text: value.slice(start, caret) };
  }

  // Toggles each option's visibility against the active token and returns the
  // list of options still visible (used for keyboard navigation).
  function filterVariablePicker(field, token) {
    var query = (token || "").trim().toLowerCase();
    var visible = [];

    field
      .querySelectorAll("[data-erankly-variable]")
      .forEach(function (option) {
        var haystack =
          option.getAttribute("data-erankly-variable-search-text") || "";
        var isVisible = !query || haystack.indexOf(query) !== -1;

        option.hidden = !isVisible;
        option.classList.remove("is-active");

        if (isVisible) {
          visible.push(option);
        }
      });

    return visible;
  }

  // Replaces the active token with the chosen {{variable}} (so a partially
  // typed "site" becomes "{{site_name}}"), then places the caret right after it.
  function insertVariable(control, variable, token) {
    var value = control.value;
    var start = token ? token.start : value.length;
    var end = token ? token.end : value.length;

    control.value = value.slice(0, start) + variable + value.slice(end);
    control.focus();

    if (typeof control.setSelectionRange === "function") {
      control.setSelectionRange(
        start + variable.length,
        start + variable.length,
      );
    }

    control.dispatchEvent(new Event("input", { bubbles: true }));
    control.dispatchEvent(new Event("change", { bubbles: true }));
  }

  // Shows a resolved friendly value (e.g. the real site name, or the first
  // post's title as a stand-in for {{post_title}} on fields that aren't
  // tied to any single post) over a {{variable}} field while it's blurred,
  // and reveals the raw token again on focus so it stays editable. Only
  // touches the overlay text node, never control.value itself — the
  // autosave serializer (bindSettingsAutosave) reads field.value straight
  // off the DOM, so swapping the real value would risk saving the resolved
  // text instead of the token on a mistimed autosave. Any token with no
  // example (e.g. a post type with no published posts yet) is left as-is.
  function resolveVariablePreviewText(raw, examples, siteName) {
    return raw.replace(/{{\s*([a-z0-9_]+)\s*}}/gi, function (match, key) {
      var normalizedKey = key.toLowerCase();

      if (examples && Object.prototype.hasOwnProperty.call(examples, normalizedKey)) {
        return examples[normalizedKey];
      }

      if ("site_name" === normalizedKey && siteName) {
        return siteName;
      }

      return match;
    });
  }

  function bindVariablePreview(field, control) {
    var preview = field.querySelector("[data-erankly-variable-preview]");
    var config = window.eranklyVariablePreview;

    if (!preview || !control || !config || !config.resolvePlaceholders) {
      return;
    }

    var examples = null;
    var rawExamples = preview.getAttribute("data-erankly-variable-examples");

    if (rawExamples) {
      try {
        examples = JSON.parse(rawExamples);
      } catch (e) {
        examples = null;
      }
    }

    function update() {
      var raw = control.value;
      var resolved = raw
        ? resolveVariablePreviewText(raw, examples, config.siteName)
        : raw;

      if (resolved !== raw) {
        preview.textContent = resolved;
        field.classList.add("erankly-is-previewing");
      } else {
        field.classList.remove("erankly-is-previewing");
      }
    }

    control.addEventListener("focus", function () {
      field.classList.remove("erankly-is-previewing");
    });

    control.addEventListener("blur", update);

    update();
  }

  function bindVariablePicker(field) {
    var control = field.querySelector('input:not([type="search"]), textarea');
    var menu = field.querySelector("[data-erankly-variable-menu]");

    if (
      !control ||
      !menu ||
      field.getAttribute("data-erankly-variable-bound") === "true"
    ) {
      return;
    }

    field.setAttribute("data-erankly-variable-bound", "true");

    bindVariablePreview(field, control);

    // Give each option a stable id so aria-activedescendant can point at it.
    field
      .querySelectorAll("[data-erankly-variable]")
      .forEach(function (option, index) {
        if (!option.id) {
          option.id =
            "erankly-variable-option-" +
            Math.random().toString(36).slice(2) +
            "-" +
            index;
        }
      });

    // Per-field open state. `visibleOptions` is the currently matching list and
    // `activeIndex` the keyboard-highlighted entry within it.
    var visibleOptions = [];
    var activeIndex = -1;

    function highlight(index) {
      if (activeIndex >= 0 && visibleOptions[activeIndex]) {
        visibleOptions[activeIndex].classList.remove("is-active");
      }

      activeIndex = index;

      if (index >= 0 && visibleOptions[index]) {
        visibleOptions[index].classList.add("is-active");
        control.setAttribute(
          "aria-activedescendant",
          visibleOptions[index].id || "",
        );
        visibleOptions[index].scrollIntoView({ block: "nearest" });
      } else {
        control.removeAttribute("aria-activedescendant");
      }
    }

    function openMenu() {
      var token = getActiveVariableToken(control);

      visibleOptions = filterVariablePicker(field, token.text);
      activeIndex = -1;

      if (!visibleOptions.length) {
        closeVariablePicker(field);
        return;
      }

      document
        .querySelectorAll("[data-erankly-variable-field]")
        .forEach(function (otherField) {
          if (otherField !== field) {
            closeVariablePicker(otherField);
          }
        });

      menu.hidden = false;
      control.setAttribute("aria-expanded", "true");
    }

    control.addEventListener("focus", openMenu);
    control.addEventListener("click", openMenu);
    control.addEventListener("input", openMenu);

    control.addEventListener("keydown", function (event) {
      if (menu.hidden) {
        return;
      }

      if (event.key === "ArrowDown") {
        event.preventDefault();
        highlight(Math.min(activeIndex + 1, visibleOptions.length - 1));
      } else if (event.key === "ArrowUp") {
        event.preventDefault();
        highlight(Math.max(activeIndex - 1, 0));
      } else if (event.key === "Enter") {
        // Only hijack Enter once the user has arrowed onto a suggestion, so it
        // still inserts newlines / submits when they're just typing prose.
        if (activeIndex >= 0 && visibleOptions[activeIndex]) {
          event.preventDefault();
          insertVariable(
            control,
            visibleOptions[activeIndex].getAttribute("data-erankly-variable") ||
              "",
            getActiveVariableToken(control),
          );
          closeVariablePicker(field);
        }
      } else if (event.key === "Escape") {
        closeVariablePicker(field);
      }
    });

    menu.addEventListener("mousedown", function (event) {
      // Keep the field focused so the caret/selection survives the click.
      event.preventDefault();
    });

    menu.addEventListener("click", function (event) {
      var option = event.target
        ? event.target.closest("[data-erankly-variable]")
        : null;

      if (!option) {
        return;
      }

      insertVariable(
        control,
        option.getAttribute("data-erankly-variable") || "",
        getActiveVariableToken(control),
      );
      closeVariablePicker(field);
    });

    document.addEventListener("click", function (event) {
      if (event.target !== control && !menu.contains(event.target)) {
        closeVariablePicker(field);
      }
    });
  }

  function bindVariablePickers(container) {
    container
      .querySelectorAll("[data-erankly-variable-field]")
      .forEach(bindVariablePicker);
  }

  function setSchemaBlockExpanded(block, isExpanded) {
    block.open = isExpanded;
  }

  function updateSchemaBuilderState(builder) {
    var list = builder
      ? builder.querySelector("[data-erankly-schema-blocks]")
      : null;
    var hasBlocks = list && list.querySelector("[data-erankly-schema-block]");

    if (list) {
      list.classList.toggle("is-empty", !hasBlocks);
    }
  }

  function bindSchemaBlock(block) {
    var removeButton = block.querySelector("[data-erankly-remove-schema]");

    bindVariablePickers(block);

    if (removeButton) {
      removeButton.addEventListener("click", function (event) {
        event.stopPropagation();
        var builder = block.closest("[data-erankly-schema-builder]");

        // Dispatch before detaching: the event needs to still bubble
        // through an attached ancestor to reach the autosave listener.
        block.dispatchEvent(new Event("input", { bubbles: true }));
        block.dispatchEvent(new Event("change", { bubbles: true }));
        block.remove();
        updateSchemaBuilderState(builder);
      });
    }
  }

  function bindSchemaBuilder(builder) {
    var list = builder.querySelector("[data-erankly-schema-blocks]");
    var template = builder.querySelector("[data-erankly-schema-template]");
    var addButton = builder.querySelector("[data-erankly-add-schema]");

    builder
      .querySelectorAll("[data-erankly-schema-block]")
      .forEach(bindSchemaBlock);
    updateSchemaBuilderState(builder);

    if (!list || !template || !addButton) {
      return;
    }

    addButton.addEventListener("click", function () {
      var nextIndex =
        parseInt(builder.getAttribute("data-erankly-next-index"), 10) || 0;
      var html = template.innerHTML.replace(/__INDEX__/g, String(nextIndex));

      list.insertAdjacentHTML("beforeend", html);
      builder.setAttribute("data-erankly-next-index", String(nextIndex + 1));
      bindSchemaBlock(list.lastElementChild);
      setSchemaBlockExpanded(list.lastElementChild, true);
      updateSchemaBuilderState(builder);
      list.lastElementChild.dispatchEvent(
        new Event("input", { bubbles: true }),
      );
      list.lastElementChild.dispatchEvent(
        new Event("change", { bubbles: true }),
      );
    });
  }

  function bindSchemaIdentityField(field) {
    var container = field.closest(".erankly-settings");
    var personField = container
      ? container.querySelector("[data-erankly-person-reference-field]")
      : null;
    var personDescription = container
      ? container.querySelector("[data-erankly-person-reference-description]")
      : null;
    var identityFields = container
      ? container.querySelector("[data-erankly-schema-identity-fields]")
      : null;

    if (!personField) {
      return;
    }

    function updatePersonField() {
      var isPerson = field.value === "person";

      personField.hidden = !isPerson;

      if (personDescription) {
        personDescription.hidden = !isPerson;
      }

      if (identityFields) {
        identityFields.classList.toggle("is-person", isPerson);
      }

      syncOrganizationFieldsVisibility(container);
    }

    field.addEventListener("change", updatePersonField);
    updatePersonField();
  }

  function syncOrganizationFieldsVisibility(container) {
    var identity = container
      ? container.querySelector("[data-erankly-schema-identity]")
      : null;
    var localBusiness = container
      ? container.querySelector("[data-erankly-local-business-toggle]")
      : null;
    var showOrganizationFields = identity && identity.value !== "person";

    if (!identity) {
      return;
    }

    container
      .querySelectorAll("[data-erankly-organization-only]")
      .forEach(function (fields) {
        fields.hidden = !showOrganizationFields;
      });
  }

  function bindUserSearch(wrap) {
    var config = window.eranklyUserSearch;

    if (!config || !config.restUrl || !config.nonce) {
      return;
    }

    var idInput = wrap.querySelector("[data-erankly-user-id]");
    var selected = wrap.querySelector("[data-erankly-user-selected]");
    var selectedName = wrap.querySelector("[data-erankly-user-selected-name]");
    var removeBtn = wrap.querySelector("[data-erankly-user-remove]");
    var inputWrap = wrap.querySelector("[data-erankly-user-search-input-wrap]");
    var searchInput = wrap.querySelector("[data-erankly-user-search-input]");
    var resultsList = wrap.querySelector("[data-erankly-user-results]");

    if (
      !idInput ||
      !selected ||
      !removeBtn ||
      !inputWrap ||
      !searchInput ||
      !resultsList
    ) {
      return;
    }

    var debounceTimer = null;
    var i18n = config.i18n || {};

    function closeResults() {
      resultsList.hidden = true;
      resultsList.innerHTML = "";
    }

    function selectUser(id, text) {
      idInput.value = id;
      if (selectedName) {
        selectedName.value = text;
      }
      selected.hidden = false;
      inputWrap.hidden = true;
      removeBtn.hidden = false;
      searchInput.value = "";
      closeResults();
      idInput.dispatchEvent(new Event("input", { bubbles: true }));
      idInput.dispatchEvent(new Event("change", { bubbles: true }));
    }

    function clearUser() {
      idInput.value = "0";
      if (selectedName) {
        selectedName.value = "";
      }
      selected.hidden = true;
      inputWrap.hidden = false;
      removeBtn.hidden = true;
      searchInput.value = "";
      closeResults();
      searchInput.focus();
      idInput.dispatchEvent(new Event("input", { bubbles: true }));
      idInput.dispatchEvent(new Event("change", { bubbles: true }));
    }

    function fetchResults(query) {
      var url = config.restUrl + "?q=" + encodeURIComponent(query);

      resultsList.hidden = false;
      resultsList.innerHTML =
        '<li class="erankly-autocomplete-status erankly-user-result-status">' +
        (i18n.searching || "Searching…") +
        "</li>";

      fetch(url, {
        headers: { "X-WP-Nonce": config.nonce },
        credentials: "same-origin",
      })
        .then(function (res) {
          return res.ok ? res.json() : [];
        })
        .then(function (items) {
          resultsList.innerHTML = "";

          if (!items || items.length === 0) {
            resultsList.innerHTML =
              '<li class="erankly-autocomplete-status erankly-user-result-status">' +
              (i18n.noResults || "No matches found.") +
              "</li>";
            return;
          }

          items.forEach(function (item) {
            var li = document.createElement("li");
            var button = document.createElement("button");

            button.type = "button";
            button.className =
              "erankly-autocomplete-item erankly-user-result-item";
            button.setAttribute("role", "option");
            button.setAttribute("tabindex", "-1");

            if (item.name) {
              if (item.avatar) {
                var avatar = document.createElement("img");
                avatar.className = "erankly-user-result-avatar";
                avatar.src = item.avatar;
                avatar.alt = "";
                avatar.loading = "lazy";
                button.appendChild(avatar);
              }

              var details = document.createElement("span");
              details.className = "erankly-user-result-details";

              var name = document.createElement("span");
              name.className = "erankly-user-result-name";
              name.textContent = item.name;
              details.appendChild(name);

              if (item.meta) {
                var meta = document.createElement("span");
                meta.className = "erankly-user-result-meta";
                meta.textContent = item.meta;
                details.appendChild(meta);
              }

              button.appendChild(details);
            } else {
              button.textContent = item.text;
            }

            function chooseUser(e) {
              e.preventDefault();
              selectUser(item.id, item.text);
            }

            button.addEventListener("mousedown", function (e) {
              e.preventDefault();
            });
            button.addEventListener("click", chooseUser);
            button.addEventListener("keydown", function (e) {
              if (e.key === "Enter" || e.key === " ") {
                chooseUser(e);
              }
            });
            li.appendChild(button);
            resultsList.appendChild(li);
          });
        })
        .catch(function () {
          closeResults();
        });
    }

    removeBtn.addEventListener("click", clearUser);

    searchInput.addEventListener("input", function () {
      clearTimeout(debounceTimer);
      var query = searchInput.value.trim();

      debounceTimer = setTimeout(function () {
        fetchResults(query);
      }, 300);
    });

    searchInput.addEventListener("focus", function () {
      if (resultsList.hidden) {
        fetchResults(searchInput.value.trim());
      }
    });

    searchInput.addEventListener("keydown", function (e) {
      if (e.key === "Escape") {
        closeResults();
        return;
      }

      if (e.key !== "ArrowDown") {
        return;
      }

      var first = resultsList.querySelector('[role="option"]');

      if (first) {
        e.preventDefault();
        first.focus();
      }
    });

    resultsList.addEventListener("keydown", function (e) {
      var items = Array.prototype.slice.call(
        resultsList.querySelectorAll('[role="option"]'),
      );
      var current = items.indexOf(document.activeElement);

      if (e.key === "ArrowDown") {
        e.preventDefault();
        if (current < items.length - 1) {
          items[current + 1].focus();
        }
      } else if (e.key === "ArrowUp") {
        e.preventDefault();
        if (current > 0) {
          items[current - 1].focus();
        } else {
          searchInput.focus();
        }
      } else if (e.key === "Escape") {
        closeResults();
        searchInput.focus();
      }
    });

    document.addEventListener("click", function (e) {
      if (!wrap.contains(e.target)) {
        closeResults();
      }
    });
  }

  function bindBloatToggle(panel) {
    var advancedView = panel.querySelector(
      '[data-erankly-bloat-view="advanced"]',
    );
    var master = panel.querySelector("[data-erankly-bloat-master]");

    if (!advancedView || !master) {
      return;
    }

    // The master toggle only drives the cleanups marked as safe; the riskier
    // ones keep their saved state and stay advanced-mode only.
    function getSafeItems() {
      return Array.prototype.slice.call(
        advancedView.querySelectorAll("[data-erankly-bloat-safe]"),
      );
    }

    function syncMasterFromItems() {
      var items = getSafeItems();
      master.checked =
        items.length > 0 &&
        items.every(function (cb) {
          return cb.checked;
        });
    }

    master.addEventListener("change", function () {
      getSafeItems().forEach(function (cb) {
        cb.checked = master.checked;
      });
    });

    getSafeItems().forEach(function (cb) {
      cb.addEventListener("change", syncMasterFromItems);
    });

    syncMasterFromItems();
  }

  function bindLocalBusiness(container) {
    var toggle = container.querySelector(
      "[data-erankly-local-business-toggle]",
    );
    var fields = container.querySelector(
      "[data-erankly-local-business-fields]",
    );
    var type = container.querySelector("[data-erankly-local-business-type]");
    var foodFields = container.querySelector(
      "[data-erankly-food-business-fields]",
    );
    var foodTypes = [
      "Restaurant",
      "CafeOrCoffeeShop",
      "BarOrPub",
      "Bakery",
      "FoodEstablishment",
    ];

    if (!toggle || !fields) {
      return;
    }

    function syncVisibility() {
      fields.hidden = !toggle.checked;
      syncOrganizationFieldsVisibility(container.closest(".erankly-settings"));

      if (type && foodFields) {
        foodFields.hidden = foodTypes.indexOf(type.value) === -1;
      }
    }

    toggle.addEventListener("change", syncVisibility);

    if (type) {
      type.addEventListener("change", syncVisibility);
    }

    container
      .querySelectorAll("[data-erankly-opening-day]")
      .forEach(function (day) {
        var closed = day.querySelector("[data-erankly-day-closed]");
        var intervals = day.querySelector("[data-erankly-opening-intervals]");

        if (!closed || !intervals) {
          return;
        }

        function syncDay() {
          intervals.hidden = closed.checked;
        }

        closed.addEventListener("change", syncDay);
        syncDay();
      });

    syncVisibility();
  }

  // Autosaves a settings panel via REST instead of the shared "Save Changes"
  // button (see erankly_rest_save_settings_panel()). Serializes every
  // config.fieldRoot[...] field under the panel — including ones inside
  // hidden inner tabs, since those still hold real values — into a nested
  // object from its bracket-notation name, then debounces a POST.
  // config: { restUrl, nonce, i18n, fieldRoot? } — fieldRoot defaults to
  // 'erankly_settings' (every ERANKLY_OPTION-backed panel); Multilingual
  // passes 'erankly_ml_sites', its form's own top-level field name.
  function bindSettingsReplacement(root) {
    bindTabs(root);
    bindSettingsTabs(root);
    bindSimplifiedMode(root);
    root
      .querySelectorAll("[data-erankly-media-url-field]")
      .forEach(bindMediaUrlField);
    root
      .querySelectorAll(".erankly-counted-field")
      .forEach(bindCharacterCounter);
    bindVariablePickers(root);
    root
      .querySelectorAll("[data-erankly-linked-defaults]")
      .forEach(bindLinkedDefaults);
    root
      .querySelectorAll("[data-erankly-schema-builder]")
      .forEach(bindSchemaBuilder);
    root
      .querySelectorAll("[data-erankly-schema-identity]")
      .forEach(bindSchemaIdentityField);
    root
      .querySelectorAll("[data-erankly-user-search-wrap]")
      .forEach(bindUserSearch);
    root
      .querySelectorAll("[data-erankly-local-business]")
      .forEach(bindLocalBusiness);
    root
      .querySelectorAll("[data-erankly-file-dropzone]")
      .forEach(bindFileDropzone);

    var bloatPanel = root.querySelector("#erankly-settings-panel-bloat");
    if (bloatPanel) {
      bindBloatToggle(bloatPanel);
    }

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
      // nothing ends up checked — otherwise an all-unchecked group would
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
      // of being omitted like native HTML form submission would — omitting
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
          // fixed by retrying the same request — stop and ask for a reload.
          if (!res.ok) {
            if (res.status >= 400 && res.status < 500) {
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
          setStatus(
            warnings.length
              ? i18n.warning || "Saved with warnings"
              : i18n.saved || "Saved",
            warnings.length ? "warning" : "success",
          );

          // window.eranklyVariablePreview is only ever localized once, at
          // the page's initial render — toggling this field via autosave
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
          if (config.reloadOnSave && !warnings.length) {
            reloadTimer = window.setTimeout(function () {
              refreshSettingsRoot(settingsRoot);
            }, 700);
          }
        })
        .catch(function (err) {
          if (err && "AbortError" === err.name) {
            return;
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

    function scheduleSave() {
      window.clearTimeout(debounceTimer);
      window.clearTimeout(reloadTimer);
      debounceTimer = window.setTimeout(save, 900);
    }

    panel.addEventListener("input", scheduleSave);
    panel.addEventListener("change", scheduleSave);
  }

  // Binds every settings panel that has a matching entry in the config
  // PHP localized (see eranklySettingsAutosave in erankly_admin_enqueue_assets()).
  // A panel with no entry there — because its tier hasn't landed yet — is
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
          fieldRoot: panelConfig.fieldRoot,
        });
      });
  }

  document.addEventListener("DOMContentLoaded", function () {
    document.querySelectorAll(".erankly-meta-box").forEach(bindTabs);
    document.querySelectorAll(".erankly-settings").forEach(bindTabs);
    document.querySelectorAll(".erankly-settings").forEach(bindSettingsTabs);
    document.querySelectorAll(".erankly-settings").forEach(bindSimplifiedMode);
    document
      .querySelectorAll("[data-erankly-media-url-field]")
      .forEach(bindMediaUrlField);
    document
      .querySelectorAll(".erankly-counted-field")
      .forEach(bindCharacterCounter);
    bindVariablePickers(document);
    document
      .querySelectorAll("[data-erankly-linked-defaults]")
      .forEach(bindLinkedDefaults);
    document
      .querySelectorAll("[data-erankly-schema-builder]")
      .forEach(bindSchemaBuilder);
    document
      .querySelectorAll("[data-erankly-schema-identity]")
      .forEach(bindSchemaIdentityField);
    document
      .querySelectorAll("[data-erankly-user-search-wrap]")
      .forEach(bindUserSearch);
    document
      .querySelectorAll("[data-erankly-local-business]")
      .forEach(bindLocalBusiness);
    document
      .querySelectorAll("[data-erankly-file-dropzone]")
      .forEach(bindFileDropzone);
    var bloatPanel = document.getElementById("erankly-settings-panel-bloat");
    if (bloatPanel) {
      bindBloatToggle(bloatPanel);
    }

    document
      .querySelectorAll(".erankly-settings")
      .forEach(bindAllSettingsAutosave);

    // Outside-click dismissal is handled per field in bindVariablePicker();
    // a global click closer here would fire on the very click that focuses a
    // field and close the menu the instant it opens.
    document.addEventListener("keydown", function (event) {
      if (event.key === "Escape") {
        document
          .querySelectorAll("[data-erankly-variable-field]")
          .forEach(closeVariablePicker);
      }
    });

    bindResetConfirmModal();
  });

  /**
   * Wires the Reset card's confirmation modal.
   *
   * The trigger buttons only open the modal; the modal's own Delete button
   * is what actually submits the reset. This is a deliberate two-step
   * confirmation for a destructive, irreversible action.
   *
   * @return void
   */
  function bindResetConfirmModal() {
    var modal = document.querySelector("[data-erankly-reset-modal]");

    if (!modal) {
      return;
    }

    var titleEl = modal.querySelector("[data-erankly-reset-modal-title]");
    var descEl = modal.querySelector("[data-erankly-reset-modal-desc]");
    var confirmBtn = modal.querySelector("[data-erankly-reset-modal-confirm]");
    var cancelBtn = modal.querySelector("[data-erankly-reset-modal-cancel]");
    var pendingTrigger = null;
    var lastFocused = null;

    function openModal(trigger) {
      pendingTrigger = trigger;
      lastFocused = document.activeElement;
      titleEl.textContent =
        trigger.getAttribute("data-erankly-reset-title") || "";
      descEl.textContent =
        trigger.getAttribute("data-erankly-reset-confirm") || "";
      confirmBtn.textContent =
        trigger.getAttribute("data-erankly-reset-button") ||
        confirmBtn.textContent;
      modal.hidden = false;
      confirmBtn.focus();
    }

    function closeModal() {
      modal.hidden = true;
      pendingTrigger = null;
      if (lastFocused && typeof lastFocused.focus === "function") {
        lastFocused.focus();
      }
    }

    function submitPendingReset() {
      if (!pendingTrigger) {
        return;
      }

      // The trigger button lives inside the settings page's own <form>, so
      // the POST is sent through a standalone form assembled here and
      // appended to <body> — nesting a <form> inside the settings form is
      // invalid HTML and browsers would route the submit to the wrong
      // (outer) form.
      var postForm = document.createElement("form");
      postForm.method = "post";
      postForm.action = pendingTrigger.getAttribute("data-erankly-reset-url");
      postForm.style.display = "none";

      var fields = {
        _wpnonce: pendingTrigger.getAttribute("data-erankly-reset-nonce"),
        erankly_reset_action: pendingTrigger.getAttribute(
          "data-erankly-reset-action",
        ),
      };

      Object.keys(fields).forEach(function (name) {
        var input = document.createElement("input");
        input.type = "hidden";
        input.name = name;
        input.value = fields[name];
        postForm.appendChild(input);
      });

      document.body.appendChild(postForm);
      postForm.submit();
    }

    document.addEventListener("click", function (event) {
      var trigger = event.target.closest(".erankly-reset-trigger");

      if (trigger) {
        openModal(trigger);
      }
    });

    confirmBtn.addEventListener("click", submitPendingReset);
    cancelBtn.addEventListener("click", closeModal);

    modal.addEventListener("click", function (event) {
      if (event.target === modal) {
        closeModal();
      }
    });

    document.addEventListener("keydown", function (event) {
      if (event.key === "Escape" && !modal.hidden) {
        closeModal();
      }
    });
  }
})();
