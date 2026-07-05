(function () {
  "use strict";

  var ER = window.ERanklyAdmin || {};

  document.addEventListener("DOMContentLoaded", function () {
    document.querySelectorAll(".erankly-meta-box").forEach(ER.bindTabs);
    document.querySelectorAll(".erankly-meta-box").forEach(ER.bindSeoChecklist);

    if (window.eranklyLinkSuggestionsUi) {
      document
        .querySelectorAll(".erankly-meta-box")
        .forEach(window.eranklyLinkSuggestionsUi.bindClassicInternalLinks);
    }
    document
      .querySelectorAll("[data-erankly-expandable]")
      .forEach(ER.bindExpandablePanel);
    document.querySelectorAll(".erankly-settings").forEach(ER.bindTabs);
    document.querySelectorAll(".erankly-settings").forEach(ER.bindSettingsTabs);
    document.querySelectorAll(".erankly-settings").forEach(ER.bindSimplifiedMode);
    document
      .querySelectorAll("[data-erankly-media-url-field]")
      .forEach(ER.bindMediaUrlField);
    document
      .querySelectorAll(".erankly-counted-field")
      .forEach(ER.bindCharacterCounter);
    ER.bindVariablePickers(document);
    document
      .querySelectorAll("[data-erankly-linked-defaults]")
      .forEach(ER.bindLinkedDefaults);
    document
      .querySelectorAll("[data-erankly-schema-builder]")
      .forEach(ER.bindSchemaBuilder);
    document
      .querySelectorAll("[data-erankly-schema-identity]")
      .forEach(ER.bindSchemaIdentityField);
    document
      .querySelectorAll("[data-erankly-user-search-wrap]")
      .forEach(ER.bindUserSearch);
    document
      .querySelectorAll("[data-erankly-local-business]")
      .forEach(ER.bindLocalBusiness);
    document
      .querySelectorAll("[data-erankly-file-dropzone]")
      .forEach(ER.bindFileDropzone);
    document
      .querySelectorAll("[data-erankly-segment-control]")
      .forEach(ER.bindSegmentControl);
    var bloatPanel = document.getElementById("erankly-settings-panel-bloat");
    if (bloatPanel) {
      ER.bindBloatToggle(bloatPanel);
    }

    document
      .querySelectorAll(".erankly-settings")
      .forEach(ER.bindAllSettingsAutosave);

    // Outside-click dismissal is handled per field in bindVariablePicker();
    // a global click closer here would fire on the very click that focuses a
    // field and close the menu the instant it opens.
    document.addEventListener("keydown", function (event) {
      if (event.key === "Escape") {
        document
          .querySelectorAll("[data-erankly-variable-field]")
          .forEach(ER.closeVariablePicker);
      }
    });

    ER.bindResetConfirmModal();
  });

})();
