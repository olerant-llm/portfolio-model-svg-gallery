(() => {
  'use strict';
  const reduce=matchMedia('(prefers-reduced-motion: reduce)');
  document.querySelectorAll('[data-osvg-player]').forEach(container=>{
    const config=JSON.parse(container.dataset.config), poster=container.querySelector('[data-poster]'), slot=container.querySelector('[data-frame-slot]');
    const status=container.querySelector('[data-player-status]'), seek=container.querySelector('[data-seek]'), speed=container.querySelector('[data-speed]'), loop=container.querySelector('[data-loop]');
    let frame=null, instance='', ready=false, starting=false, generation=0, timeout=null, lastState=null, abort=null, visible=true;
    const tell=(type,value)=>{if(frame && ready) frame.contentWindow.postMessage({type,instance,id:config.id,version:config.version,...(value===undefined?{}:{value})},'*');};
    const reset=message=>{
      generation++; abort?.abort(); abort=null; tell('destroy'); frame?.remove(); frame=null; slot.replaceChildren();
      clearTimeout(timeout); ready=false; starting=false; lastState=null; poster.hidden=false; seek.disabled=true; seek.value=0;
      container.querySelector('[data-command="pause"]').disabled=true; status.textContent=message;
    };
    const motionPreference=()=>{
      if(reduce.matches) reset('端末の「動きを減らす」設定に合わせて、静止画を表示しています。');
      for(const el of container.querySelectorAll('[data-command], [data-speed], [data-loop], [data-seek]')) {
        el.disabled=reduce.matches || (el===loop && config.loopMode==='none') || ((el===seek || el.dataset.command==='pause') && !ready);
      }
      if(!reduce.matches && !frame) status.textContent='再生を押すと動きます。';
    };
    const start=async command=>{
      if(reduce.matches || document.hidden || !visible) return;
      if(ready) {tell(command);return;}
      if(starting) return; starting=true; const run=++generation;
      status.textContent='再生を準備しています…';
      try {
        abort=new AbortController();
        timeout=setTimeout(()=>reset('再生の準備に時間がかかっています。静止画を表示しています。'),12000);
        const response=await fetch(config.url,{headers:config.nonce?{'X-WP-Nonce':config.nonce}:{},credentials:config.nonce?'same-origin':'omit',cache:'no-store',signal:abort.signal});
        if(!response.ok) throw new Error('LOAD');
        const payload=await response.json();
        if(payload.id!==config.id || payload.version!==config.version || payload.payloadSha256!==config.payloadSha256) throw new Error('VERSION');
        if(run!==generation || reduce.matches || document.hidden || !visible) return;
        instance=Array.from(crypto.getRandomValues(new Uint8Array(16)),b=>b.toString(16).padStart(2,'0')).join('');
        frame=document.createElement('iframe'); frame.title='アニメーションの再生画面'; frame.setAttribute('sandbox','allow-scripts'); frame.referrerPolicy='no-referrer';
        frame.src=config.frame; frame.dataset.pendingCommand=command;
        frame._payload=payload; slot.append(frame);
      } catch(error) {if(run===generation) reset('動きを読み込めませんでした。静止画を表示しています。');}
    };
    addEventListener('message',event=>{
      if(!frame || event.source!==frame.contentWindow || !event.data || typeof event.data!=='object' || event.origin!=='null') return;
      const d=event.data;
      if(Object.keys(d).length>6 || Object.keys(d).some(k=>!['type','instance','id','version','state'].includes(k))) return;
      if(d.type==='osvg-host-ready') {
        if(frame._payload) {frame.contentWindow.postMessage({type:'init',instance,payload:frame._payload},'*'); delete frame._payload;}
        return;
      }
      if(d.instance!==instance || d.id!==config.id || d.version!==config.version) return;
      if(d.type==='error') {reset('この作品の動きを確認できませんでした。静止画を表示しています。');return;}
      if(!['ready','state'].includes(d.type)) return;
      const s=d.state;
      if(!s || typeof s!=='object' || Object.keys(s).length!==10 || s.id!==config.id || s.version!==config.version || !Number.isFinite(s.timeSeconds) || s.timeSeconds<0 || s.timeSeconds>config.duration || s.durationSeconds!==config.duration || ![.5,1,2].includes(s.speed) || ['paused','ended','loop','reducedMotion','destroyed'].some(k=>typeof s[k]!=='boolean')) return;
      if(d.type==='ready') {
        if(ready || reduce.matches || document.hidden || !visible) return;
        ready=true; starting=false; clearTimeout(timeout); poster.hidden=true; seek.disabled=false; container.querySelector('[data-command="pause"]').disabled=false;
        tell('setSpeed',Number(speed.value)); if(config.loopMode!=='none') tell('setLoop',loop.checked); tell(frame.dataset.pendingCommand||'play');
      }
      seek.value=s.timeSeconds;
      const description=s.ended?'再生が終わりました。':s.paused?'一時停止しています。':'再生中です。';
      if(description!==lastState) {status.textContent=description;lastState=description;}
    });
    container.querySelectorAll('[data-command]').forEach(button=>button.addEventListener('click',()=>button.dataset.command==='pause'?tell('pause'):start(button.dataset.command)));
    speed.addEventListener('change',()=>tell('setSpeed',Number(speed.value)));
    loop.addEventListener('change',()=>tell('setLoop',loop.checked));
    seek.addEventListener('input',()=>{tell('pause');tell('seek',Number(seek.value));});
    container.querySelector('[data-background]').addEventListener('change',event=>{container.querySelector('.osvg-stage').dataset.background=event.target.value;});
    document.addEventListener('visibilitychange',()=>{if(document.hidden) reset('画面を離れたため停止しました。再生を押すと動きます。');});
    addEventListener('pagehide',()=>reset('停止しました。'));
    new IntersectionObserver(entries=>{
      visible=entries[0].isIntersecting;
      if(!visible && (frame || starting)) reset('画面の外に出たため停止しました。再生を押すと動きます。');
    },{threshold:0}).observe(container);
    reduce.addEventListener('change',motionPreference); motionPreference();
  });
})();
