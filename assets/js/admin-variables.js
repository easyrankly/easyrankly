(function (ER) {
  "use strict";

  var documentClickBound = false;

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

    field
      .querySelectorAll('[data-erankly-variable][aria-selected="true"]')
      .forEach(function (option) {
        option.setAttribute("aria-selected", "false");
        option.classList.remove("is-active");
      });
  }

  // Reads the "word" the caret currently sits in (from the previous whitespace
  // up to the caret), the fragment the suggestions filter against, mirroring
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

    field
      .querySelectorAll("[data-erankly-variable-group]")
      .forEach(function (group) {
        group.hidden = !group.querySelector(
          "[data-erankly-variable]:not([hidden])",
        );
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

  var VARIABLE_TOKEN_PATTERN = /{{\s*([a-z0-9_]+)\s*}}/gi;

  // Label drawn in place of a token that has no example value to stand in for
  // it (e.g. {{term_description}} on a term with an empty description).
  function getVariableUnavailableLabel() {
    var config = window.eranklyVariablePreview;

    return (config && config.unavailableLabel) || "Preview not available";
  }

  function buildVariablePreviewValues(examples, siteName, siteDescription) {
    var resolved = examples ? Object.assign({}, examples) : {};

    if (siteName && !Object.prototype.hasOwnProperty.call(resolved, "site_name")) {
      resolved.site_name = siteName;
    }

    if (
      siteDescription &&
      !Object.prototype.hasOwnProperty.call(resolved, "site_description")
    ) {
      resolved.site_description = siteDescription;
    }

    return resolved;
  }

  // Shows a resolved friendly value (e.g. the real site name or tagline, or
  // the first post's title as a stand-in for {{post_title}} on fields that
  // aren't tied to any single post) over a {{variable}} field while it's
  // blurred, and reveals the raw token again on focus so it stays editable.
  // Only touches the overlay text node, never control.value itself. The
  // autosave serializer (bindSettingsAutosave) reads field.value straight
  // off the DOM, so swapping the real value would risk saving the resolved
  // text instead of the token on a mistimed autosave. Any token with no
  // example (e.g. a post type with no published posts yet) previews as the
  // "Preview not available" label instead of the literal token.
  function resolveVariablePreviewText(
    raw,
    examples,
    siteName,
    siteDescription,
  ) {
    var resolved = buildVariablePreviewValues(
      examples,
      siteName,
      siteDescription,
    );

    return raw.replace(VARIABLE_TOKEN_PATTERN, function (match, key) {
      var normalizedKey = key.toLowerCase();

      if (Object.prototype.hasOwnProperty.call(resolved, normalizedKey)) {
        return resolved[normalizedKey];
      }

      return getVariableUnavailableLabel();
    });
  }

  // Paints the overlay as DOM nodes rather than one text run so unresolvable
  // tokens can carry their own colour. Returns false when raw holds no token
  // at all, i.e. there is nothing to preview and the field shows itself.
  function renderVariablePreview(
    preview,
    raw,
    examples,
    siteName,
    siteDescription,
  ) {
    var resolved = buildVariablePreviewValues(
      examples,
      siteName,
      siteDescription,
    );
    var pattern = new RegExp(VARIABLE_TOKEN_PATTERN.source, "gi");
    var text = document.createElement("span");
    var lastIndex = 0;
    var found = false;
    var match;

    text.className = "erankly-variable-preview-text";

    while ((match = pattern.exec(raw)) !== null) {
      var normalizedKey = match[1].toLowerCase();

      if (match.index > lastIndex) {
        text.appendChild(
          document.createTextNode(raw.slice(lastIndex, match.index)),
        );
      }

      if (Object.prototype.hasOwnProperty.call(resolved, normalizedKey)) {
        text.appendChild(document.createTextNode(resolved[normalizedKey]));
      } else {
        var unavailable = document.createElement("span");

        unavailable.className = "erankly-variable-preview-unavailable";
        unavailable.textContent = getVariableUnavailableLabel();
        text.appendChild(unavailable);
      }

      found = true;
      lastIndex = pattern.lastIndex;
    }

    if (!found) {
      return false;
    }

    if (lastIndex < raw.length) {
      text.appendChild(document.createTextNode(raw.slice(lastIndex)));
    }

    preview.textContent = "";
    preview.appendChild(text);

    return true;
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

      if (
        raw &&
        renderVariablePreview(
          preview,
          raw,
          examples,
          config.siteName,
          config.siteDescription,
        )
      ) {
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

    if (control.id) {
      menu.id = control.id + "-variables";
    } else if (!menu.id) {
      menu.id = "erankly-variable-listbox";
    }
    control.setAttribute("role", "combobox");
    control.setAttribute("aria-autocomplete", "list");
    control.setAttribute("aria-controls", menu.id);
    control.setAttribute("aria-haspopup", "listbox");
    control.setAttribute("aria-expanded", "false");

    // Give each option a stable id so aria-activedescendant can point at it.
    field
      .querySelectorAll("[data-erankly-variable]")
      .forEach(function (option, index) {
        if (!option.id) {
          option.id = menu.id + "-option-" + index;
        }
        option.setAttribute("aria-selected", "false");
      });

    // Per-field open state. `visibleOptions` is the currently matching list and
    // `activeIndex` the keyboard-highlighted entry within it.
    var visibleOptions = [];
    var activeIndex = -1;

    function highlight(index) {
      if (activeIndex >= 0 && visibleOptions[activeIndex]) {
        visibleOptions[activeIndex].classList.remove("is-active");
        visibleOptions[activeIndex].setAttribute("aria-selected", "false");
      }

      activeIndex = index;

      if (index >= 0 && visibleOptions[index]) {
        visibleOptions[index].classList.add("is-active");
        visibleOptions[index].setAttribute("aria-selected", "true");
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

  }

  function bindVariablePickerDocumentListener() {
    if (documentClickBound) {
      return;
    }

    documentClickBound = true;
    document.addEventListener("click", function (event) {
      document
        .querySelectorAll("[data-erankly-variable-field]")
        .forEach(function (field) {
          if (!field.contains(event.target)) {
            closeVariablePicker(field);
          }
        });
    });
  }

  function bindVariablePickers(container) {
    bindVariablePickerDocumentListener();
    container
      .querySelectorAll("[data-erankly-variable-field]")
      .forEach(bindVariablePicker);
  }

  ER.closeVariablePicker = closeVariablePicker;
  ER.getActiveVariableToken = getActiveVariableToken;
  ER.filterVariablePicker = filterVariablePicker;
  ER.insertVariable = insertVariable;
  ER.resolveVariablePreviewText = resolveVariablePreviewText;
  ER.bindVariablePreview = bindVariablePreview;
  ER.renderVariablePreview = renderVariablePreview;
  ER.bindVariablePicker = bindVariablePicker;
  ER.bindVariablePickers = bindVariablePickers;
})(window.ERanklyAdmin = window.ERanklyAdmin || {});
