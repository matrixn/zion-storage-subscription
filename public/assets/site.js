'use strict';
document.querySelector('[data-billing-switch]')?.addEventListener('submit',event=>{
 const interval=event.submitter?.value;if(!['month','year'].includes(interval))return;event.preventDefault();
 event.currentTarget.querySelectorAll('button').forEach(button=>button.setAttribute('aria-pressed',String(button.value===interval)));
 document.querySelectorAll('.pricing-grid .plan').forEach(card=>{
  card.querySelector('.price-amount').textContent=card.dataset[interval+'Amount'];
  card.querySelector('.price-interval').textContent=card.dataset[interval+'Interval'];
  card.querySelector('.plan-caption').textContent=card.dataset[interval+'Caption'];
  const link=card.querySelector('.button'),target=new URL(link.href);target.searchParams.set('billing_interval',interval);link.href=target.pathname+target.search;
 });
 const target=new URL(location.href);target.searchParams.set('billing_interval',interval);history.replaceState(null,'',target);
});
document.querySelectorAll('[data-storage-comparison]').forEach(comparison=>{
 const input=comparison.querySelector('input[type=range]'),total=Number(comparison.dataset.total);
 const format=new Intl.NumberFormat(document.documentElement.lang);
 const result=comparison.querySelector('[data-result]');
 function update(){
  const overhead=Math.max(0,Math.min(total,Number(input.value))),files=Math.round(overhead*4/7),email=overhead-files,media=total-overhead;
  for(const [kind,value] of Object.entries({media,files,email})){
   comparison.querySelector('[data-'+kind+']').textContent=format.format(value);
   comparison.querySelector('[data-bar-'+kind+']').style.width=(value/total*100)+'%';
  }
  comparison.querySelector('[data-overhead]').textContent=format.format(overhead)+' GB';
  result.textContent=result.dataset.resultTemplate.replace('{media}',format.format(media)).replace('{total}',format.format(total));
 }
 input.addEventListener('input',update);update();comparison.querySelector('[data-comparison-control]').hidden=false;
});
if('IntersectionObserver' in window && !matchMedia('(prefers-reduced-motion: reduce)').matches){
 const observer=new IntersectionObserver(entries=>entries.forEach(entry=>{if(entry.isIntersecting){entry.target.classList.add('revealed');observer.unobserve(entry.target);}}),{threshold:.05});
 document.querySelectorAll('.section,.storage-section,.final-cta').forEach(section=>{section.classList.add('reveal-enhanced');observer.observe(section);});
}
const toggle=document.querySelector('.menu-toggle'),nav=document.querySelector('#navigation');
toggle?.addEventListener('click',()=>{const open=toggle.getAttribute('aria-expanded')!=='true';toggle.setAttribute('aria-expanded',String(open));nav.classList.toggle('open',open);});
document.addEventListener('keydown',event=>{if(event.key==='Escape'&&toggle?.getAttribute('aria-expanded')==='true'){toggle.click();toggle.focus();}});
nav?.querySelectorAll('a').forEach(a=>a.addEventListener('click',()=>{nav.classList.remove('open');toggle?.setAttribute('aria-expanded','false');}));
const form=document.querySelector('[data-registration]');
function updateBilling(){
 const interval=document.querySelector('#billing_interval')?.value||'month';
 document.querySelectorAll('#plan option').forEach(option=>{if(option.dataset[interval+'Label'])option.textContent=option.dataset[interval+'Label'];});
 const group=document.querySelector('[data-billing]');if(!group)return;
 const plan=document.querySelector('#plan'),type=group.querySelector('[data-billing-type]');
 const paid=!plan||plan.value!=='free';group.hidden=!paid;group.disabled=!paid;
 const company=type.value==='company';
 group.querySelectorAll('[data-company-field]').forEach(section=>{section.hidden=!company;section.querySelectorAll('input').forEach(input=>input.disabled=!company);});
 group.querySelectorAll('[data-billing-required]').forEach(input=>input.required=paid&&!input.disabled);
 for(const [target,source] of [['billing-name','name'],['billing-billing_email','email']]){const input=document.getElementById(target),value=document.getElementById(source);if(input&&value&&!input.value)input.value=value.value;}
}
document.querySelector('#plan')?.addEventListener('change',updateBilling);
document.querySelector('#billing_interval')?.addEventListener('change',updateBilling);
document.querySelector('[data-billing-type]')?.addEventListener('change',updateBilling);
document.querySelector('[data-next]')?.addEventListener('click',updateBilling);
updateBilling();
if(form){
 const details=form.querySelector('[data-details]'),plan=form.querySelector('[data-plan]'),next=form.querySelector('[data-next]'),back=form.querySelector('[data-back]'),steps=document.querySelectorAll('.form-steps li');
 plan.hidden=true;next.hidden=false;
 next.addEventListener('click',()=>{
  const fields=[...details.querySelectorAll('input,select')];
  const invalid=fields.find(f=>!f.checkValidity());if(invalid){invalid.reportValidity();return;}
  details.hidden=true;plan.hidden=false;next.hidden=true;back.hidden=false;steps[0].classList.remove('active');steps[1].classList.add('active');plan.querySelector('select').focus();
 });
 back.addEventListener('click',()=>{details.hidden=false;plan.hidden=true;next.hidden=false;back.hidden=true;steps[0].classList.add('active');steps[1].classList.remove('active');details.querySelector('input').focus();});
 form.addEventListener('invalid',e=>{if(details.contains(e.target)&&details.hidden)back.click();if(plan.contains(e.target)&&plan.hidden)next.click();},true);
}
