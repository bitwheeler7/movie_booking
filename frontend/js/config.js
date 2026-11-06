(function () {
  "use strict";
  window.MovieConfig = Object.freeze({
    services: Object.freeze({
      auth: "http://127.0.0.1:8001/api",
      catalog: "http://127.0.0.1:8002/api",
      booking: "http://127.0.0.1:8003/api",
      payment: "http://127.0.0.1:8004/api",
      notifications: "http://127.0.0.1:8005/api"
    }),
    availabilityRefreshMs: 20000,
    notificationRefreshMs: 30000,
    paymentRefreshMs: 5000
  });
})();
