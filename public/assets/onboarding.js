(()=>{
 'use strict';
 const root=document.querySelector('[data-onboarding]'),budgetKey='zion:onboarding:checks';
 if(root){
  try{sessionStorage.removeItem(budgetKey);}catch{/* Optional local state only. */}
  const choose=platform=>{
   root.dataset.platform=platform;
   root.querySelectorAll('[data-platform]').forEach(button=>button.setAttribute('aria-pressed',String(button.dataset.platform===platform)));
   root.querySelectorAll('[data-store],[data-help]').forEach(item=>{item.hidden=platform!=='both'&&(item.dataset.store||item.dataset.help)!==platform;});
   root.querySelector('[data-desktop-help]').hidden=platform!=='both';
  };
  const detection=window.ZionDevice?window.ZionDevice.detect(navigator):{platform:'both',category:'unknown'};
  root.dataset.device=detection.category;choose(detection.platform);
  root.querySelector('[data-platform-selector]').hidden=false;
  root.querySelectorAll('[data-platform]').forEach(button=>button.addEventListener('click',()=>choose(button.dataset.platform)));
  const copy=root.querySelector('[data-copy-server]'),field=root.querySelector('#on-server'),feedback=root.querySelector('[data-copy-feedback]');copy.hidden=false;
  copy.addEventListener('click',async()=>{
   let copied=false;
   try{if(navigator.clipboard&&navigator.clipboard.writeText){await navigator.clipboard.writeText(field.value);copied=true;}}catch{/* Try selection-based copy below. */}
   if(!copied){field.focus();field.select();field.setSelectionRange(0,field.value.length);try{copied=typeof document.execCommand==='function'&&document.execCommand('copy');}catch{/* Keep selected for manual copying. */}if(copied)copy.focus();}
   feedback.textContent=copied?feedback.dataset.success:feedback.dataset.error;
  });
 }
 // Existing authenticated status GET and CSRF-protected activation POST only.
 // A bounded tab-local budget prevents permanent reload/provisioning loops.
 const poll=document.querySelector('[data-status-poll]'),activation=document.querySelector('[data-auto-activation]');if(!poll&&!activation)return;
 const delays=[5000,10000,20000,30000,45000,60000];let budget=null;
 try{const raw=sessionStorage.getItem(budgetKey),saved=raw?JSON.parse(raw):null;budget=saved&&Number.isInteger(saved.count)&&saved.count>=0&&Date.now()-saved.at<900000?saved:{count:0,at:Date.now()};sessionStorage.setItem(budgetKey,JSON.stringify(budget));}catch{/* Without reliable storage, offer manual checks rather than an unbounded reload. */}
 const feedback=document.querySelector('[data-poll-feedback]');
 document.querySelectorAll('[data-status-refresh]').forEach(link=>link.addEventListener('click',()=>{try{sessionStorage.removeItem(budgetKey);}catch{}}));
 if(!budget||budget.count>=delays.length){if(feedback)feedback.textContent=feedback.dataset.stopped;return;}
 const delay=activation?300:delays[budget.count];budget.count++;
 try{sessionStorage.setItem(budgetKey,JSON.stringify(budget));}catch{if(feedback)feedback.textContent=feedback.dataset.stopped;return;}
 const timer=setTimeout(()=>{if(activation)activation.requestSubmit();else location.reload();},delay);
 document.querySelectorAll('.on-state form,.on-state a').forEach(element=>element.addEventListener(element.tagName==='FORM'?'submit':'click',()=>clearTimeout(timer)));
})();
