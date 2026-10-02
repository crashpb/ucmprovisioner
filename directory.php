<?php
/** Public, token-protected, read-only phone directory generated from the local PBX cache. */
$root = '/var/lib/asterisk/ucmprovisioner/directory';
$pathInfo = trim((string)($_SERVER['PATH_INFO'] ?? ''), '/');
$pathParts = $pathInfo === '' ? [] : array_map('rawurldecode', explode('/', $pathInfo));
$legacyPath = basename((string)($_SERVER['SCRIPT_NAME'] ?? '')) === 'ucmp-d.php' && !empty($pathParts);
$token = $legacyPath ? (string)($pathParts[0] ?? '') : (string)($_GET['token'] ?? '');
if ($token === '' || strlen($token)>128) { http_response_code(403); exit; }
// New snapshots authenticate themselves, binding the access token to these exact contacts.
// Read the legacy token first for a coherent transition from caches without a token digest.
$stored = is_readable($root . '/token') ? trim((string)file_get_contents($root . '/token',false,null,0,130)) : '';
$bytes = is_readable($root . '/directory.json') ? file_get_contents($root . '/directory.json',false,null,0,67108865) : false;
if ($bytes === false || strlen($bytes)>67108864) { http_response_code(503); exit; }
$payload = json_decode($bytes,true);
if (!is_array($payload)) { http_response_code(503); exit; }
$valid = array_key_exists('token_sha256',$payload)
    ? is_string($payload['token_sha256']) && preg_match('/^[a-f0-9]{64}$/D',$payload['token_sha256']) && hash_equals($payload['token_sha256'],hash('sha256',$token))
    : $stored !== '' && hash_equals($stored,$token);
if (!$valid) { http_response_code(403); exit; }
unset($bytes,$stored);
$format=$legacyPath?'cisco_legacy':(string)($_GET['format']??'');
if (!in_array($format,['cisco','cisco_legacy','yealink','grandstream'],true)) { http_response_code(400); die("Unsupported format"); }

$selected = null; $bookKey = trim($legacyPath?(string)($pathParts[1]??''):(string)($_GET['book'] ?? '')); $bookFile = $legacyPath?'':basename(trim((string)($_GET['file'] ?? '')));
$effectiveDefaultKey = $bookKey === '' && $bookFile === '' ? trim((string)($payload['default_book_key'] ?? '')) : '';
$routeView = $legacyPath?(string)($pathParts[2]??''):(string)($_GET['view']??'');
$routeNode = $legacyPath?(string)($pathParts[3]??'root'):(string)($_GET['node']??'root');
$routePage = $legacyPath?(int)($pathParts[4]??0):(int)($_GET['page']??0);
$routeDirect = $legacyPath?((string)($pathParts[5]??'0')==='1'):!empty($_GET['direct']);
if ($bookKey !== '' && !preg_match('/^[A-Za-z0-9_.-]{1,64}$/D',$bookKey)) { http_response_code(400); exit; }
foreach ((array)($payload['phonebooks'] ?? []) as $candidate) {
    if (!is_array($candidate)) { continue; }
    $key = (string)($candidate['book_key'] ?? $candidate['key'] ?? '');
    if (($bookKey !== '' && hash_equals($key,$bookKey)) || ($bookKey === '' && $bookFile !== '' && hash_equals(basename((string)($candidate['filename'] ?? '')),$bookFile))) { $selected=$candidate; break; }
    if ($bookKey === '' && $bookFile === '' && $effectiveDefaultKey !== '' && hash_equals($key,$effectiveDefaultKey)) { $selected=$candidate; break; }
    if ($bookKey === '' && $bookFile === '' && $effectiveDefaultKey === '' && $selected === null && !empty($candidate['is_default'])) { $selected=$candidate; }
}
if ($selected === null) {
    foreach ((array)($payload['phonebooks'] ?? []) as $candidate) { if (is_array($candidate) && !empty($candidate['is_default'])) { $selected=$candidate; break; } }
}
if ($selected === null && is_array($payload['phonebooks'][0] ?? null)) { $selected=$payload['phonebooks'][0]; }
if ($bookKey !== '' && ($selected === null || !hash_equals((string)($selected['book_key'] ?? $selected['key'] ?? ''),$bookKey))) { http_response_code(404); exit; }
if ($bookKey === '' && $bookFile !== '' && ($selected === null || !hash_equals(basename((string)($selected['filename'] ?? '')),$bookFile))) { http_response_code(404); exit; }

