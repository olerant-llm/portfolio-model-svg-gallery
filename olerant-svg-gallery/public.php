<?php
if (!defined('ABSPATH')) { exit; }
function osvg_headers(string $type): void {
    nocache_headers(); header('Cache-Control: private, no-store, max-age=0'); header('Content-Type: '.$type);
    header('X-Content-Type-Options: nosniff'); header('Referrer-Policy: no-referrer');
}
function osvg_origin(): string {
    $u=wp_parse_url(home_url('/')); return $u['scheme'].'://'.$u['host'].(isset($u['port'])?':'.$u['port']:'');
}
function osvg_binary_route(): void {
    if (isset($_GET['osvg_player'])) {
        $nonce=base64_encode(random_bytes(24)); osvg_headers('text/html; charset=utf-8');
        header("Content-Security-Policy: sandbox allow-scripts; default-src 'none'; script-src 'nonce-$nonce'; style-src 'unsafe-inline'; img-src data:; connect-src 'none'; frame-src 'none'; worker-src 'none'; object-src 'none'; base-uri 'none'; form-action 'none'; frame-ancestors ".osvg_origin());
        header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=()');
        $pins=json_decode(file_get_contents(__DIR__.'/vendor/pins.json'),true);
        $parser=file_get_contents(__DIR__.'/vendor/acorn.js');
        if (!hash_equals($pins['acorn']['sha256'],hash('sha256',$parser))) { status_header(503); exit('Player unavailable'); }
        // Only trusted host source occurs in this response. Artwork arrives after owner authorization.
        echo '<!doctype html><html lang="ja"><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>アニメーション再生</title><style>html,body{margin:0;width:100%;height:100%;overflow:hidden}#stage{width:100%;height:100%;display:grid;place-items:center}svg{width:100%;height:100%;max-width:100%;max-height:100%}</style><body><div id="stage"></div>';
        echo '<script nonce="'.esc_attr($nonce).'">window.OSVG_HOST='.wp_json_encode(['origin'=>osvg_origin(),'nonce'=>$nonce],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT).';</script>';
        echo '<script nonce="'.esc_attr($nonce).'">'.str_ireplace('</script','<\/script',$parser).'</script>';
        foreach(['syntax.js','frame.js'] as $name) { echo '<script nonce="'.esc_attr($nonce).'">'.str_ireplace('</script','<\/script',file_get_contents(__DIR__.'/'.$name)).'</script>'; }
        exit('</body></html>');
    }
    if (isset($_GET['osvg_submit'])) { osvg_headers('text/html; charset=utf-8'); osvg_submission_page(); exit; }
    if (!isset($_GET['osvg_asset'])) { return; }
    try {
        $id=is_string($_GET['osvg_asset'])?$_GET['osvg_asset']:''; $r=osvg_receipt($id);
        $owner=current_user_can('manage_options') && wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce']??'')),'osvg-asset-'.$id);
        $kind=is_string($_GET['kind']??null)?$_GET['kind']:'poster';
        $download=$r && $kind==='download' && osvg_public($r) && osvg_manifest($r)['license']['assetStatus']==='decided' && osvg_manifest($r)['license']['downloadPermission']==='granted';
        osvg_require($r && ($owner || ($kind==='poster' && osvg_public($r)) || $download),'NOT_FOUND','',404);
        if ($kind==='poster') { $raw=osvg_file($id,'safe-poster.png'); osvg_headers('image/png'); }
        elseif($kind==='download' && $download) {
            $raw=osvg_file($id,'package.zip'); osvg_headers('application/zip');
            header('Content-Disposition: attachment; filename="'.$r['item_id'].'-'.$r['version'].'.zip"');
            header("Content-Security-Policy: sandbox; default-src 'none'");
        }
        else {
            osvg_require($owner && in_array($kind,['package.zip','manifest.json','artwork.svg','animation.js','README.txt','LICENSE.txt','THIRD-PARTY-NOTICES.txt'],true),'NOT_FOUND','',404);
            $raw=osvg_file($id,$kind); osvg_headers('application/octet-stream');
            header('Content-Disposition: attachment; filename="'.$kind.'"'); header("Content-Security-Policy: sandbox; default-src 'none'");
        }
        echo $raw; exit;
    } catch(Throwable $e) { osvg_headers('text/plain; charset=utf-8'); status_header($e instanceof OSVG_Error?$e->http:500); exit('素材を取得できませんでした。'); }
}
function osvg_asset_url(array $r,bool $owner=false,string $kind='poster'): string {
    $args=['osvg_asset'=>$r['receipt_id'],'kind'=>$kind];
    if ($owner) { $args['_wpnonce']=wp_create_nonce('osvg-asset-'.$r['receipt_id']); }
    return add_query_arg($args,home_url('/'));
}
function osvg_styles(): void { wp_enqueue_style('olerant-svg',plugins_url('gallery.css',__FILE__),[],OSVG_VERSION); }
function osvg_catalog(): string {
    global $wpdb; osvg_styles();
    if (isset($_GET['animation']) && is_string($_GET['animation'])) { return osvg_detail(['id'=>sanitize_text_field(wp_unslash($_GET['animation']))]); }
    $query=isset($_GET['svg_q']) && is_string($_GET['svg_q'])?mb_substr(sanitize_text_field(wp_unslash($_GET['svg_q'])),0,100):'';
    $category=isset($_GET['svg_category']) && is_string($_GET['svg_category'])?mb_substr(sanitize_text_field(wp_unslash($_GET['svg_category'])),0,100):'';
    $page=max(1,min(100000,(int)($_GET['svg_page']??1)));
    // The DB filters all items; PHP only reads at most 16 manifests, never artwork bytes.
    $per_page=16;
    $from=' FROM '.osvg_table('items').' i JOIN '.osvg_table('receipts')." r ON r.receipt_id=i.published_receipt WHERE r.status='published'";
    $all=(int)$wpdb->get_var('SELECT COUNT(*)'.$from);
    $categories=array_fill_keys($wpdb->get_col("SELECT DISTINCT JSON_UNQUOTE(JSON_EXTRACT(r.manifest,'$.category'))".$from.' LIMIT 5000'),true);
    $filtered=$from; $values=[];
    if($category!=='') { $filtered.=" AND JSON_UNQUOTE(JSON_EXTRACT(r.manifest,'$.category'))=%s"; $values[]=$category; }
    if($query!=='') {
        $filtered.=" AND CONCAT_WS(' ', JSON_UNQUOTE(JSON_EXTRACT(r.manifest,'$.title')), JSON_UNQUOTE(JSON_EXTRACT(r.manifest,'$.summary')), r.item_id, JSON_UNQUOTE(JSON_EXTRACT(r.manifest,'$.category')), JSON_EXTRACT(r.manifest,'$.tags')) LIKE %s";
        $values[]='%'.$wpdb->esc_like($query).'%';
    }
    $total=(int)$wpdb->get_var($values?$wpdb->prepare('SELECT COUNT(*)'.$filtered,...$values):'SELECT COUNT(*)'.$filtered);
    $pages=max(1,(int)ceil($total/$per_page)); $page=min($page,$pages);
    $cards=$wpdb->get_results($wpdb->prepare('SELECT r.*'.$filtered.' ORDER BY r.received_at DESC,r.receipt_id DESC LIMIT %d OFFSET %d',...array_merge($values,[$per_page,($page-1)*$per_page])),ARRAY_A);
    $start=$total?($page-1)*$per_page+1:0;$end=min($page*$per_page,$total);
    ob_start(); ?>
    <div class="osvg osvg-catalog"><header class="osvg-intro"><p class="osvg-eyebrow">OLERANT / SVG ANIMATION COLLECTION</p><h2>伝えたいことに、ひとつの動き。</h2><p>仕事の合図も、小さな達成も。場面に合うアニメーションを探す。</p></header>
    <form class="osvg-filters" method="get" action="<?= esc_url(home_url('/svg-animations/')) ?>"><label>名前・タグで探す<input name="svg_q" type="search" value="<?= esc_attr($query) ?>" placeholder="例：達成、支払い、確認"></label><label>カテゴリー<select name="svg_category"><option value="">すべて</option><?php foreach(array_keys($categories) as $c): ?><option <?= selected($category,$c,false) ?> value="<?= esc_attr($c) ?>"><?= esc_html($c) ?></option><?php endforeach; ?></select></label><button>この条件で探す</button><a href="<?= esc_url(home_url('/svg-animations/')) ?>">条件をクリア</a></form>
    <p class="osvg-count">全<?= $total ?>件中、<?= $start ?>〜<?= $end ?>件を表示<?php if($total!==$all): ?>（掲載全<?= $all ?>件）<?php endif; ?></p><div class="osvg-cards">
    <?php foreach($cards as $r): $m=osvg_manifest($r); ?><article class="osvg-card"><a href="<?= esc_url(osvg_url($m['id'])) ?>"><div class="osvg-card-poster"><img loading="lazy" width="512" height="512" src="<?= esc_url(osvg_asset_url($r)) ?>" alt="<?= esc_attr($m['title']) ?>"></div><div class="osvg-card-info"><span class="osvg-category"><?= esc_html($m['category']) ?></span><h3><?= esc_html($m['title']) ?></h3><p><?= esc_html($m['summary']) ?></p><div class="osvg-card-bottom"><span><?= esc_html($m['playback']['durationSeconds']) ?>秒</span><span>動きを見る ↗</span></div></div></a></article><?php endforeach; ?></div>
    <?php if (!$cards): ?><div class="osvg-empty"><h3><?= $all?'条件に合う作品がありません':'アニメーションを準備しています' ?></h3><p><?= $all?'別の言葉やカテゴリーで探してみてください。':'確認が済んだ作品から、ここに並びます。' ?></p></div><?php endif; ?>
    <?php if($total): ?><nav class="osvg-pager" aria-label="作品一覧のページ"><?php if($page>1): ?><a rel="prev" href="<?= esc_url(add_query_arg(['svg_page'=>$page-1,'svg_q'=>$query,'svg_category'=>$category],home_url('/svg-animations/'))) ?>">← 前へ</a><?php else: ?><span aria-disabled="true">← 前へ</span><?php endif; ?><span aria-current="page"><?= $page ?> / <?= $pages ?>ページ</span><?php if($page<$pages): ?><a rel="next" href="<?= esc_url(add_query_arg(['svg_page'=>$page+1,'svg_q'=>$query,'svg_category'=>$category],home_url('/svg-animations/'))) ?>">次へ →</a><?php else: ?><span aria-disabled="true">次へ →</span><?php endif; ?></nav><?php endif; ?>
    <section class="osvg-note"><h3>動きは、見たいときだけ。</h3><p>作品のページで再生を押すと、ひとつずつ動きを確認できます。端末で「動きを減らす」が選ばれている場合は、静止画を表示します。</p><p>利用条件は作品ごとの案内をご確認ください。</p></section></div>
    <?php return ob_get_clean();
}
function osvg_player_markup(array $r,bool $owner=false): string {
    $m=osvg_manifest($r); osvg_styles(); wp_enqueue_script('osvg-player',plugins_url('player.js',__FILE__),[],OSVG_VERSION,true);
    $config=['receipt'=>$r['receipt_id'],'id'=>$m['id'],'version'=>$m['version'],'payloadSha256'=>$r['payload_sha'],
        'url'=>rest_url('olerant-svg/v1/items/'.$r['receipt_id'].'/playback'),'frame'=>add_query_arg('osvg_player','1',home_url('/')),
        'nonce'=>$owner?wp_create_nonce('wp_rest'):'','duration'=>$m['playback']['durationSeconds'],'loopMode'=>$m['playback']['loopMode']];
    ob_start(); ?><div class="osvg-player" data-osvg-player data-config="<?= esc_attr(wp_json_encode($config)) ?>"><div class="osvg-stage"><img data-poster src="<?= esc_url(osvg_asset_url($r,$owner)) ?>" width="512" height="512" alt="<?= esc_attr($m['accessibility']['description']) ?>"><div data-frame-slot></div></div><div class="osvg-controls"><button type="button" data-command="play">再生</button><button type="button" data-command="pause" disabled>一時停止</button><button type="button" data-command="replay">もう一度</button><label>速さ<select data-speed><option value="0.5">0.5倍</option><option value="1" selected>1倍</option><option value="2">2倍</option></select></label><label><input type="checkbox" data-loop <?= $m['playback']['loopMode']==='none'?'disabled':'' ?>>繰り返す</label><label>背景<select data-background><option value="light">明るい</option><option value="dark">暗い</option></select></label></div><label class="osvg-seek">再生位置<input data-seek type="range" min="0" max="<?= esc_attr($m['playback']['durationSeconds']) ?>" step="0.01" value="0" disabled></label><p data-player-status role="status" aria-live="polite">再生を押すと動きます。</p></div><?php return ob_get_clean();
}
function osvg_detail($args=[]): string {
    global $wpdb; osvg_styles(); $id=(string)($args['id']??'');
    $r=$wpdb->get_row($wpdb->prepare('SELECT r.* FROM '.osvg_table('items').' i JOIN '.osvg_table('receipts')." r ON i.published_receipt=r.receipt_id WHERE i.item_id=%s AND r.status='published'",$id),ARRAY_A);
    if (!$r) { return '<div class="osvg osvg-empty"><p>この作品は現在公開されていません。</p><a href="'.esc_url(home_url('/svg-animations/')).'">一覧へ戻る</a></div>'; }
    $m=osvg_manifest($r); ob_start(); ?><article class="osvg osvg-detail"><a class="osvg-back" href="<?= esc_url(home_url('/svg-animations/')) ?>">← アニメーション一覧</a><header class="osvg-intro"><p class="osvg-eyebrow"><?= esc_html($m['category']) ?></p><h2><?= esc_html($m['title']) ?></h2><p><?= esc_html($m['summary']) ?></p></header><div class="osvg-detail-grid"><div><?= osvg_player_markup($r) ?></div><div class="osvg-description"><h3>この動きで伝えること</h3><p><?= esc_html($m['meaning']) ?></p><h3>動きの流れ</h3><p><?= esc_html($m['sequence']) ?></p><dl><dt>長さ</dt><dd><?= esc_html($m['playback']['durationSeconds']) ?>秒</dd><dt>目安の表示幅</dt><dd><?= esc_html($m['artwork']['recommendedCssWidth']) ?>px</dd><dt>版</dt><dd><?= esc_html($m['version']) ?></dd></dl><p class="osvg-tags"><?php foreach($m['tags'] as $tag): ?><span><?= esc_html($tag) ?></span><?php endforeach; ?></p></div></div><section class="osvg-note"><h3>使う前に</h3><?php foreach($m['caveats'] as $c): ?><p><?= esc_html($c) ?></p><?php endforeach; ?><p><?= esc_html($m['accessibility']['motionNotes']) ?></p><h3>利用条件</h3><p><?= esc_html($m['license']['assetLicenseName']) ?></p><p><?= esc_html($m['license']['notes']) ?></p><?php if($m['license']['assetStatus']==='decided' && $m['license']['downloadPermission']==='granted'): ?><p><a class="osvg-download" href="<?= esc_url(osvg_asset_url($r,false,'download')) ?>">この作品のZIPをダウンロード</a></p><p>動かすための部品と利用条件は、ZIP内のREADMEとLICENSEをご確認ください。</p><?php else: ?><p>この作品のダウンロードは現在提供していません。</p><?php endif; ?></section></article><?php return ob_get_clean();
}
function osvg_submission_page(): void {
    $config=['url'=>rest_url('olerant-svg/v1/submissions')];
    echo '<!doctype html><html lang="ja"><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>SVGアニメーション入稿</title><link rel="stylesheet" href="'.esc_url(plugins_url('gallery.css',__FILE__)).'"><body><main class="osvg osvg-submit"><p class="osvg-eyebrow">OLERANT / SVG ANIMATION</p><h1>アニメーションを送る</h1><p>1作品ずつ、指定の7ファイルをまとめたZIPを送ってください。送信だけでは公開されません。</p><form data-osvg-upload data-config="'.esc_attr(wp_json_encode($config)).'"><label>作品ZIP（5MBまで）<input type="file" name="zip" accept=".zip" required></label><button>この作品を送る</button></form><button type="button" data-osvg-status>受付状況を確認</button><pre data-osvg-result role="status"></pre></main><script src="'.esc_url(plugins_url('upload.js',__FILE__)).'"></script></body></html>';
}
