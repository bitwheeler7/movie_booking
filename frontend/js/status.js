(function () {
  "use strict";
  const app = window.App;
  const cards = document.getElementById("service-cards");
  if (!cards) return;
  const services = [
    { key: "auth", name: "Authentication Service", base: "auth", path: "auth/profile", needsAuth: true },
    { key: "catalog", name: "Catalog Service", base: "catalog", path: "movies", needsAuth: false },
    { key: "booking", name: "Booking Service", base: "booking", needsAuth: false, path: null },
    { key: "payment", name: "Payment Service", base: "payment", needsAuth: true, path: null },
    { key: "notifications", name: "Notification Service", base: "notifications", path: "notifications/unread?per_page=1", needsAuth: true }
  ];

  document.getElementById("check-services").addEventListener("click", checkAll);
  checkAll();

  async function checkAll() {
    const button = document.getElementById("check-services");
    await window.Api.withLoading(button, async function () {
      cards.replaceChildren();
      const results = await Promise.all(services.map(checkService));
      results.forEach(function (result) { cards.append(serviceCard(result)); });
      renderSummary(results);
    }, "Checking…");
  }

  async function checkService(service) {
    let path = service.path;
    if (service.key === "booking") {
      const showId = app.readStored(app.accountKey("moviebook_show_id")) || sessionStorage.getItem("moviebook_selected_show_id");
      if (!showId || !/^\d+$/.test(String(showId))) {
        return { service: service, path: "shows/{showId}/availability", state: "configuration", status: "Not checked", duration: "—", checked: false, error: "No show ID is configured. Select a show first; this dashboard does not invent a health endpoint." };
      }
      path = "shows/" + encodeURIComponent(showId) + "/availability";
    }
    if (service.key === "payment") {
      const paymentId = sessionStorage.getItem(app.accountKey("moviebook_payment_id"));
      if (!paymentId || !/^\d+$/.test(paymentId)) {
        return { service: service, path: "payments/{id}", state: "configuration", status: "Not checked", duration: "—", checked: false, error: "A payment ID created by this browser session is required for GET /payments/{id}." };
      }
      path = "payments/" + encodeURIComponent(paymentId);
    }
    const start = performance.now();
    let statusCode = 0;
    let duration = 0;
    try {
      const token = sessionStorage.getItem("moviebook_token");
      await window.Api.request(service.base, path, {
        auth: Boolean(token),
        onResponse: function (result) { statusCode = result.status; duration = result.duration; }
      });
      return { service: service, path: path, state: "reachable", status: "HTTP " + statusCode, statusCode: statusCode, duration: duration, checked: true };
    } catch (error) {
      const authFailure = error.status === 401 || error.status === 403;
      const serverResponse = error.status > 0;
      const reachable = authFailure || (serverResponse && error.status < 500);
      return {
        service: service,
        path: path,
        state: authFailure ? "auth" : reachable ? "reachable" : "unavailable",
        status: error.status ? "HTTP " + error.status : "Network error",
        statusCode: error.status || null,
        duration: duration || Math.round(performance.now() - start),
        checked: true,
        error: error.message
      };
    }
  }

  function serviceCard(result) {
    const card = app.element("article", "service-card");
    const header = app.element("div", "service-head");
    header.append(app.element("h3", "", result.service.name));
    header.append(app.element("span", "status-pill " + (result.state === "reachable" ? "confirmed" : result.state === "auth" ? "pending" : result.state === "unavailable" ? "failed" : ""), stateLabel(result.state)));
    const base = window.MovieConfig.services[result.service.base];
    card.append(header, app.element("p", "service-url", base + "/" + String(result.path || result.service.path || "").replace(/^\//, "")));
    const detail = app.element("div", "service-details");
    detail.append(detailCell("Connection", stateLabel(result.state)));
    detail.append(detailCell("Response time", result.duration === "—" ? "—" : result.duration + " ms"));
    detail.append(detailCell("HTTP status", result.status));
    detail.append(detailCell("Last checked", result.checked ? new Date().toLocaleTimeString() : "—"));
    card.append(detail);
    if (result.error) card.append(app.element("p", "service-error", result.error));
    return card;
  }

  function detailCell(label, value) {
    const item = app.element("div");
    item.append(document.createTextNode(label + ": "), app.element("strong", "", String(value)));
    return item;
  }

  function renderSummary(results) {
    const container = document.getElementById("service-summary");
    const checked = results.filter(function (result) { return result.checked; });
    const reachable = results.filter(function (result) { return result.state === "reachable"; });
    const auth = results.filter(function (result) { return result.state === "auth"; });
    const unavailable = results.filter(function (result) { return result.state === "unavailable"; });
    const notConfigured = results.filter(function (result) { return result.state === "configuration"; });
    container.replaceChildren();
    [
      ["Services checked", checked.length + " / " + results.length],
      ["Reachable", reachable.length],
      ["Requiring authentication", auth.length],
      ["Unavailable", unavailable.length],
      ["Needs an ID", notConfigured.length]
    ].forEach(function (item) {
      const chip = app.element("div", "summary-chip");
      chip.append(app.element("strong", "", String(item[1])), document.createTextNode(item[0]));
      container.append(chip);
    });
  }

  function stateLabel(state) {
    return { reachable: "Reachable", auth: "Requires authentication", unavailable: "Unavailable", configuration: "Needs configured ID" }[state] || state;
  }
})();
