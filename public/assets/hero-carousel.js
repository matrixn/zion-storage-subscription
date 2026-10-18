'use strict';
document.querySelectorAll('[data-hero-carousel]').forEach(carousel=>{
 const slides=[...carousel.querySelectorAll('[data-hero-scene]')],selectors=[...carousel.querySelectorAll('[data-scene-select]')];
 const play=carousel.querySelector('[data-scene-play]'),status=carousel.querySelector('[data-scene-status]');
 const motion=matchMedia('(prefers-reduced-motion: reduce)');
 let index=0,playing=!motion.matches,timer=null,hovered=false,visible=true,touch=null;
 function stopTimer(){clearTimeout(timer);timer=null;}
 function schedule(){
  stopTimer();
  if(playing&&!hovered&&visible&&!document.hidden)timer=setTimeout(()=>show(index+1,false),8000);
 }
 function updatePlay(){play.textContent=playing?play.dataset.pauseLabel:play.dataset.playLabel;carousel.dataset.playing=String(playing);}
 function pause(){playing=false;stopTimer();updatePlay();}
 function show(next,manual=true){
  if(manual)pause();
  index=(next+slides.length)%slides.length;
  slides.forEach((slide,i)=>{slide.hidden=i!==index;slide.classList.toggle('is-entering',i===index);});
  selectors.forEach((button,i)=>button.setAttribute('aria-pressed',String(i===index)));
  if(manual)status.textContent=status.dataset.statusTemplate.replace('{current}',String(index+1)).replace('{total}',String(slides.length)).replace('{title}',slides[index].dataset.title);
  schedule();
 }
 carousel.querySelector('[data-scene-previous]').addEventListener('click',()=>show(index-1));
 carousel.querySelector('[data-scene-next]').addEventListener('click',()=>show(index+1));
 selectors.forEach(button=>button.addEventListener('click',()=>show(Number(button.dataset.sceneSelect))));
 play.addEventListener('click',()=>{playing=!playing;updatePlay();schedule();});
 carousel.addEventListener('mouseenter',()=>{hovered=true;stopTimer();});
 carousel.addEventListener('mouseleave',()=>{hovered=false;schedule();});
 carousel.addEventListener('focusin',event=>{if(event.target!==play)pause();else stopTimer();});
 carousel.addEventListener('focusout',()=>schedule());
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
 document.addEventListener('visibilitychange',schedule);
 motion.addEventListener('change',event=>{if(event.matches)pause();});
 if('IntersectionObserver' in window)new IntersectionObserver(entries=>{visible=entries[0].isIntersecting;schedule();},{threshold:.15}).observe(carousel);
 carousel.querySelector('[data-carousel-controls]').hidden=false;updatePlay();schedule();
});
