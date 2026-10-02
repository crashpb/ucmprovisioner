'use strict';
const fs=require('node:fs'),path=require('node:path'),vm=require('node:vm'),assert=require('node:assert/strict');
const read=name=>fs.readFileSync(path.join(__dirname,'../',name),'utf8');
const template=read('views/templates.php'),operator=read('assets/js/operator-workflow.js');
function extract(source,name){const start=source.indexOf('  function '+name+'(');assert.ok(start>=0,name);const end=source.indexOf('\n  function ',start+1);return source.slice(start,end).split(/\n  \$\(/)[0];}
const values={'#ucm-advanced-settings':'{}'},visible={key:'test_visible_setting',value:'changed without blur'},sent=[];
const empty={each(){return this;},filter(){return this;},is(){return false;}};
function $(selector){if(selector===visible)return{is:()=>false,data:key=>visible[key],val:()=>visible.value};if(selector==='.ucm-setting:visible')return{each(fn){fn.call(visible);}};return Object.assign({},empty,{val(value){if(value===undefined)return values[selector];values[selector]=value;return this;}});}
$.each=(object,fn)=>Object.keys(object).forEach(key=>fn(key,object[key]));
let policyOk=true,buttonSerializations=0;
const context={$,JSON,Object,Array,String,alert(){},isGhp63x:()=>false,modelKey:()=>'',family:()=> 'yealink',scheduleFields:{},firmwareSelections:{},inactiveFirmwareSelections:{},timezoneSelections:{},wallpaperSelections:{},hostedImageSelections:{},hostedRingtoneSelection:'',dialRules:()=>[],ghpSpeedDials:()=>[],serializeButtons(){buttonSerializations++;},serializePolicy:()=>policyOk};
vm.createContext(context);vm.runInContext(extract(template,'serializeSettings'),context);vm.runInContext(extract(template,'serializeDraft'),context);
const click=operator.match(/\.text\('Compare unsaved draft impact'\)\.on\('click',function \(\) \{([\s\S]*?)\n      \}\)\);/);assert.ok(click,'Actual impact handler found');
context.form={triggerHandler(event){assert.equal(event,'ucmp:serialize-draft');return context.serializeDraft();},serializeArray(){return[{name:'settings',value:values['#ucm-tpl-settings-json']}];},find(){return{first(){return this;},val:()=> '1',each(){}};}};
context.params={get:()=> '1'};context.tab='templates';context.dialog=()=>({});context.review=(dialog,request)=>sent.push(request);
function compare(){vm.runInContext('(function(){'+click[1]+'})()',context);}
compare();assert.equal(sent.length,1);assert.equal(JSON.parse(JSON.parse(sent[0].values).settings).test_visible_setting,visible.value,'Visible draft serialized before request');assert.equal(buttonSerializations,1);
values['#ucm-advanced-settings']='{broken';compare();assert.equal(sent.length,1,'Malformed JSON prevents request');
values['#ucm-advanced-settings']='[]';compare();assert.equal(sent.length,1,'List-shaped JSON prevents request');
values['#ucm-advanced-settings']='{}';policyOk=false;compare();assert.equal(sent.length,1,'Invalid softkey policy prevents request');
for(const file of ['templates','groups','settings'])assert.ok(read('views/'+file+'.php').includes(".on('ucmp:serialize-draft',serializeDraft)"),file+' uses dedicated serializer, not submit');
console.log('Operator draft serialization assertions passed');
const requests=[],clicks=[];
function jq(){return{text(){return this;},on(event,fn){clicks.push(fn);return this;},prop(){return this;}};}jq.extend=Object.assign;
const paging={$:jq,output(){},request(payload){requests.push(payload);return{done(fn){fn({pagination:{next_after_phone_id:requests.length===1?5:null,inventory_hash:'fixed-inventory'}});return this;},fail(){return this;}};}};
vm.createContext(paging);vm.runInContext(extract(operator,'review'),paging);
const view={current:()=>true,body:{find(){return{remove(){}};},append(){}}},fixed={op:'impact',site_id:1,kind:'settings',values:'{"label":"reviewed"}'};
paging.review(view,fixed);assert.equal(clicks.length,1);clicks[0]();assert.equal(requests.length,2);assert.equal(requests[1].after_phone_id,5);assert.equal(requests[1].inventory_hash,'fixed-inventory');assert.equal(requests[1].values,fixed.values,'Next page keeps the exact serialized owner draft');
console.log('Operator impact pagination UI assertions passed');

// Exercise the real request adapter: structured failures must reach the dialog.
const rejected={ok:false,state:'no_eligible_relationships',eligible_count:0,skipped_count:4,message:'No eligible relationships'};
const failureContext={window:{ucmpAjax(){return{then(ok){return ok({result:rejected});}};}},$:{extend:Object.assign,Deferred(){return{reject(value){return{promise(){return value;}};}};}}};
vm.createContext(failureContext);vm.runInContext(extract(operator,'request'),failureContext);
assert.equal(failureContext.request({}).state,rejected.state);
assert.equal(failureContext.request({}).skipped_count,4);
failureContext.window.ucmpAjax=()=>({then(ok,fail){return fail({responseText:'private payload'});}});
assert.equal(failureContext.request({}).state,'request_failed');
assert.equal(JSON.stringify(failureContext.request({})).includes('private payload'),false);
vm.runInContext(extract(operator,'safeKey'),failureContext);
for(const key of ['bind_password','secret_value','api_token','private_key','input','plan_hash'])assert.equal(failureContext.safeKey(key),false,key);
assert.equal(failureContext.safeKey('plan_id'),true,'Retained plan ID remains visible');
assert.ok(!operator.includes('output(view,String(error))'),'Error handlers must preserve structured detail');
assert.ok(operator.includes("!$('#ucm-operator-identity-toolbar').length"),'Toolbar respects an existing rendered instance');
console.log('Operator structured-error and sensitive-key assertions passed');
