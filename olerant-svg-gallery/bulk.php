<?php
if (!defined('ABSPATH')) { exit; }

function osvg_bulk_ids($value): array {
    osvg_require(is_array($value) && count($value)>0 && count($value)<=50,'BULK_SELECTION','',400);
    $ids=[];foreach($value as $id) {
        osvg_require(is_string($id) && preg_match('/^[a-f0-9]{32}$/D',$id) && !isset($ids[$id]),'BULK_SELECTION','',400);
        $ids[$id]=true;
    }
    return array_keys($ids);
}
function osvg_bulk_message(string $code): string {
    return ['BULK_SELECTION'=>'1〜50件の候補を選んでください。','BULK_SAME_ITEM'=>'同じ作品の複数の版は、同時に選べません。','BULK_SIZE'=>'作品ファイルの合計が大きいため、選ぶ件数を減らしてください。','OWNER_CONFIRMATION_REQUIRED'=>'内容を確認し、確認欄にチェックしてください。','SYNTAX_REVIEW_REQUIRED'=>'プログラムの確認が終わっていません。画面を開き直してください。','STATE_CONFLICT'=>'状態が変わりました。現在の表示を確認してください。','REVIEW_CHANGED'=>'確認対象が変わりました。画面を開き直してください。','REVIEW_REQUIRED'=>'先に内容確認を終えてください。','DISPLAY_PERMISSION_REQUIRED'=>'展示許可が確認できないため、公開しませんでした。','CATALOG_FULL'=>'公開件数の上限に達しています。','BULK_SIGNATURE'=>'確認画面の有効期限が切れたか、対象が変わりました。画面を開き直してください。'][$code]??'処理できませんでした。理由: '.$code;
}
function osvg_bulk_rows(array $ids): array {
    $rows=[];$items=[];$bytes=0;
    foreach($ids as $id) {
        $r=osvg_receipt($id);osvg_require($r && $r['manifest'] && in_array($r['status'],['needs-review','preview-ready','published'],true),'STATE_CONFLICT','',409);
        osvg_require(!isset($items[$r['item_id']]),'BULK_SAME_ITEM','',400);$items[$r['item_id']]=true;
        $bytes+=(int)$r['bytes'];osvg_require($bytes<=8*1024*1024,'BULK_SIZE','',413);$rows[]=$r;
    }
    return $rows;
}
function osvg_bulk_entry(array $r): array {
    global $wpdb;
    return ['id'=>$r['receipt_id'],'hash'=>$r['payload_sha'],'status'=>$r['status'],
        'published'=>$wpdb->get_var($wpdb->prepare('SELECT published_receipt FROM '.osvg_table('items').' WHERE item_id=%s',$r['item_id']))];
}
function osvg_bulk_apply(string $payload,string $action,array $selected,string $nonce,string $ack,array $syntax): array {
    osvg_require(current_user_can('manage_options'),'OWNER_REQUIRED','',403);
    osvg_require(in_array($action,['review','publish'],true) && strlen($payload)<=24000,'BULK_SELECTION','',400);
    osvg_require(wp_verify_nonce($nonce,'osvg-bulk-'.$action.'-'.OSVG_PLAYER_VERSION.'-'.hash('sha256',$payload)),'BULK_SIGNATURE','',403);
    osvg_require($ack==='yes','OWNER_CONFIRMATION_REQUIRED','',400);
    $entries=json_decode($payload,true,8,JSON_THROW_ON_ERROR);osvg_require(is_array($entries) && count($entries)>0 && count($entries)<=50,'BULK_SELECTION','',400);
    $map=[];foreach($entries as $e) {
        osvg_require(is_array($e) && count($e)===4 && isset($e['id'],$e['hash'],$e['status']) && array_key_exists('published',$e),'BULK_SELECTION','',400);
        osvg_bulk_ids([$e['id']]);osvg_require(!isset($map[$e['id']]) && is_string($e['hash']) && preg_match('/^[a-f0-9]{64}$/D',$e['hash']) && $e['status']===($action==='review'?'needs-review':'preview-ready') && ($e['published']===null || (is_string($e['published']) && preg_match('/^[a-f0-9]{32}$/D',$e['published']))),'BULK_SELECTION','',400);
        $map[$e['id']]=$e;
    }
    $ids=osvg_bulk_ids($selected);foreach($ids as $id)osvg_require(isset($map[$id]),'BULK_SELECTION','',400);
    // Validate the whole selection before changing anything. Same-item versions are ambiguous.
    $seen=[];foreach($ids as $id){$r=osvg_receipt($id);if($r){osvg_require(!isset($seen[$r['item_id']]),'BULK_SAME_ITEM','',400);$seen[$r['item_id']]=true;}}
    $results=[];
    foreach($ids as $id) {
        try {
            $e=$map[$id];
            if($action==='review')osvg_require(isset($syntax[$id]) && is_string($syntax[$id]) && hash_equals(hash('sha256',osvg_file($id,'animation.js')),$syntax[$id]),'SYNTAX_REVIEW_REQUIRED','',400);
            $r=osvg_owner_change($id,$e['hash'],$action,get_current_user_id(),$e);
            $results[]=['id'=>$id,'ok'=>true,'status'=>$r['status']];
        }catch(Throwable $error){$results[]=['id'=>$id,'ok'=>false,'code'=>$error instanceof OSVG_Error?$error->reason:'INTERNAL_ERROR'];}
    }
    return $results;
}
function osvg_bulk_form_start(array $entries,string $action): void {
    $payload=wp_json_encode($entries);
    echo '<form method="post" id="osvg-bulk-'.esc_attr($action).'" data-bulk-action="'.esc_attr($action).'"><input type="hidden" name="osvg_bulk_operation" value="'.esc_attr($action).'"><input type="hidden" name="bulk_payload" value="'.esc_attr($payload).'">';
    wp_nonce_field('osvg-bulk-'.$action.'-'.OSVG_PLAYER_VERSION.'-'.hash('sha256',$payload));
}
function osvg_bulk_page(): void {
    osvg_require(current_user_can('manage_options'),'OWNER_REQUIRED','',403);
    echo '<p><a href="'.esc_url(osvg_admin_url()).'">← 受付一覧へ戻る</a></p><h2>選んだ作品の確認</h2>';
    try {
        $ids=osvg_bulk_ids($_GET['bulk']??[]);
        if(isset($_POST['osvg_bulk_operation'])) {
            try {
                foreach(['bulk_payload','osvg_bulk_operation','_wpnonce','bulk_ack'] as $key)osvg_require(isset($_POST[$key]) && is_string($_POST[$key]),$key==='bulk_ack'?'OWNER_CONFIRMATION_REQUIRED':'BULK_SELECTION','',400);
                $syntaxRaw=$_POST['bulk_syntax']??'{}';osvg_require(is_string($syntaxRaw) && strlen($syntaxRaw)<=6000,'BULK_SELECTION','',400);
                $syntax=json_decode(wp_unslash($syntaxRaw),true,4,JSON_THROW_ON_ERROR);osvg_require(is_array($syntax),'BULK_SELECTION','',400);
                $selected=osvg_bulk_ids($_POST['bulk_selected']??[]);foreach($selected as $id)osvg_require(in_array($id,$ids,true),'BULK_SELECTION','',400);
                $results=osvg_bulk_apply(wp_unslash($_POST['bulk_payload']),$_POST['osvg_bulk_operation'],$selected,$_POST['_wpnonce'],$_POST['bulk_ack'],$syntax);
                $ok=count(array_filter($results,fn($r)=>$r['ok']));echo '<div class="notice notice-info"><p>処理できた作品: '.(int)$ok.'件 ／ 見送った作品: '.(count($results)-$ok).'件</p><ul>';
                foreach($results as $result)echo '<li>'.esc_html($result['id'].'：'.($result['ok']?osvg_admin_status_label($result['status']):osvg_bulk_message($result['code']))).'</li>';
                echo '</ul></div>';
            }catch(Throwable $e){echo '<div class="notice notice-error"><p>'.esc_html(osvg_bulk_message($e instanceof OSVG_Error?$e->reason:'BULK_SELECTION')).'</p></div>';}
        }
        $rows=osvg_bulk_rows($ids);$review=[];$publish=[];
        wp_enqueue_script('osvg-acorn',plugins_url('vendor/acorn.js',__FILE__),[],OSVG_VERSION,true);
        wp_enqueue_script('osvg-syntax',plugins_url('syntax.js',__FILE__),['osvg-acorn'],OSVG_VERSION,true);
        wp_enqueue_script('osvg-bulk',plugins_url('bulk.js',__FILE__),['osvg-syntax'],OSVG_VERSION,true);
        echo '<p>内容確認 → 動作確認 → 選んだものを承認、の順に進めます。'.count($rows).'件を表示しています。</p><h2>1. 制作元・作品ファイル・利用条件の確認</h2><p>信頼できる制作元の作品か、各作品のファイルと利用条件を確認してください。プログラムの書き方の検査だけでは、安全性は保証できません。</p>';
        foreach($rows as $r) {
            $m=osvg_manifest($r);echo '<section data-bulk-item="'.esc_attr($r['receipt_id']).'"><h3>'.esc_html($m['title'].' ／ '.$r['item_id'].' ／ '.$r['version']).'</h3><p>制作元: '.esc_html($r['producer']).' ／ 状態: '.esc_html(osvg_admin_status_label($r['status'])).'</p><p><img width="100" height="100" src="'.esc_url(osvg_asset_url($r,true)).'" alt=""></p><p>'.esc_html($m['summary']).'</p><p>展示許可: '.esc_html(osvg_admin_permission_label($m['license']['displayPermission'])).' ／ 配布許可: '.esc_html(osvg_admin_permission_label($m['license']['downloadPermission'])).'</p><p>'.esc_html($m['license']['notes']).'</p>';
            if($r['status']==='needs-review') {
                $review[]=osvg_bulk_entry($r);
                foreach(['manifest.json','artwork.svg','animation.js','README.txt','LICENSE.txt','THIRD-PARTY-NOTICES.txt'] as $name){$raw=osvg_file($r['receipt_id'],$name);echo '<details class="osvg-source"><summary>'.esc_html($name).'</summary><textarea readonly spellcheck="false" '.($name==='animation.js'?'data-bulk-source="'.esc_attr($r['receipt_id']).'" data-source-base64="'.esc_attr(base64_encode($raw)).'"':'').'>'.esc_textarea($raw).'</textarea></details>';}
                echo '<p data-bulk-syntax-status="'.esc_attr($r['receipt_id']).'">プログラムの書き方を確認しています。</p>';
            }
            echo '<p><a href="'.esc_url(osvg_admin_url($r['receipt_id'])).'" target="_blank" rel="noopener">この作品の詳細を開く</a> ／ <a href="'.esc_url(osvg_asset_url($r,true,'package.zip')).'">原本ZIPを保存</a></p></section><hr>';
        }
        if($review){osvg_bulk_form_start($review,'review');foreach($review as $e)echo '<input type="hidden" name="bulk_selected[]" value="'.esc_attr($e['id']).'">';echo '<input type="hidden" name="bulk_syntax" value="{}"><p><label><input required type="checkbox" name="bulk_ack" value="yes"> 上の確認待ち'.count($review).'件の制作元・作品ファイル・利用条件を確認しました。確認用の画面でプログラムを動かすことを許可します。</label></p><button class="button button-primary" disabled>選んだものの動作確認へ</button><p data-bulk-review-status role="status">プログラムの確認中です。</p></form>';}
        echo '<h2>2. 動作確認と公開の選択</h2><p>動きと操作、注意書きを確認した作品にチェックを入れてください。再生は作品ごとに「再生」を押して行います。</p>';
        echo '<div class="osvg-bulk-previews">';
        foreach($rows as $r) {
            if($r['status']!=='preview-ready')continue;
            $m=osvg_manifest($r);$valid=hash_equals((string)$r['review_hash'],(string)$r['payload_sha']) && $r['player_version']===OSVG_PLAYER_VERSION && $m['license']['assetStatus']==='decided' && $m['license']['displayPermission']==='granted';
            echo '<section><h3>'.esc_html($m['title'].' ／ '.$r['item_id'].' ／ '.$r['version']).'</h3>';
            if($r['player_version']===OSVG_PLAYER_VERSION){echo osvg_player_markup($r,true);}else{echo '<p>再生部品が変わったため、作品の詳細画面で内容を再確認してください。</p>';}
            foreach($m['caveats'] as $c)echo '<p>'.esc_html($c).'</p>';echo '<p>'.esc_html($m['accessibility']['motionNotes']).'</p>';
            if($valid){$publish[]=osvg_bulk_entry($r);echo '<p><label><input type="checkbox" form="osvg-bulk-publish" name="bulk_selected[]" value="'.esc_attr($r['receipt_id']).'"> この作品を公開承認の対象にする</label></p>';}else{echo '<p>内容の再確認または展示許可の確認が必要です。詳細画面から確認してください。</p>';}
            echo '</section>';
        }
        echo '</div>';
        if($publish){echo '<h2>3. 選んだものを承認</h2>';osvg_bulk_form_start($publish,'publish');echo '<p><label><input required type="checkbox" name="bulk_ack" value="yes"> 選んだ作品の動き・操作・注意書きと展示許可を確認しました。この版の公開を承認します。公開中の版があれば切り替えます。</label></p><button class="button button-primary">選んだものを承認</button></form>';}
        if(!$review && !$publish)echo '<p>この画面で承認できる候補はありません。公開済みか、詳細画面での確認が必要です。</p>';
    }catch(Throwable $e){echo '<div class="notice notice-error"><p>'.esc_html(osvg_bulk_message($e instanceof OSVG_Error?$e->reason:'INTERNAL_ERROR')).'</p></div>';}
}
