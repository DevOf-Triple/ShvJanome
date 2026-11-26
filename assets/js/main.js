/**
 * assets/js/main.js — базовый скрипт «Стежок».
 * Reveal через IntersectionObserver, тосты, шапка (бургер, тень),
 * счётчики корзины/избранного, обёртка fetch для api/*.php.
 * Vanilla JS, без jQuery.
 */
(function () {
  "use strict";

  /* ---------- Конфиг из подвала (render_footer) ---------- */
  var config = {
    isAuth: false,
    isAdmin: false,
    loginUrl: "login.php",
    csrf: "",
  };
  try {
    var configEl = document.getElementById("app-config");
    if (configEl) {
      config = Object.assign(config, JSON.parse(configEl.textContent));
    }
  } catch (err) {
    // Битый JSON не должен ронять остальной скрипт
  }

  var prefersReduced = window.matchMedia(
    "(prefers-reduced-motion: reduce)",
  ).matches;

  /* ---------- Тосты ---------- */
  var TOAST_ICONS = {
    success:
      '<svg viewBox="0 0 24 24" width="19" height="19" aria-hidden="true"><circle cx="12" cy="12" r="9" fill="none" stroke="currentColor" stroke-width="2"/><path d="M8.5 12.2l2.4 2.4 4.6-4.8" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>',
    error:
      '<svg viewBox="0 0 24 24" width="19" height="19" aria-hidden="true"><circle cx="12" cy="12" r="9" fill="none" stroke="currentColor" stroke-width="2"/><path d="M9 9l6 6M15 9l-6 6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>',
    warning:
      '<svg viewBox="0 0 24 24" width="19" height="19" aria-hidden="true"><path d="M12 4 2.8 19.5h18.4L12 4z" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/><path d="M12 10v4.2" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><circle cx="12" cy="16.8" r="1" fill="currentColor"/></svg>',
    info: '<svg viewBox="0 0 24 24" width="19" height="19" aria-hidden="true"><circle cx="12" cy="12" r="9" fill="none" stroke="currentColor" stroke-width="2"/><path d="M12 11v5" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><circle cx="12" cy="8" r="1" fill="currentColor"/></svg>',
  };

  /**
   * Показать тост. Типы: success | error | warning | info.
   * Текст вставляется через textContent — HTML не исполняется.
   */
  function toast(message, type, duration) {
    type = TOAST_ICONS[type] ? type : "info";
    duration = typeof duration === "number" ? duration : 4200;

    var stack = document.getElementById("toast-stack");
    if (!stack) {
      return;
    }

    // Не больше четырёх тостов одновременно
    while (stack.children.length >= 4) {
      stack.firstElementChild.remove();
    }

    var el = document.createElement("div");
    el.className = "toast toast--" + type;
    el.setAttribute("role", "status");
    el.style.setProperty("--toast-dur", duration + "ms");

    var icon = document.createElement("span");
    icon.className = "toast__icon";
    icon.innerHTML = TOAST_ICONS[type]; // константа, не пользовательские данные

    var text = document.createElement("div");
    text.className = "toast__text";
    text.textContent = String(message);

    var close = document.createElement("button");
    close.type = "button";
    close.className = "toast__close";
    close.setAttribute("aria-label", "Закрыть уведомление");
    close.textContent = "\u00D7";

    var bar = document.createElement("span");
    bar.className = "toast__bar";

    el.append(icon, text, close, bar);
    stack.appendChild(el);

    // Автозакрытие с паузой при наведении
    var remaining = duration;
    var started = Date.now();
    var timer = setTimeout(dismiss, remaining);
    var dismissed = false;

    function dismiss() {
      if (dismissed) {
        return;
      }
      dismissed = true;
      clearTimeout(timer);
      el.classList.add("is-leaving");
      el.addEventListener(
        "animationend",
        function () {
          el.remove();
        },
        { once: true },
      );
      setTimeout(function () {
        el.remove();
      }, 400); // страховка, если анимация не сработала
    }

    close.addEventListener("click", dismiss);
    el.addEventListener("mouseenter", function () {
      clearTimeout(timer);
      remaining -= Date.now() - started;
      el.classList.add("is-paused");
    });
    el.addEventListener("mouseleave", function () {
      started = Date.now();
      timer = setTimeout(dismiss, Math.max(600, remaining));
      el.classList.remove("is-paused");
    });
  }

  // Flash-тосты из сессии (render_footer кладёт их в #flash-toasts)
  try {
    var flashEl = document.getElementById("flash-toasts");
    if (flashEl) {
      var flashes = JSON.parse(flashEl.textContent);
      if (Array.isArray(flashes)) {
        flashes.forEach(function (item, i) {
          setTimeout(function () {
            toast(item.message, item.type);
          }, i * 180);
        });
      }
    }
  } catch (err) {
    /* игнорируем битый JSON */
  }

  /* ---------- Reveal через IntersectionObserver ---------- */
  var revealEls = document.querySelectorAll(".reveal");

  // Каскад для сеток: data-stagger на родителе задаёт задержку детям
  document.querySelectorAll("[data-stagger]").forEach(function (group) {
    group.querySelectorAll(".reveal").forEach(function (el, i) {
      el.style.transitionDelay = Math.min(i * 80, 400) + "ms";
    });
  });

  if ("IntersectionObserver" in window && !prefersReduced) {
    var observer = new IntersectionObserver(
      function (entries) {
        entries.forEach(function (entry) {
          if (entry.isIntersecting) {
            entry.target.classList.add("is-visible");
            observer.unobserve(entry.target);
          }
        });
      },
      { threshold: 0.15, rootMargin: "0px 0px -40px 0px" },
    );
    revealEls.forEach(function (el) {
      observer.observe(el);
    });
  } else {
    // Нет IO или reduced motion — показываем всё сразу
    revealEls.forEach(function (el) {
      el.classList.add("is-visible");
    });
  }

  /* ---------- Шапка: тень при скролле и бургер ---------- */
  var header = document.getElementById("site-header");
  if (header) {
    var ticking = false;
    var onScroll = function () {
      header.classList.toggle("is-scrolled", window.scrollY > 8);
    };
    window.addEventListener(
      "scroll",
      function () {
        if (!ticking) {
          window.requestAnimationFrame(function () {
            onScroll();
            ticking = false;
          });
          ticking = true;
        }
      },
      { passive: true },
    );
    onScroll();

    var burger = header.querySelector(".burger");
    var nav = document.getElementById("site-nav");

    var closeNav = function () {
      header.classList.remove("is-open");
      if (burger) {
        burger.setAttribute("aria-expanded", "false");
      }
    };

    if (burger) {
      burger.addEventListener("click", function () {
        var open = header.classList.toggle("is-open");
        burger.setAttribute("aria-expanded", String(open));
      });
    }
    if (nav) {
      nav.addEventListener("click", function (e) {
        if (e.target.closest("a")) {
          closeNav();
        }
      });
    }
    document.addEventListener("keydown", function (e) {
      if (e.key === "Escape") {
        closeNav();
      }
    });
    document.addEventListener("click", function (e) {
      if (
        header.classList.contains("is-open") &&
        !e.target.closest(".site-header")
      ) {
        closeNav();
      }
    });
    window.addEventListener("resize", function () {
      if (window.innerWidth > 1080) {
        closeNav();
      }
    });
  }

  /* ---------- Счётчики корзины и избранного ---------- */
  function setCounts(counts) {
    ["cart", "favorites"].forEach(function (key) {
      if (!(key in counts)) {
        return;
      }
      var badge = document.querySelector('[data-count="' + key + '"]');
      if (!badge) {
        return;
      }
      var n = parseInt(counts[key], 10) || 0;
      badge.textContent = n > 99 ? "99+" : String(n);
      badge.hidden = n === 0;
      var btn = badge.closest(".icon-btn");
      if (btn && !prefersReduced) {
        btn.classList.remove("is-pop");
        void btn.offsetWidth; // перезапуск анимации
        btn.classList.add("is-pop");
      }
    });
  }
  window.addEventListener("janome:counts", function (e) {
    setCounts(e.detail || {});
  });

  /* ---------- Обёртка fetch для api/*.php ---------- */
  async function api(url, options) {
    options = options || {};
    var method = (options.method || "GET").toUpperCase();
    var body = options.body !== undefined ? options.body : null;
    var headers = { Accept: "application/json" };
    if (body !== null) {
      headers["Content-Type"] = "application/json";
    }
    if (method !== "GET" && config.csrf) {
      headers["X-CSRF-Token"] = config.csrf;
    }

    var response = await fetch(url, {
      method: method,
      headers: headers,
      credentials: "same-origin",
      body: body !== null ? JSON.stringify(body) : null,
    });

    var data = {};
    try {
      data = await response.json();
    } catch (err) {
      /* пустой ответ */
    }

    if (!response.ok || data.ok === false) {
      var error = new Error(
        data.error || "Ошибка запроса (" + response.status + ")",
      );
      error.status = response.status;
      error.data = data;
      throw error;
    }
    return data;
  }

  /* ---------- Мелкие помощники для будущих страниц ---------- */
  function escapeHtml(value) {
    return String(value).replace(/[&<>"']/g, function (ch) {
      return {
        "&": "&amp;",
        "<": "&lt;",
        ">": "&gt;",
        '"': "&quot;",
        "'": "&#39;",
      }[ch];
    });
  }

  function formatPrice(value) {
    return (
      new Intl.NumberFormat("ru-RU", { maximumFractionDigits: 0 }).format(
        Number(value) || 0,
      ) + " ₽"
    );
  }

  // Гостя — на вход с возвратом на текущую страницу
  function goLogin() {
    window.location.href = config.loginUrl;
  }

  /* ---------- Публичное API ---------- */
  window.Janome = {
    config: config,
    toast: toast,
    setCounts: setCounts,
    api: api,
    escapeHtml: escapeHtml,
    formatPrice: formatPrice,
    goLogin: goLogin,
  };
  window.toast = toast;
})();
