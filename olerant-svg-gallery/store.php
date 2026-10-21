<?php
if (!defined('ABSPATH')) { exit; }
function osvg_table(string $name): string {
    global $wpdb; osvg_require(in_array($name,['links','receipts','files','items','audit'],true),'TABLE', '',500);
    return $wpdb->prefix.'osvg_'.$name;
}
function osvg_db($result) { global $wpdb; osvg_require($result!==false,'STORAGE_ERROR','',503); return $result; }
function osvg_install(): void {
    global $wpdb; require_once ABSPATH.'wp-admin/includes/upgrade.php';
    $c=$wpdb->get_charset_collate();
    $queries=[
    'links'=>"id varchar(32) NOT NULL, producer varchar(100) NOT NULL, token_hash char(64) NOT NULL, active tinyint NOT NULL DEFAULT 1, last_upload bigint NOT NULL DEFAULT 0, created_at varchar(32) NOT NULL, PRIMARY KEY  (id), UNIQUE KEY token_hash (token_hash)",
    'receipts'=>"receipt_id varchar(32) NOT NULL, link_id varchar(32) NOT NULL, producer varchar(100) NOT NULL, item_id varchar(100) DEFAULT NULL, version varchar(32) DEFAULT NULL, package_sha char(64) NOT NULL, payload_sha char(64) DEFAULT NULL, status varchar(24) NOT NULL, received_at varchar(32) NOT NULL, updated_at varchar(32) NOT NULL, manifest longtext DEFAULT NULL, error_json text DEFAULT NULL, review_hash char(64) DEFAULT NULL, review_actor bigint DEFAULT NULL, player_version varchar(32) DEFAULT NULL, bytes bigint NOT NULL DEFAULT 0, PRIMARY KEY  (receipt_id), UNIQUE KEY item_version (item_id,version), KEY producer_time (producer,received_at), KEY producer_zip (producer,package_sha), KEY payload_sha (payload_sha), KEY status (status)",
    'files'=>"receipt_id varchar(32) NOT NULL, name varchar(64) NOT NULL, data longblob NOT NULL, sha char(64) NOT NULL, PRIMARY KEY  (receipt_id,name)",
    'items'=>"item_id varchar(100) NOT NULL, producer varchar(100) NOT NULL, published_receipt varchar(32) DEFAULT NULL, PRIMARY KEY  (item_id), KEY published_receipt (published_receipt)",
    'audit'=>"id bigint unsigned NOT NULL AUTO_INCREMENT, receipt_id varchar(32) NOT NULL, action varchar(32) NOT NULL, actor bigint NOT NULL, payload_sha char(64) NOT NULL, created_at varchar(32) NOT NULL, PRIMARY KEY  (id), KEY receipt_id (receipt_id)"
    ];
    foreach($queries as $name=>$fields) { dbDelta('CREATE TABLE '.osvg_table($name)." ($fields) ENGINE=InnoDB $c;"); }
    foreach(array_keys($queries) as $name) {
        $engine=$wpdb->get_var($wpdb->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',osvg_table($name)));
        osvg_require(strcasecmp((string)$engine,'InnoDB')===0,'STORAGE_ENGINE','',503);
    }
    update_option('osvg_schema_version',OSVG_VERSION,false);
}
function osvg_now(): string { return gmdate('Y-m-d\TH:i:s\Z'); }
function osvg_id(): string { return bin2hex(random_bytes(16)); }
function osvg_lock(): void {
    global $wpdb; $name='osvg:'.substr(hash('sha256',DB_NAME.':'.$wpdb->prefix),0,48);
    osvg_require((string)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,10)',$name))==='1','BUSY','',503);
}
function osvg_unlock(): void {
    global $wpdb; $name='osvg:'.substr(hash('sha256',DB_NAME.':'.$wpdb->prefix),0,48);
    $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$name));
}
function osvg_receipt(string $id): ?array {
    global $wpdb; return $wpdb->get_row($wpdb->prepare('SELECT * FROM '.osvg_table('receipts').' WHERE receipt_id=%s',$id),ARRAY_A)?:null;
}
function osvg_file(string $receipt, string $name): string {
    global $wpdb; $f=$wpdb->get_row($wpdb->prepare('SELECT data,sha FROM '.osvg_table('files').' WHERE receipt_id=%s AND name=%s',$receipt,$name),ARRAY_A);
    osvg_require($f && hash_equals($f['sha'],hash('sha256',$f['data'])),'FILE_UNAVAILABLE',$name,503); return $f['data'];
}
function osvg_save_file(string $receipt,string $name,string $raw): void {
    global $wpdb; osvg_db($wpdb->insert(osvg_table('files'),['receipt_id'=>$receipt,'name'=>$name,'data'=>$raw,'sha'=>hash('sha256',$raw)]));
}
function osvg_manifest(array $receipt): array { return json_decode($receipt['manifest'],true,32,JSON_THROW_ON_ERROR); }
function osvg_public(array $receipt): bool {
    global $wpdb;
    return $receipt['status']==='published' && $wpdb->get_var($wpdb->prepare('SELECT published_receipt FROM '.osvg_table('items').' WHERE item_id=%s',$receipt['item_id']))===$receipt['receipt_id'];
}
function osvg_url(string $item): string { return add_query_arg('animation',$item,home_url('/svg-animations/')); }
function osvg_receipt_json(array $r, bool $duplicate=false): array {
    $out=['receiptId'=>$r['receipt_id'],'id'=>$r['item_id'],'version'=>$r['version'],
        'packageSha256'=>$r['package_sha'],'payloadSha256'=>$r['payload_sha'],'status'=>$r['status'],
        'receivedAt'=>$r['received_at'],'updatedAt'=>$r['updated_at'],'duplicate'=>$duplicate,
        'message'=>$duplicate?'受付済みです。再登録していません。':($r['status']==='needs-review'?'受け付けました。管理者の確認待ちです。':'受付状況を取得しました。'),
        'reasonCodes'=>[],'validation'=>['archive'=>$r['payload_sha']?'passed':'not-passed','javascriptSyntax'=>$r['review_hash']?'owner-reviewed':'pending-owner-review']];
    if ($r['error_json']) { $e=json_decode($r['error_json'],true); $out['reasonCodes']=[$e['code']]; $out['errors']=[$e]; }
    if (osvg_public($r)) { $out['publicUrl']=osvg_url($r['item_id']); }
    return $out;
}
function osvg_create_link(string $producer): array {
    global $wpdb; osvg_require(preg_match('/^[a-z0-9][a-z0-9-]{2,99}$/D',$producer),'PRODUCER_ID');
    $token=bin2hex(random_bytes(32)); $id=osvg_id();
    osvg_db($wpdb->insert(osvg_table('links'),['id'=>$id,'producer'=>$producer,'token_hash'=>hash('sha256',$token),'created_at'=>osvg_now()]));
    return ['id'=>$id,'token'=>$token];
}
function osvg_capacity(string $producer): void {
    global $wpdb;
    $free=@disk_free_space(ABSPATH);
    osvg_require(is_numeric($free) && $free>=512*1024*1024,'LOW_STORAGE','',507);
    $all=(int)$wpdb->get_var('SELECT COALESCE(SUM(bytes),0) FROM '.osvg_table('receipts'));
    $own=(int)$wpdb->get_var($wpdb->prepare('SELECT COALESCE(SUM(bytes),0) FROM '.osvg_table('receipts').' WHERE producer=%s',$producer));
    osvg_require($all<20*1024*1024*1024 && $own<10*1024*1024*1024,'STORAGE_QUOTA','',507);
    $count=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.osvg_table('receipts').' WHERE producer=%s',$producer));
    osvg_require($count<50000,'RECEIPT_QUOTA','',507);
}
function osvg_receive(string $path,array $link): array {
    global $wpdb; osvg_require(is_file($path) && filesize($path)>0 && filesize($path)<=5*1024*1024,'ZIP_SIZE','zip',413);
    $sha=hash_file('sha256',$path); $table=osvg_table('receipts'); $receipt=null; osvg_lock();
    try {
        // Lock serializes intake and all owner changes. Re-check revocation inside the lock.
        $active=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.osvg_table('links').' WHERE id=%s AND active=1',$link['id']),ARRAY_A);
        osvg_require($active,'TOKEN_INVALID','',401);
        $existing=$wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE producer=%s AND package_sha=%s ORDER BY received_at LIMIT 1",$link['producer'],$sha),ARRAY_A);
        if ($existing && !in_array($existing['status'],['received','validating'],true)) {
            if($existing['status']==='duplicate') {
                $alias=json_decode($existing['error_json'],true); $existing=osvg_receipt($alias['existingReceiptId']??'');
                osvg_require($existing && $existing['producer']===$link['producer'],'RECEIPT_UNAVAILABLE','',503);
            }
            return ['data'=>osvg_receipt_json($existing,true),'http'=>200];
        }
        if (!$existing) {
            osvg_require(time()-(int)$active['last_upload']>=2,'RATE_LIMIT','',429);
            osvg_capacity($link['producer']);
            osvg_db($wpdb->update(osvg_table('links'),['last_upload'=>time()],['id'=>$link['id']]));
            $receipt=osvg_id();
            osvg_db($wpdb->query('START TRANSACTION'));
            // WordPress reserves link_id as an integer field; our link IDs are opaque strings.
            osvg_db($wpdb->insert($table,['receipt_id'=>$receipt,'link_id'=>$link['id'],'producer'=>$link['producer'],'package_sha'=>$sha,'status'=>'received','received_at'=>osvg_now(),'updated_at'=>osvg_now(),'bytes'=>filesize($path)],['%s','%s','%s','%s','%s','%s','%s','%d']));
            osvg_save_file($receipt,'package.zip',file_get_contents($path));
            osvg_db($wpdb->query('COMMIT'));
        } else { $receipt=$existing['receipt_id']; }
        osvg_db($wpdb->update($table,['status'=>'validating','updated_at'=>osvg_now()],['receipt_id'=>$receipt]));
        try {
            $v=osvg_validate_zip($path); $m=$v['manifest'];
            $item=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.osvg_table('items').' WHERE item_id=%s',$m['id']),ARRAY_A);
            osvg_require(!$item || $item['producer']===$link['producer'],'ITEM_OWNERSHIP','id',403);
            $old=$wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE item_id=%s AND version=%s",$m['id'],$m['version']),ARRAY_A);
            if ($old) {
                osvg_require(hash_equals($old['payload_sha'],$v['payloadSha256']),'VERSION_CONTENT_CONFLICT','version',409);
                // A recompressed delivery refers to the original receipt; retain a tiny audit tombstone.
                osvg_db($wpdb->query('START TRANSACTION'));
                osvg_db($wpdb->delete(osvg_table('files'),['receipt_id'=>$receipt]));
                osvg_db($wpdb->update($table,['status'=>'duplicate','error_json'=>wp_json_encode(['code'=>'DUPLICATE','field'=>'','existingReceiptId'=>$old['receipt_id']]),'bytes'=>0,'updated_at'=>osvg_now()],['receipt_id'=>$receipt]));
                osvg_db($wpdb->query('COMMIT'));
                return ['data'=>osvg_receipt_json($old,true),'http'=>200];
            }
            if ($item) {
                $versions=$wpdb->get_col($wpdb->prepare("SELECT version FROM $table WHERE item_id=%s AND version IS NOT NULL LIMIT 100",$m['id']));
                osvg_require(count($versions)<100,'VERSION_QUOTA','version',409);
                foreach($versions as $version) { osvg_require(version_compare($m['version'],$version,'>'),'VERSION_NOT_NEWER','version',409); }
            }
            osvg_db($wpdb->query('START TRANSACTION'));
            if (!$item) { osvg_db($wpdb->insert(osvg_table('items'),['item_id'=>$m['id'],'producer'=>$link['producer']])); }
            $bytes=filesize($path);
            foreach($v['files'] as $name=>$raw) { osvg_save_file($receipt,$name,$raw); $bytes+=strlen($raw); }
            osvg_save_file($receipt,'safe-poster.png',$v['poster']); $bytes+=strlen($v['poster']);
            osvg_db($wpdb->update($table,['item_id'=>$m['id'],'version'=>$m['version'],'payload_sha'=>$v['payloadSha256'],'manifest'=>wp_json_encode($m),'status'=>'needs-review','bytes'=>$bytes,'updated_at'=>osvg_now()],['receipt_id'=>$receipt]));
            osvg_db($wpdb->query('COMMIT'));
            return ['data'=>osvg_receipt_json(osvg_receipt($receipt)),'http'=>201];
        } catch (OSVG_Error $e) {
            $wpdb->query('ROLLBACK');
            osvg_db($wpdb->update($table,['status'=>'rejected','error_json'=>wp_json_encode(['code'=>$e->reason,'field'=>$e->field]),'updated_at'=>osvg_now()],['receipt_id'=>$receipt]));
            return ['data'=>osvg_receipt_json(osvg_receipt($receipt)),'http'=>$e->http];
        }
    } finally { $wpdb->query('ROLLBACK'); osvg_unlock(); }
}
function osvg_owner_change(string $id,string $hash,string $action,int $actor,?array $expected=null): array {
    global $wpdb; osvg_require($actor>0 && user_can($actor,'manage_options'),'OWNER_REQUIRED','',403); osvg_lock();
    try {
        $r=osvg_receipt($id); osvg_require($r && hash_equals($r['payload_sha']?:$r['package_sha'],$hash),'REVIEW_CHANGED','',409);
        if ($expected!==null) {
            $published=$wpdb->get_var($wpdb->prepare('SELECT published_receipt FROM '.osvg_table('items').' WHERE item_id=%s',$r['item_id']));
            osvg_require($r['status']===$expected['status'] && $published===$expected['published'],'STATE_CONFLICT','',409);
        }
        $m=$r['manifest']?osvg_manifest($r):null; $status=$r['status']; $changes=['updated_at'=>osvg_now()];
        osvg_db($wpdb->query('START TRANSACTION'));
        if ($action==='review') {
            osvg_require($status==='needs-review' || (in_array($status,['preview-ready','published','superseded','withdrawn'],true) && $r['player_version']!==OSVG_PLAYER_VERSION),'STATE_CONFLICT','',409);
            // The producer's sourceReviewStatus is provenance, not owner authorization.
            // This explicit owner action records approval for these exact bytes below;
            // leave the original manifest (including "unverified") unchanged.
            osvg_runtime();
            if($status==='published') { osvg_db($wpdb->update(osvg_table('items'),['published_receipt'=>null],['item_id'=>$r['item_id']])); }
            $changes+=['status'=>'preview-ready','review_hash'=>$hash,'review_actor'=>$actor,'player_version'=>OSVG_PLAYER_VERSION];
        } elseif ($action==='publish') {
            osvg_require(in_array($status,['preview-ready','superseded','withdrawn'],true) && hash_equals((string)$r['review_hash'],$hash) && $r['player_version']===OSVG_PLAYER_VERSION,'REVIEW_REQUIRED','',409);
            osvg_require($m['license']['assetStatus']==='decided' && $m['license']['displayPermission']==='granted','DISPLAY_PERMISSION_REQUIRED'); osvg_runtime();
            $old=$wpdb->get_var($wpdb->prepare('SELECT published_receipt FROM '.osvg_table('items').' WHERE item_id=%s',$r['item_id']));
            $count=(int)$wpdb->get_var('SELECT COUNT(*) FROM '.osvg_table('items').' WHERE published_receipt IS NOT NULL');
            osvg_require($old || $count<5000,'CATALOG_FULL','',409);
            if ($old && $old!==$id) { osvg_db($wpdb->update(osvg_table('receipts'),['status'=>'superseded','updated_at'=>osvg_now()],['receipt_id'=>$old])); }
            osvg_db($wpdb->update(osvg_table('items'),['published_receipt'=>$id],['item_id'=>$r['item_id']])); $changes['status']='published';
        } elseif ($action==='withdraw') {
            osvg_require(osvg_public($r),'STATE_CONFLICT','',409);
            osvg_db($wpdb->update(osvg_table('items'),['published_receipt'=>null],['item_id'=>$r['item_id']])); $changes['status']='withdrawn';
        } elseif ($action==='discard') {
            osvg_require(!osvg_public($r) && in_array($status,['received','validating','needs-review','preview-ready','rejected'],true),'STATE_CONFLICT','',409);
            osvg_require(!osvg_has_published($id),'PUBLISHED_HISTORY_PROTECTED','',409);
            osvg_db($wpdb->delete(osvg_table('files'),['receipt_id'=>$id])); $changes+=['status'=>'discarded','bytes'=>0];
        } else { throw new OSVG_Error('ACTION_INVALID','',400); }
        osvg_db($wpdb->update(osvg_table('receipts'),$changes,['receipt_id'=>$id]));
        osvg_db($wpdb->insert(osvg_table('audit'),['receipt_id'=>$id,'action'=>$action,'actor'=>$actor,'payload_sha'=>$hash,'created_at'=>osvg_now()]));
        osvg_db($wpdb->query('COMMIT')); return osvg_receipt($id);
    } finally { $wpdb->query('ROLLBACK'); osvg_unlock(); }
}
function osvg_runtime(): string {
    $file=__DIR__.'/vendor/gsap-3.15.0.min.js';
    osvg_require(is_file($file) && hash_equals(OSVG_GSAP_SHA,hash_file('sha256',$file)),'RUNTIME_UNAVAILABLE','runtime',503);
    return file_get_contents($file);
}
function osvg_has_published(string $receipt): bool {
    global $wpdb;
    return (bool)$wpdb->get_var($wpdb->prepare('SELECT id FROM '.osvg_table('audit')." WHERE receipt_id=%s AND action='publish' LIMIT 1",$receipt));
}
