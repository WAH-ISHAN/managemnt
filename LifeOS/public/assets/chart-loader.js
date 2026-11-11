(() => {
  let loading = false;
  let loaded = false;
  const script = source => new Promise((resolve, reject) => {
    const element = document.createElement('script');
    element.src = source;
    element.onload = resolve;
    element.onerror = reject;
    document.head.append(element);
  });
  function clearErrors() {
    document.querySelectorAll('.chart-load-error').forEach(error => error.remove());
  }
  function showError() {
    document.querySelectorAll('canvas[data-chart]').forEach(canvas => {
      const wrap = canvas.closest('.chart-wrap');
      if (!wrap || wrap.querySelector('.chart-load-error')) return;
      const error = document.createElement('div');
      error.className = 'chart-load-error';
      error.setAttribute('role', 'status');
      const message = document.createElement('span');
      message.textContent = window.lifeosT?.('Chart could not load. Your records are still available below.') || 'Chart could not load. Your records are still available below.';
      const retry = document.createElement('button');
      retry.type = 'button';
      retry.className = 'secondary';
      retry.textContent = window.lifeosT?.('Retry chart') || 'Retry chart';
      retry.addEventListener('click', loadWhenNeeded);
      error.append(message, retry);
      wrap.append(error);
    });
  }
  function loadWhenNeeded() {
    if (loaded || loading || !document.querySelector('canvas[data-chart]')) return;
    loading = true;
    const needsCashflowDepth = [...document.querySelectorAll('canvas[data-chart]')]
      .some(canvas => canvas.dataset.chart?.includes('lifeos-cashflow-depth'));
    Promise.all([script('/assets/chart.min.js'), ...(needsCashflowDepth ? [script('/assets/cashflow3d.js')] : [])])
      .then(() => {
        loaded = true;
        clearErrors();
        document.dispatchEvent(new Event('lifeos:charts-ready'));
      })
      .catch(() => { loading = false; showError(); })
  }
  requestAnimationFrame(loadWhenNeeded);
  document.addEventListener('htmx:afterSettle', loadWhenNeeded);
  window.addEventListener('online', loadWhenNeeded);
})();
