const loginForm = document.querySelector("#login-form");
const manager = document.querySelector("#manager");
const message = document.querySelector("#message");
const rows = document.querySelector("#preview-rows");
const search = document.querySelector("#search");
const dialog = document.querySelector("#edit-dialog");
const editForm = document.querySelector("#edit-form");
let csrfToken = "";
let timezone = "UTC";
let page = 1;
let sort = "expires_at";
let direction = "asc";
let total = 0;
let searchTimer;
let loadSequence = 0;
const deleteDialog = document.querySelector("#delete-dialog");
let pendingDelete = null;
let expiredCount = 0;

function showMessage(text, kind = "error") {
  message.textContent = text;
  message.className = `message ${kind}`;
  message.hidden = false;
  if (dialog.open) {
    document.querySelector("#edit-error").textContent = text;
  }
}

async function request(path, options = {}) {
  const { mutate = false, ...fetchOptions } = options;
  const headers = { Accept: "application/json", ...(fetchOptions.headers || {}) };
  if (fetchOptions.body) headers["Content-Type"] = "application/json";
  if (mutate) headers["X-CSRF-Token"] = csrfToken;
  const response = await fetch(path, { ...fetchOptions, headers });
  const text = await response.text();
  let data = {};
  try { data = JSON.parse(text); } catch {}
  if (!response.ok) {
    if (response.status === 401) {
      document.body.classList.add('signed-out');
      manager.hidden = true;
      document.querySelector("#logout-button").hidden = true;
      loginForm.hidden = false;
      csrfToken = "";
    }
    throw new Error(data.error || data.message || text || `Request failed (${response.status}).`);
  }
  return data;
}

async function checkSession() {
  try {
    const session = await request("/api/manage/session");
    csrfToken = session.csrf || "";
    timezone = session.timezone || "UTC";
    document.body.classList.toggle('signed-out', !session.authenticated);
    manager.hidden = !session.authenticated;
    loginForm.hidden = Boolean(session.authenticated);
    if (session.authenticated) await loadPreviews();
  } catch (error) {
    showMessage(error.message || "Could not load the manager.");
  }
}

async function loadPreviews() {
  const sequence = ++loadSequence;
  try {
    const query = new URLSearchParams({ search: search.value.trim(), sort, direction, page: String(page) });
    const data = await request(`/api/manage/previews?${query}`);
    if (sequence !== loadSequence) return;
    total = Number(data.total) || 0;
    expiredCount = Number(data.expiredCount) || 0;
    document.querySelector("#delete-expired").hidden = expiredCount === 0;
    document.querySelector('[data-sort="expires_at"]').textContent = `Expires (${timezone})`;
    rows.replaceChildren();
    for (const item of data.items || []) rows.append(makeRow(item));
    if (!rows.childElementCount) {
      const empty = document.createElement("tr");
      const cell = document.createElement("td");
      cell.colSpan = 6;
      cell.className = "empty-cell";
      cell.textContent = search.value.trim() ? "No matching previews." : "No previews yet.";
      empty.append(cell);
      rows.append(empty);
    }
    const perPage = Math.max(1, Number(data.perPage) || 25);
    document.querySelector(".pagination").hidden = total <= perPage;
    document.querySelector("#results-count").textContent = `${total} preview${total === 1 ? "" : "s"}`;
    document.querySelector("#page-label").textContent = `Page ${data.page || page} of ${Math.max(1, Math.ceil(total / perPage))}`;
    document.querySelector("#previous-page").disabled = page <= 1;
    document.querySelector("#next-page").disabled = page * perPage >= total;
  } catch (error) {
    showMessage(error.message || "Could not load previews.");
  }
}

function makeRow(item) {
  const row = document.createElement("tr");
  const linkCell = document.createElement("td");
  const link = document.createElement("a");
  link.href = safePreviewUrl(item.url);
  link.textContent = item.url || item.label;
  link.title = link.textContent;
  link.className = "table-url";
  link.target = "_blank";
  link.rel = "noopener noreferrer";
  linkCell.append(link);
  const domainCell = cell('');
  const domainLink = link.cloneNode(false);
  domainLink.href = safePreviewUrl(item.original_url);
  domainLink.textContent = item.original_url;
  domainLink.title = item.original_url;
  domainCell.append(domainLink);
  const ipCell = cell(item.ip);
  const expiry = Number(item.expires_at);
  const expiryCell = cell(Number.isFinite(expiry) ? new Date(expiry * 1000).toLocaleString(undefined, { timeZone: timezone }) : "—");
  const passwordCell = cell(item.password_protected ? "Yes" : "No");
  const actionCell = document.createElement("td");
  actionCell.className = "row-actions";
  const edit = action("Edit", "edit", () => openEditor(item));
  const remove = action("Delete", "trash", () => confirmDelete(item));
  actionCell.append(edit, remove);
  row.append(linkCell, domainCell, ipCell, expiryCell, passwordCell, actionCell);
  return row;
}

function cell(value) {
  const element = document.createElement("td");
  element.textContent = value == null ? "—" : String(value);
  return element;
}

function action(label, iconName, handler) {
  const button = document.createElement("button");
  button.type = "button";
  button.className = "text-button";
  button.append(SitePreviewUI.icon(iconName), document.createTextNode(label));
  button.addEventListener("click", handler);
  return button;
}

