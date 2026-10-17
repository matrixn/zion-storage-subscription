'use strict';
if('IntersectionObserver' in window && !matchMedia('(prefers-reduced-motion: reduce)').matches){
 const observer=new IntersectionObserver(entries=>entries.forEach(entry=>{if(entry.isIntersecting){entry.target.classList.add('revealed');observer.unobserve(entry.target);}}),{threshold:.05});
 document.querySelectorAll('.section,.storage-section,.final-cta').forEach(section=>{section.classList.add('reveal-enhanced');observer.observe(section);});
}
const toggle=document.querySelector('.menu-toggle'),nav=document.querySelector('#navigation');
toggle?.addEventListener('click',()=>{const open=toggle.getAttribute('aria-expanded')!=='true';toggle.setAttribute('aria-expanded',String(open));nav.classList.toggle('open',open);});
document.addEventListener('keydown',event=>{if(event.key==='Escape'&&toggle?.getAttribute('aria-expanded')==='true'){toggle.click();toggle.focus();}});
nav?.querySelectorAll('a').forEach(a=>a.addEventListener('click',()=>{nav.classList.remove('open');toggle?.setAttribute('aria-expanded','false');}));
const form=document.querySelector('[data-registration]');
if(form){
 const details=form.querySelector('[data-details]'),plan=form.querySelector('[data-plan]'),next=form.querySelector('[data-next]'),back=form.querySelector('[data-back]'),steps=document.querySelectorAll('.form-steps li');
 plan.hidden=true;next.hidden=false;
 next.addEventListener('click',()=>{
  const fields=[...details.querySelectorAll('input,select')];
  const invalid=fields.find(f=>!f.checkValidity());if(invalid){invalid.reportValidity();return;}
  details.hidden=true;plan.hidden=false;next.hidden=true;back.hidden=false;steps[0].classList.remove('active');steps[1].classList.add('active');plan.querySelector('select').focus();
 });
 back.addEventListener('click',()=>{details.hidden=false;plan.hidden=true;next.hidden=false;back.hidden=true;steps[0].classList.add('active');steps[1].classList.remove('active');details.querySelector('input').focus();});
 form.addEventListener('invalid',e=>{if(details.contains(e.target)&&details.hidden)back.click();},true);
}
