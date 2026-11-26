/**
 * assets/js/catalog.js — каталог без перезагрузки.
 * Фильтры, ползунок-лента цены, поиск, сортировка, пагинация по 9,
 * избранное с всплытием наверх, тост со ссылкой на вход для гостя.
 * Vanilla JS, все запросы через fetch к api/*.php.
 */
(function () {
  "use strict";

  document.addEventListener("DOMContentLoaded", init);

  function init() {
    if (!window.Janome) {
      return;
    }

    var cfgEl = document.getElementById("catalog-config");
    if (!cfgEl) {
      return;
    }
    var cfg;
    try {
      cfg = JSON.parse(cfgEl.textContent);
    } catch (err) {
      return;
    }

    var reduced = window.matchMedia("(prefers-reduced-motion: reduce)").matches;
    var esc = window.Janome.escapeHtml;
    var money = window.Janome.formatPrice;

    /* ---------- Состояние ---------- */
    var state = Object.assign(
      {
        brand: "",
        category: "",
        type: "",
        q: "",
        min: cfg.bounds.min,
        max: cfg.bounds.max,
        sort: "new",
        page: 1,
        in_stock: false,
        on_sale: false,
      },
      cfg.initial || {},
    );

    var lastData = null;
    var controller = null;
    var searchTimer = null;
    var priceTimer = null;

    /* ---------- Элементы ---------- */
    var grid = document.getElementById("catalog-grid");
    var pagination = document.getElementById("catalog-pagination");
    var chipsBox = document.getElementById("active-chips");
    var countEl = document.getElementById("results-count");
    var searchInput = document.getElementById("catalog-search");
    var searchClear = document.getElementById("search-clear");
    var sortSelect = document.getElementById("catalog-sort");
    var minRange = document.getElementById("price-min");
    var maxRange = document.getElementById("price-max");
    var minInput = document.getElementById("price-min-input");
    var maxInput = document.getElementById("price-max-input");
    var priceFill = document.getElementById("price-fill");
    var inStockBox = document.getElementById("filter-in-stock");
    var onSaleBox = document.getElementById("filter-on-sale");
    var resetBtn = document.getElementById("filters-reset");
    var filters = document.getElementById("filters");
    var filtersOpen = document.getElementById("filters-open");
    var filtersClose = document.getElementById("filters-close");
    var scrim = document.getElementById("filters-scrim");
    var toolbar = document.getElementById("catalog-toolbar");
    var progress = document.getElementById("thread-progress");
    var results = document.getElementById("results");

    var HEART_SVG =
      '<svg viewBox="0 0 24 24" width="19" height="19" aria-hidden="true"><path d="M12 20.3 4.8 13a4.9 4.9 0 0 1 0-7 4.6 4.6 0 0 1 6.6 0l.6.6.6-.6a4.6 4.6 0 0 1 6.6 0 4.9 4.9 0 0 1 0 7Z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg>';
    var ARROW_SVG =
      '<svg viewBox="0 0 24 24" width="17" height="17" aria-hidden="true"><path d="M5 12h13M13 6l6 6-6 6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
    var FALLBACK_IMG =
      "data:image/svg+xml," +
      encodeURIComponent(
        '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 800 600"><rect width="800" height="600" fill="#FBF6F6"/><circle cx="400" cy="270" r="90" fill="none" stroke="#E8D5D8" stroke-width="4" stroke-dasharray="8 8"/><circle cx="400" cy="270" r="58" fill="#fff" stroke="#7A1220" stroke-width="5"/><circle cx="400" cy="270" r="22" fill="none" stroke="#B01B2E" stroke-width="5"/><circle cx="400" cy="270" r="8" fill="#4E0B15"/><text x="400" y="420" text-anchor="middle" font-family="Georgia,serif" font-size="30" fill="#6B5558">Фото готовится</text></svg>',
      );

    /* ---------- Тост со ссылкой на вход (гость) ---------- */
    function guestToast(what) {
      var stack = document.getElementById("toast-stack");
      var message =
        what === "cart"
          ? "Корзина доступна только после входа"
          : "Избранное доступно только после входа";

      if (!stack) {
        window.Janome.toast(message, "warning");
        return;
      }

      var el = document.createElement("div");
      el.className = "toast toast--warning";
      el.setAttribute("role", "status");
      el.style.setProperty("--toast-dur", "7000ms");

      var icon = document.createElement("span");
      icon.className = "toast__icon";
      icon.innerHTML =
        '<svg viewBox="0 0 24 24" width="19" height="19" aria-hidden="true"><path d="M12 4 2.8 19.5h18.4L12 4z" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/><path d="M12 10v4.2" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><circle cx="12" cy="16.8" r="1" fill="currentColor"/></svg>';

      var text = document.createElement("div");
      text.className = "toast__text";
      text.textContent = message;

      var link = document.createElement("a");
      link.className = "toast__action";
      link.href = cfg.loginUrl;
      link.textContent = "Войти или зарегистрироваться";

      text.appendChild(document.createElement("br"));
      text.appendChild(link);

      var close = document.createElement("button");
      close.type = "button";
      close.className = "toast__close";
      close.setAttribute("aria-label", "Закрыть уведомление");
      close.textContent = "\u00D7";

      var bar = document.createElement("span");
      bar.className = "toast__bar";

      el.append(icon, text, close, bar);
      stack.appendChild(el);
      while (stack.children.length > 4) {
        stack.firstElementChild.remove();
      }

      var timer = setTimeout(dismiss, 7000);
      var gone = false;
      function dismiss() {
        if (gone) {
          return;
        }
        gone = true;
        clearTimeout(timer);
        el.classList.add("is-leaving");
        setTimeout(function () {
          el.remove();
        }, 320);
      }
      close.addEventListener("click", dismiss);
      el.addEventListener("mouseenter", function () {
        clearTimeout(timer);
        el.classList.add("is-paused");
      });
      el.addEventListener("mouseleave", function () {
        timer = setTimeout(dismiss, 3000);
        el.classList.remove("is-paused");
      });
    }

    /* ---------- Характеристики для карточки ---------- */
    var SPEC_PRIORITY = [
      ["Количество операций", "Операций", ""],
      ["Швейных операций", "Операций", ""],
      ["Нитей", "Нитей", ""],
      ["Количество нитей", "Нитей", ""],
      ["Скорость шитья (ст/мин)", "Скорость", " ст/мин"],
      ["Скорость (ст/мин)", "Скорость", " ст/мин"],
      ["Скорость вышивки (ст/мин)", "Вышивка", " ст/мин"],
      ["Макс. поле вышивки (мм)", "Поле вышивки", " мм"],
      ["Встроенных дизайнов", "Дизайнов", ""],
      ["Дизайнов вышивки", "Дизайнов", ""],
      ["Челнок", "Челнок", ""],
      ["Макс. ширина стежка (мм)", "Ширина стежка", " мм"],
      ["Дифференциальная подача", "Дифф. подача", ""],
    ];

    function specRows(specs) {
      var rows = [];
      SPEC_PRIORITY.forEach(function (rule) {
        if (rows.length >= 3) {
          return;
        }
        var key = rule[0];
        if (!(key in specs)) {
          return;
        }
        var value = specs[key];
        if (typeof value === "boolean") {
          value = value ? "да" : "нет";
        }
        if (key === "Челнок") {
          value = String(value).replace(" ротационный", "");
        }
        if (typeof value === "number") {
          value = value.toLocaleString("ru-RU");
        }
        rows.push({ label: rule[1], value: String(value) + rule[2] });
      });
      return rows;
    }

    /* ---------- Разметка карточки ---------- */
    function cardHTML(item, index) {
      var specs = specRows(item.specs || {});
      var specsHtml = specs
        .map(function (row) {
          return (
            "<li><span>" +
            esc(row.label) +
            "</span><i></i><b>" +
            esc(row.value) +
            "</b></li>"
          );
        })
        .join("");

      var favClass = item.is_favorite ? " is-active" : "";
      var favLabel = item.is_favorite ? "Убрать из избранного" : "В избранное";

      // Гость: кнопка с тостом. Авторизованный: ссылка на товар для оформления.
      var cartHtml = cfg.isAuth
        ? '<a class="btn btn--primary btn--sm pcard__cart" href="' +
          esc(item.url) +
          '">В корзину</a>'
        : '<button class="btn btn--primary btn--sm pcard__cart" type="button" data-guest-cart>В корзину</button>';

      return (
        "" +
        '<article class="card pcard" data-id="' +
        item.id +
        '" style="--i:' +
        index +
        '">' +
        '<div class="pcard__media">' +
        '<img src="../' +
        esc(item.image_path) +
        '" alt="' +
        esc(item.name) +
        '" loading="lazy" decoding="async" ' +
        "onerror=\"this.onerror=null;this.src='" +
        FALLBACK_IMG +
        "'\">" +
        '<span class="pcard__brand">' +
        esc(item.brand_name) +
        "</span>" +
        '<span class="pcard__type">' +
        esc(item.type_label) +
        "</span>" +
        (item.discount > 0
          ? '<span class="pcard__sale">−' + item.discount + "%</span>"
          : "") +
        '<button class="pcard__fav' +
        favClass +
        '" type="button" data-fav="' +
        item.id +
        '" ' +
        'aria-pressed="' +
        (item.is_favorite ? "true" : "false") +
        '" aria-label="' +
        esc(favLabel) +
        '" title="' +
        esc(favLabel) +
        '">' +
        HEART_SVG +
        "</button>" +
        "</div>" +
        '<h3 class="pcard__name"><a href="' +
        esc(item.url) +
        '">' +
        esc(item.name) +
        "</a></h3>" +
        '<span class="pcard__model">' +
        esc(item.model) +
        "</span>" +
        (specsHtml ? '<ul class="pcard__specs">' + specsHtml + "</ul>" : "") +
        '<div class="pcard__tag">' +
        '<span class="pcard__price">' +
        esc(money(item.price)) +
        "</span>" +
        (item.old_price
          ? '<span class="pcard__old">' + esc(money(item.old_price)) + "</span>"
          : "") +
        "</div>" +
        '<div class="pcard__meta">' +
        '<span class="stock stock--' +
        esc(item.stock_status) +
        '">' +
        esc(item.stock_text) +
        "</span>" +
        (item.is_favorite
          ? '<span class="pcard__favmark">' + HEART_SVG + "в избранном</span>"
          : item.sold > 0
            ? '<span class="pcard__sold">заказано ' + item.sold + " шт.</span>"
            : "") +
        "</div>" +
        '<div class="pcard__actions">' +
        cartHtml +
        '<a class="btn btn--ghost btn--sm pcard__more" href="' +
        esc(item.url) +
        '" aria-label="Подробнее о товаре" title="Подробнее">' +
        ARROW_SVG +
        "</a>" +
        "</div>" +
        "</article>"
      );
    }

    function skeletons(count) {
      var html = "";
      for (var i = 0; i < count; i++) {
        html +=
          '<div class="card card--static skeleton-card" aria-hidden="true">' +
          '<div class="sk sk--media"></div>' +
          '<div class="sk sk--line"></div>' +
          '<div class="sk sk--line sk--short"></div>' +
          '<div class="sk sk--tag"></div>' +
          '<div class="sk sk--btn"></div>' +
          "</div>";
      }
      return html;
    }

    function emptyHTML() {
      return (
        '<div class="card card--static catalog-empty" style="grid-column:1/-1">' +
        '<svg class="catalog-empty__needle" viewBox="0 0 24 24" width="54" height="54" aria-hidden="true">' +
        '<path d="M12 3v13" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>' +
        '<path d="M12 16v4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>' +
        '<ellipse cx="12" cy="6.5" rx="2" ry="3.4" fill="none" stroke="currentColor" stroke-width="1.6"/>' +
        "</svg>" +
        '<h3 class="empty-state__title">Под такие стежки ничего не нашлось</h3>' +
        '<p class="empty-state__text">Попробуйте расширить ленту цены, снять фильтры или изменить запрос.</p>' +
        '<button class="btn btn--primary" type="button" data-reset-empty>Сбросить фильтры</button>' +
        "</div>"
      );
    }

    /* ---------- Пагинация ---------- */
    function paginationHTML(page, pages) {
      if (pages <= 1) {
        return "";
      }
      var html = "";
      html +=
        '<button class="page-link' +
        (page <= 1 ? " is-disabled" : "") +
        '" type="button" data-page="' +
        (page - 1) +
        '"' +
        (page <= 1 ? " disabled" : "") +
        ' aria-label="Предыдущая страница">‹</button>';

      var slots = [];
      for (var i = 1; i <= pages; i++) {
        if (i === 1 || i === pages || Math.abs(i - page) <= 1) {
          slots.push(i);
        }
      }
      var prev = 0;
      slots.forEach(function (n) {
        if (prev && n - prev > 1) {
          html += '<span class="page-ellipsis">…</span>';
        }
        html +=
          '<button class="page-link' +
          (n === page ? " is-active" : "") +
          '" type="button" data-page="' +
          n +
          '"' +
          (n === page ? ' aria-current="page"' : "") +
          ">" +
          n +
          "</button>";
        prev = n;
      });

      html +=
        '<button class="page-link' +
        (page >= pages ? " is-disabled" : "") +
        '" type="button" data-page="' +
        (page + 1) +
        '"' +
        (page >= pages ? " disabled" : "") +
        ' aria-label="Следующая страница">›</button>';
      return html;
    }

    /* ---------- Активные фильтры ---------- */
    function renderChips() {
      var chips = [];
      if (state.brand) {
        chips.push({
          key: "brand",
          text: "Бренд: " + (cfg.brandNames[state.brand] || state.brand),
        });
      }
      if (state.category) {
        chips.push({
          key: "category",
          text:
            "Категория: " +
            (cfg.categoryNames[state.category] || state.category),
        });
      }
      if (state.type) {
        chips.push({
          key: "type",
          text: "Тип: " + (cfg.typeLabels[state.type] || state.type),
        });
      }
      if (state.q) {
        chips.push({ key: "q", text: "Поиск: «" + state.q + "»" });
      }
      if (state.min > cfg.bounds.min || state.max < cfg.bounds.max) {
        chips.push({
          key: "price",
          text: money(state.min) + " — " + money(state.max),
        });
      }
      if (state.in_stock) {
        chips.push({ key: "in_stock", text: "В наличии" });
      }
      if (state.on_sale) {
        chips.push({ key: "on_sale", text: "Со скидкой" });
      }

      chipsBox.innerHTML = chips
        .map(function (chip) {
          return (
            '<span class="active-chip">' +
            esc(chip.text) +
            '<button type="button" data-clear="' +
            chip.key +
            '" aria-label="Убрать фильтр">×</button></span>'
          );
        })
        .join("");
    }

    /* ---------- Синхронизация контролов ---------- */
    function syncControls() {
      document.querySelectorAll("[data-facet]").forEach(function (group) {
        var facet = group.dataset.facet;
        group.querySelectorAll(".filter-opt").forEach(function (btn) {
          btn.classList.toggle(
            "is-active",
            btn.dataset.value === String(state[facet] || ""),
          );
        });
      });
      if (searchInput.value !== state.q) {
        searchInput.value = state.q;
      }
      searchClear.classList.toggle("is-visible", state.q !== "");
      sortSelect.value = state.sort;
      minRange.value = state.min;
      maxRange.value = state.max;
      minInput.value = state.min;
      maxInput.value = state.max;
      inStockBox.checked = !!state.in_stock;
      onSaleBox.checked = !!state.on_sale;
      paintSlider();
    }

    function paintSlider() {
      var span = cfg.bounds.max - cfg.bounds.min;
      if (span <= 0) {
        priceFill.style.left = "0%";
        priceFill.style.width = "100%";
        return;
      }
      var left = ((state.min - cfg.bounds.min) / span) * 100;
      var right = ((state.max - cfg.bounds.min) / span) * 100;
      priceFill.style.left = left + "%";
      priceFill.style.width = Math.max(0, right - left) + "%";
    }

    function syncURL() {
      var params = buildParams();
      params.delete("page");
      if (state.page > 1) {
        params.set("page", state.page);
      }
      var qs = params.toString();
      history.replaceState(
        null,
        "",
        window.location.pathname + (qs ? "?" + qs : ""),
      );
    }

    function buildParams() {
      var params = new URLSearchParams();
      if (state.brand) {
        params.set("brand", state.brand);
      }
      if (state.category) {
        params.set("category", state.category);
      }
      if (state.type) {
        params.set("type", state.type);
      }
      if (state.q) {
        params.set("q", state.q);
      }
      if (state.min > cfg.bounds.min) {
        params.set("min", state.min);
      }
      if (state.max < cfg.bounds.max) {
        params.set("max", state.max);
      }
      if (state.sort !== "new") {
        params.set("sort", state.sort);
      }
      if (state.in_stock) {
        params.set("in_stock", "1");
      }
      if (state.on_sale) {
        params.set("on_sale", "1");
      }
      params.set("page", state.page);
      return params;
    }

    /* ---------- Загрузка ---------- */
    function load(options) {
      options = options || {};
      var silent = !!options.silent;

      if (controller) {
        controller.abort();
      }
      controller = new AbortController();

      if (silent) {
        grid.classList.add("is-updating");
      } else {
        grid.innerHTML = skeletons(cfg.perPage);
        pagination.innerHTML = "";
      }

      fetch("../api/filter_products.php?" + buildParams().toString(), {
        headers: { Accept: "application/json" },
        credentials: "same-origin",
        signal: controller.signal,
      })
        .then(function (response) {
          return response.json().then(function (data) {
            return { ok: response.ok, data: data };
          });
        })
        .then(function (result) {
          grid.classList.remove("is-updating");
          if (!result.ok || result.data.ok === false) {
            throw new Error(
              result.data.error || "Не удалось загрузить каталог",
            );
          }
          lastData = result.data;
          state.page = result.data.page;
          render(result.data);
          syncURL();
        })
        .catch(function (err) {
          if (err.name === "AbortError") {
            return;
          }
          grid.classList.remove("is-updating");
          grid.innerHTML =
            '<div class="card card--static catalog-empty" style="grid-column:1/-1">' +
            '<h3 class="empty-state__title">Каталог не отвечает</h3>' +
            '<p class="empty-state__text">' +
            esc(err.message) +
            "</p>" +
            '<button class="btn btn--primary" type="button" data-retry>Повторить</button></div>';
          countEl.textContent = "Ошибка загрузки";
        });
    }

    function render(data) {
      grid.innerHTML = data.items.length
        ? data.items.map(cardHTML).join("")
        : emptyHTML();

      pagination.innerHTML = paginationHTML(data.page, data.pages);

      var word =
        data.total === 1
          ? "товар"
          : data.total >= 2 && data.total <= 4
            ? "товара"
            : "товаров";
      countEl.innerHTML =
        "Найдено <strong>" +
        data.total +
        "</strong> " +
        word +
        (data.pages > 1 ? " · стр. " + data.page + " из " + data.pages : "");

      renderChips();
      syncControls();
    }

    function refresh(resetPage, silent) {
      if (resetPage !== false) {
        state.page = 1;
      }
      load({ silent: !!silent });
    }

    /* ---------- Избранное ---------- */
    function toggleFavorite(btn) {
      if (!cfg.isAuth) {
        guestToast("favorite");
        return;
      }
      if (btn.classList.contains("is-busy")) {
        return;
      }

      var id = parseInt(btn.dataset.fav, 10);
      btn.classList.add("is-busy");

      window.Janome.api("../api/favorite_toggle.php", {
        method: "POST",
        body: { product_id: id },
      })
        .then(function (data) {
          btn.classList.remove("is-busy");
          btn.classList.toggle("is-active", data.favorite);
          btn.setAttribute("aria-pressed", data.favorite ? "true" : "false");
          btn.setAttribute(
            "aria-label",
            data.favorite ? "Убрать из избранного" : "В избранное",
          );
          btn.title = data.favorite ? "Убрать из избранного" : "В избранное";

          if (data.favorite && !reduced) {
            btn.classList.remove("is-burst");
            void btn.offsetWidth;
            btn.classList.add("is-burst");
            setTimeout(function () {
              btn.classList.remove("is-burst");
            }, 600);
          }

          window.Janome.setCounts({ favorites: data.favorites_count });
          window.Janome.toast(
            data.favorite ? "Добавлено в избранное" : "Убрано из избранного",
            data.favorite ? "success" : "info",
            2600,
          );

          // Избранное всегда первым — пересобираем выдачу с текущей страницы
          load({ silent: true });
        })
        .catch(function (err) {
          btn.classList.remove("is-busy");
          if (err.status === 401) {
            guestToast("favorite");
            return;
          }
          window.Janome.toast(err.message, "error");
        });
    }

    /* ---------- События: сетка ---------- */
    grid.addEventListener("click", function (e) {
      var fav = e.target.closest("[data-fav]");
      if (fav) {
        e.preventDefault();
        toggleFavorite(fav);
        return;
      }

      if (e.target.closest("[data-guest-cart]")) {
        e.preventDefault();
        guestToast("cart");
        return;
      }
      if (e.target.closest("[data-reset-empty]")) {
        resetAll();
        return;
      }
      if (e.target.closest("[data-retry]")) {
        load();
      }
    });

    pagination.addEventListener("click", function (e) {
      var btn = e.target.closest("[data-page]");
      if (!btn || btn.disabled) {
        return;
      }
      var page = parseInt(btn.dataset.page, 10);
      if (!page || page === state.page) {
        return;
      }
      state.page = page;
      load();
      if (results) {
        results.scrollIntoView({
          behavior: reduced ? "auto" : "smooth",
          block: "start",
        });
      }
    });

    chipsBox.addEventListener("click", function (e) {
      var btn = e.target.closest("[data-clear]");
      if (!btn) {
        return;
      }
      var key = btn.dataset.clear;
      if (key === "price") {
        state.min = cfg.bounds.min;
        state.max = cfg.bounds.max;
      } else if (key === "in_stock" || key === "on_sale") {
        state[key] = false;
      } else {
        state[key] = "";
      }
      refresh(true);
    });

    /* ---------- События: фильтры ---------- */
    document.querySelectorAll("[data-facet]").forEach(function (group) {
      group.addEventListener("click", function (e) {
        var btn = e.target.closest(".filter-opt");
        if (!btn) {
          return;
        }
        var facet = group.dataset.facet;
        var value = btn.dataset.value;
        // Повторный клик по активному — снимаем фильтр
        state[facet] = String(state[facet]) === value ? "" : value;
        refresh(true);
        if (window.innerWidth <= 1080) {
          closeFilters();
        }
      });
    });

    searchInput.addEventListener("input", function () {
      searchClear.classList.toggle("is-visible", searchInput.value !== "");
      clearTimeout(searchTimer);
      searchTimer = setTimeout(function () {
        state.q = searchInput.value.trim();
        refresh(true);
      }, 350);
    });

    searchClear.addEventListener("click", function () {
      searchInput.value = "";
      state.q = "";
      searchClear.classList.remove("is-visible");
      searchInput.focus();
      refresh(true);
    });

    sortSelect.addEventListener("change", function () {
      state.sort = sortSelect.value;
      refresh(true);
    });

    inStockBox.addEventListener("change", function () {
      state.in_stock = inStockBox.checked;
      refresh(true);
    });
    onSaleBox.addEventListener("change", function () {
      state.on_sale = onSaleBox.checked;
      refresh(true);
    });

    /* ---------- Ползунок цены ---------- */
    function onRangeInput() {
      var min = parseInt(minRange.value, 10);
      var max = parseInt(maxRange.value, 10);
      var gap = cfg.step;
      if (min > max - gap) {
        if (document.activeElement === minRange) {
          min = Math.max(cfg.bounds.min, max - gap);
          minRange.value = min;
        } else {
          max = Math.min(cfg.bounds.max, min + gap);
          maxRange.value = max;
        }
      }
      state.min = min;
      state.max = max;
      minInput.value = min;
      maxInput.value = max;
      paintSlider();
      clearTimeout(priceTimer);
      priceTimer = setTimeout(function () {
        refresh(true);
      }, 420);
    }
    minRange.addEventListener("input", onRangeInput);
    maxRange.addEventListener("input", onRangeInput);

    function onNumberChange() {
      var min = parseInt(minInput.value, 10);
      var max = parseInt(maxInput.value, 10);
      if (isNaN(min)) {
        min = cfg.bounds.min;
      }
      if (isNaN(max)) {
        max = cfg.bounds.max;
      }
      min = Math.max(cfg.bounds.min, Math.min(min, cfg.bounds.max));
      max = Math.max(cfg.bounds.min, Math.min(max, cfg.bounds.max));
      if (min > max) {
        var t = min;
        min = max;
        max = t;
      }
      state.min = min;
      state.max = max;
      syncControls();
      refresh(true);
    }
    minInput.addEventListener("change", onNumberChange);
    maxInput.addEventListener("change", onNumberChange);

    /* ---------- Сброс ---------- */
    function resetAll() {
      state.brand = "";
      state.category = "";
      state.type = "";
      state.q = "";
      state.min = cfg.bounds.min;
      state.max = cfg.bounds.max;
      state.sort = "new";
      state.in_stock = false;
      state.on_sale = false;
      state.page = 1;
      syncControls();
      load();
      window.Janome.toast("Фильтры сброшены", "info", 2200);
    }
    resetBtn.addEventListener("click", resetAll);

    /* ---------- Мобильный ящик фильтров ---------- */
    function openFilters() {
      filters.classList.add("is-open");
      scrim.hidden = false;
      requestAnimationFrame(function () {
        scrim.classList.add("is-open");
      });
      filtersOpen.setAttribute("aria-expanded", "true");
      document.body.style.overflow = "hidden";
      var first = filters.querySelector(".filter-opt");
      if (first) {
        first.focus();
      }
    }
    function closeFilters() {
      filters.classList.remove("is-open");
      scrim.classList.remove("is-open");
      setTimeout(function () {
        if (!scrim.classList.contains("is-open")) {
          scrim.hidden = true;
        }
      }, 300);
      filtersOpen.setAttribute("aria-expanded", "false");
      document.body.style.overflow = "";
    }
    filtersOpen.addEventListener("click", openFilters);
    filtersClose.addEventListener("click", closeFilters);
    scrim.addEventListener("click", closeFilters);
    document.addEventListener("keydown", function (e) {
      if (e.key === "Escape" && filters.classList.contains("is-open")) {
        closeFilters();
        filtersOpen.focus();
      }
    });
    window.addEventListener("resize", function () {
      if (window.innerWidth > 1080 && filters.classList.contains("is-open")) {
        closeFilters();
      }
    });

    /* ---------- Прогресс-нить и тень панели ---------- */
    var ticking = false;
    function onScroll() {
      var doc = document.documentElement;
      var max = doc.scrollHeight - window.innerHeight;
      if (progress) {
        progress.style.transform =
          "scaleX(" + (max > 0 ? window.scrollY / max : 0) + ")";
      }
      if (toolbar) {
        var top =
          parseFloat(
            getComputedStyle(document.documentElement).getPropertyValue(
              "--header-h",
            ),
          ) || 72;
        toolbar.classList.toggle(
          "is-stuck",
          toolbar.getBoundingClientRect().top <= top + 1,
        );
      }
      ticking = false;
    }
    window.addEventListener(
      "scroll",
      function () {
        if (!ticking) {
          requestAnimationFrame(onScroll);
          ticking = true;
        }
      },
      { passive: true },
    );

    /* ---------- Старт ---------- */
    syncControls();
    onScroll();
    load();
  }
})();
