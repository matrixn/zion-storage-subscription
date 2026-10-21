'use strict';
document.querySelectorAll('[data-copy]').forEach(button => {
 button.addEventListener('click', async () => {
  const input = document.getElementById(button.dataset.copy);
  const status = document.getElementById('copy-result');
  try {
   await navigator.clipboard.writeText(input.value);
   status.textContent = 'Link copiat.';
  } catch {
   input.focus(); input.select();
   status.textContent = 'Link selectat. Apasă Ctrl+C sau folosește Copiază.';
  }
 });
});

document.querySelectorAll('[data-plan-editor]').forEach(form => {
 const input = form.elements.monthly_price, output = form.querySelector('[data-plan-annual]');
 const update = () => {
  const match = input.value.trim().match(/^(0|[1-9][0-9]{0,3})(?:[.,]([0-9]{1,2}))?$/);
  if (!match) { output.textContent = '—'; form.querySelectorAll('[data-plan-eur-month],[data-plan-eur-year]').forEach(el=>el.textContent='—'); return; }
  const cents = Number(match[1]) * 100 + Number((match[2] || '').padEnd(2, '0'));
  output.textContent = new Intl.NumberFormat('ro-RO', {minimumFractionDigits:2, maximumFractionDigits:2}).format(cents * 11 / 100) + ' RON';
  const rate = Number(form.dataset.fxRate), format = value => new Intl.NumberFormat('ro-RO', {minimumFractionDigits:2,maximumFractionDigits:2}).format(value / 100) + ' EUR';
  for (const [selector, multiple] of [['[data-plan-eur-month]',1],['[data-plan-eur-year]',11]]) {
   const el=form.querySelector(selector);
   if(el) el.textContent=cents===0?format(0):(rate>0?format(Math.floor((cents*multiple*10000+Math.floor(rate/2))/rate)):'Indisponibil');
  }
 };
 input.addEventListener('input', update); update();
});
