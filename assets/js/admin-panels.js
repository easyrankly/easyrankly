(function (ER) {
  "use strict";

  function bindExpandablePanel(panel) {
    var i18n = window.eranklyPanels || {};
    var toggle = panel.querySelector("[data-erankly-expand-toggle]");
    var storageKey = panel.id ? "erankly_panel_expanded_" + panel.id : null;

    // Below this width the expanded table is a fixed layout squeezed into a
    // phone: seven columns end up ~45px each. It matches the breakpoint the
    // stylesheet already uses to stack the panel toolbar.
    var EXPAND_MIN_WIDTH = 960;

    function canExpand() {
      return (
        typeof window.matchMedia !== "function" ||
        window.matchMedia("(min-width: " + EXPAND_MIN_WIDTH + "px)").matches
      );
    }

    function setExpanded(expanded) {
      panel.classList.toggle("erankly-panel-expanded", expanded);
      if (toggle) {
        var label = expanded
          ? i18n.collapse || "Collapse table"
          : i18n.expand || "Expand table";

        toggle.setAttribute("aria-pressed", expanded ? "true" : "false");
        toggle.title = label;

        // The visible label is an icon, so this span is the only name a screen
        // reader gets: leaving it at "Expand table" while aria-pressed said
        // true announced the opposite of the button's state.
        var srText = toggle.querySelector(".screen-reader-text");

        if (srText) {
          srText.textContent = label;
        }
      }
    }

    if (toggle) {
      if (storageKey) {
        try {
          // The stored state is not per-breakpoint: restoring an expand made on
          // a desktop would break the layout when the same user opens the page
          // on a phone.
          if (window.localStorage.getItem(storageKey) === "1" && canExpand()) {
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

  ER.bindExpandablePanel = bindExpandablePanel;
})(window.ERanklyAdmin = window.ERanklyAdmin || {});
