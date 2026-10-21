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
