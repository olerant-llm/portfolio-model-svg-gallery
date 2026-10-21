(() => {
  'use strict';
  document.querySelectorAll('[data-bulk-select]').forEach(form=>{
    const boxes=[...form.querySelectorAll('[name="bulk[]"]')],all=form.querySelector('[data-bulk-all]');
    const count=()=>{const n=boxes.filter(b=>b.checked).length;form.querySelector('[data-bulk-count]').textContent=n+'件選択';all.checked=boxes.length>0&&n===boxes.length;all.indeterminate=n>0&&n<boxes.length;};
    all.addEventListener('change',()=>{boxes.forEach(b=>b.checked=all.checked);count();});boxes.forEach(b=>b.addEventListener('change',count));
    form.addEventListener('submit',event=>{if(!boxes.some(b=>b.checked)){event.preventDefault();form.querySelector('[data-bulk-count]').textContent='確認する候補を選んでください。';}});count();
  });
  const form=document.querySelector('[data-bulk-action="review"]');if(!form)return;
  (async()=>{
    const hashes={};let failed=false;
    for(const source of document.querySelectorAll('[data-bulk-source]')){
      const id=source.dataset.bulkSource,status=document.querySelector('[data-bulk-syntax-status="'+id+'"]');
      try{const bytes=Uint8Array.from(atob(source.dataset.sourceBase64),c=>c.charCodeAt(0));window.osvgCheckSyntax(new TextDecoder('utf-8',{fatal:true}).decode(bytes));const hash=await crypto.subtle.digest('SHA-256',bytes);hashes[id]=Array.from(new Uint8Array(hash),v=>v.toString(16).padStart(2,'0')).join('');status.textContent='プログラムの書き方に問題は見つかりませんでした。制作元と内容は別途確認してください。';}
      catch(error){failed=true;status.textContent='プログラムの確認で止まりました: '+String(error.message).slice(0,200);}
    }
    form.elements.bulk_syntax.value=JSON.stringify(hashes);form.querySelector('button').disabled=failed||!Object.keys(hashes).length;
    form.querySelector('[data-bulk-review-status]').textContent=failed?'確認できない作品があります。一覧へ戻り、その作品を選択から外して個別に確認してください。':'書き方の検査が終わりました。制作元・作品ファイル・利用条件の確認後に進めます。';
  })();
})();
