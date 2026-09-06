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

  ER.bindExpandablePanel = bindExpandablePanel;
})(window.ERanklyAdmin = window.ERanklyAdmin || {});
