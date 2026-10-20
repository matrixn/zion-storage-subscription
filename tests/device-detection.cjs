const assert=require('node:assert/strict'),{detect}=require('../public/assets/device-detection.js');
const cases=[
 [{userAgent:'Mozilla/5.0 (Linux; Android 14; Pixel 8) Mobile Safari/537.36'},'android_phone','android'],
 [{userAgent:'Mozilla/5.0 (Linux; Android 14; SM-X700) Safari/537.36'},'android_tablet','android'],
 [{userAgent:'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X)'},'iphone','ios'],
 [{userAgent:'Mozilla/5.0 (iPad; CPU OS 18_0 like Mac OS X)'},'ipad','ios'],
 [{userAgent:'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15) Safari/605',platform:'MacIntel',maxTouchPoints:5},'ipad','ios'],
 [{userAgent:'Mozilla/5.0 (Windows NT 10.0; Win64; x64)',maxTouchPoints:10},'desktop','both'],
 [{userAgent:'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15)',maxTouchPoints:0},'desktop','both'],
 [{userAgent:'Mozilla/5.0 (X11; Linux x86_64)'},'desktop','both'],
 [{userAgent:'Mozilla/5.0 (X11; CrOS x86_64)'},'desktop','both'],
 [{userAgent:'unknown'},'unknown','both'],
 [{userAgentData:{platform:'Android',mobile:true}},'android_phone','android'],
 [{userAgentData:{platform:'Android',mobile:false}},'unknown','android'],
 [{userAgentData:{platform:'iOS'}},'unknown','ios'],
 [{userAgent:'Mozilla/5.0 (iPod touch)'},'unknown','ios'],
 [{},'unknown','both'],
];
for(const [signal,category,platform] of cases)assert.deepEqual(detect(signal),{category,platform});
console.log(cases.length+' device detection assertions passed.');
