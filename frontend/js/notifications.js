(function () {
  "use strict";
  const app = window.App;
  const list = document.getElementById("notification-list");
  if (!list) return;
  const status = document.getElementById("notification-status");
  let loading = false;
  let poller = null;

  (async function initialize() {
    const user = await window.Auth.requireAuth();
    if (!user) return;
    await loadNotifications();
    poller = window.setInterval(loadNotifications, window.MovieConfig.notificationRefreshMs);
  })();

  async function loadNotifications() {
    if (loading) return;
    loading = true;
    status.className = "inline-status";
    status.textContent = "Loading notifications…";
    try {
      const response = await window.Api.request("notifications", "notifications?per_page=50");
      const items = app.getRecords(response).items;
      list.replaceChildren();
      if (!items.length) {
        status.textContent = "No notifications have been returned for this account.";
        list.append(app.emptyState("You're all caught up", "Only notifications stored by Notification Service are shown."));
        return;
      }
      status.textContent = "Showing " + items.length + " notification(s) returned by Notification Service.";
      items.forEach(function (notification) { list.append(notificationCard(notification)); });
    } catch (error) {
      status.className = "inline-status error";
      status.textContent = error.message;
      list.replaceChildren(app.emptyState("Notifications unavailable", "Check Notification Service and authentication, then refresh."));
    } finally { loading = false; }
  }

  function notificationCard(notification) {
    const unread = !notification.read_at;
    const card = app.element("article", "notification-card" + (unread ? " unread" : ""));
    const content = app.element("div");
    const title = notification.title || notification.event_type || notification.type || "Notification";
    content.append(app.element("h3", "", title));
    content.append(app.element("p", "", notification.message || notification.body || "No message was included."));
    content.append(app.element("p", "muted", [
      notification.event_type || notification.type || "Event type not provided",
      app.dateTime(notification.created_at),
      unread ? "Unread" : "Read"
    ].join(" · ")));
    const actions = app.element("div", "notification-actions");
    const details = app.element("button", "button button-outline", "Details");
    details.type = "button";
    details.addEventListener("click", function () { showDetails(notification.id); });
    actions.append(details);
    if (unread) {
      const read = app.element("button", "button button-primary", "Mark read");
      read.type = "button";
      read.addEventListener("click", async function () {
        try {
          await window.Api.request("notifications", "notifications/" + encodeURIComponent(notification.id) + "/read", { method: "POST" });
          await app.refreshUnreadCount();
          app.toast("Notification marked as read.", "success");
          const popover = document.querySelector(".notification-popover:not([hidden])");
          if (popover) {
            const bell = document.querySelector(".notification-link");
            if (bell) bell.click();
          }
          await loadNotifications();
        } catch (error) { app.toast(error.message, "error"); }
      });
      actions.append(read);
    }
    card.append(content, actions);
    return card;
  }

  async function showDetails(id) {
    try {
      const responses = await Promise.all([
        window.Api.request("notifications", "notifications/" + encodeURIComponent(id)),
        window.Api.request("notifications", "notifications/" + encodeURIComponent(id) + "/delivery-status")
      ]);
      const notification = responses[0] && (responses[0].data || responses[0]);
      const delivery = responses[1] && (responses[1].data || responses[1]);
      const title = notification.title || notification.event_type || "Notification";
      app.toast(title + " · Delivery: " + (delivery.delivery_status || "not provided") + (delivery.delivered_at ? " · " + app.dateTime(delivery.delivered_at) : ""), "info");
    } catch (error) { app.toast(error.message, "error"); }
  }

  document.getElementById("refresh-notifications").addEventListener("click", function (event) {
    window.Api.withLoading(event.currentTarget, loadNotifications, "Refreshing…").catch(function (error) { app.toast(error.message, "error"); });
  });
  document.getElementById("read-all").addEventListener("click", async function (event) {
    try {
      await window.Api.withLoading(event.currentTarget, function () {
        return window.Api.request("notifications", "notifications/read-all", { method: "POST" });
      }, "Updating…");
      await app.refreshUnreadCount();
      app.toast("Notification Service marked all available notifications as read.", "success");
      await loadNotifications();
    } catch (error) { app.toast(error.message, "error"); }
  });
  window.addEventListener("beforeunload", function () { if (poller) window.clearInterval(poller); });
})();
