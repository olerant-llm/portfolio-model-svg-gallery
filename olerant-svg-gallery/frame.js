(() => {
  'use strict';
  const host=window.OSVG_HOST, root=document.getElementById('stage');
  let binding=null, control=null, ticker=null;
  const send=(type,extra={})=>{
    if(binding) parent.postMessage({type,instance:binding.instance,id:binding.id,version:binding.version,...extra},host.origin);
  };
  const state=()=>{
    const s=control.state();
    const expected=['id','version','timeSeconds','durationSeconds','paused','ended','speed','loop','reducedMotion','destroyed'];
    if(!s || Object.keys(s).sort().join('|')!==expected.sort().join('|') || s.id!==binding.id || s.version!==binding.version ||
      !Number.isFinite(s.timeSeconds) || !Number.isFinite(s.durationSeconds) || s.durationSeconds!==binding.duration || s.timeSeconds<0 || s.timeSeconds>s.durationSeconds ||
      ![0.5,1,2].includes(s.speed) || ['paused','ended','loop','reducedMotion','destroyed'].some(k=>typeof s[k]!=='boolean')) throw new Error('ADAPTER_STATE');
    return s;
  };
  const fail=()=>{clearInterval(ticker); try{control?.destroy();}catch(_){} control=null; root.replaceChildren(); send('error');};
  const script=source=>{const el=document.createElement('script'); el.nonce=host.nonce; el.textContent=source; document.body.append(el); el.remove();};
  addEventListener('error',()=>{if(binding)fail();});
  addEventListener('unhandledrejection',()=>{if(binding)fail();});
  addEventListener('message',event=>{
    if(event.source!==parent || event.origin!==host.origin || !event.data || typeof event.data!=='object') return;
    const d=event.data;
    if(!binding) {
      if(d.type!=='init' || typeof d.instance!=='string' || !/^[a-f0-9]{32}$/.test(d.instance)) return;
      const p=d.payload;
      if(!p || typeof p.svg!=='string' || p.svg.length>2097152 || typeof p.script!=='string' || p.script.length>2097152 || typeof p.runtime!=='string' || p.runtime.length>150000 || typeof p.id!=='string' || typeof p.version!=='string' || !Number.isFinite(p.playback?.durationSeconds)) return;
      binding={instance:d.instance,id:p.id,version:p.version,duration:p.playback.durationSeconds};
      try {
        window.osvgCheckSyntax(p.script);
        const doc=new DOMParser().parseFromString(p.svg,'image/svg+xml');
        if(doc.querySelector('parsererror') || doc.documentElement.namespaceURI!=='http://www.w3.org/2000/svg') throw new Error('SVG_PARSE');
        const svg=document.importNode(doc.documentElement,true); root.replaceChildren(svg);
        script(p.runtime); script(p.script);
        const adapter=window.svgAnimationItem;
        if(!adapter || adapter.adapterVersion!=='1.0' || adapter.id!==p.id || adapter.version!==p.version || typeof adapter.mount!=='function') throw new Error('ADAPTER_ID');
        control=adapter.mount({root,svg,gsap:window.gsap,reducedMotion:false});
        if(!control || ['play','pause','replay','seek','setSpeed','setLoop','state','destroy'].some(k=>typeof control[k]!=='function')) throw new Error('ADAPTER_METHOD');
        const initial=state();
        if(!initial.paused || initial.loop || initial.speed!==1 || initial.destroyed || initial.reducedMotion) throw new Error('ADAPTER_INITIAL_STATE');
        send('ready',{state:initial}); ticker=setInterval(()=>{try{send('state',{state:state()});}catch(_){fail();}},100);
      } catch(_) {fail();}
      return;
    }
    if(d.instance!==binding.instance || d.id!==binding.id || d.version!==binding.version || !control || Object.keys(d).some(k=>!['type','instance','id','version','value'].includes(k))) return;
    try {
      switch(d.type) {
        case 'play': control.play(); break;
        case 'pause': control.pause(); break;
        case 'replay': control.replay(); break;
        case 'seek': if(!Number.isFinite(d.value) || d.value<0 || d.value>binding.duration) return; control.seek(d.value); break;
        case 'setSpeed': if(![0.5,1,2].includes(d.value)) return; control.setSpeed(d.value); break;
        case 'setLoop': if(typeof d.value!=='boolean') return; control.setLoop(d.value); break;
        case 'destroy': control.destroy(); control.destroy(); clearInterval(ticker); root.replaceChildren(); control=null; send('destroyed'); return;
        default:return;
      }
      send('state',{state:state()});
    } catch(_) {fail();}
  });
  parent.postMessage({type:'osvg-host-ready'},host.origin);
})();
