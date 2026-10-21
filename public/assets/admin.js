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
  if (!match) { output.textContent = '—'; return; }
  const cents = Number(match[1]) * 100 + Number((match[2] || '').padEnd(2, '0'));
  output.textContent = new Intl.NumberFormat('ro-RO', {minimumFractionDigits:2, maximumFractionDigits:2}).format(cents * 11 / 100) + ' EUR';
 };
 input.addEventListener('input', update); update();
});
