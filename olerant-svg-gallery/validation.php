<?php
if (!defined('ABSPATH')) { exit; }

final class OSVG_Error extends RuntimeException {
    public string $reason;
    public string $field;
    public int $http;
    public function __construct(string $reason, string $field = '', int $http = 422) {
        parent::__construct($reason); $this->reason=$reason; $this->field=$field; $this->http=$http;
    }
}
function osvg_require($ok, string $reason, string $field = '', int $http = 422): void {
    if (!$ok) { throw new OSVG_Error($reason, $field, $http); }
}
function osvg_json(string $raw) {
    osvg_require(strlen($raw)<=65536 && preg_match('//u',$raw)===1, 'MANIFEST_ENCODING','manifest.json');
    try { $value=json_decode($raw, false, 32, JSON_THROW_ON_ERROR); }
    catch (Throwable $e) { throw new OSVG_Error('MANIFEST_JSON','manifest.json'); }
    // Decode first, then walk its lexical tokens to reject duplicate object keys.
    preg_match_all('/"(?:[^"\\\\]|\\\\.)*"|[{}\[\]:,]|[^\s{}\[\]:,]+/s', $raw, $matches);
    $stack=[]; $tokens=$matches[0];
    foreach ($tokens as $i=>$token) {
        if ($token==='{' || $token==='[') { $stack[]=['kind'=>$token,'keys'=>[]]; }
        elseif ($token==='}' || $token===']') { array_pop($stack); }
        elseif (str_starts_with($token,'"') && ($tokens[$i+1]??'')===':') {
            $key=json_decode($token,true,32,JSON_THROW_ON_ERROR); $j=count($stack)-1;
            osvg_require(!isset($stack[$j]['keys'][$key]),'MANIFEST_DUPLICATE_KEY',$key);
            $stack[$j]['keys'][$key]=true;
        }
    }
    return $value;
}
function osvg_schema($value, array $s, string $path='manifest'): void {
    if (array_key_exists('const',$s)) { osvg_require($value===$s['const'], 'MANIFEST_VALUE',$path); }
    if (isset($s['enum'])) { osvg_require(in_array($value,$s['enum'],true),'MANIFEST_VALUE',$path); }
    if (isset($s['type'])) {
        $matches=false;
        foreach ((array)$s['type'] as $t) {
            $matches=$matches || match($t) {
                'object'=>is_object($value), 'array'=>is_array($value), 'string'=>is_string($value),
                'integer'=>is_int($value), 'number'=>(is_int($value)||is_float($value)) && is_finite((float)$value),
                'boolean'=>is_bool($value), 'null'=>$value===null, default=>false
            };
        }
        osvg_require($matches,'MANIFEST_TYPE',$path);
    }
    if (is_object($value)) {
        $vars=get_object_vars($value);
        foreach ($s['required']??[] as $k) { osvg_require(array_key_exists($k,$vars),'MANIFEST_REQUIRED',"$path.$k"); }
        foreach ($vars as $k=>$v) {
            osvg_require(isset($s['properties'][$k]),'MANIFEST_UNKNOWN_FIELD',"$path.$k");
            osvg_schema($v,$s['properties'][$k],"$path.$k");
        }
    } elseif (is_array($value)) {
        osvg_require(count($value)>=($s['minItems']??0) && count($value)<=($s['maxItems']??PHP_INT_MAX),'MANIFEST_LENGTH',$path);
        foreach ($value as $k=>$v) { osvg_schema($v,$s['items'],"$path.$k"); }
    } elseif (is_string($value)) {
        $length=preg_match_all('/./us',$value);
        osvg_require($length>=($s['minLength']??0) && $length<=($s['maxLength']??PHP_INT_MAX),'MANIFEST_LENGTH',$path);
        osvg_require(!preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$value),'MANIFEST_CONTROL_CHARACTER',$path);
        if (isset($s['pattern'])) { osvg_require(preg_match('~'.$s['pattern'].'~D',$value)===1,'MANIFEST_VALUE',$path); }
    } elseif (is_int($value)||is_float($value)) {
        osvg_require($value>=($s['minimum']??-INF) && $value<=($s['maximum']??INF) && $value>($s['exclusiveMinimum']??-INF),'MANIFEST_RANGE',$path);
    }
}
function osvg_validate_svg(string $raw, array $viewbox): void {
    osvg_require(preg_match('//u',$raw)===1 && !preg_match('/<!DOCTYPE|<!ENTITY|<\?/i',$raw),'SVG_XML','artwork.svg');
    $doc=new DOMDocument(); $old=libxml_use_internal_errors(true);
    try { $ok=$doc->loadXML($raw,LIBXML_NONET); }
    finally { libxml_clear_errors(); libxml_use_internal_errors($old); }
    osvg_require($ok && !$doc->doctype && $doc->documentElement?->localName==='svg','SVG_XML','artwork.svg');
    $tags=explode(' ','svg g defs path circle ellipse rect line polyline polygon text tspan title desc linearGradient radialGradient stop clipPath mask pattern use');
    $attrs=explode(' ','id viewBox width height x y x1 y1 x2 y2 cx cy r rx ry d points fill fill-opacity fill-rule stroke stroke-width stroke-linecap stroke-linejoin stroke-miterlimit stroke-dasharray stroke-dashoffset stroke-opacity opacity transform clip-path clip-rule mask gradientUnits gradientTransform spreadMethod offset stop-color stop-opacity href preserveAspectRatio text-anchor dominant-baseline font-family font-size font-weight letter-spacing dx dy patternUnits patternContentUnits patternTransform role aria-label focusable data-part data-stage');
    $nodes=$doc->getElementsByTagName('*'); osvg_require($nodes->length<=10000,'SVG_COMPLEXITY','artwork.svg');
    foreach ($nodes as $node) {
        osvg_require($node->namespaceURI==='http://www.w3.org/2000/svg' && in_array($node->localName,$tags,true),'SVG_ELEMENT',$node->nodeName);
        foreach ($node->attributes as $attr) {
            if ($attr->namespaceURI==='http://www.w3.org/2000/xmlns/') { continue; }
            $name=$attr->localName; $value=$attr->value;
            osvg_require((!$attr->namespaceURI || ($attr->namespaceURI==='http://www.w3.org/1999/xlink' && $name==='href')) && in_array($name,$attrs,true),'SVG_ATTRIBUTE',$attr->nodeName);
            osvg_require(!preg_match('/[\\\\<>\x00-\x1f]|(?:https?:|data:|javascript:|@import)/i',$value),'SVG_REFERENCE',$name);
            if ($name==='href') { osvg_require(preg_match('/^#[A-Za-z_][A-Za-z0-9_.:-]*$/D',$value),'SVG_REFERENCE',$name); }
            if (stripos($value,'url')!==false) { osvg_require(preg_match('/^url\(#[A-Za-z_][A-Za-z0-9_.:-]*\)$/D',$value),'SVG_REFERENCE',$name); }
            if ($name==='id') { osvg_require(preg_match('/^[A-Za-z_][A-Za-z0-9_.:-]{0,100}$/D',$value),'SVG_ID',$name); }
        }
    }
    $actual=preg_split('/[\s,]+/',trim($doc->documentElement->getAttribute('viewBox')));
    osvg_require(count($actual)===4,'SVG_VIEWBOX','artwork.viewBox');
    foreach($actual as $i=>$v) { osvg_require(is_numeric($v) && (float)$v===(float)$viewbox[$i],'SVG_VIEWBOX','artwork.viewBox'); }
}
function osvg_validate_js(string $raw): void {
    osvg_require(preg_match('//u',$raw)===1 && !str_contains($raw,"\0"),'JS_ENCODING','animation.js');
    // Triage only. This is NOT a proof of harmlessness; exact-source owner review is mandatory.
    osvg_require(str_contains($raw,'window.svgAnimationItem') && str_contains($raw,'mount'),'JS_ADAPTER','animation.js');
    osvg_require(!preg_match('/\b(eval|Function|fetch|XMLHttpRequest|WebSocket|EventSource|Worker|SharedWorker|importScripts|sendBeacon|localStorage|sessionStorage|indexedDB|serviceWorker|cookie|location|top|opener)\b|\b(?:import|export)\s|\\\\[xu][0-9a-f]{2}/i',$raw),'JS_REVIEW_BLOCKED','animation.js');
}
function osvg_validate_zip(string $path): array {
    osvg_require(is_file($path) && filesize($path)>0 && filesize($path)<=5*1024*1024,'ZIP_SIZE','zip',413);
    $z=new ZipArchive(); osvg_require($z->open($path,ZipArchive::RDONLY)===true,'ZIP_INVALID','zip');
    $names=['manifest.json','artwork.svg','animation.js','poster.png','README.txt','LICENSE.txt','THIRD-PARTY-NOTICES.txt'];
    $files=[]; $total=0;
    try {
        osvg_require($z->numFiles===7,'ZIP_FILES','zip');
        for($i=0;$i<7;$i++) {
            $st=$z->statIndex($i,ZipArchive::FL_UNCHANGED); $name=$st['name'];
            osvg_require(in_array($name,$names,true) && !isset($files[$name]),'ZIP_FILES','zip');
            $limit=$name==='manifest.json'?65536:2*1024*1024;
            osvg_require($st['size']>0 && $st['size']<=$limit && ($st['encryption_method']??0)===0,'ZIP_ENTRY',$name);
            $ops=0; $attr=0; osvg_require($z->getExternalAttributesIndex($i,$ops,$attr),'ZIP_ENTRY',$name);
            $mode=($attr>>16)&0170000; osvg_require(!in_array($mode,[0120000,0040000],true) && ($mode===0 || $mode===0100000),'ZIP_ENTRY',$name);
            $stream=$z->getStream($name); osvg_require(is_resource($stream),'ZIP_ENTRY',$name);
            try { $raw=stream_get_contents($stream,$limit+1); } finally { fclose($stream); }
            osvg_require(is_string($raw) && strlen($raw)===$st['size'] && strlen($raw)<=$limit,'ZIP_ENTRY',$name);
            $total+=strlen($raw); osvg_require($total<=10*1024*1024,'ZIP_EXPANDED_SIZE','zip'); $files[$name]=$raw;
        }
    } finally { $z->close(); }
    $m=osvg_json($files['manifest.json']);
    osvg_schema($m,json_decode(file_get_contents(__DIR__.'/manifest.schema.json'),true,32,JSON_THROW_ON_ERROR));
    $manifest=json_decode(json_encode($m,JSON_THROW_ON_ERROR),true,32,JSON_THROW_ON_ERROR);
    $seen=[];
    foreach($manifest['files'] as $file) {
        $name=$file['path']; osvg_require(!isset($seen[$name]),'MANIFEST_FILES','files'); $seen[$name]=true;
        osvg_require(strlen($files[$name])===$file['bytes'] && hash_equals($file['sha256'],hash('sha256',$files[$name])),'FILE_HASH',$name);
    }
    osvg_require(count($seen)===6,'MANIFEST_FILES','files');
    $a=$manifest['artwork']; osvg_require($a['viewBox'][2]>0 && $a['viewBox'][3]>0 && $a['minRecommendedCssWidth']<=$a['recommendedCssWidth'],'MANIFEST_RANGE','artwork');
    osvg_validate_svg($files['artwork.svg'],$a['viewBox']); osvg_validate_js($files['animation.js']);
    foreach(['README.txt','LICENSE.txt','THIRD-PARTY-NOTICES.txt'] as $name) { osvg_require(preg_match('//u',$files[$name])===1 && !str_contains($files[$name],"\0"),'TEXT_ENCODING',$name); }
    $info=@getimagesizefromstring($files['poster.png']);
    osvg_require($info && $info[2]===IMAGETYPE_PNG && $info[0]>0 && $info[1]>0 && $info[0]<=2048 && $info[1]<=2048,'POSTER_DIMENSIONS','poster.png');
    $im=@imagecreatefromstring($files['poster.png']); osvg_require($im!==false,'POSTER_DECODE','poster.png');
    imagesavealpha($im,true); ob_start(); imagepng($im,null,6); $poster=ob_get_clean(); imagedestroy($im);
    osvg_require(is_string($poster) && strlen($poster)<=4*1024*1024,'POSTER_SIZE','poster.png');
    ksort($files,SORT_STRING); $payload='';
    foreach($files as $name=>$raw) { $payload.=$name."\t".strlen($raw)."\t".hash('sha256',$raw)."\n"; }
    return ['manifest'=>$manifest,'files'=>$files,'poster'=>$poster,'payloadSha256'=>hash('sha256',$payload),'packageSha256'=>hash_file('sha256',$path)];
}
