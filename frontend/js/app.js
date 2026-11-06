(function () {
  "use strict";
  const app = {};
  const header = document.getElementById("site-header");
  if (header) {
    header.innerHTML = '<nav class="site-nav" aria-label="Main navigation"><a class="brand" href="index.html">Movie Booking System</a><button class="nav-toggle" id="nav-toggle" type="button" aria-label="Toggle navigation" aria-expanded="false">☰</button><div class="nav-links" id="nav-links"><a href="index.html">Movies</a><a href="my-bookings.html">My bookings</a><a href="api-status.html">API status</a></div><div class="nav-actions" id="nav-actions"></div></nav>';
    const toggle = document.getElementById("nav-toggle");
    toggle.addEventListener("click", function () {
      const links = document.getElementById("nav-links");
      const expanded = links.classList.toggle("open");
      toggle.setAttribute("aria-expanded", String(expanded));
    });
    updateNavigation();
  }
  const footer = document.getElementById("site-footer");
  if (footer) footer.innerHTML = '<footer class="site-footer"><span>© MovieBookingSystem </span><div class="footer-links"><a href="api-status.html">API status</a><a href="notifications.html">Notifications</a></div></footer>';

  function updateNavigation() {
    const actions = document.getElementById("nav-actions");
    if (!actions) return;
    const token = sessionStorage.getItem("moviebook_token");
    const user = readStored("moviebook_user");
    actions.replaceChildren();
    if (token) {
      if (user && user.name) {
        const name = document.createElement("span");
        name.className = "nav-user";
        name.textContent = user.name;
        actions.append(name);
      }
      const notificationMenu = document.createElement("div");
      notificationMenu.className = "notification-menu";
      const bell = document.createElement("button");
      bell.className = "notification-link";
      bell.type = "button";
      bell.setAttribute("aria-label", "Notifications");
      bell.setAttribute("aria-expanded", "false");
      bell.setAttribute("aria-controls", "notification-popover");
      bell.title = "View recent notifications";
      bell.textContent = "🔔";
      const popover = document.createElement("section");
      popover.className = "notification-popover";
      popover.id = "notification-popover";
      popover.hidden = true;
      popover.setAttribute("aria-label", "Recent notifications");
      notificationMenu.append(bell, popover);
      actions.append(notificationMenu);
      bell.addEventListener("click", function () {
        const opening = popover.hidden;
        popover.hidden = !opening;
        bell.setAttribute("aria-expanded", String(opening));
        if (opening) loadRecentNotifications(popover);
      });
      refreshUnreadCount(bell);
      const logout = document.createElement("button");
      logout.className = "button button-outline";
      logout.type = "button";
      logout.textContent = "Sign out";
      logout.addEventListener("click", async function () {
        try {
          await window.Api.request("auth", "auth/logout", { method: "POST" });
          clearAuth();
          toast("Signed out.", "success");
        } catch (error) {
          if (error.status === 401) clearAuth();
          toast(error.message, "error");
        }
      });
      actions.append(logout);
    } else {
      const notificationLink = document.createElement("a");
      notificationLink.className = "notification-link";
      notificationLink.href = "notifications.html";
      notificationLink.setAttribute("aria-label", "View notifications");
      notificationLink.title = "Sign in to view notifications";
      notificationLink.textContent = "🔔";
      const login = document.createElement("a");
      login.className = "button button-outline";
      login.href = "login.html";
      login.textContent = "Sign in";
      const register = document.createElement("a");
      register.className = "button button-primary";
      register.href = "register.html";
      register.textContent = "Register";
      actions.append(notificationLink, login, register);
    }
  }

  async function refreshUnreadCount(anchor) {
    const target = anchor || document.querySelector(".notification-link");
    if (!target || !sessionStorage.getItem("moviebook_token")) return;
    const existingCount = target.querySelector(".notification-count");
    if (existingCount) existingCount.remove();
    try {
      const response = await window.Api.request("notifications", "notifications/unread?per_page=1");
      const data = records(response);
      const total = data.total;
      if (typeof total === "number" && total > 0) {
        const count = document.createElement("span");
        count.className = "notification-count";
        count.textContent = total > 99 ? "99+" : String(total);
        count.setAttribute("aria-hidden", "true");
        target.append(count);
        target.setAttribute("aria-label", "Notifications, " + total + " unread");
      } else {
        target.setAttribute("aria-label", "Notifications, no unread items");
      }
    } catch (error) {
      if (error.status === 401) {
        updateNavigation();
      } else {
        target.title = "Unread notifications could not be loaded: " + error.message;
      }
    }
  }

  async function loadRecentNotifications(popover) {
    popover.replaceChildren(element("p", "notification-popover-status", "Loading notifications…"));
    try {
      const response = await window.Api.request("notifications", "notifications?per_page=5");
      const items = records(response).items;
      popover.replaceChildren();

      if (!items.length) {
        popover.append(element("p", "notification-popover-status", "No notifications yet."));
      } else {
        items.forEach(function (item) {
          const entry = element("article", "notification-popover-item" + (item.read_at ? "" : " unread"));
          entry.append(
            element("strong", "", item.title || "Movie Booking Update"),
            element("span", "", item.message || "Your booking has been updated."),
            element("small", "", dateTime(item.created_at || item.occurred_at))
          );
          popover.append(entry);
        });
      }

      const all = element("a", "notification-popover-all", "View all notifications");
      all.href = "notifications.html";
      popover.append(all);
    } catch (error) {
      popover.replaceChildren(element(
        "p",
        "notification-popover-status error",
        error.status === 401 ? "Sign in to view your notifications." : "Notifications are temporarily unavailable."
      ));
    }
  }

  document.addEventListener("click", function (event) {
    const menu = document.querySelector(".notification-menu");
    if (menu && !menu.contains(event.target)) {
      const bell = menu.querySelector(".notification-link");
      const popover = menu.querySelector(".notification-popover");
      if (popover && !popover.hidden) {
        popover.hidden = true;
        bell.setAttribute("aria-expanded", "false");
      }
    }
  });

  document.addEventListener("keydown", function (event) {
    if (event.key === "Escape") {
      const menu = document.querySelector(".notification-menu");
      const bell = menu && menu.querySelector(".notification-link");
      const popover = menu && menu.querySelector(".notification-popover");
      if (popover && !popover.hidden) {
        popover.hidden = true;
        bell.setAttribute("aria-expanded", "false");
        bell.focus();
      }
    }
  });

  function readStored(key) {
    try { return JSON.parse(sessionStorage.getItem(key) || "null"); }
    catch (_) { return null; }
  }
  function clearAuth() {
    sessionStorage.removeItem("moviebook_token");
    sessionStorage.removeItem("moviebook_user");
    updateNavigation();
    if (location.pathname.toLowerCase().indexOf("booking") >= 0 || location.pathname.toLowerCase().indexOf("ticket") >= 0) {
      location.href = "login.html";
    }
  }
  function records(response) {
    let node = response;
    for (let i = 0; i < 3 && node && !Array.isArray(node); i++) {
      if (Array.isArray(node.data)) return { items: node.data, total: node.total };
      if (node.data && typeof node.data === "object") {
        const nested = node.data;
        if (Array.isArray(nested.data)) return { items: nested.data, total: nested.total };
        node = nested;
      } else break;
    }
    return { items: Array.isArray(node) ? node : [], total: node && node.total };
  }
  function toast(message, type) {
    const region = document.getElementById("toast-region");
    if (!region) return;
    const item = document.createElement("div");
    item.className = "toast " + (type || "info");
    item.textContent = message || "Request completed.";
    region.append(item);
    window.setTimeout(function () { item.remove(); }, 6000);
  }
  function element(tag, className, text) {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== undefined) node.textContent = text;
    return node;
  }
  function emptyState(title, message) {
    const node = element("div", "empty-state");
    node.append(element("strong", "", title), element("span", "", message));
    return node;
  }
  function escapeText(value, fallback) {
    return value === null || value === undefined || value === "" ? (fallback || "—") : String(value);
  }
  function money(value) {
    const amount = Number(value);
    return Number.isFinite(amount) ? new Intl.NumberFormat("en-IN", { style: "currency", currency: "INR" }).format(amount) : "—";
  }
  function dateTime(value, options) {
    if (!value) return "—";
    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? String(value) : new Intl.DateTimeFormat(undefined, options || { dateStyle: "medium", timeStyle: "short" }).format(date);
  }
  function getRecords(response) { return records(response); }
  function storeKnownBooking(bookingId) {
    const key = accountKey("moviebook_booking_ids");
    const ids = readStored(key) || [];
    if (bookingId && !ids.some(function (id) { return String(id) === String(bookingId); })) {
      ids.unshift(bookingId);
      sessionStorage.setItem(key, JSON.stringify(ids.slice(0, 40)));
    }
  }
  function accountKey(prefix) {
    const user = readStored("moviebook_user");
    return prefix + "_" + (user && user.id ? String(user.id) : "unknown");
  }

  const debugList = document.getElementById("debug-list");
  if (debugList) {
    window.ApiDebug = {
      add: function (entry) {
        const count = document.getElementById("debug-count");
        if (count) count.textContent = String(Number(count.textContent || 0) + 1);
        const row = element("details", "debug-entry");
        const summary = element("summary", "", entry.method + " " + entry.url + " · " + entry.status + " · " + entry.duration + " ms");
        const pre = element("pre");
        pre.textContent = JSON.stringify({
          requestHeaders: entry.requestHeaders,
          requestBody: entry.requestBody,
          responseBody: entry.responseBody
        }, null, 2);
        row.append(summary, pre);
        debugList.prepend(row);
        while (debugList.children.length > 35) debugList.lastElementChild.remove();
        const statusDebug = document.getElementById("status-debug");
        if (statusDebug && statusDebug !== debugList) {
          const copy = row.cloneNode(true);
          statusDebug.prepend(copy);
        }
      }
    };
  }

  window.App = {
    toast: toast, element: element, emptyState: emptyState, money: money,
    dateTime: dateTime, getRecords: getRecords, readStored: readStored,
    storeKnownBooking: storeKnownBooking, updateNavigation: updateNavigation,
    refreshUnreadCount: refreshUnreadCount, clearAuth: clearAuth,
    escapeText: escapeText, accountKey: accountKey
  };
  window.setInterval(function () {
    if (sessionStorage.getItem("moviebook_token")) {
      refreshUnreadCount();
      const popover = document.querySelector(".notification-popover:not([hidden])");
      if (popover) loadRecentNotifications(popover);
    }
  }, window.MovieConfig.notificationRefreshMs);
  if (location.pathname.toLowerCase().endsWith("index.html") && sessionStorage.getItem("moviebook_token")) {
    window.addEventListener("load", function () {
      if (!window.Auth) return;
      window.Auth.profile().catch(function (error) { toast("Profile could not be refreshed: " + error.message, "error"); });
    }, { once: true });
  }
})();
