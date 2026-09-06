(function (ER) {
  "use strict";

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

  function isValidJsonLd(value) {
    if (window.eranklyJsonLd && typeof window.eranklyJsonLd.isValid === "function") {
      return window.eranklyJsonLd.isValid(value);
    }

    return window.eranklyJsonLd
      ? window.eranklyJsonLd.validate(value).valid
      : String(value || "").trim() === "";
  }

  function jsonLdValidation(value) {
    if (window.eranklyJsonLd && typeof window.eranklyJsonLd.validate === "function") {
      return window.eranklyJsonLd.validate(value);
    }

    var fallback =
      window.wp && window.wp.i18n && typeof window.wp.i18n.__ === "function"
        ? window.wp.i18n.__(
            "This is not valid JSON, so it cannot be used as JSON-LD.",
            "easyrankly"
          )
        : "This is not valid JSON, so it cannot be used as JSON-LD.";

    return isValidJsonLd(value)
      ? { valid: true, code: "", message: "" }
      : {
          valid: false,
          code: "syntax",
          message: fallback,
        };
  }

  function focusInvalidJsonLd(root) {
    var scope = root || document;
    var input = scope.querySelector(
      '[data-erankly-json-ld-input][aria-invalid="true"]'
    );

    if (!input) {
      return false;
    }

    var block = input.closest("[data-erankly-schema-block]");

    if (block && typeof setSchemaBlockExpanded === "function") {
      setSchemaBlockExpanded(block, true);
    }

    if (typeof input.focus === "function") {
      input.focus();
    }

    return true;
  }

  function bindJsonLdValidation(block) {
    var input = block.querySelector("[data-erankly-json-ld-input]");
    var error = block.querySelector("[data-erankly-json-ld-error]");

    if (!input || !error) {
      return;
    }

    function validate() {
      var result = jsonLdValidation(input.value);
      var invalid = !result.valid;

      error.hidden = !invalid;
      if (result.message) {
        error.textContent = result.message;
      }
      input.classList.toggle("erankly-is-invalid", invalid);
      input.setAttribute("aria-invalid", invalid ? "true" : "false");
    }

    input.addEventListener("input", validate);
    input.addEventListener("blur", validate);
    validate();
  }

  function bindSchemaBlock(block) {
    var removeButton = block.querySelector("[data-erankly-remove-schema]");

    bindJsonLdValidation(block);
    bindSchemaTargeting(block);

    // The variables module is not enqueued on every surface that reuses the
    // schema builder (e.g. custom code blocks carry no variable pickers).
    // Guard so one missing module cannot break Add/Delete on those screens.
    if (typeof ER.bindVariablePickers === "function") {
      ER.bindVariablePickers(block);
    }

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
      var schemaRoot = builder.closest("[data-erankly-post-schema]");
      var modeSelect = schemaRoot
        ? schemaRoot.querySelector("[data-erankly-schema-mode-select]")
        : null;

      if (modeSelect && modeSelect.value === "default") {
        modeSelect.value = "merge";
        modeSelect.dispatchEvent(new Event("change", { bubbles: true }));
      }

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

  function bindSchemaTargeting(block) {
    var contextInputs = block.querySelectorAll("[data-erankly-target-context]");
    var postTypes = block.querySelector('[data-erankly-targeting-for="post-types"]');
    var includeExclude = block.querySelector(
      '[data-erankly-targeting-for="include-exclude"]',
    );

    if (!contextInputs.length) {
      return;
    }

    function update() {
      var selected = [];
      var usesPostTypes = false;
      var usesInclude = false;

      contextInputs.forEach(function (input) {
        if (input.checked) {
          selected.push(input.value);
        }
      });

      usesPostTypes =
        selected.indexOf("singular") !== -1 ||
        selected.indexOf("post_type_archive") !== -1;
      usesInclude =
        selected.indexOf("singular") !== -1 ||
        selected.indexOf("taxonomy") !== -1 ||
        selected.indexOf("author") !== -1;

      if (postTypes) {
        postTypes.hidden = !usesPostTypes;
      }

      if (includeExclude) {
        includeExclude.hidden = !usesInclude;
      }
    }

    contextInputs.forEach(function (input) {
      input.addEventListener("change", update);
    });

    update();
  }

  function bindPostSchemaPanel(root) {
    var modeSelect = root.querySelector("[data-erankly-schema-mode-select]");
    var generated = root.querySelector("[data-erankly-schema-generated-controls]");
    var custom = root.querySelector("[data-erankly-schema-custom-controls]");
    var switchMerge = root.querySelector("[data-erankly-schema-switch-merge]");

    function hasCustomJson() {
      return Array.prototype.some.call(
        root.querySelectorAll("[data-erankly-json-ld-input]"),
        function (input) {
          return String(input.value || "").trim() !== "";
        },
      );
    }

    function update() {
      var mode = modeSelect ? modeSelect.value : "default";
      var customPresent = hasCustomJson();

      root.setAttribute("data-erankly-schema-mode", mode);

      root.querySelectorAll("[data-erankly-schema-notice]").forEach(function (notice) {
        var key = notice.getAttribute("data-erankly-schema-notice");
        var show = false;

        if (key === "default-custom") {
          show = mode === "default" && customPresent;
        } else if (key === "replace-empty") {
          show = mode === "replace" && !customPresent;
        } else if (key === "disabled") {
          show = mode === "disabled";
        }

        notice.hidden = !show;
      });

      if (generated) {
        generated.hidden = mode === "disabled";
      }

      if (custom) {
        custom.hidden = mode === "disabled";
      }
    }

    if (modeSelect) {
      modeSelect.addEventListener("change", update);
    }

    if (switchMerge && modeSelect) {
      switchMerge.addEventListener("click", function () {
        modeSelect.value = "merge";
        modeSelect.dispatchEvent(new Event("change", { bubbles: true }));
      });
    }

    root.addEventListener("input", update);
    update();
  }

  function bindSchemaIdentityField(field) {
    var container = field.closest(".erankly-settings");
    var personField = container
      ? container.querySelector("[data-erankly-person-reference-field]")
      : null;

    if (!personField) {
      return;
    }

    function updatePersonField() {
      personField.hidden = field.value !== "person";
      syncOrganizationFieldsVisibility(container);
    }

    field.addEventListener("change", updatePersonField);
    updatePersonField();
  }

  function syncOrganizationFieldsVisibility(container) {
    var identity = container
      ? container.querySelector("[data-erankly-schema-identity]")
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

  ER.isValidJsonLd = isValidJsonLd;
  ER.setSchemaBlockExpanded = setSchemaBlockExpanded;
  ER.updateSchemaBuilderState = updateSchemaBuilderState;
  ER.bindSchemaBlock = bindSchemaBlock;
  ER.bindSchemaBuilder = bindSchemaBuilder;
  ER.bindSchemaIdentityField = bindSchemaIdentityField;
  ER.bindPostSchemaPanel = bindPostSchemaPanel;
  ER.syncOrganizationFieldsVisibility = syncOrganizationFieldsVisibility;
  ER.focusInvalidJsonLd = focusInvalidJsonLd;

  document.addEventListener("DOMContentLoaded", function () {
    if (document.getElementById("erankly-invalid-json-ld-notice")) {
      window.setTimeout(function () {
        focusInvalidJsonLd(document);
      }, 0);
    }
  });

  document.addEventListener(
    "submit",
    function (event) {
      var form = event.target;

      if (!form || !form.querySelector) {
        return;
      }

      window.setTimeout(function () {
        focusInvalidJsonLd(form);
      }, 0);
    },
    true
  );
})(window.ERanklyAdmin = window.ERanklyAdmin || {});
