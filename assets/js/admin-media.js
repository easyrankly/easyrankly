(function (ER) {
  "use strict";

  function bindMediaUrlField(field) {
    var selectButton = field.querySelector("[data-erankly-select-media-url]");
    var clearButton = field.querySelector("[data-erankly-clear-media-url]");
    var input = field.querySelector("[data-erankly-media-url-input]");
    var idInput = field.querySelector("[data-erankly-media-url-id]");
    var preview = field.querySelector("[data-erankly-media-url-preview]");
    var frame;
    var isMediaSelection = false;

    if (
      !selectButton ||
      !clearButton ||
      !input ||
      !window.wp ||
      !window.wp.media
    ) {
      return;
    }

    function updatePreview(url) {
      if (!preview) {
        return;
      }

      preview.innerHTML = "";

      if (!url || url.indexOf("{{") !== -1) {
        return;
      }

      var image = document.createElement("img");

      image.src = url;
      image.alt = "";
      preview.appendChild(image);
    }

    selectButton.addEventListener("click", function () {
      if (frame) {
        frame.open();
        return;
      }

      frame = window.wp.media({
        title: selectButton.textContent,
        button: {
          text: selectButton.textContent,
        },
        multiple: false,
      });

      frame.on("select", function () {
        var attachment = frame.state().get("selection").first().toJSON();
        var url = attachment.url || "";

        isMediaSelection = true;
        input.value = url;

        if (idInput) {
          idInput.value = attachment.id || "";
        }

        updatePreview(url);
        input.dispatchEvent(new Event("input", { bubbles: true }));
        input.dispatchEvent(new Event("change", { bubbles: true }));
        isMediaSelection = false;
      });

      frame.open();
    });

    clearButton.addEventListener("click", function () {
      input.value = "";

      if (idInput) {
        idInput.value = "";
      }

      updatePreview("");
      input.dispatchEvent(new Event("input", { bubbles: true }));
      input.dispatchEvent(new Event("change", { bubbles: true }));
    });

    input.addEventListener("input", function () {
      if (idInput && !isMediaSelection) {
        idInput.value = "";
      }

      updatePreview(input.value);
    });
  }

  ER.bindMediaUrlField = bindMediaUrlField;
})(window.ERanklyAdmin = window.ERanklyAdmin || {});
