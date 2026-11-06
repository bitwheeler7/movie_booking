(function () {
  "use strict";
  const config = window.MovieConfig;

  function safeValue(value) {
    if (Array.isArray(value)) return value.map(safeValue);
    if (value && typeof value === "object") {
      const clean = {};
      Object.keys(value).forEach(function (key) {
        clean[key] = /password|token|secret|signature|authorization|credential|gateway_response|card_number|cvv/i.test(key)
          ? "[masked]"
          : safeValue(value[key]);
      });
      return clean;
    }
    return value;
  }

  function pushDebug(entry) {
    if (window.ApiDebug && typeof window.ApiDebug.add === "function") {
      window.ApiDebug.add(entry);
    }
  }

  function makeError(message, status, body) {
    const error = new Error(message || "The request could not be completed.");
    error.status = status || 0;
    error.body = body;
    return error;
  }

  async function request(service, path, options) {
    const settings = options || {};
    const method = (settings.method || "GET").toUpperCase();
    const base = config.services[service];
    if (!base) throw new Error("Unknown API service: " + service);
    const url = /^https?:\/\//i.test(path) ? path : base.replace(/\/$/, "") + "/" + String(path).replace(/^\//, "");
    const headers = new Headers(settings.headers || {});
    headers.set("Accept", "application/json");
    const token = sessionStorage.getItem("moviebook_token");
    if (settings.auth !== false && token) headers.set("Authorization", "Bearer " + token);
    if (settings.idempotencyKey) headers.set("Idempotency-Key", settings.idempotencyKey);
    let body;
    if (settings.body !== undefined) {
      headers.set("Content-Type", "application/json");
      body = JSON.stringify(settings.body);
    }
    const debugHeaders = {};
    headers.forEach(function (value, key) {
      debugHeaders[key] = key.toLowerCase() === "authorization" ? "[masked]" : value;
    });
    const started = performance.now();
    let response;
    let responseBody;
    try {
      response = await fetch(url, {
        method: method,
        headers: headers,
        body: body,
        signal: settings.signal
      });
      const text = await response.text();
      if (text) {
        try { responseBody = JSON.parse(text); }
        catch (_) { responseBody = text; }
      } else {
        responseBody = null;
      }
      pushDebug({
        method: method,
        url: url,
        requestHeaders: debugHeaders,
        requestBody: safeValue(settings.body),
        status: response.status,
        responseBody: safeValue(responseBody),
        duration: Math.round(performance.now() - started)
      });
      if (typeof settings.onResponse === "function") {
        settings.onResponse({ status: response.status, duration: Math.round(performance.now() - started) });
      }
      if (!response.ok) {
        const detail = responseBody && typeof responseBody === "object"
          ? (responseBody.message || responseBody.error || validationMessage(responseBody.errors))
          : responseBody;
        const statusText = response.statusText ? " " + response.statusText : "";
        const message = detail ? String(detail) : httpMessage(response.status) + statusText;
        const error = makeError(message, response.status, responseBody);
        if (response.status === 401) {
          sessionStorage.removeItem("moviebook_token");
          sessionStorage.removeItem("moviebook_user");
        }
        throw error;
      }
      return responseBody;
    } catch (error) {
      if (error.status) throw error;
      const isAbort = error.name === "AbortError";
      if (!response) {
        pushDebug({
          method: method,
          url: url,
          requestHeaders: debugHeaders,
          requestBody: safeValue(settings.body),
          status: "NETWORK ERROR",
          responseBody: error.message,
          duration: Math.round(performance.now() - started)
        });
        if (typeof settings.onResponse === "function") {
          settings.onResponse({ status: 0, duration: Math.round(performance.now() - started) });
        }
      }
      throw makeError(
        isAbort ? "Request was cancelled." : "Cannot reach the API. Check that the service is running, its CORS configuration allows this frontend, and the URL is correct.",
        0,
        error
      );
    }
  }

  function validationMessage(errors) {
    if (!errors || typeof errors !== "object") return "";
    return Object.keys(errors).map(function (field) {
      const messages = Array.isArray(errors[field]) ? errors[field].join(", ") : String(errors[field]);
      return field + ": " + messages;
    }).join(" ");
  }

  function httpMessage(status) {
    const messages = {
      400: "Bad request.", 401: "Authentication is required or has expired.",
      403: "You are not allowed to perform this action.", 404: "The requested record was not found.",
      409: "This request conflicts with the current resource state.", 422: "The submitted data was not accepted.",
      429: "Too many requests. Please wait and try again.", 500: "The service encountered an internal error.",
      502: "A dependent service is unavailable."
    };
    return messages[status] || "The API returned HTTP " + status + ".";
  }

  function withLoading(element, operation, label) {
    if (!element) return operation();
    const original = element.innerHTML;
    element.disabled = true;
    element.setAttribute("aria-busy", "true");
    element.innerHTML = '<span class="loading-spinner" aria-hidden="true"></span> ' + (label || "Working…");
    return Promise.resolve().then(operation).finally(function () {
      element.innerHTML = original;
      element.disabled = false;
      element.removeAttribute("aria-busy");
    });
  }

  window.Api = { request: request, withLoading: withLoading, httpMessage: httpMessage };
})();