$layout = array_merge([
    'title'=>'Company Directory','group_by'=>'department','name_format'=>'display_label','sort_by'=>'display_name','include_mobile'=>true,'cisco_page_size'=>32,
    'search_extension'=>true,'search_mobile'=>true,'search_pager'=>true,'numeric_min_length'=>1,
    'mobile_result_mode'=>'find_extension','mobile_editdial'=>false,
],is_array($selected['layout'] ?? null)?$selected['layout']:(is_array($payload['layout'] ?? null)?$payload['layout']:[]));
$layout['title'] = trim((string)$layout['title']) ?: 'Company Directory';
$layout['name_format'] = in_array((string)$layout['name_format'],['display','display_label','label_display','label','display_extension'],true)?$layout['name_format']:'display_label';
$layout['sort_by'] = in_array((string)$layout['sort_by'],['display_name','label','extension'],true)?$layout['sort_by']:'display_name';
$layout['include_mobile'] = !empty($layout['include_mobile']);
$layout['cisco_page_size'] = in_array((int)$layout['cisco_page_size'],[16,24,32],true)?(int)$layout['cisco_page_size']:32;
$layout['hide_uncategorised'] = !empty($layout['hide_uncategorised']);
$layout['search_enabled'] = !array_key_exists('search_enabled',$layout) || !empty($layout['search_enabled']);
$layout['search_position'] = in_array((string)($layout['search_position']??''),['first','after_uncategorised','last'],true)?(string)$layout['search_position']:'first';
$layout['search_scope'] = in_array((string)($layout['search_scope']??''),['root','all'],true)?(string)$layout['search_scope']:'root';
$layout['search_extension'] = !array_key_exists('search_extension',$layout) || !empty($layout['search_extension']);
$layout['search_mobile'] = !array_key_exists('search_mobile',$layout) || !empty($layout['search_mobile']);
$layout['search_pager'] = !array_key_exists('search_pager',$layout) || !empty($layout['search_pager']);
$layout['numeric_min_length'] = max(1,min(10,(int)($layout['numeric_min_length']??1)));
$layout['mobile_result_mode'] = in_array((string)($layout['mobile_result_mode']??''),['find_extension','extension_and_mobile'],true)?(string)$layout['mobile_result_mode']:'find_extension';
$layout['mobile_editdial'] = !empty($layout['mobile_editdial']);
$softkeyDefaults=['menu'=>['Select','Exit','Previous','Next'],'list'=>['Dial','EditDial','Exit','Next']];
$softkeyAllowed=['menu'=>['Select','Search','Exit','Previous','Next','None'],'list'=>['Dial','EditDial','Search','Exit','Previous','Next','None']];
$softkeyRequired=['menu'=>['Select','Exit'],'list'=>['Dial','Exit']];
$rawSoftkeys=is_array($layout['cisco_softkeys']??null)?$layout['cisco_softkeys']:[];$layout['cisco_softkeys']=[];
foreach($softkeyDefaults as $softView=>$defaults){$raw=is_array($rawSoftkeys[$softView]??null)?array_values($rawSoftkeys[$softView]):$defaults;$keys=array_fill(0,4,'None');$seen=[];foreach(array_slice($raw,0,4) as $position=>$action){$action=(string)$action;if($action==='None'||!in_array($action,$softkeyAllowed[$softView],true)||isset($seen[$action])){continue;}$keys[$position]=$action;$seen[$action]=true;}foreach($softkeyRequired[$softView] as $required){if(isset($seen[$required])){continue;}$position=array_search('None',$keys,true);if($position===false){foreach(array_reverse(array_keys($keys)) as $candidate){if(!in_array($keys[$candidate],$softkeyRequired[$softView],true)){$position=$candidate;break;}}unset($seen[$keys[$position]]);}$keys[$position]=$required;$seen[$required]=true;}$layout['cisco_softkeys'][$softView]=$keys;}
$cut = static fn($v,$n=64) => function_exists('mb_substr')?mb_substr(trim((string)$v),0,$n,'UTF-8'):substr(trim((string)$v),0,$n);
$lower = static fn($v) => function_exists('mb_strtolower')?mb_strtolower((string)$v,'UTF-8'):strtolower((string)$v);
$digits = static function($value): string {
    $value=strtr((string)$value,['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9','٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9']);
    return (string)preg_replace('/[^0-9]+/','',$value);
};
$labelPattern = array_key_exists('label_pattern',$_GET) ? (string)$_GET['label_pattern'] : null;
if ($labelPattern !== null) {
    if (strlen($labelPattern)>128 || preg_match('/[\x00-\x1F\x7F]/',$labelPattern)) { http_response_code(400); exit; }
    preg_match_all('/\{([^{}]+)\}/',$labelPattern,$labelTokens);
    $allowed=['display','given','initial','surname','label','extension','department','site'];
    foreach($labelTokens[1]??[] as $labelToken){if(!in_array((string)$labelToken,$allowed,true)){http_response_code(400);exit;}}
    $remainder=preg_replace('/\{(?:display|given|initial|surname|label|extension|department|site)\}/','',$labelPattern);
    if(str_contains((string)$remainder,'{')||str_contains((string)$remainder,'}')){http_response_code(400);exit;}
}
$contactName = static function (array $entry) use ($layout,$labelPattern,$cut): string {
    $display=trim((string)($entry['display_name']??''));$extension=trim((string)($entry['extension']??''));
    if(!empty($entry['directory_label_override_set'])){
        $label=trim((string)($entry['directory_label_override']??''));
    }elseif($labelPattern!==null){
        $words=preg_split('/\s+/u',$display,-1,PREG_SPLIT_NO_EMPTY)?:[];
        $given=trim((string)($entry['given_name']??''));$surname=trim((string)($entry['surname']??''));
        if($given===''&&$words){$given=(string)$words[0];}if($surname===''&&count($words)>1){$surname=(string)end($words);}
        $initial=trim((string)($entry['initial']??''));if($initial===''&&$given!==''){$initial=$cut($given,1);}
        $baseLabel=trim((string)($entry['canonical_label']??($entry['label']??'')));if($baseLabel===''){$baseLabel=$display;}
        $label=strtr($labelPattern,['{display}'=>$display,'{given}'=>$given,'{initial}'=>$initial,'{surname}'=>$surname,'{label}'=>$baseLabel,'{extension}'=>$extension,'{department}'=>trim((string)($entry['department']??'')),'{site}'=>trim((string)($entry['site_name']??''))]);
    }else{
        $label=trim((string)($entry['label']??''));
    }
    return match ($layout['name_format']) {
        'display'=>$display,
        'label_display'=>$label!==''?$label.($display!==''?' — '.$display:''):$display,
        'label'=>$label!==''?$label:$display,
        'display_extension'=>$display.($extension!==''?' ('.$extension.')':''),
        default=>$display.($label!==''&&strcasecmp($label,$display)!==0?' ('.$label.')':''),
    };
};

