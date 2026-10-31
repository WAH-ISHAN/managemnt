(() => {
  const drawer = document.getElementById('notifications-drawer');
  const opener = document.querySelector('[data-notifications-open]');
  if (!drawer || !opener) return;
  const list = drawer.querySelector('[data-notifications-list]');
  const status = drawer.querySelector('[data-notifications-status]');
  const toast = document.querySelector('[data-notification-toast]');
  const pending = new Set();
  let data = null, filter = 'all', returnFocus = null, toastTimeout = null;
  const seen = new Set();
  const icons = {
    calendar: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="3"/><path d="M16 3v4M8 3v4M3 11h18m-13 5h3"/></svg>',
    reminder: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/></svg>'
  };
  function element(tag, className, text) {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== undefined) node.textContent = text;
    return node;
  }
  function safeRoute(route) {
    if (typeof route !== 'string' || !route.startsWith('/') || route.startsWith('//') || /[\\\u0000-\u001f\u007f]/.test(route)) return '/insights#alerts';
    try { return new URL(route, location.origin).origin === location.origin ? route : '/insights#alerts'; } catch { return '/insights#alerts'; }
  }
  function message(text, error = false) { status.textContent = text; status.classList.toggle('is-error', error); }
  function refresh() { document.dispatchEvent(new CustomEvent('lifeos:alerts-refresh')); }
  function close() { if (drawer.open) drawer.close(); }
  function open() {
    hideToast();
    document.getElementById('command-dialog')?.close();
    document.getElementById('quick-add-dialog')?.close();
    if (!drawer.open) {
      returnFocus = document.activeElement;
      drawer.showModal();
      opener.setAttribute('aria-expanded', 'true');
      drawer.querySelector('[data-notifications-close]').focus();
    }
    if (!data) message('Checking notifications…');
    refresh();
  }
  function hideToast() { clearTimeout(toastTimeout); toastTimeout = null; if (toast) toast.hidden = true; }
  function showToast(alert) {
    if (!toast || drawer.open) return;
    toast.querySelector('[data-notification-toast-title]').textContent = alert.title;
    toast.querySelector('[data-notification-toast-body]').textContent = alert.body;
    toast.hidden = false;
    clearTimeout(toastTimeout);
    toastTimeout = setTimeout(hideToast, 10000);
    const live = document.getElementById('desktop-alert-status');
    if (live) live.textContent = alert.title + '. ' + alert.body;
  }
  function dateLabel(value) {
    if (typeof value !== 'string' || !value) return null;
    const normalized = /^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/.test(value) ? value.replace(' ', 'T') + '+05:30' : value;
    const date = new Date(normalized);
    return Number.isNaN(date.getTime()) ? null : date.toLocaleString(undefined, {timeZone:'Asia/Colombo', month:'short', day:'numeric', hour:'numeric', minute:'2-digit'});
  }
  function render() {
    if (!data) return;
    const active = document.activeElement;
    const activeRow = active?.closest('[data-notification-id]');
    const focusId = activeRow?.dataset.notificationId;
    const focusAction = active?.dataset.notificationAction;
    const focusLink = active?.matches('[data-notifications-link]');
    const unread = data.alerts.filter(alert => !alert.read).length;
    const count = document.getElementById('alert-count');
    if (count) { count.textContent = String(data.unreadCount); count.hidden = data.unreadCount === 0; }
    opener.setAttribute('aria-label', data.unreadCount ? 'Notifications, ' + data.unreadCount + ' unread' : 'Notifications');
    drawer.querySelector('#notifications-summary').textContent = data.alerts.length ? unread + ' unread · ' + data.alerts.length + ' notification' + (data.alerts.length === 1 ? '' : 's') : 'Your calendar and reminders, together.';
    drawer.querySelector('[data-notifications-unread]').textContent = String(unread);
    drawer.querySelector('[data-notifications-preference]').textContent = data.enabled ? 'Windows popups are on' : 'Windows popups are off';
    drawer.querySelector('[data-notifications-read-all]').disabled = unread === 0 || pending.size > 0;
    drawer.querySelectorAll('[data-notifications-filter]').forEach(button => button.setAttribute('aria-pressed', String(button.dataset.notificationsFilter === filter)));
    const rows = data.alerts.filter(alert => filter === 'all' || (filter === 'unread' ? !alert.read : alert.kind === 'calendar'));
    list.replaceChildren();
    list.setAttribute('aria-busy', String(pending.size > 0));
    if (!rows.length) {
      const empty = element('div', 'notifications-empty');
      const icon = element('span'); icon.innerHTML = filter === 'calendar' ? icons.calendar : icons.reminder;
      empty.append(icon, element('h3', '', filter === 'unread' ? 'You’re all caught up' : filter === 'calendar' ? 'No calendar updates yet' : 'Nothing needs your attention'));
      empty.append(element('p', '', filter === 'calendar' ? 'New lectures, time changes and cancellations appear here after your NIBM calendar syncs.' : filter === 'unread' ? 'New updates will appear here as they arrive.' : 'Your calendar updates and upcoming reminders will appear here.'));
      list.append(empty);
    }
    for (const alert of rows) {
      const row = element('article', 'notification-item' + (alert.read ? ' is-read' : '') + (alert.level === 'warning' ? ' level-warning' : ''));
      row.dataset.notificationId = alert.id;
      const icon = element('span', 'notification-kind-icon'); icon.innerHTML = icons[alert.kind]; icon.setAttribute('aria-hidden', 'true');
      const content = element('div', 'notification-content');
      const meta = element('div', 'notification-topline');
      if (!alert.read) { const dot = element('span', 'notification-unread-dot'); dot.setAttribute('aria-hidden', 'true'); meta.append(dot, element('span', 'sr-only', 'Unread. ')); }
      meta.append(element('span', '', alert.kind === 'calendar' ? 'Calendar update' : 'Reminder'));
      const stamp = dateLabel(alert.created_at);
      if (stamp) { const time = element('time', '', stamp); time.title = 'Sri Lanka time'; meta.append(time); }
      const link = element('a', 'notification-title', alert.title); link.href = safeRoute(alert.route); link.dataset.notificationsLink = '';
      const body = element('p', 'notification-body', alert.body);
      const actions = element('div', 'notification-actions');
      for (const [action, label] of [...(!alert.read ? [['read','Mark as read']] : []), ['dismiss','Dismiss']]) {
        const button = element('button', 'quiet', label); button.type = 'button'; button.dataset.notificationAction = action; button.disabled = pending.has(alert.id) || pending.has('all');
        button.setAttribute('aria-label', label + ': ' + alert.title);
        actions.append(button);
      }
      content.append(meta, link, body, actions); row.append(icon, content); list.append(row);
    }
    if (drawer.open && focusId) {
      const row = [...list.querySelectorAll('[data-notification-id]')].find(item => item.dataset.notificationId === focusId);
      const replacement = row && (focusAction ? row.querySelector('[data-notification-action="' + focusAction + '"]') : focusLink ? row.querySelector('[data-notifications-link]') : null);
      (replacement && !replacement.disabled ? replacement : drawer.querySelector('[data-notifications-filter="' + filter + '"]'))?.focus({preventScroll:true});
    }
  }
  async function mutate(action, id = null, silent = false) {
    const key = id || 'all';
    if (!data?.csrf || pending.has(key) || pending.has('all') || (action === 'read-all' && pending.size)) return;
    pending.add(key); render();
    try {
      const path = action === 'read-all' ? '/insights/alerts/read-all' : '/insights/alerts/' + id + '/' + action;
      const response = await fetch(path, {method:'POST', headers:{Accept:'application/json'}, body:new URLSearchParams({_csrf:data.csrf})});
      if (!response.ok) throw new Error('notification update failed');
      if (action === 'dismiss') data.alerts = data.alerts.filter(alert => alert.id !== id);
      else data.alerts.forEach(alert => { if (!id || alert.id === id) alert.read = true; });
      data.unreadCount = data.alerts.filter(alert => !alert.read).length;
      if (!silent) message(action === 'dismiss' ? 'Notification dismissed.' : action === 'read-all' ? 'All notifications marked as read.' : 'Notification marked as read.');
      refresh();
    } catch {
      if (!silent) message('Could not update notifications. Check the connection and try again.', true);
    } finally { pending.delete(key); render(); }
  }
  window.addEventListener('lifeos:alerts', event => {
    const payload = event.detail;
    if (!payload || !Array.isArray(payload.alerts)) return;
    const unique = new Set();
    const alerts = payload.alerts.filter(alert => alert && typeof alert.id === 'string' && /^[a-f0-9]{64}$/.test(alert.id) && !unique.has(alert.id) && unique.add(alert.id) && typeof alert.title === 'string' && typeof alert.body === 'string').map(alert => ({...alert, kind:alert.kind === 'calendar' ? 'calendar' : 'reminder', read:alert.read === true}));
    const fresh = data ? alerts.find(alert => alert.kind === 'calendar' && !alert.read && !seen.has(alert.id)) : null;
    for (const alert of alerts) seen.add(alert.id);
    data = {alerts, csrf:typeof payload.csrf === 'string' ? payload.csrf : '', enabled:payload.enabled === true, unreadCount:Number.isSafeInteger(payload.unreadCount) && payload.unreadCount >= 0 ? payload.unreadCount : alerts.filter(alert => !alert.read).length};
    if (status.textContent === 'Checking notifications…') message('');
    render();
    if (fresh) showToast(fresh);
  });
  opener.addEventListener('click', open);
  drawer.addEventListener('close', () => {
    opener.setAttribute('aria-expanded', 'false');
    if (returnFocus?.isConnected) returnFocus.focus({preventScroll:true});
    else opener.focus({preventScroll:true});
  });
  drawer.addEventListener('click', event => {
    if (event.target.closest('[data-notifications-close]')) close();
    const selection = event.target.closest('[data-notifications-filter]');
    if (selection) { filter = selection.dataset.notificationsFilter; message(''); render(); }
    if (event.target.closest('[data-notifications-read-all]')) mutate('read-all');
    const action = event.target.closest('[data-notification-action]');
    if (action) mutate(action.dataset.notificationAction, action.closest('[data-notification-id]').dataset.notificationId);
    const link = event.target.closest('[data-notifications-link]');
    if (link) {
      const row = link.closest('[data-notification-id]');
      if (row) mutate('read', row.dataset.notificationId, true);
      close();
    }
    if (event.target === drawer) {
      const box = drawer.getBoundingClientRect();
      if (event.clientX < box.left || event.clientX > box.right || event.clientY < box.top || event.clientY > box.bottom) close();
    }
  });
  toast?.querySelector('[data-notification-toast-open]')?.addEventListener('click', () => { filter = 'calendar'; render(); open(); });
  toast?.querySelector('[data-notification-toast-close]')?.addEventListener('click', hideToast);
  document.addEventListener('visibilitychange', () => { if (document.hidden) hideToast(); });
})();
