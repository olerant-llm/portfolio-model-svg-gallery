(() => {
  'use strict';
  const form=document.querySelector('[data-owner-action="review"]');
  const source=document.querySelector('[data-osvg-source]');
  if(!form || !source) return;
  (async () => {
    try {
      const bytes=Uint8Array.from(atob(source.dataset.sourceBase64),c=>c.charCodeAt(0));
      window.osvgCheckSyntax(new TextDecoder('utf-8',{fatal:true}).decode(bytes));
      const hash=await crypto.subtle.digest('SHA-256',bytes);
      form.elements.syntax_hash.value=Array.from(new Uint8Array(hash),v=>v.toString(16).padStart(2,'0')).join('');
      form.querySelector('[data-syntax-result]').textContent='プログラムの書き方に問題は見つかりませんでした。制作元と作品ファイルの内容は別途確認してください。';
      form.querySelector('button').disabled=false;
    } catch(error) {
      form.querySelector('[data-syntax-result]').textContent='プログラムの書き方や参照先の確認で止まりました: '+String(error.message).slice(0,200);
    }
  })();
})();
