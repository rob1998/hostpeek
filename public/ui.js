const icons = {
  logout: 'M9 5H5v14h4M14 8l4 4-4 4M9 12h9',
  plus: 'M12 5v14M5 12h14',
  edit: 'm14 5 5 5M4 20l4-1L20 7l-4-4L4 15v5Z',
  trash: 'M4 7h16M9 7V4h6v3M6 7l1 13h10l1-13M10 10v7M14 10v7',
  back: 'm14 6-6 6 6 6',
  next: 'm10 6 6 6-6 6',
  help: 'M9.1 9a3 3 0 0 1 5.8 1c0 2-3 3-3 5M12 18h.01M22 12a10 10 0 1 1-20 0 10 10 0 0 1 20 0Z'
};
function icon(name) {
  const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
  svg.setAttribute('viewBox', '0 0 24 24');
  svg.setAttribute('aria-hidden', 'true');
  svg.setAttribute('class', 'icon');
  const path = document.createElementNS(svg.namespaceURI, 'path');
  path.setAttribute('d', icons[name] || '');
  svg.append(path);
  return svg;
}
document.querySelectorAll('[data-icon]').forEach(element => element.replaceChildren(icon(element.dataset.icon)));

const configuration = fetch('/api/config').then(async response => {
  if (!response.ok) return null;
  const config = await response.json();
  document.querySelector('.header-actions').hidden = false;
  document.querySelector('#logout-button').hidden = false;
  document.querySelector('#help-link').hidden = false;
  return config;
}).catch(() => null);
window.SitePreviewUI = { icon, configuration };
document.querySelector('#logout-button').addEventListener('click', async event => {
  const button = event.currentTarget;
  button.disabled = true;
  try {
    const config = await configuration;
    const response = await fetch('/api/manage/logout', {
      method: 'POST', headers: { 'X-CSRF-Token': config?.csrf || '' }
    });
    if (!response.ok && response.status !== 401) throw new Error('Could not sign out. Try again.');
    window.location.assign('/manage');
  } catch (error) {
    const message = document.querySelector('#message');
    message.textContent = error.message;
    message.hidden = false;
    button.disabled = false;
  }
});
