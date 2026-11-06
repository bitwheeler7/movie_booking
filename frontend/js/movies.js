(function () {
  "use strict";
  const app = window.App;
  let catalogRecords = [];
  let catalogPage = 1;
  let catalogLastPage = 1;

  const grid = document.getElementById("movie-grid");
  if (grid) initializeCatalog();
  const detail = document.getElementById("movie-detail");
  if (detail) initializeMovieDetails();

  async function initializeCatalog() {
    const status = document.getElementById("movies-status");
    const search = document.getElementById("movie-search");
    search.addEventListener("input", renderCatalog);
    loadCatalogPage(1);
  }

  async function loadCatalogPage(page) {
    const status = document.getElementById("movies-status");
    const pages = document.getElementById("movie-pagination");
    status.className = "inline-status";
    status.textContent = "Loading movies…";
    grid.replaceChildren();
    pages.replaceChildren();
    try {
      const response = await window.Api.request("catalog", "movies?page=" + encodeURIComponent(page));
      const result = app.getRecords(response);
      catalogRecords = result.items;
      const pageInfo = findPagination(response);
      catalogPage = pageInfo.current || page;
      catalogLastPage = pageInfo.last || catalogPage;
      renderCatalog();
      status.textContent = catalogRecords.length ? "" : "No active movies are currently listed by the catalog.";
      if (!catalogRecords.length) grid.append(app.emptyState("No movies yet", "The catalog returned no active movie records."));
      if (catalogLastPage > 1) {
        if (catalogPage > 1) pages.append(pageButton("Previous", catalogPage - 1));
        const label = app.element("span", "muted", "Page " + catalogPage + " of " + catalogLastPage);
        label.style.alignSelf = "center";
        pages.append(label);
        if (catalogPage < catalogLastPage) pages.append(pageButton("Next", catalogPage + 1));
      }
    } catch (error) {
      status.className = "inline-status error";
      status.textContent = error.message;
      grid.append(app.emptyState("Movies unavailable", "Check the Catalog Service status and try again."));
    }
  }

  function renderCatalog() {
    const term = document.getElementById("movie-search").value.trim().toLowerCase();
    const filtered = catalogRecords.filter(function (movie) {
      return [movie.title, movie.genre, movie.language].join(" ").toLowerCase().includes(term);
    });
    grid.replaceChildren();
    if (!filtered.length && catalogRecords.length) {
      grid.append(app.emptyState("No matching movies", "Try another title, genre, or language."));
      return;
    }
    filtered.forEach(function (movie) { grid.append(movieCard(movie)); });
  }

  function pageButton(text, page) {
    const button = app.element("button", "button button-outline", text);
    button.type = "button";
    button.addEventListener("click", function () { loadCatalogPage(page); });
    return button;
  }

  function movieCard(movie) {
    const card = app.element("article", "movie-card");
    const poster = app.element("div", "poster-wrap");
    const posterUrl = safeImageUrl(movie.poster_url);
    if (posterUrl) {
      const image = document.createElement("img");
      image.src = posterUrl;
      image.alt = (movie.title || "Movie") + " poster";
      image.loading = "lazy";
      image.addEventListener("error", function () {
        image.remove();
        poster.append(app.element("div", "poster-fallback", "M"));
      }, { once: true });
      poster.append(image);
    } else {
      poster.append(app.element("div", "poster-fallback", "M"));
    }
    const info = app.element("div", "movie-info");
    info.append(app.element("h3", "", movie.title || "Untitled movie"));
    const tags = app.element("div", "movie-tags");
    if (movie.genre) tags.append(app.element("span", "tag", movie.genre));
    if (movie.language) tags.append(app.element("span", "tag", movie.language));
    info.append(tags);
    const meta = [];
    if (movie.duration_minutes) meta.push(movie.duration_minutes + " min");
    if (movie.certificate) meta.push(movie.certificate);
    info.append(app.element("p", "movie-meta", meta.join(" · ") || "Details from the catalog"));
    const link = app.element("a", "button button-primary full-width", "Book tickets");
    link.href = "movie-details.html?id=" + encodeURIComponent(movie.id);
    info.append(link);
    card.append(poster, info);
    return card;
  }

  async function initializeMovieDetails() {
    const movieId = new URLSearchParams(location.search).get("id");
    const content = document.getElementById("movie-detail");
    const dateInput = document.getElementById("show-date");
    const showList = document.getElementById("show-list");
    const status = document.getElementById("shows-status");
    if (!movieId || !/^\d+$/.test(movieId)) {
      content.append(app.emptyState("Movie not selected", "Return to movies and choose a catalog listing."));
      return;
    }
    dateInput.value = localDate(new Date());
    dateInput.min = dateInput.value;
    try {
      const response = await window.Api.request("catalog", "movies/" + encodeURIComponent(movieId));
      const movie = response && (response.data || response);
      content.append(movieHero(movie));
    } catch (error) {
      content.append(app.emptyState("Movie unavailable", error.message));
      status.className = "inline-status error";
      status.textContent = error.message;
      return;
    }

    async function loadShows() {
      showList.replaceChildren();
      status.className = "inline-status";
      status.textContent = "Loading showtimes…";
      const query = new URLSearchParams({ movie_id: movieId, date: dateInput.value });
      try {
        const response = await window.Api.request("catalog", "shows?" + query.toString());
        const shows = app.getRecords(response).items;
        if (!shows.length) {
          status.textContent = "No scheduled shows were returned for this movie and date.";
          showList.append(app.emptyState("No shows available", "Try another date or check back later."));
          return;
        }
        status.textContent = "";
        shows.forEach(function (show) { showList.append(showCard(show)); });
      } catch (error) {
        status.className = "inline-status error";
        status.textContent = error.message;
      }
    }
    dateInput.addEventListener("change", loadShows);
    loadShows();
  }

  function movieHero(movie) {
    const hero = app.element("section", "movie-detail-hero");
    const poster = app.element("div", "detail-poster");
    const posterUrl = safeImageUrl(movie.poster_url);
    if (posterUrl) {
      const image = document.createElement("img");
      image.src = posterUrl;
      image.alt = (movie.title || "Movie") + " poster";
      image.addEventListener("error", function () { image.remove(); }, { once: true });
      poster.append(image);
    }
    const copy = app.element("div", "detail-copy");
    copy.append(app.element("span", "eyebrow", "MOVIE DETAILS"), app.element("h1", "", movie.title || "Untitled movie"));
    const facts = [movie.genre, movie.language, movie.duration_minutes ? movie.duration_minutes + " min" : null, movie.certificate].filter(Boolean);
    copy.append(app.element("p", "", facts.join(" · ") || "Movie details from the catalog."));
    if (movie.description) copy.append(app.element("p", "", movie.description));
    hero.append(poster, copy);
    return hero;
  }

  function showCard(show) {
    const row = app.element("article", "show-row");
    const details = show.screen && show.screen.theatre;
    const time = app.element("div");
    time.append(app.element("div", "show-time", app.dateTime(show.start_time, { hour: "numeric", minute: "2-digit" })));
    const locationParts = [];
    if (details && details.name) locationParts.push(details.name);
    if (details && details.city && details.city.name) locationParts.push(details.city.name);
    if (show.screen && show.screen.name) locationParts.push(show.screen.name);
    time.append(app.element("div", "show-location", locationParts.join(" · ") || "Theatre details not included in the show response"));
    const price = app.element("div", "show-price", show.ticket_price === undefined ? "Price not provided" : app.money(show.ticket_price));
    const link = app.element("a", "button button-primary", "Select seats");
    link.href = "booking.html?show_id=" + encodeURIComponent(show.id);
    link.addEventListener("click", function () {
      sessionStorage.setItem("moviebook_selected_show_id", String(show.id));
    });
    row.append(time, price, link);
    return row;
  }

  function safeImageUrl(value) {
    if (typeof value !== "string") return "";
    try {
      const url = new URL(value, location.href);
      return ["http:", "https:"].includes(url.protocol) ? url.href : "";
    } catch (_) { return ""; }
  }

  function findPagination(response) {
    let node = response;
    if (node && node.data && !Array.isArray(node.data)) node = node.data;
    return {
      current: node && Number(node.current_page),
      last: node && Number(node.last_page)
    };
  }

  function localDate(date) {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, "0");
    const day = String(date.getDate()).padStart(2, "0");
    return year + "-" + month + "-" + day;
  }
})();
