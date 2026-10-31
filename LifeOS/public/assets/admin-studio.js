(() => {
  document.addEventListener('change', event => {
    if (event.target.matches('[data-hub-media-target]')) {
      const input=event.target.closest('form')?.elements[event.target.dataset.hubMediaTarget];
      if(input && event.target.value) input.value=event.target.value;
      return;
    }
    if(event.target.matches('[data-hub-client-filter]')) {
      const form=event.target.closest('form');
      for(const label of form.querySelectorAll('[data-hub-client]')) {
        const visible=label.dataset.hubClient===event.target.value;
        label.hidden=!visible;
        const input=label.querySelector('input');input.disabled=!visible;if(!visible)input.checked=false;
      }
      return;
    }
    if (!event.target.matches('[data-studio-switch]')) return;
    const path = event.target.value;
    if (/^\/web-admin\/[1-9]\d*$/.test(path)) location.assign(path);
  });
  document.addEventListener('click', event => {
    const copy=event.target.closest('[data-hub-copy],[data-hub-copy-value]');
    if(copy) {
      const value=copy.dataset.hubCopyValue ?? copy.previousElementSibling?.value;
      const status=copy.closest('.card')?.querySelector('[data-hub-copy-status]');
      if(value) navigator.clipboard.writeText(value).then(()=>{if(status)status.textContent='Copied.';}).catch(()=>{if(status)status.textContent='Select the value and copy it manually.';});
      return;
    }
    const button = event.target.closest('[data-studio-nav]');
    if (!button) return;
    const nav = document.getElementById('studio-navigation');
    if (!nav) return;
    const open = !nav.hasAttribute('data-open');
    nav.toggleAttribute('data-open', open);
    button.setAttribute('aria-expanded', String(open));
  });
})();
