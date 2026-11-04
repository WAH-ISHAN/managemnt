(() => {
  const copy=window.lifeosLocale || {};
  window.lifeosT=text=>Object.prototype.hasOwnProperty.call(copy,text) ? copy[text] : text;
  const escape=text=>String(text).replace(/[&<>"']/g,ch=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch]));
  const decode=text=>{const el=document.createElement('textarea');el.innerHTML=text;return el.value;};
  // Called only with source-owned string literals, never user HTML or records.
  window.lifeosHTML=source=>source.replace(/>([^<>]+)(?=<|$)/g,(whole,value)=>{
    const key=decode(value.trim());return Object.prototype.hasOwnProperty.call(copy,key) ? '>'+value.match(/^\s*/)[0]+escape(copy[key])+value.match(/\s*$/)[0] : whole;
  }).replace(/((?:title|aria-label|placeholder)=")([^"]*)(")/g,(whole,start,value,end)=>{
    const key=decode(value);return Object.prototype.hasOwnProperty.call(copy,key) ? start+escape(copy[key])+end : whole;
  });
})();