function safePreviewUrl(value) {
  try {
    const url = new URL(value, window.location.origin);
    return url.protocol === "https:" || url.protocol === "http:" ? url.href : "#";
  } catch { return "#"; }
}

loginForm.addEventListener("submit", async (event) => {
  event.preventDefault();
  message.hidden = true;
  try {
    const data = await request("/api/manage/login", {
      method: "POST",
      body: JSON.stringify({ password: loginForm.elements.password.value })
    });
    csrfToken = data.csrf || "";
    timezone = data.timezone || "UTC";
    loginForm.reset();
    location.assign(new URLSearchParams(location.search).get('create') === '1' ? '/' : '/manage');
  } catch (error) {
    showMessage(error.message || "Sign-in failed.");
  }
});

search.addEventListener("input", () => {
  clearTimeout(searchTimer);
  searchTimer = setTimeout(() => { page = 1; loadPreviews(); }, 250);
});

document.querySelectorAll(".sort-button").forEach(button => button.addEventListener("click", () => {
  const nextSort = button.dataset.sort;
  direction = sort === nextSort && direction === "asc" ? "desc" : "asc";
  sort = nextSort;
  page = 1;
  document.querySelectorAll(".sort-button").forEach(item => item.closest("th").removeAttribute("aria-sort"));
  button.closest("th").setAttribute("aria-sort", direction === "asc" ? "ascending" : "descending");
  loadPreviews();
}));

document.querySelector("#previous-page").addEventListener("click", () => { if (page > 1) { page--; loadPreviews(); } });
document.querySelector("#next-page").addEventListener("click", () => { page++; loadPreviews(); });

function openEditor(item) {
  editForm.elements.label.value = item.label;
  editForm.elements.hostname.value = item.original_url;
  editForm.elements.ip.value = item.ip;
  editForm.elements.expires.value = item.expires_local;
  document.querySelector('label[for="edit-expires"]').textContent = `Expires (${timezone})`;
  editForm.elements.password.value = "";
  editForm.elements.password.disabled = false;
  editForm.elements.removePassword.checked = false;
  editForm.elements.removePassword.disabled = !item.password_protected;
  document.querySelector("#edit-error").textContent = "";
  dialog.showModal();
}

editForm.elements.removePassword.addEventListener("change", () => {
  editForm.elements.password.disabled = editForm.elements.removePassword.checked;
});
document.querySelector("#cancel-edit").addEventListener("click", () => dialog.close());

editForm.addEventListener("submit", async event => {
  event.preventDefault();
  const payload = {
    hostname: editForm.elements.hostname.value.trim(),
    ip: editForm.elements.ip.value.trim(),
    expires_local: editForm.elements.expires.value
  };
  if (editForm.elements.removePassword.checked) payload.password = "";
  else if (editForm.elements.password.value) payload.password = editForm.elements.password.value;
  try {
    await request(`/api/manage/previews/${encodeURIComponent(editForm.elements.label.value)}`, {
      method: "PATCH", mutate: true, body: JSON.stringify(payload)
    });
    dialog.close();
    showMessage("Preview updated.", "info");
    await loadPreviews();
  } catch (error) { showMessage(error.message || "Could not update the preview."); }
});

function confirmDelete(item) {
  pendingDelete = item;
  document.querySelector("#delete-title").textContent = "Delete preview?";
  document.querySelector("#confirm-delete span:last-child").textContent = "Delete preview";
  document.querySelector('#delete-description').textContent = `Delete the preview for ${item.hostname}? Its preview link will stop working.`;
  document.querySelector('#delete-error').textContent = '';
  deleteDialog.showModal();
}
document.querySelector('#delete-expired').addEventListener('click', () => {
  pendingDelete = { expired: true };
  document.querySelector('#delete-title').textContent = 'Delete all expired previews?';
  document.querySelector('#delete-description').textContent = `Delete all ${expiredCount} expired preview${expiredCount === 1 ? '' : 's'}? Active previews will be kept.`;
  document.querySelector('#confirm-delete span:last-child').textContent = 'Delete all expired';
  document.querySelector('#delete-error').textContent = '';
  deleteDialog.showModal();
});
document.querySelector('#cancel-delete').addEventListener('click', () => deleteDialog.close());
deleteDialog.addEventListener('close', () => { pendingDelete = null; });
document.querySelector('#delete-form').addEventListener('submit', async event => {
  event.preventDefault();
  if (!pendingDelete) return;
  const button = document.querySelector('#confirm-delete');
  button.disabled = true;
  try {
    const expired = pendingDelete.expired;
    const path = expired ? '/api/manage/expired' : `/api/manage/previews/${encodeURIComponent(pendingDelete.label)}`;
    const result = await request(path, { method: 'DELETE', mutate: true });
    deleteDialog.close();
    showMessage(expired ? `${result.deleted} expired preview${result.deleted === 1 ? "" : "s"} deleted.` : 'Preview deleted.', 'info');
    if (expired) page = 1;
    else if (page > 1 && rows.childElementCount === 1) page--;
    await loadPreviews();
  } catch (error) {
    document.querySelector('#delete-error').textContent = error.message || 'Could not delete the preview.';
  } finally { button.disabled = false; }
});

checkSession();
