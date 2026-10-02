<?php
declare(strict_types=1);
require __DIR__.'/IdentityBindingModesTest.php';
$row=$logical->ensurePbxExtension(1,'998');$id=(int)$row['id'];
$revision=(int)$row['revision'];
$saved=$logical->saveExtensionOverrides($id,['phone_label'=>'Desk','blf_label'=>'Security','directory_label'=>'Security Desk'],'fixture','test');
$expect(count($saved)===3 && (int)$logical->findById($id)['revision']===$revision+1,'Several fields must save in one identity revision');
$before=$logical->overridesForExtension($id);
try {$logical->saveExtensionOverrides($id,['phone_label'=>'Bad partial write','unsupported'=>'x'],'fixture','test');throw new RuntimeException('Invalid field accepted');}
catch(InvalidArgumentException){}
$expect($before===$logical->overridesForExtension($id) && (int)$logical->findById($id)['revision']===$revision+1,'Invalid multi-field input must leave every field and revision unchanged');
$logical->saveExtensionOverrides($id,['line_label'=>'0','directory_label'=>''],'fixture','test');
$values=array_column($logical->overridesForExtension($id),'value','field_name');
$expect($values['line_label']==='0' && $values['directory_label']==='' && $values['blf_label']==='Security','Zero/blank overrides must be preserved without erasing unselected fields');
echo "Atomic multi-field identity override fixtures passed\n";
