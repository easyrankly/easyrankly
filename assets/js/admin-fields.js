(function (ER) {
  "use strict";

  function bindCharacterCounter(field) {
    var limit = parseInt(field.getAttribute("data-erankly-limit"), 10);
    var counterId = field.getAttribute("data-erankly-counter");
    var warning = field.getAttribute("data-erankly-warning") || "too long";
    var counter = counterId ? document.getElementById(counterId) : null;

    if (!counter || !limit) {
      return;
    }

    function updateCounter() {
      var length = field.value.length;
      var isTooLong = length > limit;

      counter.textContent = isTooLong
        ? length + "/" + limit + " - " + warning
        : length + "/" + limit;
      counter.classList.toggle("is-warning", isTooLong);
    }

    field.addEventListener("input", updateCounter);
    updateCounter();
  }

  function bindFileDropzone(dropzone) {
    var input = dropzone.querySelector("[data-erankly-file-dropzone-input]");
    var textEl = dropzone.querySelector("[data-erankly-file-dropzone-text]");

    if (!input || !textEl) {
      return;
    }

    var defaultText = textEl.innerHTML;

    function showFileName() {
      var file = input.files && input.files[0];

      textEl.innerHTML = defaultText;

      if (file) {
        var nameEl = document.createElement("span");
        nameEl.className = "erankly-dropzone-filename";
        nameEl.textContent = file.name;
        textEl.appendChild(nameEl);
      }
    }

    input.addEventListener("change", showFileName);

    ["dragenter", "dragover"].forEach(function (type) {
      dropzone.addEventListener(type, function (event) {
        event.preventDefault();
        dropzone.classList.add("is-dragover");
      });
    });

    ["dragleave", "dragend", "drop"].forEach(function (type) {
      dropzone.addEventListener(type, function (event) {
        event.preventDefault();
        dropzone.classList.remove("is-dragover");
      });
    });

    dropzone.addEventListener("drop", function (event) {
      var files = event.dataTransfer && event.dataTransfer.files;

      if (files && files.length) {
        input.files = files;
        showFileName();
      }
    });
  }

  ER.bindCharacterCounter = bindCharacterCounter;
  ER.bindFileDropzone = bindFileDropzone;
})(window.ERanklyAdmin = window.ERanklyAdmin || {});
