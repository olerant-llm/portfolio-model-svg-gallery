(() => {
  'use strict';
  const form=document.querySelector('[data-osvg-upload]'); if(!form) return;
  const config=JSON.parse(form.dataset.config), result=document.querySelector('[data-osvg-result]');
  const token=new URLSearchParams(location.hash.slice(1)).get('token');
  const headers=config.nonce?{'X-WP-Nonce':config.nonce}:{Authorization:'Bearer '+(token||'')};
  form.addEventListener('submit',async event=>{
    event.preventDefault(); const button=form.querySelector('button'), file=form.elements.zip.files[0];
    if(!file || file.size>5*1024*1024) {result.textContent='5MB以下の作品ZIPを選んでください。'; return;}
    button.disabled=true; result.textContent='検査して受け付けています…';
    try {
      const data=new FormData(); data.append('zip',file);
      const r=await fetch(config.url,{method:'POST',headers,body:data,cache:'no-store',credentials:config.nonce?'same-origin':'omit'});
      result.textContent=JSON.stringify(await r.json(),null,2);
    } catch (_) {result.textContent='通信結果が分かりません。受付状況を確認してから、同じZIPを再送してください。';}
    finally {button.disabled=false;}
  });
  document.querySelector('[data-osvg-status]')?.addEventListener('click',async()=>{
    try {const r=await fetch(config.url,{headers,cache:'no-store',credentials:'omit'}); result.textContent=JSON.stringify(await r.json(),null,2);}
    catch (_) {result.textContent='受付状況を取得できませんでした。時間をおいて確認してください。';}
  });
})();
