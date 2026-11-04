(() => {
  for (const form of document.querySelectorAll('[data-role-picker]')) {
    if (form.dataset.rolesReady) continue;
    form.dataset.rolesReady = 'true';
    const choices = [...form.querySelectorAll('[name="roles[]"]')];
    const pack = form.querySelector('[data-persona-template]');
    const preview = form.querySelector('[data-role-preview]');
    const features = form.querySelector('[data-role-feature-list]');
    const selected = () => choices.filter(input => input.checked);
    const validateRoles = () => {
      const valid = selected().length > 0;
      choices[0].setCustomValidity(valid ? '' : form.querySelector('[data-role-choices]').dataset.requiredMessage);
      return valid;
    };
    const update = () => {
      const roles = selected().map(input => input.value);
      choices[0].setCustomValidity('');
      features.replaceChildren();
      const labels = new Set(selected().flatMap(input => JSON.parse(input.dataset.roleFeatures)));
      for (const label of labels) { const item=document.createElement('li');item.textContent=label;features.append(item); }
      preview.hidden = !roles.length;
      for (const option of pack.options) option.hidden = option.disabled = !!option.dataset.persona && !roles.includes(option.dataset.persona);
      if (pack.selectedOptions[0]?.disabled) pack.value = '';
      const institution = form.querySelector('[data-persona-institution]');
      if (institution) institution.textContent = window.lifeosT(roles.length !== 1 ? 'School, university or workplace (optional)' :
        roles[0] === 'school' ? 'School (optional)' : roles[0] === 'uni' ? 'University / institution (optional)' : 'Workplace / organization (optional)');
    };
    form.addEventListener('change', event => { if (event.target.name === 'roles[]') update(); });
    const steps = [...form.querySelectorAll('[data-wizard-step]')];
    if (steps.length) {
      const back=form.querySelector('[data-wizard-back]'), next=form.querySelector('[data-wizard-next]'), submit=form.querySelector('[data-wizard-submit]');
      const progress=form.querySelector('[data-wizard-progress]'), labels=JSON.parse(progress.dataset.stepLabels);
      let index=0;
      const show=()=>{
        steps.forEach((step,i)=>{step.hidden=i!==index;});
        back.hidden=index===0;next.hidden=index===steps.length-1;submit.hidden=!next.hidden;
        progress.textContent=`${index+1} / ${steps.length} · ${labels[index]}`;
      };
      next.addEventListener('click',()=>{
        if (index===0 && !validateRoles()) { choices[0].reportValidity();return; }
        if (![...steps[index].querySelectorAll('input,select')].every(input=>input.reportValidity())) return;
        index++;show();steps[index].querySelector('input,select')?.focus();
      });
      back.addEventListener('click',()=>{index--;show();});
      form.addEventListener('submit',event=>{
        if (!validateRoles()) { event.preventDefault();index=0;show();choices[0].reportValidity(); }
      });
      form.addEventListener('keydown',event=>{if(event.key==='Enter'&&index<steps.length-1&&event.target.tagName!=='BUTTON'){event.preventDefault();next.click();}});
      show();
    } else {
      form.addEventListener('submit',event=>{if(!validateRoles()){event.preventDefault();choices[0].reportValidity();}});
    }
    update();
  }
})();
