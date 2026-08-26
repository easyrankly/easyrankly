(function (ER) {
  "use strict";

  function prefersReducedMotion() {
    return (
      typeof window.matchMedia === "function" &&
      window.matchMedia("(prefers-reduced-motion: reduce)").matches
    );
  }

  function getSlidingIndicator(control) {
    var indicator = control.querySelector(".erankly-sliding-indicator");

    if (indicator) {
      return indicator;
    }

    indicator = document.createElement("span");
    indicator.className = "erankly-sliding-indicator is-positioning";
    indicator.setAttribute("aria-hidden", "true");
    control.prepend(indicator);

    return indicator;
  }

  function positionSlidingIndicator(control, activeItem, animate) {
    var indicator;

    if (!control) {
      return;
    }

    if (!activeItem || activeItem.hidden || activeItem.offsetWidth === 0) {
      indicator = control.querySelector(".erankly-sliding-indicator");
      control.classList.remove("has-sliding-indicator");

      if (indicator) {
        indicator.hidden = true;
      }

      return;
    }

    indicator = getSlidingIndicator(control);
    indicator.hidden = false;
    control.classList.add("has-sliding-indicator");
    indicator.classList.toggle(
      "is-positioning",
      !animate || prefersReducedMotion(),
    );
    indicator.style.width = activeItem.offsetWidth + "px";
    indicator.style.height = activeItem.offsetHeight + "px";
    indicator.style.transform =
      "translate3d(" +
      activeItem.offsetLeft +
      "px," +
      activeItem.offsetTop +
      "px,0)";

    if (!animate || prefersReducedMotion()) {
      // Apply the measured position before transitions are re-enabled, so the
      // first paint and responsive layout changes never slide in from (0, 0).
      indicator.getBoundingClientRect();
      indicator.classList.remove("is-positioning");
    }
  }

  function observeSlidingIndicator(control, getActiveItem) {
    var observer;

    if (
      typeof window.ResizeObserver !== "function" ||
      control.eranklySlidingObserver
    ) {
      return;
    }

    observer = new window.ResizeObserver(function () {
      positionSlidingIndicator(control, getActiveItem(), false);
    });
    observer.observe(control);
    control.eranklySlidingObserver = observer;
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

        if (tabList.hasAttribute("data-erankly-sliding-tabs")) {
          positionSlidingIndicator(tabList, tab, true);
        }

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

      if (tabList.hasAttribute("data-erankly-sliding-tabs")) {
        positionSlidingIndicator(
          tabList,
          tabs.filter(function (tab) {
            return tab.classList.contains("is-active");
          })[0],
          false,
        );
        observeSlidingIndicator(tabList, function () {
          return tabs.filter(function (tab) {
            return tab.classList.contains("is-active");
          })[0];
        });
      }

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
    var currentLink = tablist.querySelector('[aria-current="page"]');
    var requestedSubtab =
      tablist.getAttribute("data-erankly-active-subtab") || "";

    if (currentLink && sidebarToggleLabel) {
      sidebarToggleLabel.textContent = getTabLabel(currentLink);
    }

    if (requestedSubtab) {
      activateInnerTab(requestedSubtab);
    }
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
    var stateChanged = container.classList.contains("is-linked") !== isLinked;

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
        tab.setAttribute("tabindex", isActive ? "0" : "-1");
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
      positionSlidingIndicator(
        toggle,
        toggle.querySelector(isLinked ? ".is-yes" : ".is-no"),
        stateChanged,
      );
    }

    positionSlidingIndicator(
      container.querySelector("[data-erankly-sliding-tabs]"),
      isLinked
        ? null
        : tabs.filter(function (tab) {
            return tab.classList.contains("is-active");
          })[0],
      false,
    );

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
    observeSlidingIndicator(toggle, function () {
      return toggle.querySelector(
        container.classList.contains("is-linked") ? ".is-yes" : ".is-no",
      );
    });
  }

  ER.bindTabs = bindTabs;
  ER.bindSettingsTabs = bindSettingsTabs;
  ER.getLinkedFieldName = getLinkedFieldName;
  ER.getLinkedDefaultFields = getLinkedDefaultFields;
  ER.getLinkedDefaultSource = getLinkedDefaultSource;
  ER.syncLinkedDefaultFields = syncLinkedDefaultFields;
  ER.setLinkedDefaultsState = setLinkedDefaultsState;
  ER.bindLinkedDefaults = bindLinkedDefaults;
})(window.ERanklyAdmin = window.ERanklyAdmin || {});
