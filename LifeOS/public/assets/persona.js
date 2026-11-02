(() => {
  const form = document.querySelector('[data-persona-wizard]');
  if (!form) return;
  const steps = [...form.querySelectorAll('[data-wizard-step]')];
  const back = form.querySelector('[data-wizard-back]');
  const next = form.querySelector('[data-wizard-next]');
  const submit = form.querySelector('[data-wizard-submit]');
  let index = 0;
  const show = () => {
    steps.forEach((step, i) => { step.hidden = i !== index; });
    back.hidden = index === 0;
    next.hidden = index === steps.length - 1;
    submit.hidden = !next.hidden;
    form.querySelector('[data-wizard-progress]').textContent = `${index + 1} / ${steps.length} · ${['Persona', 'Profile', 'Preferences'][index]}`;
  };
  const validate = step => [...step.querySelectorAll('input,select')].every(input => input.reportValidity());
  next.addEventListener('click', () => { if (validate(steps[index])) { index++; show(); steps[index].querySelector('input,select')?.focus(); } });
  back.addEventListener('click', () => { index--; show(); });
  const pack = form.querySelector('[data-persona-template]');
  form.addEventListener('change', event => {
    if (event.target.name !== 'persona') return;
    [...pack.options].forEach(option => { option.hidden = !!option.dataset.persona && option.dataset.persona !== event.target.value; });
    if (pack.selectedOptions[0]?.hidden) pack.value = '';
  });
  form.addEventListener('keydown', event => { if (event.key === 'Enter' && index < steps.length - 1) { event.preventDefault(); next.click(); } });
  show();
})();
