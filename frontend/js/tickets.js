(function () {
  "use strict";
  const app = window.App;
  const list = document.getElementById("booking-list");
  const ticketContent = document.getElementById("ticket-content");

  if (list) initializeBookings();
  if (ticketContent) initializeTicketPage();

  async function initializeBookings() {
    const user = await window.Auth.requireAuth();
    if (!user) return;
    const ids = app.readStored(app.accountKey("moviebook_booking_ids")) || [];
    list.replaceChildren();
    if (!ids.length) {
      list.append(app.emptyState("No session bookings", "Bookings created with this account in this browser session will appear here. The API does not provide a booking-list endpoint."));
      return;
    }
    ids.forEach(function (id) {
      const card = app.element("article", "booking-card");
      card.dataset.bookingId = String(id);
      card.append(app.element("div", "", "Loading booking #" + id + "…"));
      list.append(card);
      loadBookingCard(id, card, user);
    });

    const dialog = document.getElementById("cancel-dialog");
    let cancelTarget = null;
    list.addEventListener("click", function (event) {
      const release = event.target.closest("[data-release]");
      if (release) releasePending(release.dataset.release, release, user);
      const cancel = event.target.closest("[data-cancel]");
      if (cancel) {
        cancelTarget = cancel.dataset.cancel;
        dialog.showModal();
      }
    });
    document.getElementById("confirm-cancel").addEventListener("click", async function (event) {
      event.preventDefault();
      if (!cancelTarget) return;
      const reason = document.getElementById("cancel-reason").value.trim();
      try {
        await window.Api.withLoading(event.currentTarget, function () {
          return window.Api.request("booking", "bookings/" + encodeURIComponent(cancelTarget) + "/cancel", {
            method: "POST", body: { reason: reason }
          });
        }, "Cancelling…");
        dialog.close();
        app.toast("Cancellation and refund request were accepted by Booking Service.", "success");
        await loadBookingCard(cancelTarget, findCard(cancelTarget), user);
      } catch (error) {
        app.toast(error.message, "error");
      } finally {
        cancelTarget = null;
      }
    });
  }

  async function loadBookingCard(id, card, user) {
    if (!card) return;
    card.replaceChildren(app.element("div", "", "Loading booking #" + id + "…"));
    try {
      const response = await window.Api.request("booking", "bookings/" + encodeURIComponent(id));
      const booking = response && (response.data || response);
      const details = app.element("div");
      const reference = booking.booking_reference || booking.id;
      details.append(app.element("h3", "", "Booking " + reference));
      details.append(app.element("p", "", "Booking #" + booking.id + " · Show #" + booking.show_id));
      details.append(app.element("p", "", "Seats: " + seatNumbers(booking.seats)));
      details.append(app.element("p", "", "Total: " + app.money(booking.total_amount) + " · Payment: " + app.escapeText(booking.payment_status)));
      const row = app.element("div");
      row.append(app.element("span", "status-pill " + statusClass(booking.booking_status), app.escapeText(booking.booking_status)));
      const actions = app.element("div", "button-row");
      const view = app.element("a", "button button-outline", "View details / ticket");
      view.href = "ticket.html?id=" + encodeURIComponent(booking.id);
      actions.append(view);
      if (booking.booking_status === "CONFIRMED") {
        const cancel = app.element("button", "button button-outline", "Cancel booking");
        cancel.type = "button";
        cancel.dataset.cancel = String(booking.id);
        actions.append(cancel);
      } else if (booking.booking_status === "PENDING") {
        const release = app.element("button", "button button-outline", "Release seat lock");
        release.type = "button";
        release.dataset.release = String(booking.id);
        actions.append(release);
      }
      if (booking.payment_status) row.append(document.createTextNode(" Payment: " + booking.payment_status));
      card.replaceChildren(details, row, actions);
    } catch (error) {
      card.replaceChildren(app.element("div", "inline-status error", "Booking #" + id + " could not be loaded: " + error.message));
      const remove = app.element("button", "button button-outline", "Remove from this list");
      remove.type = "button";
      remove.addEventListener("click", function () {
        const ids = (app.readStored(app.accountKey("moviebook_booking_ids")) || []).filter(function (value) { return String(value) !== String(id); });
        sessionStorage.setItem(app.accountKey("moviebook_booking_ids"), JSON.stringify(ids));
        card.remove();
      });
      card.append(remove);
    }
  }

  async function releasePending(id, button, user) {
    if (!window.confirm("Release this pending booking and its seat locks?")) return;
    try {
      await window.Api.withLoading(button, function () {
        return window.Api.request("booking", "bookings/" + encodeURIComponent(id) + "/release", {
          method: "POST", body: { user_id: Number(user.id) }
        });
      }, "Releasing…");
      app.toast("Seat locks released.", "success");
      await loadBookingCard(id, findCard(id), user);
    } catch (error) { app.toast(error.message, "error"); }
  }

  async function initializeTicketPage() {
    const user = await window.Auth.requireAuth();
    if (!user) return;
    const id = new URLSearchParams(location.search).get("id");
    if (!id || !/^\d+$/.test(id)) {
      ticketContent.append(app.emptyState("Booking not selected", "Choose a booking from My bookings."));
      return;
    }
    try {
      const response = await window.Api.request("booking", "bookings/" + encodeURIComponent(id));
      const booking = response && (response.data || response);
      let show = null;
      try {
        const showResponse = await window.Api.request("catalog", "shows/" + encodeURIComponent(booking.show_id));
        show = showResponse && (showResponse.data || showResponse);
      } catch (error) {
        app.toast("Show details could not be loaded: " + error.message, "error");
      }
      let ticket = booking.ticket || null;
      let ticketError = null;
      if (String(booking.booking_status).toUpperCase() === "CONFIRMED") {
        try {
          const ticketResponse = await window.Api.request("booking", "bookings/" + encodeURIComponent(id) + "/ticket");
          ticket = ticketResponse && (ticketResponse.data || ticketResponse);
        } catch (error) { ticketError = error; }
      }
      ticketContent.append(renderTicket(booking, show, ticket, ticketError));
    } catch (error) {
      ticketContent.append(app.emptyState("Booking unavailable", error.message));
    }
  }

  function renderTicket(booking, show, ticket, ticketError) {
    const wrapper = app.element("section", "ticket-card");
    const heading = app.element("div", "ticket-top");
    heading.append(app.element("div", "brand", "MovieBook"));
    heading.append(app.element("h1", "", ticket && String(booking.booking_status).toUpperCase() !== "CANCELLED" ? "Your movie ticket" : "Booking details"));
    heading.append(app.element("p", "", "Booking reference · " + (booking.booking_reference || booking.id)));
    const body = app.element("div", "ticket-body");
    const theatre = show && show.screen && show.screen.theatre;
    const fields = [
      ["Booking ID", booking.id],
      ["Booking status", booking.booking_status],
      ["Payment status", booking.payment_status],
      ["Movie", show && show.movie && show.movie.title],
      ["Theatre", theatre && theatre.name],
      ["Screen", show && show.screen && show.screen.name],
      ["Show date", show && app.dateTime(show.start_time, { dateStyle: "full" })],
      ["Show time", show && app.dateTime(show.start_time, { hour: "numeric", minute: "2-digit" })],
      ["Seats", seatNumbers(booking.seats)],
      ["Total amount", booking.total_amount === undefined ? "—" : app.money(booking.total_amount)],
      ["Ticket number", ticket && ticket.ticket_number],
      ["Ticket status", ticket && ticket.status]
    ];
    const grid = app.element("div", "ticket-grid");
    fields.forEach(function (field) {
      const cell = app.element("div", "ticket-field");
      cell.append(app.element("small", "", field[0]), app.element("strong", "", app.escapeText(field[1])));
      grid.append(cell);
    });
    body.append(grid, app.element("div", "ticket-separator"));
    if (ticketError) {
      body.append(app.element("p", "inline-status error", "Ticket API: " + ticketError.message));
    } else if (!ticket && String(booking.booking_status).toUpperCase() !== "CONFIRMED") {
      body.append(app.element("p", "muted", "A ticket is issued only after Booking Service confirms the booking. Current status: " + booking.booking_status + "."));
    }
    if (String(booking.booking_status).toUpperCase() === "CANCELLED") {
      body.append(app.element("p", "inline-status", "Refund status: " + app.escapeText(booking.payment_status || "Check Payment Service for details.")));
    }
    const actions = app.element("div", "ticket-actions");
    if (ticket) {
      const print = app.element("button", "button button-primary", "Print ticket");
      print.type = "button";
      print.addEventListener("click", function () { window.print(); });
      actions.append(print);
    }
    const back = app.element("a", "button button-outline", "My bookings");
    back.href = "my-bookings.html";
    actions.append(back);
    wrapper.append(heading, body, actions);
    return wrapper;
  }

  function seatNumbers(seats) {
    if (!Array.isArray(seats) || !seats.length) return "—";
    return seats.map(function (seat) {
      return seat.seat_number || seat.seat && seat.seat.seat_number || seat.id;
    }).filter(Boolean).join(", ");
  }
  function statusClass(status) {
    const normalized = String(status || "").toLowerCase();
    if (normalized.indexOf("confirm") >= 0 || normalized === "booked") return "confirmed";
    if (normalized.indexOf("pending") >= 0) return "pending";
    if (normalized.indexOf("cancel") >= 0 || normalized.indexOf("fail") >= 0 || normalized === "expired") return "cancelled";
    return "";
  }
  function findCard(id) {
    return list.querySelector('[data-booking-id="' + CSS.escape(String(id)) + '"]');
  }
})();
