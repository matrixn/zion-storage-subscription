/* Best-effort platform hints only. Viewport size is deliberately not an input. */
(function(root,factory){
 if(typeof module==='object'&&module.exports)module.exports=factory();
 else root.ZionDevice=factory();
})(typeof globalThis!=='undefined'?globalThis:this,function(){
 'use strict';
 function detect(signal){
  signal=signal||{};
  const ua=signal.userAgent||'',platform=signal.platform||'',hints=signal.userAgentData||{};
  const result=(category,os)=>({category,platform:os});
  if(/Android/i.test(ua))return result(/Mobile/i.test(ua)?'android_phone':'android_tablet','android');
  if(/iPhone/i.test(ua))return result('iphone','ios');
  if(/iPad/i.test(ua)||(/Macintosh/i.test(ua)||platform==='MacIntel')&&Number(signal.maxTouchPoints)>1)return result('ipad','ios');
  if(hints.platform==='Android')return result(hints.mobile===true?'android_phone':'unknown','android');
  if(/iPod/i.test(ua)||hints.platform==='iOS')return result('unknown','ios');
  if(/Windows NT|Macintosh|X11|CrOS|Linux (?:x86_64|i686)/i.test(ua)&&!/Mobile/i.test(ua))return result('desktop','both');
  return result('unknown','both');
 }
 return {detect};
});
