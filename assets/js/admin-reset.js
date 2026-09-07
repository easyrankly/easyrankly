(function (ER) {
  "use strict";

  // The settings root is replaced wholesale after a Simplified mode save, so
  // this module is re-bound against a brand-new modal node. Document-level
  // listeners must therefore be registered exactly once and always resolve the
  // *current* modal, instead of closing over the one present at first bind:
  // the old code kept a detached node and clicking Reset silently did nothing.
  var documentListenersBound = false;
  var pendingTrigger = null;
  var lastFocused = null;

  function getModal() {
    return document.querySelector("[data-erankly-reset-modal]");
  }

  function getFocusable(modal) {
    return Array.prototype.filter.call(
      modal.querySelectorAll(
        'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])',
      ),
      function (node) {
        return !node.disabled && node.offsetParent !== null;
      },
    );
  }

  function openModal(trigger) {
    var modal = getModal();

    if (!modal) {
      return;
    }

    var titleEl = modal.querySelector("[data-erankly-reset-modal-title]");
    var descEl = modal.querySelector("[data-erankly-reset-modal-desc]");
    var confirmBtn = modal.querySelector("[data-erankly-reset-modal-confirm]");
    var cancelBtn = modal.querySelector("[data-erankly-reset-modal-cancel]");

    pendingTrigger = trigger;
    lastFocused = document.activeElement;
    titleEl.textContent = trigger.getAttribute("data-erankly-reset-title") || "";
    descEl.textContent =
      trigger.getAttribute("data-erankly-reset-confirm") || "";
    confirmBtn.textContent =
      trigger.getAttribute("data-erankly-reset-button") ||
      confirmBtn.textContent;
    modal.hidden = false;
    document.body.classList.add("erankly-modal-open");

    // An alertdialog opens on the *least* destructive action (APG): focusing
    // "Reset" made Enter wipe the site straight after opening the dialog.
    (cancelBtn || confirmBtn).focus();
  }

  function closeModal() {
    var modal = getModal();

    if (modal) {
      modal.hidden = true;
    }

    document.body.classList.remove("erankly-modal-open");
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

  function bindResetConfirmModal() {
    var modal = getModal();

    if (!modal) {
      return;
    }

    // Idempotent per node: a re-bind after a DOM refresh must not stack a
    // second set of listeners on the same buttons.
    if (!modal.eranklyResetBound) {
      var confirmBtn = modal.querySelector("[data-erankly-reset-modal-confirm]");
      var cancelBtn = modal.querySelector("[data-erankly-reset-modal-cancel]");

      modal.eranklyResetBound = true;

      if (confirmBtn) {
        confirmBtn.addEventListener("click", submitPendingReset);
      }

      if (cancelBtn) {
        cancelBtn.addEventListener("click", closeModal);
      }

      modal.addEventListener("click", function (event) {
        if (event.target === modal) {
          closeModal();
        }
      });
    }

    if (documentListenersBound) {
      return;
    }

    documentListenersBound = true;

    document.addEventListener("click", function (event) {
      var trigger = event.target.closest(".erankly-reset-trigger");

      if (trigger) {
        openModal(trigger);
      }
    });

    document.addEventListener("keydown", function (event) {
      var current = getModal();

      if (!current || current.hidden) {
        return;
      }

      if (event.key === "Escape") {
        closeModal();
        return;
      }

      // aria-modal alone does not trap the Tab key: without this the focus
      // walked out of the dialog into the WordPress admin chrome behind it.
      if (event.key !== "Tab") {
        return;
      }

      var focusable = getFocusable(current);

      if (focusable.length === 0) {
        return;
      }

      var first = focusable[0];
      var last = focusable[focusable.length - 1];

      if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
      }
    });
  }

  ER.bindResetConfirmModal = bindResetConfirmModal;
})(window.ERanklyAdmin = window.ERanklyAdmin || {});
