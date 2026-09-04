(function () {
  "use strict";

  var ER = window.ERanklyAdmin || {};

  function bindEach(selector, callbackName) {
    if (typeof ER[callbackName] !== "function") {
      return;
    }

    document.querySelectorAll(selector).forEach(ER[callbackName]);
  }

  function bindRoot(callbackName) {
    bindEach(".erankly-settings", callbackName);
  }

  document.addEventListener("DOMContentLoaded", function () {
    bindEach(".erankly-meta-box", "bindTabs");
    bindEach("[data-erankly-expandable]", "bindExpandablePanel");
    bindRoot("bindTabs");
    bindRoot("bindSettingsTabs");
    bindEach("[data-erankly-media-url-field]", "bindMediaUrlField");
    bindEach(".erankly-counted-field", "bindCharacterCounter");
    if (typeof ER.bindVariablePickers === "function") {
      ER.bindVariablePickers(document);
    }
    bindEach("[data-erankly-linked-defaults]", "bindLinkedDefaults");
    bindEach("[data-erankly-schema-builder]", "bindSchemaBuilder");
    bindEach("[data-erankly-schema-identity]", "bindSchemaIdentityField");
    bindEach("[data-erankly-user-search-wrap]", "bindUserSearch");
    bindEach("[data-erankly-local-business]", "bindLocalBusiness");
    bindEach("[data-erankly-file-dropzone]", "bindFileDropzone");
    bindEach("[data-erankly-segment-control]", "bindSegmentControl");

    bindRoot("bindAllSettingsAutosave");

    // Outside-click dismissal is handled per field in bindVariablePicker();
    // a global click closer here would fire on the very click that focuses a
    // field and close the menu the instant it opens.
    document.addEventListener("keydown", function (event) {
      if (event.key === "Escape" && typeof ER.closeVariablePicker === "function") {
        document
          .querySelectorAll("[data-erankly-variable-field]")
          .forEach(ER.closeVariablePicker);
      }
    });

    if (typeof ER.bindResetConfirmModal === "function") {
      ER.bindResetConfirmModal();
    }
  });

})();
