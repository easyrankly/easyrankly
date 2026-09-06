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

  // Mirrors erankly_decode_custom_json_ld(): one object, a list of objects, or
  // an object carrying @graph. Anything else is dropped server-side, so it is
  // reported here instead of vanishing on save. Placeholders are swapped for a
  // string first — {{post_title}} is not valid JSON on its own.
  function isValidJsonLd(value) {
    var text = value.replace(/{{\s*[a-z0-9_]+\s*}}/gi, "x");
    var parsed;

    if (text.trim() === "") {
      return true;
    }

    try {
      parsed = JSON.parse(text);
    } catch (error) {
      return false;
    }

    if (!parsed || typeof parsed !== "object") {
      return false;
    }

    if (Array.isArray(parsed)) {
      return parsed.some(function (entry) {
        return entry && typeof entry === "object" && !Array.isArray(entry);
      });
    }

    if (Array.isArray(parsed["@graph"])) {
      return parsed["@graph"].some(function (entry) {
        return entry && typeof entry === "object" && !Array.isArray(entry);
      });
    }

    return Object.keys(parsed).some(function (key) {
      return key !== "@context";
    });
  }

  function bindJsonLdValidation(block) {
    var input = block.querySelector("[data-erankly-json-ld-input]");
    var error = block.querySelector("[data-erankly-json-ld-error]");

    if (!input || !error) {
      return;
    }

    function validate() {
      var invalid = !isValidJsonLd(input.value);

      error.hidden = !invalid;
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
  ER.syncOrganizationFieldsVisibility = syncOrganizationFieldsVisibility;
})(window.ERanklyAdmin = window.ERanklyAdmin || {});
