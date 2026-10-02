<?php
declare(strict_types=1);

/** Reserved-domain, synthetic contacts shared by the data and HTTP regression checks. */
function webPhonebookFixture(): array
{
    $contacts = [];
    foreach (range(1, 36) as $number) {
        $id = 'fixture-person-' . $number;
        $contacts[$id] = [
            'source_key'=>$id,'display_name'=>'Person ' . str_pad((string)$number,2,'0',STR_PAD_LEFT),
            'extension'=>(string)(2100+$number),'department'=>$number%2?'Finance':'Operations',
            'site_name'=>$number%3?'Main office':'North branch','mail'=>'person'.$number.'@example.com',
            'company'=>'Example Group','mobile'=>'+1 202 555 0106','pager'=>'004726',
            'canonical_label'=>'person'.$number,'domain'=>'private.example','attributes'=>['private_note'=>'DO NOT PUBLISH'],
            'groups'=>['Private group'],'ou_path'=>['Private OU'],
        ];
    }
    $contacts['fixture-person-1']['display_name']='Ava Mehr';
    $contacts['fixture-person-2']['display_name']='کیان مهر';
    $contacts['fixture-person-3']['display_name']='=2+2';
    $contacts['fixture-person-3']['extension']='00123';
    $contacts['fixture-person-4']['display_name']='<script>window.bad=true</script>';
    return ['generated'=>1788659400,'source'=>['private'=>'DO NOT PUBLISH'],
        'entries'=>[['display_name'=>'Excluded root contact','extension'=>'9999']],
        'phonebooks'=>[
            ['book_key'=>'company','name'=>'Company directory','schema_version'=>2,'contacts'=>$contacts],
            ['book_key'=>'services','name'=>'Shared services','schema_version'=>2,'contacts'=>array_slice($contacts,0,2,true)],
            ['book_key'=>'private','name'=>'Private phonebook','schema_version'=>2,'contacts'=>array_slice($contacts,2,1,true)],
        ]];
}
