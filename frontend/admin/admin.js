(function () {
  "use strict";

  const api = window.Api;
  const formIds = ["city-form", "movie-form", "theatre-form", "screen-form", "seat-form", "show-form"];
  const state = {
    cities: [],
    movies: [],
    theatres: [],
    screens: [],
    seatsByScreen: new Map(),
    retryInventory: null
  };

  formIds.forEach(function (id) {
    document.getElementById(id).addEventListener("submit", function (event) {
      event.preventDefault();
      handleSubmit(event.currentTarget);
    });
  });

  document.getElementById("show-form").elements.screen_id.addEventListener("change", updateScheduleSeatCount);
  document.getElementById("show-form").elements.movie_id.addEventListener("change", updateMovieDuration);
  document.getElementById("retry-inventory").addEventListener("click", retryInventory);
  document.getElementById("refresh-shows").addEventListener("click", loadUpcomingShows);
  initializeScheduleDates();
  refreshCatalogData();
  loadUpcomingShows();

  async function handleSubmit(form) {
    const submit = form.querySelector('button[type="submit"]');
    const status = document.getElementById(form.dataset.status);
    status.className = "admin-form-status";
    status.textContent = "";
    submit.disabled = true;

    try {
      if (form.id === "city-form") {
        await createCity(form);
      } else if (form.id === "movie-form") {
        await createMovie(form);
      } else if (form.id === "theatre-form") {
        await createTheatre(form);
      } else if (form.id === "screen-form") {
        await createScreen(form);
      } else if (form.id === "seat-form") {
        await createSeats(form);
      } else if (form.id === "show-form") {
        await createShow(form);
      }
    } catch (error) {
      setStatus(status, error.message, "error");
    } finally {
      submit.disabled = false;
    }
  }

  async function createCity(form) {
    const body = formObject(form);
    omitEmpty(body, ["state", "country"]);
    await api.request("catalog", "cities", { method: "POST", body: body });
    form.reset();
    form.elements.country.value = "India";
    await refreshCatalogData();
    setGlobalStatus("City added.", "success");
    showFormStatus("city-status", "City added successfully.", "success");
  }

  async function createMovie(form) {
    const body = formObject(form);
    omitEmpty(body, ["description", "language", "genre", "release_date", "certificate", "poster_url"]);
    body.duration_minutes = Number(body.duration_minutes);
    await api.request("catalog", "movies", { method: "POST", body: body });
    form.reset();
    form.elements.duration_minutes.value = "120";
    await refreshCatalogData();
    setGlobalStatus("Movie added.", "success");
    showFormStatus("movie-status", "Movie added successfully.", "success");
  }

  async function createTheatre(form) {
    const body = formObject(form);
    omitEmpty(body, ["address", "pincode"]);
    body.city_id = Number(body.city_id);
    await api.request("catalog", "theatres", { method: "POST", body: body });
    form.reset();
    await refreshCatalogData();
    setGlobalStatus("Theatre added.", "success");
    showFormStatus("theatre-status", "Theatre added successfully.", "success");
  }

  async function createScreen(form) {
    const body = formObject(form);
    body.theatre_id = Number(body.theatre_id);
    await api.request("catalog", "screens", { method: "POST", body: body });
    form.reset();
    await refreshCatalogData();
    setGlobalStatus("Screen added.", "success");
    showFormStatus("screen-status", "Screen added successfully.", "success");
  }

  async function createSeats(form) {
    const screenId = Number(form.elements.screen_id.value);
    const rows = form.elements.rows.value.split(",").map(function (row) {
      return row.trim().toUpperCase();
    }).filter(Boolean);
    const seatsPerRow = Number(form.elements.seats_per_row.value);
    const basePrice = Number(form.elements.base_price.value);
    const seatType = form.elements.seat_type.value;
    if (!rows.length) throw new Error("Enter at least one row label.");
    if (new Set(rows).size !== rows.length) throw new Error("Row labels must be unique.");
    if (rows.some(function (row) { return row.length > 10; })) {
      throw new Error("Each row label must be 10 characters or fewer.");
    }

    const existing = await loadScreenSeats(screenId);
    const existingNumbers = new Set(existing.map(function (seat) { return seat.seat_number; }));
    const seats = [];
    rows.forEach(function (row) {
      for (let number = 1; number <= seatsPerRow; number++) {
        const seatNumber = row + number;
        if (!existingNumbers.has(seatNumber)) {
          seats.push({
            screen_id: screenId,
            seat_number: seatNumber,
            row_label: row,
            seat_type: seatType,
            base_price: basePrice
          });
        }
      }
    });

    if (!seats.length) {
      showFormStatus("seat-status", "All requested seat numbers already exist; nothing was changed.", "success");
      return;
    }

    let created = 0;
    try {
      for (const seat of seats) {
        await api.request("catalog", "seats", { method: "POST", body: seat });
        created++;
      }
    } catch (error) {
      state.seatsByScreen.delete(screenId);
      await updateScheduleSeatCount();
      const detail = created
        ? created + " seats were added before the request failed. " + error.message
        : error.message;
      throw new Error(detail);
    }

    state.seatsByScreen.delete(screenId);
    await updateScheduleSeatCount();
    setGlobalStatus(created + " seat" + (created === 1 ? "" : "s") + " added.", "success");
    showFormStatus("seat-status", created + " seats added and active.", "success");
  }

  async function createShow(form) {
    const movie = state.movies.find(function (item) {
      return String(item.id) === form.elements.movie_id.value;
    });
    if (!movie || !Number(movie.duration_minutes)) {
      throw new Error("The selected movie needs a valid duration before it can be scheduled.");
    }

    const screenId = Number(form.elements.screen_id.value);
    const seats = await loadScreenSeats(screenId);
    if (!seats.length) {
      throw new Error("This screen has no active seats. Add seats before scheduling a bookable show.");
    }

    const start = localDateTime(form.elements.date.value, form.elements.time.value);
    const end = new Date(start.getTime() + Number(movie.duration_minutes) * 60000);
    const show = await api.request("catalog", "shows", {
      method: "POST",
      body: {
        movie_id: Number(movie.id),
        screen_id: screenId,
        start_time: start.toISOString(),
        end_time: end.toISOString(),
        ticket_price: Number(form.elements.ticket_price.value)
      }
    });

    const showId = show && (show.id || (show.data && show.data.id));
    if (!showId) throw new Error("The show was saved but the Catalog API did not return its ID.");

    const inventory = {
      showId: showId,
      seats: seats.map(function (seat) {
        return {
          seat_id: Number(seat.id),
          seat_number: seat.seat_number,
          ticket_price: Number(form.elements.ticket_price.value)
        };
      }),
      title: movie.title
    };

    try {
      await provisionInventory(inventory);
      form.reset();
      initializeScheduleDates();
      await refreshCatalogData();
      await loadUpcomingShows();
      setGlobalStatus("Show scheduled and " + inventory.seats.length + " seats are available for booking.", "success");
      showFormStatus("show-status", movie.title + " is scheduled. " + inventory.seats.length + " seats are ready.", "success");
    } catch (error) {
      state.retryInventory = inventory;
      document.getElementById("retry-inventory").hidden = false;
      showFormStatus(
        "show-status",
        "Show " + showId + " was saved, but seat inventory could not be created: " + error.message + " Use “Retry seat setup” after Booking Service is available.",
        "error"
      );
      await loadUpcomingShows();
    }
  }

  async function provisionInventory(inventory) {
    await api.request("booking", "shows/" + encodeURIComponent(inventory.showId) + "/inventory", {
      method: "POST",
      body: { seats: inventory.seats }
    });
    state.retryInventory = null;
    document.getElementById("retry-inventory").hidden = true;
  }

  async function retryInventory() {
    const button = document.getElementById("retry-inventory");
    const status = document.getElementById("show-status");
    if (!state.retryInventory) return;
    button.disabled = true;
    try {
      await provisionInventory(state.retryInventory);
      setGlobalStatus("Show seat inventory is ready.", "success");
      showFormStatus("show-status", "Seat inventory was created successfully.", "success");
    } catch (error) {
      showFormStatus("show-status", "Seat inventory retry failed: " + error.message, "error");
    } finally {
      button.disabled = false;
    }
  }

  async function refreshCatalogData() {
    const globalStatus = document.getElementById("admin-global-status");
    globalStatus.className = "admin-global-status";
    try {
      const results = await Promise.all([
        getAllPages("catalog", "cities"),
        getAllPages("catalog", "movies"),
        getAllPages("catalog", "theatres"),
        getAllPages("catalog", "screens")
      ]);
      state.cities = results[0];
      state.movies = results[1];
      state.theatres = results[2];
      state.screens = results[3];
      renderSelect("theatre-form", "city_id", state.cities, function (city) {
        return city.name + (city.state ? ", " + city.state : "");
      });
      renderSelect("screen-form", "theatre_id", state.theatres, function (theatre) {
        return theatre.name + (theatre.city ? " — " + theatre.city.name : "");
      });
      renderSelect("seat-form", "screen_id", state.screens, function (screen) {
        return screen.name + (screen.theatre ? " — " + screen.theatre.name : "");
      });
      renderSelect("show-form", "movie_id", state.movies, function (movie) {
        return movie.title;
      });
      renderSelect("show-form", "screen_id", state.screens, function (screen) {
        return screen.name + (screen.theatre ? " — " + screen.theatre.name : "");
      });
      await updateMovieDuration();
      await updateScheduleSeatCount();
      globalStatus.textContent = "Catalog connected. " + state.cities.length + " cities, " + state.movies.length + " movies, " + state.theatres.length + " theatres, " + state.screens.length + " screens loaded.";
    } catch (error) {
      globalStatus.className = "admin-global-status error";
      globalStatus.textContent = error.message;
    }
  }

  async function loadUpcomingShows() {
    const status = document.getElementById("shows-status");
    const container = document.getElementById("upcoming-shows");
    status.className = "admin-form-status";
    status.textContent = "Loading upcoming shows…";
    container.replaceChildren();
    try {
      const shows = await getAllPages("catalog", "shows");
      if (!shows.length) {
        status.textContent = "";
        container.append(element("p", "upcoming-empty", "There are no upcoming shows in the catalog."));
        return;
      }
      status.textContent = "";
      shows.slice(0, 20).forEach(function (show) {
        const row = element("article", "upcoming-show");
        const details = document.createElement("div");
        details.append(
          element("div", "upcoming-show-title", show.movie ? show.movie.title : "Movie #" + show.movie_id),
          element("div", "upcoming-show-meta", formatDateTime(show.start_time) + " · ₹" + show.ticket_price)
        );
        const location = show.screen && show.screen.theatre
          ? show.screen.theatre.name + (show.screen.theatre.city ? ", " + show.screen.theatre.city.name : "")
          : "Screen #" + show.screen_id;
        row.append(details, element("div", "upcoming-show-location", location));
        container.append(row);
      });
    } catch (error) {
      status.className = "admin-form-status error";
      status.textContent = error.message;
    }
  }

  async function loadScreenSeats(screenId) {
    if (state.seatsByScreen.has(screenId)) return state.seatsByScreen.get(screenId);
    const seats = await getAllPages("catalog", "seats?screen_id=" + encodeURIComponent(screenId));
    state.seatsByScreen.set(screenId, seats);
    return seats;
  }

  async function updateScheduleSeatCount() {
    const select = document.querySelector('#show-form select[name="screen_id"]');
    const hint = document.getElementById("schedule-seat-count");
    if (!select || !select.value) {
      hint.textContent = "Choose a screen with active seats.";
      return;
    }
    try {
      const seats = await loadScreenSeats(Number(select.value));
      hint.textContent = seats.length
        ? seats.length + " active seat" + (seats.length === 1 ? "" : "s") + " will be made available for this show."
        : "This screen has no active seats. Add seats before scheduling a bookable show.";
    } catch (error) {
      hint.textContent = "Could not load active seats: " + error.message;
    }
  }

  function updateMovieDuration() {
    const select = document.querySelector('#show-form select[name="movie_id"]');
    const output = document.getElementById("show-duration");
    const movie = state.movies.find(function (item) {
      return select && String(item.id) === select.value;
    });
    output.value = movie && Number(movie.duration_minutes)
      ? movie.duration_minutes + " minutes"
      : "Set a movie duration first";
    return Promise.resolve();
  }

  async function getAllPages(service, path) {
    const separator = path.includes("?") ? "&" : "?";
    let page = 1;
    let lastPage = 1;
    const items = [];
    do {
      const response = await api.request(service, path + separator + "page=" + page);
      const paginator = response && response.data && !Array.isArray(response.data)
        ? response.data
        : response;
      const pageItems = Array.isArray(paginator)
        ? paginator
        : (paginator && Array.isArray(paginator.data) ? paginator.data : []);
      if (!Array.isArray(pageItems)) throw new Error("The API returned an invalid list response.");
      items.push.apply(items, pageItems);
      const pageValue = paginator && Number(paginator.last_page);
      lastPage = Number.isFinite(pageValue) && pageValue > 0 ? pageValue : 1;
      page++;
    } while (page <= lastPage);
    return items;
  }

  function renderSelect(formId, fieldName, records, labelFor) {
    const select = document.querySelector("#" + formId + ' select[name="' + fieldName + '"]');
    const currentValue = select.value;
    const placeholder = select.dataset.placeholder || "Select an option";
    select.replaceChildren(new Option(placeholder, ""));
    records.forEach(function (record) {
      const option = new Option(labelFor(record), String(record.id));
      select.add(option);
    });
    if (records.some(function (record) { return String(record.id) === currentValue; })) {
      select.value = currentValue;
    }
  }

  function formObject(form) {
    return Object.fromEntries(new FormData(form).entries());
  }

  function omitEmpty(object, keys) {
    keys.forEach(function (key) {
      if (object[key] === "") delete object[key];
    });
  }

  function localDateTime(date, time) {
    const value = new Date(date + "T" + time);
    if (Number.isNaN(value.getTime())) throw new Error("Enter a valid show date and time.");
    return value;
  }

  function initializeScheduleDates() {
    const input = document.querySelector('#show-form input[name="date"]');
    const now = new Date();
    const today = [
      now.getFullYear(),
      String(now.getMonth() + 1).padStart(2, "0"),
      String(now.getDate()).padStart(2, "0")
    ].join("-");
    input.min = today;
    input.value = today;
  }

  function setStatus(node, message, type) {
    node.className = "admin-form-status " + type;
    node.textContent = message;
  }

  function showFormStatus(id, message, type) {
    setStatus(document.getElementById(id), message, type);
  }

  function setGlobalStatus(message, type) {
    const node = document.getElementById("admin-global-status");
    node.className = "admin-global-status " + type;
    node.textContent = message;
  }

  function formatDateTime(value) {
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return "Time not available";
    return new Intl.DateTimeFormat(undefined, {
      dateStyle: "medium",
      timeStyle: "short"
    }).format(date);
  }

  function element(tag, className, text) {
    const node = document.createElement(tag);
    node.className = className;
    node.textContent = text;
    return node;
  }
})();
