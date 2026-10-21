<?php
if (!defined('ABSPATH')) { exit; }
function osvg_response($data,int $status=200): WP_REST_Response {
    $r=new WP_REST_Response($data,$status);
    $r->header('Cache-Control','private, no-store, max-age=0'); $r->header('X-Content-Type-Options','nosniff');
    $r->header('Referrer-Policy','no-referrer');
    if ($status===429 || $status===503) { $r->header('Retry-After','5'); }
    return $r;
}
function osvg_error_response(Throwable $e): WP_REST_Response {
    return osvg_response(['status'=>'not-accepted','message'=>'処理できませんでした。理由を確認してください。',
        'reasonCodes'=>[$e instanceof OSVG_Error?$e->reason:'INTERNAL_ERROR'],
        'errors'=>[['code'=>$e instanceof OSVG_Error?$e->reason:'INTERNAL_ERROR','field'=>$e instanceof OSVG_Error?$e->field:'']]],$e instanceof OSVG_Error?$e->http:500);
}
function osvg_link_auth(WP_REST_Request $request) {
    global $wpdb;
    $header=$request->get_header('authorization');
    if (!preg_match('/^Bearer ([a-f0-9]{64})$/D',$header,$match)) { return new WP_Error('TOKEN_INVALID','投稿用の認証が必要です。',['status'=>401]); }
    $link=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.osvg_table('links').' WHERE token_hash=%s AND active=1',hash('sha256',$match[1])),ARRAY_A);
    if (!$link) { return new WP_Error('TOKEN_INVALID','投稿リンクが無効です。',['status'=>401]); }
    return $link;
}
function osvg_submission_permission(WP_REST_Request $r) { $v=osvg_link_auth($r); return is_wp_error($v)?$v:true; }
function osvg_owner_permission() { return current_user_can('manage_options')?true:new WP_Error('OWNER_REQUIRED','管理者の確認が必要です。',['status'=>403]); }
function osvg_routes(): void {
    register_rest_route('olerant-svg/v1','/submissions',[
        ['methods'=>'POST','callback'=>'osvg_submit_api','permission_callback'=>'osvg_submission_permission'],
        ['methods'=>'GET','callback'=>'osvg_status_api','permission_callback'=>'osvg_submission_permission']
    ]);
    register_rest_route('olerant-svg/v1','/items/(?P<receipt>[a-f0-9]{32})/playback',[
        'methods'=>'GET','callback'=>'osvg_playback_api','permission_callback'=>'__return_true'
    ]);
    register_rest_route('olerant-svg/v1','/owner/upload',[
        'methods'=>'POST','callback'=>'osvg_owner_upload_api','permission_callback'=>'osvg_owner_permission'
    ]);
    // All SVG API errors, including permission_callback errors, are non-cacheable.
    add_filter('rest_post_dispatch',function($response,$server,$request) {
        if (str_starts_with($request->get_route(),'/olerant-svg/')) {
            $response->header('Cache-Control','private, no-store, max-age=0');
            $response->header('Referrer-Policy','no-referrer');
            $data=$response->get_data();
            if($response->get_status()>=400 && is_array($data) && !isset($data['reasonCodes'])) {
                $code=is_string($data['code']??null)?$data['code']:'REQUEST_REJECTED';
                $response->set_data(['status'=>'not-accepted','message'=>$data['message']??'処理できませんでした。','reasonCodes'=>[$code],'errors'=>[['code'=>$code,'field'=>'']]]);
            }
        }
        return $response;
    },10,3);
}
function osvg_upload_path(WP_REST_Request $request): string {
    $files=$request->get_file_params();
    osvg_require(count($files)===1 && isset($files['zip']) && !is_array($files['zip']['tmp_name']??null),'UPLOAD_FIELD','zip',400);
    $f=$files['zip']; osvg_require(($f['error']??UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_OK,'UPLOAD_FAILED','zip',413);
    osvg_require(is_uploaded_file($f['tmp_name']),'UPLOAD_FAILED','zip',400); return $f['tmp_name'];
}
function osvg_submit_api(WP_REST_Request $request): WP_REST_Response {
    try {
        $link=osvg_link_auth($request); osvg_require(!is_wp_error($link),'TOKEN_INVALID','',401);
        $result=osvg_receive(osvg_upload_path($request),$link); return osvg_response($result['data'],$result['http']);
    } catch(Throwable $e) { return osvg_error_response($e); }
}
function osvg_owner_upload_api(WP_REST_Request $request): WP_REST_Response {
    global $wpdb;
    try {
        $producer='owner-'.get_current_user_id();
        $link=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.osvg_table('links').' WHERE producer=%s AND active=1 LIMIT 1',$producer),ARRAY_A);
        if (!$link) { $new=osvg_create_link($producer); $link=['id'=>$new['id'],'producer'=>$producer]; }
        $result=osvg_receive(osvg_upload_path($request),$link); return osvg_response($result['data'],$result['http']);
    } catch(Throwable $e) { return osvg_error_response($e); }
}
function osvg_status_api(WP_REST_Request $request): WP_REST_Response {
    global $wpdb;
    try {
        $link=osvg_link_auth($request); osvg_require(!is_wp_error($link),'TOKEN_INVALID','',401);
        $where='producer=%s'; $values=[$link['producer']];
        foreach(['receiptId'=>'receipt_id','id'=>'item_id'] as $param=>$column) {
            $value=$request->get_param($param);
            if ($value!==null) { osvg_require(is_string($value) && strlen($value)<=100,'QUERY_INVALID',$param,400); $where.=" AND $column=%s"; $values[]=$value; }
        }
        $page=max(1,min(100000,(int)($request->get_param('page')??1))); $limit=max(1,min(100,(int)($request->get_param('limit')??50)));
        $total=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.osvg_table('receipts')." WHERE $where",...$values));
        $rows=$wpdb->get_results($wpdb->prepare('SELECT * FROM '.osvg_table('receipts')." WHERE $where ORDER BY received_at DESC,receipt_id DESC LIMIT %d OFFSET %d",...array_merge($values,[$limit,($page-1)*$limit])),ARRAY_A);
        return osvg_response(['items'=>array_map('osvg_receipt_json',$rows),'page'=>$page,'limit'=>$limit,'total'=>$total,'hasMore'=>$page*$limit<$total]);
    } catch(Throwable $e) { return osvg_error_response($e); }
}
function osvg_playback_api(WP_REST_Request $request): WP_REST_Response {
    try {
        $r=osvg_receipt($request['receipt']);
        osvg_require($r && (osvg_public($r) || (current_user_can('manage_options') && in_array($r['status'],['preview-ready','superseded','withdrawn'],true))),'NOT_FOUND','',404);
        osvg_require(hash_equals((string)$r['review_hash'],(string)$r['payload_sha']) && $r['player_version']===OSVG_PLAYER_VERSION,'REVIEW_REQUIRED','',409);
        $m=osvg_manifest($r);
        return osvg_response(['id'=>$m['id'],'version'=>$m['version'],'payloadSha256'=>$r['payload_sha'],
            'svg'=>osvg_file($r['receipt_id'],'artwork.svg'),'script'=>osvg_file($r['receipt_id'],'animation.js'),
            'runtime'=>osvg_runtime(),'playback'=>$m['playback']]);
    } catch(Throwable $e) { return osvg_error_response($e); }
}
