// Build-time only: QR_MODULE=/absolute/path/to/qrcode node bin/generate-onboarding-qr.cjs
const fs=require('node:fs'),path=require('node:path'),qr=require(process.env.QR_MODULE||'qrcode');
const targets={android:'https://play.google.com/store/apps/details?id=app.alextran.immich',ios:'https://apps.apple.com/us/app/immich/id1613945652'};
(async()=>{
 const dir=path.join(__dirname,'../public/assets/onboarding');fs.mkdirSync(dir,{recursive:true});
 for(const [name,url] of Object.entries(targets))fs.writeFileSync(path.join(dir,name+'.svg'),await qr.toString(url,{type:'svg',errorCorrectionLevel:'M',margin:4,width:256}));
})().catch(error=>{console.error(error.message);process.exitCode=1;});
