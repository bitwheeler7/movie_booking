(function () {
  "use strict";
  const app = window.App;
  const seatGrid = document.getElementById("seat-grid");
  if (!seatGrid) return;

  const query = new URLSearchParams(location.search);
  const showId = query.get("show_id");
  const status = document.getElementById("seat-status");
  const selectedLabel = document.getElementById("selected-seats");
  const totalLabel = document.getElementById("seat-total");
  const lockButton = document.getElementById("lock-seats");
  const checkout = document.getElementById("checkout-section");
  const lockState = document.getElementById("lock-state");
  const releaseButton = document.getElementById("release-lock");
  const selected = new Map();
  let user = null;
  let show = null;
  let seats = [];
  let activeBooking = null;
  let countdownTimer = null;
  let availabilityTimer = null;

  window.BookingState = {
    showId: showId,
    user: null,
    booking: null,
    refresh: refreshAvailability
  };

  (async function initialize() {
    if (!showId || !/^\d+$/.test(showId)) {
      status.className = "inline-status error";
      status.textContent = "A valid show_id is required. Return to the movie page and select a show.";
      disableSelection();
      return;
    }
    user = await window.Auth.requireAuth();
    if (!user) return;
    window.BookingState.user = user;
    sessionStorage.setItem(app.accountKey("moviebook_show_id"), showId);
    sessionStorage.setItem("moviebook_selected_show_id", showId);
    const back = document.getElementById("back-to-shows");
    const previousMovie = query.get("movie_id");
    if (previousMovie) back.href = "movie-details.html?id=" + encodeURIComponent(previousMovie);

    try {
      const response = await window.Api.request("catalog", "shows/" + encodeURIComponent(showId));
      show = response && (response.data || response);
      renderShowSummary(show);
    } catch (error) {
      status.className = "inline-status error";
      status.textContent = "Show details could not be loaded: " + error.message;
      disableSelection();
      return;
    }

    await refreshAvailability();
    availabilityTimer = window.setInterval(refreshAvailability, window.MovieConfig.availabilityRefreshMs);
    restorePendingBooking();
  })();

  function renderShowSummary(value) {
    const summary = document.getElementById("show-summary");
    const theatre = value.screen && value.screen.theatre;
    const parts = [theatre && theatre.name, theatre && theatre.city && theatre.city.name, value.screen && value.screen.name].filter(Boolean);
    const movieTitle = value.movie && value.movie.title;
    const card = app.element("div", "show-summary-card");
    const description = app.element("div");
    description.append(app.element("strong", "", movieTitle || "Selected show"));
    description.append(app.element("p", "muted", parts.join(" · ") || "Theatre details were not returned by Catalog Service."));
    const time = app.element("div", "", app.dateTime(value.start_time));
    time.style.fontWeight = "700";
    card.append(description, time);
    summary.replaceChildren(card);
  }

  async function refreshAvailability() {
    try {
      const response = await window.Api.request("booking", "shows/" + encodeURIComponent(showId) + "/availability", { auth: false });
      const result = app.getRecords(response);
      seats = result.items;
      const availableIds = new Set(seats.filter(isAvailable).map(function (seat) { return String(seat.seat_id); }));
      Array.from(selected.keys()).forEach(function (id) {
        if (!availableIds.has(String(id))) selected.delete(id);
      });
      renderSeats();
      updateSelectionSummary();
      status.className = "inline-status";
      status.textContent = seats.length ? "Availability from Booking Service · refreshed " + new Date().toLocaleTimeString() : "No seat inventory was returned for this show.";
      lockButton.disabled = selected.size === 0;
    } catch (error) {
      status.className = "inline-status error";
      status.textContent = error.message;
      if (!seats.length) disableSelection();
    }
  }

  function renderSeats() {
    seatGrid.replaceChildren();
    if (!seats.length) {
      seatGrid.append(app.emptyState("Seat inventory unavailable", "The backend returned no seats for this show. Inventory may need to be initialized by the backend operator."));
      return;
    }
    const groups = new Map();
    seats.forEach(function (seat) {
      const number = String(seat.seat_number || seat.seat_id);
      const match = number.match(/^([A-Za-z]+)/);
      const row = match ? match[1].toUpperCase() : number.charAt(0).toUpperCase();
      if (!groups.has(row)) groups.set(row, []);
      groups.get(row).push(seat);
    });
    Array.from(groups.keys()).sort(function (a, b) { return a.localeCompare(b, undefined, { numeric: true }); }).forEach(function (rowName) {
      const row = app.element("div", "seat-row");
      row.append(app.element("span", "row-label", rowName));
      groups.get(rowName).sort(function (a, b) {
        return String(a.seat_number).localeCompare(String(b.seat_number), undefined, { numeric: true });
      }).forEach(function (seat) {
        const key = String(seat.seat_id);
        const isSelected = selected.has(key);
        const available = isAvailable(seat);
        const seatButton = app.element("button", "seat", seat.seat_number || key);
        seatButton.type = "button";
        seatButton.setAttribute("aria-label", "Seat " + (seat.seat_number || key) + ", " + (isSelected ? "selected" : String(seat.status || "unknown").toLowerCase()));
        seatButton.title = seat.seat_number + " · " + app.money(seat.ticket_price);
        if (isSelected) seatButton.classList.add("selected");
        else if (String(seat.status).toUpperCase() === "BOOKED") seatButton.classList.add("booked");
        else if (!available) seatButton.classList.add("locked");
        seatButton.disabled = !available && !isSelected;
        seatButton.addEventListener("click", function () { toggleSeat(seat); });
        row.append(seatButton);
      });
      row.append(app.element("span", "row-label", rowName));
      seatGrid.append(row);
    });
  }

  function toggleSeat(seat) {
    const key = String(seat.seat_id);
    if (selected.has(key)) {
      selected.delete(key);
    } else if (selected.size >= 10) {
      app.toast("A maximum of 10 seats can be selected.", "error");
      return;
    } else {
      selected.set(key, seat);
    }
    renderSeats();
    updateSelectionSummary();
    lockButton.disabled = selected.size === 0;
  }

  function updateSelectionSummary() {
    const values = Array.from(selected.values());
    selectedLabel.textContent = values.length ? values.map(function (seat) { return seat.seat_number; }).join(", ") : "None";
    totalLabel.textContent = app.money(values.reduce(function (sum, seat) { return sum + Number(seat.ticket_price || 0); }, 0));
  }

  function isAvailable(seat) {
    return String(seat.status || "").toUpperCase() === "AVAILABLE";
  }

  lockButton.addEventListener("click", async function () {
    if (!user || selected.size < 1 || selected.size > 10) return;
    await window.Api.withLoading(lockButton, async function () {
      try {
        await refreshAvailability();
        const unavailable = Array.from(selected.keys()).some(function (id) {
          const current = seats.find(function (seat) { return String(seat.seat_id) === id; });
          return !current || !isAvailable(current);
        });
        if (unavailable) {
          app.toast("One or more selected seats are no longer available. Review the refreshed layout.", "error");
          return;
        }
        const response = await window.Api.request("booking", "bookings/lock", {
          method: "POST",
          body: {
            user_id: Number(user.id),
            show_id: Number(showId),
            seat_ids: Array.from(selected.keys()).map(Number)
          }
        });
        activeBooking = response && (response.data || response);
        if (!activeBooking || !activeBooking.id) throw new Error("Booking Service did not return a booking ID.");
        window.BookingState.booking = activeBooking;
        sessionStorage.setItem(app.accountKey("moviebook_active_booking_id"), String(activeBooking.id));
        app.storeKnownBooking(activeBooking.id);
        selected.clear();
        checkout.hidden = false;
        renderLockState();
        const viewLink = document.getElementById("open-confirmation");
        viewLink.href = "ticket.html?id=" + encodeURIComponent(activeBooking.id);
        app.toast("Seats locked by Booking Service.", "success");
        lockButton.disabled = true;
        startCountdown();
        window.dispatchEvent(new CustomEvent("moviebook:booking-locked", { detail: activeBooking }));
      } catch (error) {
        app.toast(error.message, "error");
        await refreshAvailability();
      }
    }, "Locking seats…");
    lockButton.disabled = selected.size === 0 || Boolean(activeBooking && activeBooking.booking_status === "PENDING" && new Date(activeBooking.lock_expires_at).getTime() > Date.now());
  });

  document.getElementById("refresh-seats").addEventListener("click", function (event) {
    window.Api.withLoading(event.currentTarget, refreshAvailability, "Refreshing…").catch(function (error) { app.toast(error.message, "error"); });
  });

  releaseButton.addEventListener("click", async function () {
    if (!activeBooking || !user) return;
    if (!window.confirm("Release this pending booking and its seat locks?")) return;
    try {
      await window.Api.withLoading(releaseButton, function () {
        return window.Api.request("booking", "bookings/" + encodeURIComponent(activeBooking.id) + "/release", {
          method: "POST", body: { user_id: Number(user.id) }
        });
      }, "Releasing…");
      activeBooking.booking_status = "EXPIRED";
      stopCountdown();
      lockState.textContent = "The pending booking was released by Booking Service.";
      app.toast("Seat locks released.", "success");
      window.dispatchEvent(new CustomEvent("moviebook:booking-released"));
      await refreshAvailability();
    } catch (error) { app.toast(error.message, "error"); }
  });

  function restorePendingBooking() {
    const id = sessionStorage.getItem(app.accountKey("moviebook_active_booking_id"));
    if (!id) return;
    window.Api.request("booking", "bookings/" + encodeURIComponent(id)).then(function (response) {
      const booking = response && (response.data || response);
      if (booking.booking_status === "PENDING" && new Date(booking.lock_expires_at).getTime() > Date.now()) {
        activeBooking = booking;
        window.BookingState.booking = booking;
        checkout.hidden = false;
        renderLockState();
        const viewLink = document.getElementById("open-confirmation");
        viewLink.href = "ticket.html?id=" + encodeURIComponent(booking.id);
        startCountdown();
      }
    }).catch(function (error) {
      if (error.status !== 404) app.toast("Could not restore the pending booking: " + error.message, "error");
    });
  }

  function renderLockState() {
    lockState.replaceChildren();
    const title = app.element("h2", "", "Seats held");
    const seatsText = activeBooking.seats && Array.isArray(activeBooking.seats)
      ? activeBooking.seats.map(function (seat) { return seat.seat_number || seat.seat && seat.seat.seat_number; }).filter(Boolean).join(", ")
      : "Booking #" + activeBooking.id;
    const label = app.element("p", "", "Booking reference: " + (activeBooking.booking_reference || activeBooking.id) + " · Seats: " + (seatsText || "see booking details"));
    const timer = app.element("p", "lock-clock", "Checking lock expiry…");
    timer.id = "lock-countdown";
    lockState.append(title, label, timer);
    document.getElementById("create-payment").disabled = false;
    releaseButton.disabled = false;
  }

  function startCountdown() {
    stopCountdown();
    updateCountdown();
    countdownTimer = window.setInterval(updateCountdown, 1000);
  }

  function updateCountdown() {
    const timer = document.getElementById("lock-countdown");
    if (!timer || !activeBooking) return;
    const expiry = new Date(activeBooking.lock_expires_at).getTime();
    if (!Number.isFinite(expiry)) {
      timer.textContent = "Lock expiry was not included in the booking response.";
      document.getElementById("create-payment").disabled = true;
      return;
    }
    const seconds = Math.max(0, Math.floor((expiry - Date.now()) / 1000));
    timer.textContent = seconds > 0
      ? "Lock expires in " + String(Math.floor(seconds / 60)).padStart(2, "0") + ":" + String(seconds % 60).padStart(2, "0")
      : "Seat lock expired. Payment cannot be initiated.";
    document.getElementById("create-payment").disabled = seconds === 0 || !["PENDING", undefined].includes(activeBooking.booking_status);
    if (seconds === 0) stopCountdown();
  }

  function stopCountdown() {
    if (countdownTimer) window.clearInterval(countdownTimer);
    countdownTimer = null;
  }

  function disableSelection() {
    lockButton.disabled = true;
    document.getElementById("refresh-seats").disabled = true;
  }

  window.addEventListener("beforeunload", function () {
    if (availabilityTimer) window.clearInterval(availabilityTimer);
    stopCountdown();
  });
})();
