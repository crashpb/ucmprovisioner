<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/ApiFileStream.php';
use FreePBX\modules\Ucmprovisioner\ApiFileStream;
if (!ApiFileStream::available()) { echo "Binary file-stream fixtures require the PHP sodium extension\n"; return; }
$expect = static function (bool $ok, string $message): void { if (!$ok) { throw new RuntimeException($message); } };
$reject = static function (callable $operation): void {
    try { $operation(); } catch (RuntimeException|SodiumException $expected) { return; }
    throw new LogicException('Damaged stream was accepted');
};
$secret = str_repeat('ab',32); $nonce = str_repeat('cd',16);
foreach ([0,1,1048576,1048577,5242883] as $size) {
    $input = tmpfile(); $output = tmpfile();
    for ($remaining=$size; $remaining>0; $remaining-=strlen($bytes)) { $bytes=random_bytes(min(65536,$remaining)); fwrite($input,$bytes); }
    $reader=ApiFileStream::reader($input,$size,$secret,$nonce,'upload');
    $decoder=new ApiFileStream($output,$size,$secret,$nonce,'upload'); $wire=0;
    while (($bytes=$reader(17381))!=='') { $expect(strlen($bytes)===min(17381,ApiFileStream::wireBytes($size)-$wire),'Non-final reads must fill the requested cURL buffer across encryption records'); $wire+=strlen($bytes); $decoder->write($bytes); }
    $decoder->finish(); rewind($input);
    $left=hash_init('sha256');hash_update_stream($left,$input);$right=hash_init('sha256');hash_update_stream($right,$output);
    $expect(hash_final($left)===hash_final($right),'Every decoded byte must match, including partial final records');
    $expect($wire===ApiFileStream::wireBytes($size),'Content-Length must match the exact encrypted body');
    fclose($input);fclose($output);
}
$input=tmpfile();fwrite($input,str_repeat('x',1048583));
$slice=tmpfile();$rangeReader=ApiFileStream::reader($input,7,$secret,$nonce,'upload',1048576);
$rangeDecoder=new ApiFileStream($slice,7,$secret,$nonce,'upload');
while(($bytes=$rangeReader(65536))!=='')$rangeDecoder->write($bytes);
$rangeDecoder->finish();$expect(stream_get_contents($slice)==='xxxxxxx','A resumed stream must encode only the requested source range');fclose($slice);
$reject(fn()=>ApiFileStream::reader($input,8,$secret,$nonce,'upload',1048576));
$encode=static function () use ($input,$secret,$nonce):string {$read=ApiFileStream::reader($input,1048583,$secret,$nonce,'upload');$out='';while(($part=$read(65536))!=='')$out.=$part;return $out;};
$wire=$encode();
$decode=static function(string $body,string $direction='upload',?string $otherNonce=null)use($secret,$nonce):void{
    $out=tmpfile();try{$d=new ApiFileStream($out,1048583,$secret,$otherNonce??$nonce,$direction);foreach(str_split($body,32768) as $part)$d->write($part);$d->finish();}finally{fclose($out);}
};
$reject(fn()=>$decode(substr($wire,0,-1)));
$reject(fn()=>$decode($wire.'x'));
$damaged=$wire;$damaged[40]=chr(ord($damaged[40])^1);$reject(fn()=>$decode($damaged));
$reject(fn()=>$decode($wire,'download'));
$reject(fn()=>$decode($wire,'upload',str_repeat('ef',16)));
$record=substr($wire,24,1048576+17);
$reject(fn()=>$decode(substr($wire,0,24).$record.$record));
$expect(ApiFileStream::wireBytes(100*1024*1024)===100*1024*1024+1724,'100 MiB must add only the stream header and authentication tags');
fclose($input);
echo "Binary API stream byte identity, bounded overhead, truncation, tamper, replay and direction checks passed\n";
