(function (ER) {
  "use strict";

  function bindExpandablePanel(panel) {
    var i18n = window.eranklyPanels || {};
    var toggle = panel.querySelector("[data-erankly-expand-toggle]");
    var storageKey = panel.id ? "erankly_panel_expanded_" + panel.id : null;

    function setExpanded(expanded) {
      panel.classList.toggle("erankly-panel-expanded", expanded);
      if (toggle) {
        toggle.setAttribute("aria-pressed", expanded ? "true" : "false");
        toggle.title = expanded
          ? i18n.collapse || "Collapse table"
          : i18n.expand || "Expand table";
      }
    }

    if (toggle) {
      if (storageKey) {
        try {
          if (window.localStorage.getItem(storageKey) === "1") {
            setExpanded(true);
          }
        } catch (e) {}
      }

      toggle.addEventListener("click", function () {
        var expanded = !panel.classList.contains("erankly-panel-expanded");
        setExpanded(expanded);
        if (storageKey) {
          try {
            window.localStorage.setItem(storageKey, expanded ? "1" : "0");
          } catch (e) {}
        }
      });
    }

    var filter = panel.querySelector("[data-erankly-filter]");
    if (filter) {
      filter.addEventListener("input", function () {
        var term = filter.value.trim().toLowerCase();
        var rows = panel.querySelectorAll("[data-erankly-filter-row]");
        for (var i = 0; i < rows.length; i++) {
          var hay = rows[i].getAttribute("data-filter-text") || "";
          rows[i].hidden = term !== "" && hay.indexOf(term) === -1;
        }
      });
    }
  }

  /**
   * Keeps segment-control pill labels in sync with their radios.
   *
   * @param {HTMLElement} control Segment control wrapper.
   * @return void
   */
  function bindSegmentControl(control) {
    var radios = control.querySelectorAll('input[type="radio"]');

    function syncActive() {
      radios.forEach(function (radio) {
        var label = radio.closest(".erankly-tab");

        if (label) {
          label.classList.toggle("is-active", radio.checked);
          label.classList.toggle("nav-tab-active", radio.checked);
        }
      });
    }

    radios.forEach(function (radio) {
      radio.addEventListener("change", syncActive);
    });
    syncActive();
  }

  ER.bindExpandablePanel = bindExpandablePanel;
  ER.bindSegmentControl = bindSegmentControl;
})(window.ERanklyAdmin = window.ERanklyAdmin || {});
