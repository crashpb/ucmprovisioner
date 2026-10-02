<?php
declare(strict_types=1);
$source=file_get_contents(dirname(__DIR__).'/views/directory.php');
$lines=explode("\n",$source);
$markup=current(array_filter($lines,static fn(string $line):bool=>str_contains($line,'id="ucm-identity-extension"')));
$init=current(array_filter($lines,static fn(string $line):bool=>str_contains($line,"$('#ucm-identity-overrides .ucm-chosen').chosen(")));
if (!$markup || !$init) { throw new RuntimeException('Identity selector or searchable initialization missing'); }
$entries=[
    ['extension_id'=>1,'extension'=>'2101','site_name'=>'North','display_name'=>'Example Person','canonical_label'=>'a.bc'],
    ['extension_id'=>2,'extension'=>'2102','site_name'=>'South','display_name'=>'Security Desk','label'=>'security'],
    ['extension_id'=>3,'extension'=>'2103','site_name'=>'South','display_name'=>'Conflicted','conflicted'=>true],
];
$h=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');
ob_start();eval('?>'.$markup);$html=ob_get_clean();
if (!str_contains($html,'a.bc') || !str_contains($html,'2102') || str_contains($html,'Conflicted')) { throw new RuntimeException('Search options lost usernames/numbers or included a conflicted identity'); }
if (in_array('--html',$argv??[],true)) {
    echo '<!doctype html><html><head><meta charset="utf-8"><title>Identity selector fixture</title><link rel="stylesheet" href="chosen.min.css"><script src="jquery.js"></script><script src="chosen.js"></script></head><body><form id="ucm-identity-overrides" style="width:550px">'.$html.'<button>Save fixture</button></form><script>jQuery(function($){'.$init.'});</script></body></html>';
} else { echo "Identity selector renders extension, name and username with Chosen search initialization\n"; }

$start=strpos($source,"<?php if (!empty(\$stats['identity_skips'])): ?>");
if ($start===false) { throw new RuntimeException('Directory exclusion warning missing'); }
$end=strpos($source,'<?php endif; ?>',$start);
$warning=substr($source,$start,$end-$start+strlen('<?php endif; ?>'));
$stats=['identity_skips'=>[['site_id'=>2,'site_name'=>'Branch <script>alert(1)</script>','extension'=>'<200>','reason'=>'Account <missing> & pending']]];
ob_start();eval('?>'.$warning);$rendered=ob_get_clean();
if (!str_contains($rendered,'1 extension(s) skipped') || !str_contains($rendered,'&lt;200&gt;') || !str_contains($rendered,'Account &lt;missing&gt; &amp; pending') || str_contains($rendered,'<script>')) { throw new RuntimeException('Directory warning must display and escape the rejected site, extension and reason'); }
$stats=['identity_skips'=>[]];ob_start();eval('?>'.$warning);$rendered=ob_get_clean();
if (trim($rendered)!=='') { throw new RuntimeException('Recovered identities must no longer display an exclusion warning'); }
