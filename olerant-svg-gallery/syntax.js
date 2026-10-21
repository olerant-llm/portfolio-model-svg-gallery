/* Parse only: no received source is evaluated by this reviewer. */
(() => {
  'use strict';
  window.osvgCheckSyntax = source => {
    const ast = acorn.parse(source, {ecmaVersion: 2022, sourceType: 'script'});
    const banned = new Set(['eval','Function','fetch','XMLHttpRequest','WebSocket','EventSource','Worker','SharedWorker','importScripts','sendBeacon','localStorage','sessionStorage','indexedDB','serviceWorker','cookie','location','parent','top','opener']);
    const stack=[ast]; let count=0;
    while(stack.length) {
      const node=stack.pop(); if(!node || typeof node!=='object') continue;
      if(++count>100000) throw new Error('JS_COMPLEXITY');
      if(node.type==='ImportExpression' || node.type==='ImportDeclaration' || node.type==='WithStatement') throw new Error('JS_FORBIDDEN_SYNTAX');
      if((node.type==='Identifier' && banned.has(node.name)) || (node.type==='Literal' && typeof node.value==='string' && banned.has(node.value))) throw new Error('JS_FORBIDDEN_REFERENCE');
      for(const key of Object.keys(node)) {
        if(Array.isArray(node[key])) stack.push(...node[key]);
        else if(node[key] && typeof node[key]==='object') stack.push(node[key]);
      }
    }
    return true;
  };
})();
