(function (ER) {
  "use strict";

  function bindResetConfirmModal() {
    var modal = document.querySelector("[data-erankly-reset-modal]");

    if (!modal) {
      return;
    }

    var titleEl = modal.querySelector("[data-erankly-reset-modal-title]");
    var descEl = modal.querySelector("[data-erankly-reset-modal-desc]");
    var confirmBtn = modal.querySelector("[data-erankly-reset-modal-confirm]");
    var cancelBtn = modal.querySelector("[data-erankly-reset-modal-cancel]");
    var pendingTrigger = null;
    var lastFocused = null;

    function openModal(trigger) {
      pendingTrigger = trigger;
      lastFocused = document.activeElement;
      titleEl.textContent =
        trigger.getAttribute("data-erankly-reset-title") || "";
      descEl.textContent =
        trigger.getAttribute("data-erankly-reset-confirm") || "";
      confirmBtn.textContent =
        trigger.getAttribute("data-erankly-reset-button") ||
        confirmBtn.textContent;
      modal.hidden = false;
      confirmBtn.focus();
    }

    function closeModal() {
      modal.hidden = true;
      pendingTrigger = null;
      if (lastFocused && typeof lastFocused.focus === "function") {
        lastFocused.focus();
      }
    }

    function submitPendingReset() {
      if (!pendingTrigger) {
        return;
      }

      // The trigger button lives inside the settings page's own <form>, so
      // the POST is sent through a standalone form assembled here and
      // appended to <body>. Nesting a <form> inside the settings form is
      // invalid HTML and browsers would route the submit to the wrong
      // (outer) form.
      var postForm = document.createElement("form");
      postForm.method = "post";
      postForm.action = pendingTrigger.getAttribute("data-erankly-reset-url");
      postForm.style.display = "none";

      var fields = {
        _wpnonce: pendingTrigger.getAttribute("data-erankly-reset-nonce"),
        erankly_reset_action: pendingTrigger.getAttribute(
          "data-erankly-reset-action",
        ),
      };

      Object.keys(fields).forEach(function (name) {
        var input = document.createElement("input");
        input.type = "hidden";
        input.name = name;
        input.value = fields[name];
        postForm.appendChild(input);
      });

      document.body.appendChild(postForm);
      postForm.submit();
    }

    document.addEventListener("click", function (event) {
      var trigger = event.target.closest(".erankly-reset-trigger");

      if (trigger) {
        openModal(trigger);
      }
    });

    confirmBtn.addEventListener("click", submitPendingReset);
    cancelBtn.addEventListener("click", closeModal);

    modal.addEventListener("click", function (event) {
      if (event.target === modal) {
        closeModal();
      }
    });

    document.addEventListener("keydown", function (event) {
      if (event.key === "Escape" && !modal.hidden) {
        closeModal();
      }
    });
  }

  ER.bindResetConfirmModal = bindResetConfirmModal;
})(window.ERanklyAdmin = window.ERanklyAdmin || {});
