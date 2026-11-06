(function () {
  "use strict";
  const app = window.App;

  function saveAuth(response) {
    const token = response && (response.access_token || (response.data && response.data.access_token));
    const user = response && (response.user || response.data && response.data.user);
    if (!token) throw new Error("The authentication API did not return an access token.");
    sessionStorage.setItem("moviebook_token", token);
    if (user) sessionStorage.setItem("moviebook_user", JSON.stringify(user));
    app.updateNavigation();
    return user;
  }

  async function profile() {
    const response = await window.Api.request("auth", "auth/profile");
    const user = response && (response.user || response.data && response.data.user || response.data);
    if (user && typeof user === "object") sessionStorage.setItem("moviebook_user", JSON.stringify(user));
    app.updateNavigation();
    return user;
  }

  async function requireAuth() {
    if (!sessionStorage.getItem("moviebook_token")) {
      const current = location.pathname.split("/").pop() + location.search;
      location.href = "login.html?return=" + encodeURIComponent(current);
      return null;
    }
    try { return await profile(); }
    catch (error) {
      if (error.status === 401) {
        location.href = "login.html";
        return null;
      }
      app.toast(error.message, "error");
      return null;
    }
  }

  const login = document.getElementById("login-form");
  if (login) {
    login.addEventListener("submit", async function (event) {
      event.preventDefault();
      const submit = login.querySelector("button[type=submit]");
      try {
        const values = new FormData(login);
        const response = await window.Api.withLoading(submit, function () {
          return window.Api.request("auth", "auth/login", {
            method: "POST",
            auth: false,
            body: { email: values.get("email"), password: values.get("password") }
          });
        }, "Signing in…");
        saveAuth(response);
        const destination = new URLSearchParams(location.search).get("return");
        const destinationPage = destination && destination.split("?")[0];
        const allowedPages = ["index.html", "movie-details.html", "booking.html", "my-bookings.html", "notifications.html", "api-status.html", "ticket.html"];
        location.href = allowedPages.includes(destinationPage) ? destination : "index.html";
      } catch (error) {
        app.toast(error.message, "error");
      }
    });
  }

  const register = document.getElementById("register-form");
  if (register) {
    register.addEventListener("submit", async function (event) {
      event.preventDefault();
      const values = new FormData(register);
      if (values.get("password") !== values.get("password_confirmation")) {
        app.toast("Passwords do not match.", "error");
        return;
      }
      const submit = register.querySelector("button[type=submit]");
      try {
        const response = await window.Api.withLoading(submit, function () {
          return window.Api.request("auth", "auth/register", {
            method: "POST", auth: false,
            body: {
              name: values.get("name"), email: values.get("email"),
              password: values.get("password"), password_confirmation: values.get("password_confirmation")
            }
          });
        }, "Creating account…");
        saveAuth(response);
        location.href = "index.html";
      } catch (error) {
        app.toast(error.message, "error");
      }
    });
  }

  window.Auth = { saveAuth: saveAuth, profile: profile, requireAuth: requireAuth };
})();
