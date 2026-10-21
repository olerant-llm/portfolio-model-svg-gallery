<?php
if (!defined('ABSPATH')) { exit; }
function osvg_admin_menu(): void { add_menu_page('SVGアニメーション入稿','SVG入稿','manage_options','olerant-svg','osvg_admin_page','dashicons-format-image',57); }
function osvg_admin_url(string $receipt=''): string { return add_query_arg(array_filter(['page'=>'olerant-svg','receipt'=>$receipt]),admin_url('admin.php')); }
function osvg_admin_status_label(string $status): string {
    return ['received'=>'受付済み','validating'=>'ファイルを確認中','rejected'=>'受付できませんでした','needs-review'=>'内容の確認待ち','preview-ready'=>'動作確認・公開承認待ち','published'=>'公開中','superseded'=>'新しい版に切替済み','withdrawn'=>'公開取り下げ済み','discarded'=>'候補消去済み'][$status]??$status;
}
function osvg_admin_permission_label(string $permission): string {
    return ['granted'=>'許可あり','pending'=>'確認待ち','denied'=>'許可なし'][$permission]??$permission;
}
function osvg_action_form(array $r,string $action,string $label,string $ack): string {
    $r['payload_sha']=$r['payload_sha']?:$r['package_sha'];
    ob_start(); ?><?php if($action==='discard'): ?><details><summary>未公開候補を完全に消去</summary><p>作品ファイルを完全に消去します。この操作は元に戻せません。二重受付を防ぐための受付記録は残ります。</p><?php endif; ?><form method="post" action="<?= esc_url(admin_url('admin-post.php')) ?>" class="osvg-owner-action" data-owner-action="<?= esc_attr($action) ?>"><input type="hidden" name="action" value="osvg_action"><input type="hidden" name="operation" value="<?= esc_attr($action) ?>"><input type="hidden" name="receipt" value="<?= esc_attr($r['receipt_id']) ?>"><input type="hidden" name="hash" value="<?= esc_attr($r['payload_sha']) ?>"><?php wp_nonce_field('osvg-'.$action.'-'.$r['receipt_id'].'-'.$r['payload_sha']); ?><p><label><input required type="checkbox" name="ack" value="yes"> <?= esc_html($ack) ?></label></p><?php if($action==='review'): ?><input type="hidden" name="syntax_hash" value=""><p data-syntax-result role="status">プログラムの書き方を確認しています。</p><?php endif; ?><button class="button<?= $action==='discard'?'':' button-primary' ?>" <?= $action==='review'?'disabled':'' ?>><?= esc_html($label) ?></button></form><?php if($action==='discard'): ?></details><?php endif; ?><?php return ob_get_clean();
}
function osvg_admin_action(): void {
    osvg_require(current_user_can('manage_options'),'OWNER_REQUIRED','',403);
    $op=sanitize_key($_POST['operation']??'');
    try {
        if ($op==='link-create' || $op==='link-revoke') {
            check_admin_referer('osvg-links');
            if ($op==='link-create') {
                $new=osvg_create_link(sanitize_text_field(wp_unslash($_POST['producer']??'')));
                $url=add_query_arg('osvg_submit','1',home_url('/')).'#token='.$new['token'];
                osvg_headers('text/html; charset=utf-8');
                echo '<!doctype html><html lang="ja"><meta charset="utf-8"><title>SVG投稿リンク</title><body><h1>投稿リンクを発行しました</h1><p>この画面を閉じる前にコピーしてください。秘密の部分は再表示できません。</p><p><a href="'.esc_url($url).'">'.esc_html($url).'</a></p><p>機械送信用トークン</p><pre>'.esc_html($new['token']).'</pre><a href="'.esc_url(osvg_admin_url()).'">SVG入稿へ戻る</a></body></html>'; exit;
            }
            global $wpdb; osvg_lock();
            try { osvg_db($wpdb->update(osvg_table('links'),['active'=>0],['id'=>sanitize_text_field($_POST['link']??'')])); }
            finally { osvg_unlock(); }
        } else {
            $id=sanitize_text_field($_POST['receipt']??''); $hash=sanitize_text_field($_POST['hash']??'');
            check_admin_referer('osvg-'.$op.'-'.$id.'-'.$hash);
            osvg_require(($_POST['ack']??'')==='yes','OWNER_CONFIRMATION_REQUIRED');
            if ($op==='review') { osvg_require(hash_equals(hash('sha256',osvg_file($id,'animation.js')),(string)($_POST['syntax_hash']??'')),'SYNTAX_REVIEW_REQUIRED'); }
            osvg_owner_change($id,$hash,$op,get_current_user_id());
        }
        wp_safe_redirect(osvg_admin_url($id??'')); exit;
    } catch(Throwable $e) {
        wp_die(esc_html('処理できませんでした。理由: '.($e instanceof OSVG_Error?$e->reason:'INTERNAL_ERROR')), 'SVG入稿',['response'=>$e instanceof OSVG_Error?$e->http:500,'back_link'=>true]);
    }
}
function osvg_admin_page(): void {
    global $wpdb; if (!current_user_can('manage_options')) { return; } osvg_styles();
    echo '<div class="wrap osvg-owner"><h1>SVGアニメーション入稿の確認</h1><p>SVGアニメーションの内容と動作を確認し、管理者が公開を承認します。投稿しただけでは公開されません。</p>';
    if (isset($_GET['bulk']) || isset($_POST['osvg_bulk_operation'])) { osvg_bulk_page(); echo '</div>'; return; }
    $id=isset($_GET['receipt']) && is_string($_GET['receipt'])?sanitize_text_field($_GET['receipt']):'';
    if ($id) {
        $r=osvg_receipt($id); if (!$r) { echo '<p>受付が見つかりません。</p></div>'; return; }
        echo '<p><a href="'.esc_url(osvg_admin_url()).'">← 受付一覧へ戻る</a></p><p>受付ID: <code>'.esc_html($id).'</code> ／ 状態: <strong>'.esc_html(osvg_admin_status_label($r['status'])).'</strong></p>';
        if (!$r['manifest']) {
            echo '<pre>'.esc_html($r['error_json']?:'検査中です。同じZIPの再送で中断した検査を再開できます。').'</pre>';
            if(in_array($r['status'],['received','validating','rejected'],true)) { echo osvg_action_form($r,'discard','候補を消去','この未公開の受付内容を消去します。再送を識別する記録は残します。'); }
            echo '</div>'; return;
        }
        $m=osvg_manifest($r);
        echo '<h2>'.esc_html($m['title']).'</h2><p>'.esc_html($m['summary']).'</p><p><code>'.esc_html($m['id'].' / '.$m['version']).'</code></p><p>確認対象の照合値: <code>'.esc_html($r['payload_sha']).'</code></p>';
        if ($r['status']==='discarded') { echo '<p>候補の内容は消去済みです。二重受付を防ぐ記録は保持しています。</p></div>'; return; }
        echo '<p><img class="osvg-owner-poster" src="'.esc_url(osvg_asset_url($r,true)).'" alt="'.esc_attr($m['title']).'"></p>';
        echo '<h2>1. 制作元・作品ファイル・利用条件の確認</h2><p>制作元: '.esc_html($r['producer']).' ／ 元作品: '.esc_html($m['source']['sourceId']).'</p><p>展示許可: '.esc_html(osvg_admin_permission_label($m['license']['displayPermission'])).' ／ 配布許可: '.esc_html(osvg_admin_permission_label($m['license']['downloadPermission'])).'</p><p>SVGアニメーションには動きをつけるプログラムが含まれるため、再生前に管理者の許可が必要です。信頼できる制作元か、作品ファイルと利用条件の内容を確認してください。</p><p>内容を読み取りにくくしたプログラム、外部への通信、管理画面など別の画面を操作する処理を含む作品は承認しないでください。</p>';
        foreach(['manifest.json','artwork.svg','animation.js','README.txt','LICENSE.txt','THIRD-PARTY-NOTICES.txt'] as $name) {
            $raw=osvg_file($id,$name);
            echo '<details class="osvg-source"><summary>'.esc_html($name).'</summary><textarea readonly spellcheck="false" aria-label="'.esc_attr($name).'" '.($name==='animation.js'?'data-osvg-source data-source-base64="'.esc_attr(base64_encode($raw)).'"':'').'>'.esc_textarea($raw).'</textarea></details>';
        }
        echo '<p><a href="'.esc_url(osvg_asset_url($r,true,'package.zip')).'">原本ZIPを保存</a>（作品の動きを再生せず、提出されたファイルを確認できます）</p>';
        if ($r['status']==='needs-review' || (in_array($r['status'],['preview-ready','published','superseded','withdrawn'],true) && $r['player_version']!==OSVG_PLAYER_VERSION)) {
            wp_enqueue_script('osvg-acorn',plugins_url('vendor/acorn.js',__FILE__),[],OSVG_VERSION,true);
            wp_enqueue_script('osvg-syntax',plugins_url('syntax.js',__FILE__),['osvg-acorn'],OSVG_VERSION,true);
            wp_enqueue_script('osvg-review',plugins_url('review.js',__FILE__),['osvg-syntax'],OSVG_VERSION,true);
            if($r['status']==='published') { echo '<p><strong>再確認後、再公開するまでは、この作品の公開を停止します。</strong></p>'; }
            echo osvg_action_form($r,'review','内容を確認して動作確認へ','この版の制作元・作品ファイル・利用条件を確認しました。確認用の画面でプログラムを動かすことを許可し、動作確認へ進みます。');
        }
        echo '<h2>2. 動作確認</h2>';
        if (in_array($r['status'],['preview-ready','published','superseded','withdrawn'],true)) {
            echo '<p>再生・一時停止・最初からの再生などを試し、動きと操作、注意書きを確認してください。</p>'.osvg_player_markup($r,true);
        } else { echo '<p>上の内容確認を終えて許可すると、この場所で作品の動きを確認できます。</p>'; }
        echo '<h2>3. 公開を承認</h2>';
        if (in_array($r['status'],['preview-ready','published','superseded','withdrawn'],true)) {
            if ($r['status']!=='published') { echo osvg_action_form($r,'publish','この内容で公開を承認','表示中の版で動き・操作・注意書きと展示許可を確認しました。公開中の版があれば、この版へ切り替えます。'); }
            else { echo '<p><a href="'.esc_url(osvg_url($m['id'])).'">公開ページを見る</a></p>'.osvg_action_form($r,'withdraw','公開を取り下げる','この作品を公開一覧と公開ページから取り下げます。'); }
        } else { echo '<p>動作確認を終えてから、この版の公開を承認します。</p>'; }
        if(in_array($r['status'],['needs-review','preview-ready'],true) && !osvg_has_published($id)) { echo '<hr>'.osvg_action_form($r,'discard','候補を消去','この未公開候補の作品ファイルを完全に消去します。元には戻せません。受付IDと照合値の記録は残します。'); }
        echo '</div>'; return;
    }
    $page=max(1,min(100000,(int)($_GET['receipt_page']??1))); $total=(int)$wpdb->get_var('SELECT COUNT(*) FROM '.osvg_table('receipts'));
    $rows=$wpdb->get_results($wpdb->prepare('SELECT * FROM '.osvg_table('receipts').' ORDER BY received_at DESC,receipt_id DESC LIMIT 50 OFFSET %d',($page-1)*50),ARRAY_A);
    wp_enqueue_script('osvg-bulk',plugins_url('bulk.js',__FILE__),[],OSVG_VERSION,true);
    echo '<h2>受付一覧</h2><p>'.$total.'件・50件ずつ表示</p><form method="get" action="'.esc_url(admin_url('admin.php')).'" data-bulk-select><input type="hidden" name="page" value="olerant-svg"><p>確認したい候補にチェックを入れてください。最大50件を同じ画面で確認できます。</p><table class="widefat striped"><thead><tr><th><input type="checkbox" data-bulk-all aria-label="このページの候補をすべて選択"></th><th>画像</th><th>名前 / ID / 版</th><th>カテゴリー・長さ</th><th>受付日時</th><th>状態</th><th>確認</th></tr></thead><tbody>';
    foreach($rows as $r) { $m=$r['manifest']?osvg_manifest($r):null; echo '<tr><td>'.($m && in_array($r['status'],['needs-review','preview-ready'],true)?'<input type="checkbox" name="bulk[]" value="'.esc_attr($r['receipt_id']).'" aria-label="'.esc_attr($m['title'].' '.$r['version'].'を選択').'">':'—').'</td><td>'.($m && $r['status']!=='discarded'?'<img width="80" height="80" src="'.esc_url(osvg_asset_url($r,true)).'" alt="">':'—').'</td><td>'.esc_html($m?$m['title']:$r['receipt_id']).'<br>'.esc_html(($r['item_id']??'').' / '.($r['version']??'')).'</td><td>'.esc_html($m?$m['category'].' / '.$m['playback']['durationSeconds'].'秒':'—').'</td><td>'.esc_html($r['received_at']).'</td><td>'.esc_html(osvg_admin_status_label($r['status'])).'</td><td><a href="'.esc_url(osvg_admin_url($r['receipt_id'])).'">内容を確認</a></td></tr>'; }
    echo '</tbody></table><p><button class="button button-primary">選んだものをまとめて確認</button> <span data-bulk-count>0件選択</span></p></form><p>';
    if($page>1) { echo '<a class="button" href="'.esc_url(add_query_arg('receipt_page',$page-1,osvg_admin_url())).'">前の50件</a> '; }
    if($page*50<$total) { echo '<a class="button" href="'.esc_url(add_query_arg('receipt_page',$page+1,osvg_admin_url())).'">次の50件</a>'; }
    echo '</p><h2>管理者としてZIPを送る</h2><form data-osvg-upload data-config="'.esc_attr(wp_json_encode(['url'=>rest_url('olerant-svg/v1/owner/upload'),'nonce'=>wp_create_nonce('wp_rest')])).'"><input type="file" name="zip" accept=".zip" required><button class="button">非公開で受け付ける</button></form><pre data-osvg-result role="status"></pre>';
    wp_enqueue_script('osvg-upload',plugins_url('upload.js',__FILE__),[],OSVG_VERSION,true);
    echo '<h2>制作元の投稿リンク</h2><p>同じ制作元IDで再発行すると、その制作元の過去の受付も確認できます。トークンは発行時の一度だけ表示します。</p><form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="osvg_action"><input type="hidden" name="operation" value="link-create">'; wp_nonce_field('osvg-links');
    echo '<label>制作元ID <input name="producer" placeholder="dots" pattern="[a-z0-9][a-z0-9-]{2,99}" required></label> <button class="button">投稿リンクを発行</button></form>';
    $links=$wpdb->get_results('SELECT id,producer,active,created_at FROM '.osvg_table('links').' ORDER BY created_at DESC LIMIT 100',ARRAY_A);
    foreach($links as $l) { echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><p>'.esc_html($l['producer'].' / '.$l['created_at'].' / '.($l['active']?'有効':'停止済み')); if($l['active']) { echo '<input type="hidden" name="action" value="osvg_action"><input type="hidden" name="operation" value="link-revoke"><input type="hidden" name="link" value="'.esc_attr($l['id']).'">'; wp_nonce_field('osvg-links'); echo ' <button class="button">このリンクを停止</button>'; } echo '</p></form>'; }
    echo '</div>';
}
