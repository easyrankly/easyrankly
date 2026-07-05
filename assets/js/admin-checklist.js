(function (ER) {
  "use strict";

  function checklistResolveVariables(text, postTitle, siteName) {
    return String(text || "")
      .replace(/{{\s*([a-z0-9_]+)\s*}}/gi, function (match, key) {
        switch (String(key || "").toLowerCase()) {
          case "post_title":
          case "seo_title":
            return postTitle;
          case "site_name":
            return siteName;
          default:
            return "";
        }
      })
      .replace(/\s+/g, " ")
      .trim();
  }

  function checklistFirstContentImage(content) {
    if (!content) {
      return "";
    }

    var document = new window.DOMParser().parseFromString(content, "text/html");
    var images = document.querySelectorAll("img[src]");

    for (var index = 0; index < images.length; index++) {
      var image = images[index];

      if (image.closest("pre, code")) {
        continue;
      }

      var src = image.getAttribute("src") || "";

      try {
        var url = new URL(src);

        if ("http:" === url.protocol || "https:" === url.protocol) {
          return url.href;
        }
      } catch (error) {
        // Ignore relative or malformed URLs.
      }
    }

    return "";
  }

  function checklistStripContent(content) {
    var document = new window.DOMParser().parseFromString(
      String(content || ""),
      "text/html",
    );

    return (document.body.textContent || "").replace(/\s+/g, " ").trim();
  }

  function checklistTextWithinLimit(text, limit) {
    var normalized = String(text || "").replace(/\s+/g, " ").trim();

    if ("" === normalized) {
      return false;
    }

    return normalized.length <= limit;
  }

  function bindSeoChecklist(root) {
    var checklist = root.querySelector("[data-erankly-seo-checklist]");
    var config = window.eranklyChecklist || {};

    if (!checklist) {
      return;
    }

    var count = checklist.querySelector("[data-erankly-seo-checklist-count]");
    var statusClasses = ["is-incomplete", "is-partial", "is-complete"];
    var titleLimit = config.titleLimit || 65;
    var descriptionLimit = config.descriptionLimit || 160;
    var minContentLength = config.minContentLength || 300;
    var siteName = config.siteName || "";
    var state = {};

    checklist
      .querySelectorAll("[data-erankly-seo-checklist-item]")
      .forEach(function (item) {
        state[item.getAttribute("data-erankly-seo-checklist-item")] =
          item.classList.contains("is-done");
      });

    function getPostTitle() {
      var titleField = document.getElementById("title");

      return titleField ? String(titleField.value || "").trim() : "";
    }

    function getEditorContent() {
      var contentField = document.getElementById("content");

      if (
        window.tinyMCE &&
        window.tinyMCE.get("content") &&
        !window.tinyMCE.get("content").isHidden()
      ) {
        return window.tinyMCE.get("content").getContent() || "";
      }

      return contentField ? contentField.value || "" : "";
    }

    function getExcerpt() {
      var excerptField = document.getElementById("excerpt");

      return excerptField ? String(excerptField.value || "").trim() : "";
    }

    function effectiveTitle(customTitle) {
      var postTitle = getPostTitle();
      var resolved = checklistResolveVariables(customTitle, postTitle, siteName);

      return (
        resolved ||
        config.titlePlaceholder ||
        postTitle ||
        siteName ||
        ""
      );
    }

    function effectiveDescription(customDescription) {
      var postTitle = getPostTitle();
      var resolved = checklistResolveVariables(
        customDescription,
        postTitle,
        siteName,
      );

      if (resolved) {
        return resolved;
      }

      if (config.descriptionPlaceholder) {
        return config.descriptionPlaceholder;
      }

      var source = getExcerpt() || checklistStripContent(getEditorContent());

      return source.slice(0, descriptionLimit);
    }

    function evaluateAll() {
      var titleField = document.getElementById("erankly-title");
      var descriptionField = document.getElementById("erankly-description");
      var canonicalField = document.getElementById("erankly-canonical");
      var socialImageField = document.getElementById("erankly-social-image-url");
      var thumbnailField = document.getElementById("_thumbnail_id");
      var hideField = document.querySelector(
        'input[name="erankly_hide_from_search_results"]',
      );
      var noindexField = document.querySelector('input[name="erankly_noindex"]');
      var contentText = getExcerpt() || checklistStripContent(getEditorContent());
      var featuredId = thumbnailField ? parseInt(thumbnailField.value, 10) : 0;
      var noindex = hideField
        ? hideField.checked
        : noindexField
          ? noindexField.checked
          : false;

      return {
        title: checklistTextWithinLimit(
          effectiveTitle(titleField ? titleField.value : ""),
          titleLimit,
        ),
        description: checklistTextWithinLimit(
          effectiveDescription(descriptionField ? descriptionField.value : ""),
          descriptionLimit,
        ),
        preview_image:
          featuredId > 0 ||
          "" !== checklistFirstContentImage(getEditorContent()) ||
          Boolean(config.hasDefaultPreviewImage),
        indexable: !noindex,
        content: contentText.length >= minContentLength,
        social_image:
          "" !==
          String(socialImageField ? socialImageField.value || "" : "").trim(),
        canonical:
          "" !==
          String(canonicalField ? canonicalField.value || "" : "").trim(),
      };
    }

    function apply() {
      var keys = Object.keys(state);
      var done = keys.filter(function (key) {
        return state[key];
      }).length;
      var status = "is-partial";

      if (done === 0) {
        status = "is-incomplete";
      } else if (done === keys.length) {
        status = "is-complete";
      }

      keys.forEach(function (key) {
        var item = checklist.querySelector(
          '[data-erankly-seo-checklist-item="' + key + '"]',
        );

        if (item) {
          item.classList.toggle("is-done", state[key]);
        }
      });

      statusClasses.forEach(function (statusClass) {
        checklist.classList.toggle(statusClass, statusClass === status);
      });

      if (count) {
        count.textContent = done + "/" + keys.length;
      }
    }

    function refresh() {
      var nextState = evaluateAll();

      Object.keys(state).forEach(function (key) {
        if (key in nextState) {
          state[key] = nextState[key];
        }
      });

      apply();
    }

    var titleField = document.getElementById("erankly-title");
    var descriptionField = document.getElementById("erankly-description");
    var canonicalField = document.getElementById("erankly-canonical");
    var socialImageField = document.getElementById("erankly-social-image-url");
    var postTitleField = document.getElementById("title");
    var contentField = document.getElementById("content");
    var excerptField = document.getElementById("excerpt");
    var hideField = document.querySelector(
      'input[name="erankly_hide_from_search_results"]',
    );
    var noindexField = document.querySelector('input[name="erankly_noindex"]');

    [
      titleField,
      descriptionField,
      canonicalField,
      socialImageField,
      postTitleField,
      contentField,
      excerptField,
      hideField,
      noindexField,
    ].forEach(function (field) {
      if (field) {
        field.addEventListener("input", refresh);
        field.addEventListener("change", refresh);
      }
    });

    if (window.tinyMCE && window.tinyMCE.on) {
      window.tinyMCE.on("AddEditor", function (event) {
        if (event.editor && "content" === event.editor.id) {
          event.editor.on("keyup change SetContent", refresh);
        }
      });

      if (window.tinyMCE.get("content")) {
        window.tinyMCE.get("content").on("keyup change SetContent", refresh);
      }
    }

    var imageBox = document.getElementById("postimagediv");

    if (imageBox && window.MutationObserver) {
      new MutationObserver(refresh).observe(imageBox, {
        childList: true,
        subtree: true,
      });
    }

    refresh();
  }

  ER.checklistResolveVariables = checklistResolveVariables;
  ER.checklistFirstContentImage = checklistFirstContentImage;
  ER.checklistStripContent = checklistStripContent;
  ER.checklistTextWithinLimit = checklistTextWithinLimit;
  ER.bindSeoChecklist = bindSeoChecklist;
})(window.ERanklyAdmin = window.ERanklyAdmin || {});
