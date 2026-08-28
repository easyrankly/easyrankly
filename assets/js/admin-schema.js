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

  function bindSchemaBlock(block) {
    var removeButton = block.querySelector("[data-erankly-remove-schema]");

    ER.bindVariablePickers(block);

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

  ER.setSchemaBlockExpanded = setSchemaBlockExpanded;
  ER.updateSchemaBuilderState = updateSchemaBuilderState;
  ER.bindSchemaBlock = bindSchemaBlock;
  ER.bindSchemaBuilder = bindSchemaBuilder;
  ER.bindSchemaIdentityField = bindSchemaIdentityField;
  ER.syncOrganizationFieldsVisibility = syncOrganizationFieldsVisibility;
})(window.ERanklyAdmin = window.ERanklyAdmin || {});
