(() => {
  let value='system';
  try { value=localStorage.getItem('lifeos-theme') || 'system'; } catch (_) {}
  if (!['light','dark','system'].includes(value)) value='system';
  document.documentElement.dataset.theme=value;
})();
