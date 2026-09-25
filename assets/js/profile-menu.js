(() => {
  const menu = document.querySelector('.profile-menu');
  if (!menu) return;
  document.addEventListener('click', event => { if (!menu.contains(event.target)) menu.open = false; });
  menu.addEventListener('keydown', event => {
    if (event.key === 'Escape') { menu.open = false; menu.querySelector('summary').focus(); }
  });
  let timer;
  async function refresh() {
    if (document.hidden) return;
    try {
      const response = await fetch(menu.dataset.notificationsUrl, {cache:'no-store', credentials:'same-origin'});
      if (response.status === 401) { clearInterval(timer); return; }
      if (!response.ok) return;
      const {count} = await response.json();
      if (!Number.isInteger(count) || count < 0) return;
      const badge = menu.querySelector('[data-unread-count]');
      badge.hidden = count === 0;
      badge.textContent = count > 99 ? '99+' : String(count);
      menu.querySelector('[data-menu-count]').textContent = count || '';
      const label = menu.querySelector('[data-unread-label]');
      const text = count + ' unread notifications';
      if (label.textContent !== text) label.textContent = text;
    } catch (_) { /* Keep the server-rendered count if the network is unavailable. */ }
  }
  timer = setInterval(refresh, 30000);
  menu.addEventListener('toggle', () => { if (menu.open) refresh(); });
  document.addEventListener('visibilitychange', refresh);
})();