// V2 caches are already compiled. Older caches are upgraded in memory and remain phone-readable.
$contacts = []; $nodes = [];
if ((int)($selected['schema_version'] ?? 0) === 2 && is_array($selected['contacts'] ?? null) && is_array($selected['nodes'] ?? null)) {
    $contacts=$selected['contacts']; $nodes=$selected['nodes'];
} else {
    $entries = is_array($selected['entries'] ?? null)?$selected['entries']:(is_array($payload['entries'] ?? null)?$payload['entries']:[]);
    foreach ($entries as $entry) {
        if (!is_array($entry) || trim((string)($entry['extension']??''))==='') { continue; }
        $id=trim((string)($entry['source_key']??''));
        if ($id==='') { $id=sha1($lower($entry['display_name']??'')."\0".(string)$entry['extension']); }
        $entry['source_key']=$id; $contacts[$id]=$entry;
    }
    uasort($contacts,static function ($a,$b) use ($layout): int {
        $left=(string)($a[$layout['sort_by']]??''); $right=(string)($b[$layout['sort_by']]??'');
        return $layout['sort_by']==='extension'?strnatcasecmp($left,$right):strcasecmp($left,$right);
    });
    $hierarchy=is_array($layout['hierarchy']??null)?$layout['hierarchy']:[];
    if (!array_is_list($hierarchy)) { $hierarchy=[]; }
    if (!$hierarchy && ($layout['group_by']??'department')!=='none') { $hierarchy=[['field'=>$layout['group_by'],'fallback'=>'Other']]; }
    $nodes=['root'=>['id'=>'root','parent'=>null,'label'=>$layout['title'],'path'=>[],'children'=>[],'contacts'=>[],'count'=>0]];
    foreach ($contacts as $id=>$entry) {
        $path=[];
        foreach (array_slice($hierarchy,0,8) as $level) {
            $field=(string)($level['field']??''); $value='';
            if (preg_match('/^ou:([0-7])$/',$field,$m)) { $ouPath=(array)($entry['ou_path']??[]); $value=trim((string)($ouPath[(int)$m[1]]??'')); }
            else { $value=trim((string)($entry[$field]??'')); }
            $path[]=$cut($value!==''?$value:($level['fallback']??'Other'));
        }
        $parent='root'; $walk=[];
        foreach ($path as $label) {
            $walk[]=$label; $nodeId='n-'.substr(hash('sha256',$parent."\0".$lower($label)),0,24);
            if (!isset($nodes[$nodeId])) { $nodes[$nodeId]=['id'=>$nodeId,'parent'=>$parent,'label'=>$label,'path'=>$walk,'children'=>[],'contacts'=>[],'count'=>0]; }
            if (!in_array($nodeId,$nodes[$parent]['children'],true)) { $nodes[$parent]['children'][]=$nodeId; }
            $parent=$nodeId;
        }
        $nodes[$parent]['contacts'][]=$id;
    }
}
if (!isset($nodes['root'])) { $nodes=['root'=>['id'=>'root','parent'=>null,'label'=>$layout['title'],'path'=>[],'children'=>[],'contacts'=>array_keys($contacts),'count'=>count($contacts)]]; }
foreach ($nodes as &$node) {
    $node['children']=array_values(array_filter((array)($node['children']??[]),static fn($id)=>isset($nodes[$id])));
    $node['contacts']=array_values(array_filter((array)($node['contacts']??[]),static fn($id)=>isset($contacts[$id])));
    $node['path']=is_array($node['path']??null)?array_slice($node['path'],0,8):[];
}
unset($node);
$descendants = static function ($id) use (&$descendants,&$nodes): array {
    static $active=[]; if (!isset($nodes[$id]) || isset($active[$id])) { return []; } $active[$id]=true;
    $ids=array_fill_keys((array)$nodes[$id]['contacts'],true);
    foreach ((array)$nodes[$id]['children'] as $child) { foreach ($descendants($child) as $contactId) { $ids[$contactId]=true; } }
    unset($active[$id]); $nodes[$id]['count']=count($ids); return array_keys($ids);
};
$descendants('root');

