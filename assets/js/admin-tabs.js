(function (ER) {
  "use strict";

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

    // Top-level settings tabs are real links and only the active panel exists
    // in the response. Keep JavaScript limited to the mobile navigation and
    // inner tab groups; navigation must continue to work with JS disabled.
    if (tablist.hasAttribute("data-erankly-server-tabs")) {
      var currentLink = tablist.querySelector('[aria-current="page"]');
      var requestedSubtab =
        tablist.getAttribute("data-erankly-active-subtab") || "";

      if (currentLink && sidebarToggleLabel) {
        sidebarToggleLabel.textContent = getTabLabel(currentLink);
      }

      if (requestedSubtab) {
        activateInnerTab(requestedSubtab);
      }

      return;
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
        // today. It is kept as-is rather than simplified, since it's still what
        // correctly hides the button for a third-party extension tab (no
        // control over whether those carry the standalone attribute) and for
        // any future built-in panel that doesn't autosave.
        settingsSubmit.hidden =
          standalone ||
          target === "settings-health" ||
          target === "settings-links" ||
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

  ER.bindTabs = bindTabs;
  ER.bindSettingsTabs = bindSettingsTabs;
  ER.bindSimplifiedMode = bindSimplifiedMode;
  ER.getLinkedFieldName = getLinkedFieldName;
  ER.getLinkedDefaultFields = getLinkedDefaultFields;
  ER.getLinkedDefaultSource = getLinkedDefaultSource;
  ER.syncLinkedDefaultFields = syncLinkedDefaultFields;
  ER.setLinkedDefaultsState = setLinkedDefaultsState;
  ER.bindLinkedDefaults = bindLinkedDefaults;
})(window.ERanklyAdmin = window.ERanklyAdmin || {});
