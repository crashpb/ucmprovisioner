<?php
declare(strict_types=1);
/** Synthetic structurally valid containers, never proprietary runtime bytes. */
function timezoneRuntimeFixture(string $name, string $metadata = ''): string
{
    if (strtolower($name)==='tzdatacsv.csv') {
        return "Table:TypeTimeZone\nMoniker,TZname,Offset,DSTStart,DSTend,DSTBias,PreTZname\n\"TIMEZONE_ETC_UTC\",\"Etc/UTC\",\"0\",\"\",\"\",\"\",\"Etc/UTC\"\n# " . $metadata . "\n";
    }
    $archive=static function(array $files): string {
        $path=tempnam(sys_get_temp_dir(),'ucmp-tz-fixture-');$zip=new ZipArchive();
        try {
            if ($zip->open($path,ZipArchive::OVERWRITE)!==true) { throw new RuntimeException('Cannot create runtime fixture'); }
            foreach ($files as $file=>$bytes) { $zip->addFromString($file,$bytes);$zip->setCompressionName($file,ZipArchive::CM_STORE);$zip->setMtimeName($file,946684800); }
            $zip->close();return (string)file_get_contents($path);
        } finally { if (is_file($path)) { unlink($path); } }
    };
    $zones=$archive(['Etc/UTC'=>"javazi\0\x01" . $metadata . "\n"]);
    return $archive(['META-INF/MANIFEST.MF'=>"Manifest-Version: 1.0\n",$name==='j9-tzdata.jar'?'zi.zip':'data/tzdata-fixture.zip'=>$zones]);
}
