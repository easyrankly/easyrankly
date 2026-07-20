(function (ER) {
  "use strict";

  function bindUserSearch(wrap) {
    var config = window.eranklyUserSearch;

    if (!config || !config.restUrl || !config.nonce) {
      return;
    }

    var idInput = wrap.querySelector("[data-erankly-user-id]");
    var selected = wrap.querySelector("[data-erankly-user-selected]");
    var selectedName = wrap.querySelector("[data-erankly-user-selected-name]");
    var removeBtn = wrap.querySelector("[data-erankly-user-remove]");
    var inputWrap = wrap.querySelector("[data-erankly-user-search-input-wrap]");
    var searchInput = wrap.querySelector("[data-erankly-user-search-input]");
    var resultsList = wrap.querySelector("[data-erankly-user-results]");

    if (
      !idInput ||
      !selected ||
      !removeBtn ||
      !inputWrap ||
      !searchInput ||
      !resultsList
    ) {
      return;
    }

    var debounceTimer = null;
    var i18n = config.i18n || {};

    function closeResults() {
      resultsList.hidden = true;
      resultsList.innerHTML = "";
    }

    function selectUser(id, text) {
      idInput.value = id;
      if (selectedName) {
        selectedName.value = text;
      }
      selected.hidden = false;
      inputWrap.hidden = true;
      removeBtn.hidden = false;
      searchInput.value = "";
      closeResults();
      idInput.dispatchEvent(new Event("input", { bubbles: true }));
      idInput.dispatchEvent(new Event("change", { bubbles: true }));
    }

    function clearUser() {
      idInput.value = "0";
      if (selectedName) {
        selectedName.value = "";
      }
      selected.hidden = true;
      inputWrap.hidden = false;
      removeBtn.hidden = true;
      searchInput.value = "";
      closeResults();
      searchInput.focus();
      idInput.dispatchEvent(new Event("input", { bubbles: true }));
      idInput.dispatchEvent(new Event("change", { bubbles: true }));
    }

    function fetchResults(query) {
      var url = config.restUrl + "?q=" + encodeURIComponent(query);

      resultsList.hidden = false;
      resultsList.innerHTML =
        '<li class="erankly-autocomplete-status erankly-user-result-status">' +
        (i18n.searching || "Searching…") +
        "</li>";

      fetch(url, {
        headers: { "X-WP-Nonce": config.nonce },
        credentials: "same-origin",
      })
        .then(function (res) {
          return res.ok ? res.json() : [];
        })
        .then(function (items) {
          resultsList.innerHTML = "";

          if (!items || items.length === 0) {
            resultsList.innerHTML =
              '<li class="erankly-autocomplete-status erankly-user-result-status">' +
              (i18n.noResults || "No matches found.") +
              "</li>";
            return;
          }

          items.forEach(function (item) {
            var li = document.createElement("li");
            var button = document.createElement("button");

            button.type = "button";
            button.className =
              "erankly-autocomplete-item erankly-user-result-item";
            button.setAttribute("role", "option");
            button.setAttribute("tabindex", "-1");

            if (item.name) {
              if (item.avatar) {
                var avatar = document.createElement("img");
                avatar.className = "erankly-user-result-avatar";
                avatar.src = item.avatar;
                avatar.alt = "";
                avatar.loading = "lazy";
                button.appendChild(avatar);
              }

              var details = document.createElement("span");
              details.className = "erankly-user-result-details";

              var name = document.createElement("span");
              name.className = "erankly-user-result-name";
              name.textContent = item.name;
              details.appendChild(name);

              if (item.meta) {
                var meta = document.createElement("span");
                meta.className = "erankly-user-result-meta";
                meta.textContent = item.meta;
                details.appendChild(meta);
              }

              button.appendChild(details);
            } else {
              button.textContent = item.text;
            }

            function chooseUser(e) {
              e.preventDefault();
              selectUser(item.id, item.text);
            }

            button.addEventListener("mousedown", function (e) {
              e.preventDefault();
            });
            button.addEventListener("click", chooseUser);
            button.addEventListener("keydown", function (e) {
              if (e.key === "Enter" || e.key === " ") {
                chooseUser(e);
              }
            });
            li.appendChild(button);
            resultsList.appendChild(li);
          });
        })
        .catch(function () {
          closeResults();
        });
    }

    removeBtn.addEventListener("click", clearUser);

    searchInput.addEventListener("input", function () {
      clearTimeout(debounceTimer);
      var query = searchInput.value.trim();

      debounceTimer = setTimeout(function () {
        fetchResults(query);
      }, 300);
    });

    searchInput.addEventListener("focus", function () {
      if (resultsList.hidden) {
        fetchResults(searchInput.value.trim());
      }
    });

    searchInput.addEventListener("keydown", function (e) {
      if (e.key === "Escape") {
        closeResults();
        return;
      }

      if (e.key !== "ArrowDown") {
        return;
      }

      var first = resultsList.querySelector('[role="option"]');

      if (first) {
        e.preventDefault();
        first.focus();
      }
    });

    resultsList.addEventListener("keydown", function (e) {
      var items = Array.prototype.slice.call(
        resultsList.querySelectorAll('[role="option"]'),
      );
      var current = items.indexOf(document.activeElement);

      if (e.key === "ArrowDown") {
        e.preventDefault();
        if (current < items.length - 1) {
          items[current + 1].focus();
        }
      } else if (e.key === "ArrowUp") {
        e.preventDefault();
        if (current > 0) {
          items[current - 1].focus();
        } else {
          searchInput.focus();
        }
      } else if (e.key === "Escape") {
        closeResults();
        searchInput.focus();
      }
    });

    document.addEventListener("click", function (e) {
      if (!wrap.contains(e.target)) {
        closeResults();
      }
    });
  }

  function bindBloatToggle(panel) {
    var advancedView = panel.querySelector(
      '[data-erankly-bloat-view="advanced"]',
    );
    var master = panel.querySelector("[data-erankly-bloat-master]");

    if (!advancedView || !master) {
      return;
    }

    // The master toggle only drives the cleanups marked as safe; the riskier
    // ones keep their saved state and stay advanced-mode only.
    function getSafeItems() {
      return Array.prototype.slice.call(
        advancedView.querySelectorAll("[data-erankly-bloat-safe]"),
      );
    }

    function syncMasterFromItems() {
      var items = getSafeItems();
      master.checked =
        items.length > 0 &&
        items.every(function (cb) {
          return cb.checked;
        });
    }

    master.addEventListener("change", function () {
      getSafeItems().forEach(function (cb) {
        cb.checked = master.checked;
      });
    });

    getSafeItems().forEach(function (cb) {
      cb.addEventListener("change", syncMasterFromItems);
    });

    syncMasterFromItems();
  }

  function bindLocalBusiness(container) {
    var toggle = container.querySelector(
      "[data-erankly-local-business-toggle]",
    );
    var fields = container.querySelector(
      "[data-erankly-local-business-fields]",
    );
    var type = container.querySelector("[data-erankly-local-business-type]");
    var foodFields = container.querySelector(
      "[data-erankly-food-business-fields]",
    );
    var foodTypes = [
      "Restaurant",
      "CafeOrCoffeeShop",
      "BarOrPub",
      "Bakery",
      "FoodEstablishment",
    ];

    if (!toggle || !fields) {
      return;
    }

    function syncVisibility() {
      fields.hidden = !toggle.checked;
      ER.syncOrganizationFieldsVisibility(container.closest(".erankly-settings"));

      if (type && foodFields) {
        foodFields.hidden = foodTypes.indexOf(type.value) === -1;
      }
    }

    toggle.addEventListener("change", syncVisibility);

    if (type) {
      type.addEventListener("change", syncVisibility);
    }

    container
      .querySelectorAll("[data-erankly-opening-day]")
      .forEach(function (day) {
        var closed = day.querySelector("[data-erankly-day-closed]");
        var intervals = day.querySelector("[data-erankly-opening-intervals]");

        if (!closed || !intervals) {
          return;
        }

        function syncDay() {
          intervals.hidden = closed.checked;
        }

        closed.addEventListener("change", syncDay);
        syncDay();
      });

    syncVisibility();
  }

  // Autosaves a settings panel via REST instead of the shared "Save Changes"
  // button (see erankly_rest_save_settings_panel()). Serializes every
  // config.fieldRoot[...] field under the panel, including ones inside
  // hidden inner tabs, since those still hold real values, into a nested
  // object from its bracket-notation name, then debounces a POST.
  // config: { restUrl, nonce, i18n, fieldRoot? }. fieldRoot defaults to
  // 'erankly_settings' (every ERANKLY_OPTION-backed panel); Multilingual

  ER.bindUserSearch = bindUserSearch;
  ER.bindBloatToggle = bindBloatToggle;
  ER.bindLocalBusiness = bindLocalBusiness;
})(window.ERanklyAdmin = window.ERanklyAdmin || {});
