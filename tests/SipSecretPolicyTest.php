<?php
declare(strict_types=1);
require_once __DIR__.'/../lib/SipSecretPolicy.php';
use FreePBX\modules\Ucmprovisioner\SipSecretPolicy;
$expect=static function(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);};
foreach (['cisco_cnf'=>16,'cisco_xml'=>30,'yealink'=>256,'grandstream'=>256] as $family=>$limit) {
    $expect(!SipSecretPolicy::needsAlignment(str_repeat('x',$limit),$family),'Supported passwords must remain valid');
    $expect(SipSecretPolicy::needsAlignment(str_repeat('x',$limit+1),$family),'Overlong passwords need alignment');
}
$expect(SipSecretPolicy::needsAlignment('quote"','cisco_cnf') && !SipSecretPolicy::needsAlignment('A&b"7','cisco_xml'),'Preserve XML-supported characters while enforcing CNF syntax');
$key=str_repeat('private-fixture-key',3);$secret=SipSecretPolicy::aligned('100','old',$key);
$expect(preg_match('/^[A-Za-z0-9]{16}$/D',$secret)===1 && $secret===SipSecretPolicy::aligned('100','old',$key),'Replacement must fit every supported family and be stable for preview');
$expect($secret!==SipSecretPolicy::aligned('101','old',$key) && $secret!==SipSecretPolicy::aligned('100','new',$key),'Account and current password must bind the replacement');
echo "SIP password compatibility and deterministic alignment passed\n";
