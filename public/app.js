const form = document.querySelector("#preview-form");
const submitButton = document.querySelector("#submit-button");
const message = document.querySelector("#message");
const result = document.querySelector("#result");
const previewLink = document.querySelector("#preview-link");
const expiry = document.querySelector("#expiry");
const copyButton = document.querySelector("#copy-button");
const expirationInput = form.elements.expiresAt;
let csrfToken = "";
let timezone = "UTC";
submitButton.disabled = true;

SitePreviewUI.configuration.then(config => {
  if (!config) {
    window.location.assign('/manage?create=1');
    return;
  }
  expirationInput.value = config.default_expires_local;
  timezone = config.timezone;
  document.querySelector('label[for="expires-at"]').textContent = `Expires (${timezone})`;
  csrfToken = config.csrf;
  submitButton.disabled = false;
});

const params = new URLSearchParams(window.location.search);
if (params.has("url")) form.elements.hostname.value = params.get("url");
else if (params.has("hostname")) form.elements.hostname.value = params.get("hostname");
if (params.has("ip")) form.elements.ip.value = params.get("ip");

function showMessage(text, kind = "error") {
  message.textContent = text;
  message.className = `message ${kind}`;
  message.hidden = false;
}

form.addEventListener("submit", async (event) => {
  event.preventDefault();
  result.hidden = true;
  message.hidden = true;

  const hostname = form.elements.hostname.value.trim();
  const ip = form.elements.ip.value.trim();
  if (!hostname || !ip) {
    showMessage("Enter a URL or hostname and server IP address.");
    return;
  }
  if (!expirationInput.value) {
    showMessage("Choose an expiration date and time.");
    return;
  }

  form.setAttribute("aria-busy", "true");
  submitButton.disabled = true;
  submitButton.querySelector(".button-label").textContent = "Creating preview";

  try {
    const response = await fetch("/api/previews", {
      method: "POST",
      headers: { "Content-Type": "application/json", Accept: "application/json", "X-CSRF-Token": csrfToken },
      body: JSON.stringify({ hostname, ip, expires_local: expirationInput.value, password: form.elements.password.value })
    });
    const responseText = await response.text();
    let data = {};
    try { data = JSON.parse(responseText); } catch {}
    if (!response.ok) throw new Error(data.error || data.message || responseText.trim() || "Could not create the preview. Try again.");
    if (!data.url) throw new Error("The server returned an invalid preview link.");

    const url = new URL(data.url, window.location.origin);
    if (url.protocol !== "https:" && url.protocol !== "http:") throw new Error("The server returned an invalid preview link.");
    previewLink.href = url.href;
    previewLink.textContent = url.href;
    expiry.textContent = data.expiresAt ? `Available until ${new Date(data.expiresAt).toLocaleString(undefined, { timeZone: timezone })}` : "";
    result.hidden = false;
    copyButton.textContent = "Copy link";
    showMessage("Preview created.", "info");
  } catch (error) {
    showMessage(error instanceof TypeError ? "Could not reach the preview service. Try again." : error.message);
  } finally {
    form.removeAttribute("aria-busy");
    submitButton.disabled = false;
    submitButton.querySelector(".button-label").textContent = "Create preview";
  }
});

copyButton.addEventListener("click", async () => {
  try {
    await navigator.clipboard.writeText(previewLink.href);
    copyButton.textContent = "Copied";
  } catch {
    showMessage("Copy isn’t available here. Select and copy the preview link.", "info");
  }
});
