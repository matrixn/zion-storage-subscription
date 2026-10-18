'use strict';
document.querySelectorAll('[data-hero-carousel]').forEach(carousel=>{
 const slides=[...carousel.querySelectorAll('[data-hero-scene]')],selectors=[...carousel.querySelectorAll('[data-scene-select]')];
 const status=carousel.querySelector('[data-scene-status]'),progress=carousel.querySelector('[data-scene-progress]');
 const motion=matchMedia('(prefers-reduced-motion: reduce)'),surfaces=[carousel,document.querySelector('.header')].filter(Boolean),hovered=new Set();
 const duration=4000,animation=progress.animate([{transform:'scaleX(0)'},{transform:'scaleX(1)'}],{duration,fill:'both'});animation.pause();
 let index=0,timer=null,remaining=duration,started=0,visible=true,touch=null;
 function keyboardFocus(){const active=document.activeElement;return active?.matches(':focus-visible')&&surfaces.some(surface=>surface.contains(active));}
 function stop(){
  if(timer!==null){remaining=Math.max(0,remaining-(performance.now()-started));clearTimeout(timer);timer=null;}
  animation.pause();animation.currentTime=duration-remaining;carousel.dataset.running='false';
 }
 function sync(){
  const run=!motion.matches&&!hovered.size&&visible&&!document.hidden&&!keyboardFocus();
  if(!run){stop();return;}
  if(timer!==null)return;
  started=performance.now();animation.currentTime=duration-remaining;animation.play();carousel.dataset.running='true';
  timer=setTimeout(()=>{timer=null;show(index+1,false);},remaining);
 }
 function show(next,manual=true){
  stop();remaining=duration;animation.currentTime=0;index=(next+slides.length)%slides.length;
  slides.forEach((slide,i)=>{slide.hidden=i!==index;slide.classList.toggle('is-entering',i===index);});
  selectors.forEach((button,i)=>button.setAttribute('aria-pressed',String(i===index)));
  if(manual)status.textContent=status.dataset.statusTemplate.replace('{current}',String(index+1)).replace('{total}',String(slides.length)).replace('{title}',slides[index].dataset.title);
  sync();
 }
 carousel.querySelector('[data-scene-previous]').addEventListener('click',()=>show(index-1));
 carousel.querySelector('[data-scene-next]').addEventListener('click',()=>show(index+1));
 selectors.forEach(button=>button.addEventListener('click',()=>show(Number(button.dataset.sceneSelect))));
 surfaces.forEach(surface=>{
  surface.addEventListener('pointerenter',event=>{if(event.pointerType==='mouse'){hovered.add(surface);sync();}});
  surface.addEventListener('pointerleave',event=>{if(event.pointerType==='mouse'){hovered.delete(surface);sync();}});
  surface.addEventListener('focusin',()=>queueMicrotask(sync));surface.addEventListener('focusout',()=>queueMicrotask(sync));
 });
 carousel.addEventListener('keydown',event=>{
  if(event.key==='ArrowRight'||event.key==='ArrowLeft'){event.preventDefault();show(index+(event.key==='ArrowRight'?1:-1));}
 });
 const viewport=carousel.querySelector('[data-carousel-viewport]');
 viewport.addEventListener('pointerdown',event=>{if(event.pointerType==='touch'&&!event.target.closest('a,button'))touch={x:event.clientX,y:event.clientY};});
 viewport.addEventListener('pointerup',event=>{
  if(!touch)return;const dx=event.clientX-touch.x,dy=event.clientY-touch.y;touch=null;
  if(Math.abs(dx)>45&&Math.abs(dx)>Math.abs(dy))show(index+(dx<0?1:-1));
 });
 viewport.addEventListener('pointercancel',()=>{touch=null;});
 document.addEventListener('visibilitychange',sync);motion.addEventListener('change',sync);
 if('IntersectionObserver' in window)new IntersectionObserver(entries=>{visible=entries[0].isIntersecting;sync();},{threshold:.15}).observe(carousel);
 carousel.querySelector('[data-carousel-controls]').hidden=false;sync();
});
