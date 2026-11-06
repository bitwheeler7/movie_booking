(function () {
  "use strict";
  const button = document.getElementById("create-payment");
  if (!button) return;
  const app = window.App;
  const output = document.getElementById("payment-state");
  const checkoutLink = document.getElementById("open-confirmation");
  let pollTimer = null;
  let user = null;
  let activeBooking = null;

  window.addEventListener("moviebook:booking-locked", function (event) {
    activeBooking = event.detail;
    user = window.BookingState && window.BookingState.user;
    output.replaceChildren();
    checkoutLink.hidden = true;
  });
  window.addEventListener("moviebook:booking-released", function () {
    activeBooking = null;
    stopPolling();
    output.textContent = "No active seat lock.";
  });

  button.addEventListener("click", async function () {
    activeBooking = activeBooking || (window.BookingState && window.BookingState.booking);
    user = user || (window.BookingState && window.BookingState.user);
    if (!activeBooking || !user) {
      app.toast("Lock seats before creating a payment intent.", "error");
      return;
    }
    if (activeBooking.booking_status && activeBooking.booking_status !== "PENDING") {
      app.toast("Only a pending booking can be paid.", "error");
      return;
    }
    const expiry = new Date(activeBooking.lock_expires_at).getTime();
    if (!Number.isFinite(expiry) || expiry <= Date.now()) {
      app.toast("The seat lock has expired. Payment cannot be initiated.", "error");
      return;
    }
    try {
      const response = await window.Api.withLoading(button, function () {
        return window.Api.request("payment", "payments/intent", {
          method: "POST",
          idempotencyKey: makeIdempotencyKey(activeBooking.id),
          body: {
            booking_id: Number(activeBooking.id),
            user_id: Number(user.id),
            payment_method: "MOCK"
          }
        });
      }, "Creating intent…");
      const payment = response && (response.data || response);
      if (!payment || !payment.id) throw new Error("Payment Service did not return a payment ID.");
      sessionStorage.setItem(app.accountKey("moviebook_payment_id"), String(payment.id));
      renderPayment(payment, "The backend returned this payment intent. Its status is not a payment confirmation.");
      app.toast("Payment intent created. Payment remains pending until a trusted gateway callback is processed.", "info");
      startPolling(payment.id);
    } catch (error) {
      renderPaymentError(error.message);
      app.toast(error.message, "error");
      if (error.status === 422 || error.status === 409) {
        try {
          await updateBookingState();
        } catch (refreshError) {
          app.toast("Booking status could not be refreshed: " + refreshError.message, "error");
        }
      }
    }
  });

  function startPolling(paymentId) {
    stopPolling();
    pollPayment(paymentId);
    pollTimer = window.setInterval(function () { pollPayment(paymentId); }, window.MovieConfig.paymentRefreshMs);
  }

  async function pollPayment(paymentId) {
    try {
      const response = await window.Api.request("payment", "payments/" + encodeURIComponent(paymentId));
      const payment = response && (response.data || response);
      renderPayment(payment, "Payment status is read from Payment Service.");
      const state = String(payment.status || "").toUpperCase();
      if (state !== "PENDING") {
        stopPolling();
        await updateBookingState(payment);
      }
    } catch (error) {
      renderPaymentError(error.message);
      if (error.status === 401 || error.status === 403 || error.status === 404) stopPolling();
    }
  }

  async function updateBookingState(payment) {
    if (!activeBooking && window.BookingState) activeBooking = window.BookingState.booking;
    if (!activeBooking || !activeBooking.id) return;
    const response = await window.Api.request("booking", "bookings/" + encodeURIComponent(activeBooking.id));
    const booking = response && (response.data || response);
    activeBooking = booking;
    window.BookingState.booking = booking;
    const status = String(booking.booking_status || "").toUpperCase();
    if (status === "CONFIRMED") {
      const ticketLink = "ticket.html?id=" + encodeURIComponent(booking.id);
      checkoutLink.href = ticketLink;
      checkoutLink.hidden = false;
      renderPayment(payment || booking, "Booking Service confirmed this booking. Ticket details are available from the booking endpoint.");
      app.toast("Booking confirmed by Booking Service.", "success");
      button.disabled = true;
      return;
    }
    if (status === "CANCELLATION_PENDING" || status === "CANCELLED") {
      renderPayment(booking, "Booking status: " + status + ". Check My bookings for refund details.");
    } else if (payment && ["REFUND_PENDING", "REFUNDED"].includes(String(payment.status || "").toUpperCase())) {
      renderPayment(payment, "Payment succeeded after the seat lock expired; the payment API reports refund handling.");
    } else if (status === "EXPIRED") {
      renderPayment(booking, "The seat lock expired and the booking was not confirmed.");
    }
  }

  function renderPayment(payment, message) {
    output.replaceChildren();
    const card = app.element("div", "payment-result");
    card.append(app.element("h3", "", "Payment / booking status"));
    const values = [
      ["Booking", payment.booking_reference || activeBooking && (activeBooking.booking_reference || activeBooking.id) || "—"],
      ["Payment reference", payment.payment_reference || "—"],
      ["Booking status", activeBooking && activeBooking.booking_status || payment.booking_status || "—"],
      ["Payment status", payment.status || activeBooking && activeBooking.payment_status || "—"],
      ["Amount", payment.amount === undefined ? activeBooking && activeBooking.total_amount !== undefined ? app.money(activeBooking.total_amount) : "—" : app.money(payment.amount)],
      ["Method", payment.payment_method || "MOCK"]
    ];
    values.forEach(function (item) {
      const line = app.element("p");
      line.append(app.element("strong", "", item[0] + ": "), document.createTextNode(String(item[1])));
      card.append(line);
    });
    if (Array.isArray(payment.refunds) && payment.refunds.length) {
      card.append(app.element("h4", "", "Refunds"));
      payment.refunds.forEach(function (refund) {
        card.append(app.element("p", "", (refund.refund_reference || "Refund") + " · " + (refund.status || "Status not provided") + (refund.amount === undefined ? "" : " · " + app.money(refund.amount))));
      });
    }
    card.append(app.element("p", "muted", message));
    card.append(app.element("p", "muted", "This API exposes payment intent creation and status reads. No browser payment-completion route exists; do not treat an intent as paid."));
    output.append(card);
  }

  function renderPaymentError(message) {
    output.replaceChildren(app.element("p", "inline-status error", message));
  }

  function makeIdempotencyKey(bookingId) {
    const keyName = "moviebook_idempotency_" + String(bookingId);
    let key = sessionStorage.getItem(keyName);
    if (!key) {
      key = "mb-" + String(bookingId) + "-" + crypto.randomUUID();
      sessionStorage.setItem(keyName, key);
    }
    return key;
  }
  function stopPolling() {
    if (pollTimer) window.clearInterval(pollTimer);
    pollTimer = null;
  }
  window.addEventListener("beforeunload", stopPolling);
})();
