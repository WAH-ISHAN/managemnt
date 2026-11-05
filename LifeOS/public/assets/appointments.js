(() => {
  document.querySelectorAll('[data-appointment-form]').forEach(form => {
    const toggle = form.querySelector('[type="checkbox"][name="all_day"]');
    const timed = form.querySelector('[data-appointment-timed]');
    const dates = form.querySelector('[data-appointment-dates]');
    const update = () => {
      const allDay = toggle.checked;
      timed.hidden = allDay;
      dates.hidden = !allDay;
      timed.querySelectorAll('input').forEach(input => { input.disabled = allDay; input.required = !allDay; });
      dates.querySelectorAll('input').forEach(input => { input.disabled = !allDay; input.required = allDay; });
      if (allDay && !form.elements.starts_on.value) {
        form.elements.starts_on.value = form.elements.starts_at.value.slice(0, 10);
        form.elements.ends_on.value = form.elements.starts_on.value;
      }
    };
    toggle.addEventListener('change', update);
    update();
  });
})();