$groups=[]; $memberships=[];
foreach ($nodes as $id=>$node) {
    if (empty($node['contacts'])) { continue; }
    $group=$id==='root'||empty($node['path'])?'All contacts':implode(' / ',$node['path']);
    foreach ($node['contacts'] as $contactId) { $groups[$group][$contactId]=$contacts[$contactId]; $memberships[$contactId][$group]=true; }
}
uksort($groups,'strnatcasecmp');

header('Content-Type: text/xml; charset=UTF-8'); header('X-Content-Type-Options: nosniff'); header('Cache-Control: private, max-age=300');
if ($selected !== null && ($bookFile!=='' || !empty($selected['filename']))) {
    $downloadName=$bookFile!==''?$bookFile:basename((string)$selected['filename']); header('Content-Disposition: inline; filename="'.str_replace('"','',$downloadName).'"');
}
$doc=new DOMDocument('1.0','UTF-8'); $doc->formatOutput=true;
$wire=static function(string $value)use($format):string{$value=(string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u','',$value);if($format==='cisco_legacy'){$value=strtr($value,["\u{00A0}"=>' ',"\u{2013}"=>'-',"\u{2014}"=>'-',"\u{2018}"=>"'", "\u{2019}"=>"'", "\u{201C}"=>'"', "\u{201D}"=>'"']);}return $value;};
$add=static function (DOMDocument $doc,DOMElement $parent,string $name,string $value='')use($wire): DOMElement { $node=$doc->createElement($name); if ($value!==''){$node->appendChild($doc->createTextNode($wire($value)));}$parent->appendChild($node);return $node; };

if ($format==='cisco' || $format==='cisco_legacy') {
    $forwarded=strtolower(trim(explode(',',(string)($_SERVER['HTTP_X_FORWARDED_PROTO']??''))[0]));
    $scheme=in_array($forwarded,['http','https'],true)?$forwarded:(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off'?'https':'http');
    $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? $_SERVER['SERVER_ADDR'] ?? '127.0.0.1';
    if (!preg_match('/^[A-Za-z0-9.\-\[\]:]+$/D',(string)$host)) { http_response_code(400); exit; }
    $selectedKey=(string)($selected['book_key']??$selected['key']??'');
    if ($legacyPath) {
        $base=$scheme.'://'.$host.($_SERVER['SCRIPT_NAME']??'/ucmp-d.php').'/'.rawurlencode($token).($selectedKey!==''?'/'.rawurlencode($selectedKey):'');
    } else {
        $base=$scheme.'://'.$host.($_SERVER['SCRIPT_NAME']??'/ucmprovisioner-directory.php').'?token='.rawurlencode($token).'&format='.$format;
        if ($selectedKey!==''){$base.='&book='.rawurlencode($selectedKey);} if($bookFile!==''){$base.='&file='.rawurlencode($bookFile);}
    }
    $nav=static function(string $view,string $node,int $page=0,bool $direct=false,string $query='',string $number='')use($base,$legacyPath,$labelPattern):string{
        $params=[];if($labelPattern!==null){$params['label_pattern']=$labelPattern;}if($query!==''){$params['q']=$query;}if($number!==''){$params['n']=$number;}
        if($legacyPath){$url=$base.'/'.rawurlencode($view).'/'.rawurlencode($node).'/'.max(0,$page).'/'.($direct?'1':'0');return $params?$url.'?'.http_build_query($params,'','&',PHP_QUERY_RFC3986):$url;}
        $url=$base.'&view='.rawurlencode($view).'&node='.rawurlencode($node);if($page>0){$url.='&page='.$page;}if($direct){$url.='&direct=1';}return $params?$url.'&'.http_build_query($params,'','&',PHP_QUERY_RFC3986):$url;
    };
    $nodeId=trim($routeNode); if(!isset($nodes[$nodeId])){$nodeId='root';} $node=$nodes[$nodeId];
    $view=$routeView!==''?$routeView:(!empty($node['children'])?'menu':'list'); $page=max(0,$routePage); $pageSize=$layout['cisco_page_size'];
    $title=$cut($nodeId==='root'?$layout['title']:implode(' / ',$node['path']),32);
    if ($view==='menu') {
        $searchVisible=$layout['search_enabled']&&($layout['search_scope']==='all'||$nodeId==='root');
        $rootNode=$doc->createElement('CiscoIPPhoneMenu');$doc->appendChild($rootNode);$add($doc,$rootNode,'Title',$title);$add($doc,$rootNode,'Prompt',$searchVisible?'Choose a category or search':'Choose a category');
        if ($nodeId!=='root' && $format==='cisco_legacy'){$item=$add($doc,$rootNode,'MenuItem');$add($doc,$item,'Name','Back');$add($doc,$item,'URL','SoftKey:Exit');}
        $addSearch=static function()use($doc,$rootNode,$add,$nav,$nodeId):void{$search=$add($doc,$rootNode,'MenuItem');$add($doc,$search,'Name','Search');$add($doc,$search,'URL',$nav('search',$nodeId));};
        $hideUncat = !empty($layout['hide_uncategorised']);
        if($searchVisible&&$layout['search_position']==='first'){$addSearch();}
        if (!empty($node['contacts']) && (!$hideUncat || $nodeId !== 'root')){$item=$add($doc,$rootNode,'MenuItem');$add($doc,$item,'Name',($nodeId==='root'?'Uncategorised':'Contacts').' ('.count($node['contacts']).')');$add($doc,$item,'URL',$nav('list',$nodeId,0,true));}
        if($searchVisible&&$layout['search_position']==='after_uncategorised'){$addSearch();}
        $fixed=($searchVisible?1:0)+(!empty($node['contacts'])&&(!$hideUncat||$nodeId!=='root')?1:0)+($nodeId!=='root'&&$format==='cisco_legacy'?1:0);$children=$node['children']; $menuPageSize=max(1,$pageSize-$fixed); $offset=$page*$menuPageSize; if($offset>=count($children)&&$page>0){$page=0;$offset=0;}
        foreach(array_slice($children,$offset,$menuPageSize) as $childId){$child=$nodes[$childId];$item=$add($doc,$rootNode,'MenuItem');$add($doc,$item,'Name',$cut($child['label'].' ('.(int)$child['count'].')'));$add($doc,$item,'URL',$nav(!empty($child['children'])?'menu':'list',$childId));}
        if($searchVisible&&$layout['search_position']==='last'){$addSearch();}
        $hasPrevious=$page>0;$hasNext=$offset+$menuPageSize<count($children);
        if($format==='cisco_legacy' && count($children)>$menuPageSize){header('Refresh: 0; url='.$nav('menu',$nodeId,($page+1)%(int)ceil(count($children)/$menuPageSize)));}
        if($format!=='cisco_legacy'){
            // A single custom key removes the phone defaults, so the saved policy
            // is normalized to retain Select and Exit before it reaches this renderer.
            foreach($layout['cisco_softkeys']['menu'] as $position=>$action){$name=$action;$url='';if($action==='Select'){$url='SoftKey:Select';}elseif($action==='Search'&&$layout['search_enabled']){$url=$nav('search',$nodeId);}elseif($action==='Exit'){$name=$nodeId==='root'?'Exit':'Back';$url='SoftKey:Exit';}elseif($action==='Previous'&&$hasPrevious){$url=$nav('menu',$nodeId,$page-1);}elseif($action==='Next'&&$hasNext){$url=$nav('menu',$nodeId,$page+1);}if($url===''){continue;}$soft=$add($doc,$rootNode,'SoftKeyItem');$add($doc,$soft,'Name',$name);$add($doc,$soft,'URL',$url);$add($doc,$soft,'Position',(string)($position+1));}
        }
        echo $doc->saveXML();exit;
    }
    if ($view==='search') {
        $rootNode=$doc->createElement('CiscoIPPhoneInput');$doc->appendChild($rootNode);$add($doc,$rootNode,'Title',$title);$add($doc,$rootNode,'Prompt','Name, login or number');
        $add($doc,$rootNode,'URL',$nav('list',$nodeId));
        $item=$add($doc,$rootNode,'InputItem');$add($doc,$item,'DisplayName','Name, login or number (ABC)');$add($doc,$item,'QueryStringParam','q');$add($doc,$item,'InputFlags','A');
        $item=$add($doc,$rootNode,'InputItem');$add($doc,$item,'DisplayName','Ext, mobile or EMP No. (123)');$add($doc,$item,'QueryStringParam','n');$add($doc,$item,'InputFlags','N');
        if($format!=='cisco_legacy'){foreach([['Search','SoftKey:Submit',1],['<<','SoftKey:<<',2],['Cancel','SoftKey:Cancel',3]] as [$name,$url,$position]){$soft=$add($doc,$rootNode,'SoftKeyItem');$add($doc,$soft,'Name',$name);$add($doc,$soft,'URL',$url);$add($doc,$soft,'Position',(string)$position);}}
        echo $doc->saveXML();exit;
    }
    $direct=$routeDirect; $ids=$direct?(array)$node['contacts']:$descendants($nodeId); $query=trim((string)($_GET['q']??''));$number=trim((string)($_GET['n']??''));
    usort($ids,static function($a,$b)use($contacts,$layout){$left=(string)($contacts[$a][$layout['sort_by']]??'');$right=(string)($contacts[$b][$layout['sort_by']]??'');return $layout['sort_by']==='extension'?strnatcasecmp($left,$right):strcasecmp($left,$right);});
    $searching=$query!==''||$number!=='';$rows=[];$numberNeedle=$digits($number);$textNeedle=$lower($query);$textDigits=preg_match('/^[+0-9۰-۹٠-٩().\s-]+$/u',$query)?$digits($query):'';$numericTooShort=$number!==''&&strlen($numberNeedle)<$layout['numeric_min_length'];
    foreach($ids as $id){$entry=$contacts[$id];$extensionMatch=$mobileMatch=$pagerMatch=false;$textMatch=true;
        // Search identity names, not display patterns that may contain department/site metadata.
        if($query!==''){$hay=$lower(implode(' ',array_map('strval',array_filter([$entry['display_name']??'',$entry['canonical_label']??($entry['label']??''),!empty($entry['directory_label_override_set'])?($entry['directory_label_override']??''):'']))));$textMatch=str_contains($hay,$textNeedle);if($textDigits!==''&&strlen($textDigits)>=$layout['numeric_min_length']){$extensionMatch=$layout['search_extension']&&str_contains($digits($entry['extension']??''),$textDigits);$mobileMatch=$layout['search_mobile']&&str_contains($digits($entry['mobile']??''),$textDigits);$pagerMatch=$layout['search_pager']&&str_contains($digits($entry['pager']??''),$textDigits);$textMatch=$textMatch||$extensionMatch||$mobileMatch||$pagerMatch;}}
        if($number!==''&&!$numericTooShort){$extensionMatch=$layout['search_extension']&&str_contains($digits($entry['extension']??''),$numberNeedle);$mobileMatch=$layout['search_mobile']&&str_contains($digits($entry['mobile']??''),$numberNeedle);$pagerMatch=$layout['search_pager']&&str_contains($digits($entry['pager']??''),$numberNeedle);}
        if(($query!==''&&!$textMatch)||($number!==''&&($numericTooShort||!$extensionMatch&&!$mobileMatch&&!$pagerMatch)))continue;
        $suffix=$pagerMatch&&!$extensionMatch&&!$mobileMatch?' - EMP No. match':($mobileMatch&&!$extensionMatch?' - Mobile match':'');$rows[]=['entry'=>$entry,'telephone'=>(string)($entry['extension']??''),'suffix'=>$suffix,'direct_mobile'=>false];
        if($mobileMatch&&$layout['mobile_result_mode']==='extension_and_mobile'&&trim((string)($entry['mobile']??''))!==''){$rows[]=['entry'=>$entry,'telephone'=>(string)$entry['mobile'],'suffix'=>' - Mobile','direct_mobile'=>true];}
    }
    $offset=$page*$pageSize;if($offset>=count($rows)&&$page>0){$page=0;$offset=0;}$pageRows=array_slice($rows,$offset,$pageSize);
    $rootNode=$doc->createElement('CiscoIPPhoneDirectory');$doc->appendChild($rootNode);$add($doc,$rootNode,'Title',$title);$last=min(count($rows),$offset+count($pageRows));$prompt=$numericTooShort?'Enter at least '.$layout['numeric_min_length'].' digits':(count($rows)?(($offset+1).'-'.$last.' of '.count($rows)):'No matching contacts');$add($doc,$rootNode,'Prompt',$prompt);
    foreach($pageRows as $row){$entry=$row['entry'];$item=$add($doc,$rootNode,'DirectoryEntry');$add($doc,$item,'Name',$cut($contactName($entry).$row['suffix']));$add($doc,$item,'Telephone',(string)$row['telephone']);}
    $pageCount=max(1,(int)ceil(count($rows)/$pageSize));$pageHasDirectMobile=(bool)array_filter($pageRows,static fn(array $row):bool=>!empty($row['direct_mobile']));
    // Legacy SIP XML uses the HTTP Refresh URL for its native Next key.
    if($format==='cisco_legacy' && $pageCount>1){header('Refresh: 0; url='.$nav('list',$nodeId,($page+1)%$pageCount,$direct,$query,$number));}
    if($format!=='cisco_legacy') {
        foreach($layout['cisco_softkeys']['list'] as $position=>$action){$url='';if($action==='Dial'){$url='SoftKey:Dial';}elseif($action==='EditDial'&&(!$pageHasDirectMobile||$layout['mobile_editdial'])){$url='SoftKey:EditDial';}elseif($action==='Search'&&$layout['search_enabled']){$url=$nav('search',$nodeId);}elseif($action==='Exit'){$url='SoftKey:Exit';}elseif($action==='Previous'&&$pageCount>1){$url=$nav('list',$nodeId,($page-1+$pageCount)%$pageCount,$direct,$query,$number);}elseif($action==='Next'&&$pageCount>1){$url=$nav('list',$nodeId,($page+1)%$pageCount,$direct,$query,$number);}if($url===''){continue;}$soft=$add($doc,$rootNode,'SoftKeyItem');$add($doc,$soft,'Name',$action);$add($doc,$soft,'URL',$url);$add($doc,$soft,'Position',(string)($position+1));}
    }
    echo $doc->saveXML();exit;
}

if ($format==='yealink') {
    $rootNode=$doc->createElement('YealinkIPPhoneBook');$doc->appendChild($rootNode);$add($doc,$rootNode,'Title',$layout['title']);
    foreach($groups as $group=>$items){$menu=$add($doc,$rootNode,'Menu');$menu->setAttribute('Name',$group);foreach($items as $entry){$unit=$doc->createElement('Unit');$unit->setAttribute('Name',$contactName($entry));$unit->setAttribute('Phone1',(string)$entry['extension']);$unit->setAttribute('Phone2',$layout['include_mobile']?(string)($entry['mobile']??''):'');$unit->setAttribute('Phone3','');$unit->setAttribute('default_photo','Resource:');$menu->appendChild($unit);}}
    echo $doc->saveXML();exit;
}

$rootNode=$doc->createElement('AddressBook');$doc->appendChild($rootNode);$add($doc,$rootNode,'version','1');$groupIds=[];$next=1;
foreach(array_keys($groups) as $group){$groupIds[$group]=$next++;$node=$add($doc,$rootNode,'pbgroup');$add($doc,$node,'id',(string)$groupIds[$group]);$add($doc,$node,'name',$group);}
foreach($contacts as $id=>$entry){$contact=$add($doc,$rootNode,'Contact');$add($doc,$contact,'FirstName',$contactName($entry));$add($doc,$contact,'Primary','0');$phone=$add($doc,$contact,'Phone');$phone->setAttribute('type','Work');$add($doc,$phone,'phonenumber',(string)$entry['extension']);$add($doc,$phone,'accountindex','0');if($layout['include_mobile']&&!empty($entry['mobile'])){$mobile=$add($doc,$contact,'Phone');$mobile->setAttribute('type','Mobile');$add($doc,$mobile,'phonenumber',(string)$entry['mobile']);$add($doc,$mobile,'accountindex','0');}if(!empty($entry['mail'])){$add($doc,$contact,'Email',(string)$entry['mail']);}$member=$add($doc,$contact,'Groups');foreach(array_keys($memberships[$id]??['All contacts'=>true]) as $group){if(isset($groupIds[$group])){$add($doc,$member,'groupid',(string)$groupIds[$group]);}}}
echo $doc->saveXML();
