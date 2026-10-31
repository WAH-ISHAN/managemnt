(() => {
  const preference=matchMedia('(prefers-reduced-motion: reduce)');
  const observed=new Set();
  const observer='IntersectionObserver' in window ? new IntersectionObserver(entries=>{
    for (const entry of entries) if (entry.isIntersecting) {
      entry.target.classList.add('is-visible'); observer.unobserve(entry.target); observed.delete(entry.target);
    }
  },{threshold:.15}) : null;
  function init() {
    for (const ring of document.querySelectorAll('[data-progress-donut]:not([data-ring-ready])')) {
      ring.dataset.ringReady='true';
      if (preference.matches || !observer) ring.classList.add('is-visible');
      else { observed.add(ring); observer.observe(ring); }
    }
  }
  document.addEventListener('htmx:afterSettle',init);
  document.addEventListener('htmx:beforeCleanupElement',event=>{
    for (const ring of observed) if (event.detail.elt===ring || event.detail.elt?.contains(ring)) {observer?.unobserve(ring);observed.delete(ring);}
  });
  preference.addEventListener('change',()=>{
    if (!preference.matches) return;
    for (const ring of observed) {ring.classList.add('is-visible');observer?.unobserve(ring);}
    observed.clear();
    document.querySelectorAll('[data-progress-donut] .progress-ring-arc').forEach(arc=>arc.getAnimations().forEach(animation=>animation.cancel()));
  });
  window.addEventListener('beforeprint',()=>{
    for (const ring of observed) {ring.classList.add('is-visible');observer?.unobserve(ring);}
    observed.clear();
  });
  init();
})();
