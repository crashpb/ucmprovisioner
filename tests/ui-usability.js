'use strict';
const fs=require('node:fs'),path=require('node:path'),vm=require('node:vm'),assert=require('node:assert/strict');
const read=file=>fs.readFileSync(path.join(__dirname,'..',file),'utf8');
// MAC validation runs before Save, preserving the editor draft on rejection.
{
  const view=read('views/phones.php'),begin=view.indexOf('      function syncCustomMac()'),end=view.indexOf('      function syncMacFromSelect()',begin);
  const elements=new Map();
  function element(key){if(!elements.has(key))elements.set(key,{value:'',props:{},attrs:{},message:'',node:{setCustomValidity(value){this.error=value;}},val(value){if(arguments.length){this.value=value;return this;}return this.value;},prop(key,value){this.props[key]=value;return this;},attr(key,value){this.attrs[key]=value;return this;},text(value){this.message=value;return this;},get(){return this.node;}});return elements.get(key);}
  const scope={$:element};vm.createContext(scope);vm.runInContext(view.slice(begin,end),scope);
  const input=element('#ucm-mac-custom'),hidden=element('#ucm-mac-value'),select=element('#ucm-mac-select');
  select.val('__custom__');
  for(const value of ['aabbccddeeff','AABBCCDDEEFF','AaBbCcDdEeFf','aa:bb:cc:dd:ee:ff','AA-BB-CC-DD-EE-FF','aabb.ccdd.eeff',' aa bb cc dd ee ff ']){
    input.val(value);assert.equal(scope.syncCustomMac(),true);assert.equal(input.val(),'AABBCCDDEEFF');assert.equal(hidden.val(),'AABBCCDDEEFF');
  }
  for(const value of ['AABBCCDDEEF','AABBCCDDEEFF0','AABBCCDDEEFG']){
    input.val(value);assert.equal(scope.syncCustomMac(),false);assert.match(input.node.error,/12 hexadecimal/);assert.equal(element('#ucm-mac-error').props.hidden,false);
    assert.equal(input.val(),value,'Invalid input must stay visible for correction');
  }
  input.val('');assert.equal(scope.syncCustomMac(),true,'Existing extension-only records may remain unbound');
  hidden.val('001122334455');select.val('001122334455');input.val('bad');
  assert.equal(scope.syncCustomMac(),true);assert.equal(hidden.val(),'001122334455');assert.equal(input.props.disabled,true);
  assert.match(view,/if \(!syncCustomMac\(\)\) \{\s*event\.preventDefault\(\)/);
  assert.doesNotMatch(view.match(/<input[^>]*id="ucm-mac-custom"[^>]*>/)[0],/maxlength=/,'A formatted paste must not be truncated before normalization');
  console.log('MAC case/format normalization, length validation, known selection and Save guard passed');
}
// Exercise actual status polling and the close/reopen handler, including navigation.
{
  const page=read('page.ucmprovisioner.php'),begin=page.indexOf('    (function replicationActivity() {'),end=page.indexOf('    })();',begin)+9;
  const saved=new Map(),requests=[];
  function mount(){
    const elements=new Map();
    function element(key){if(!elements.has(key))elements.set(key,{length:1,props:{hidden:true},events:{},find(name){return element(name);},prop(name,value){if(arguments.length===1)return this.props[name];this.props[name]=value;return this;},attr(){return this;},text(){return this;},empty(){return this;},append(){return this;},children(){return{length:1};},css(){return this;},toggleClass(){return this;},addClass(){return this;},on(name,fn){this.events[name]=fn;return this;}});return elements.get(key);}
    const context={document:{hidden:false},Date,Number,String,Math,window:{clearTimeout(){},setTimeout(){return 1;},sessionStorage:{getItem:key=>saved.get(key)||null,setItem:(key,value)=>saved.set(key,value)},ucmpReplicationResults:()=>null,ucmpAjax(){const request={done(fn){this.success=fn;return this;},fail(){return this;},always(fn){this.finish=fn;return this;}};requests.push(request);return request;}},$(key){return typeof key==='object'&&key.props?key:element(key);}};
    vm.createContext(context);vm.runInContext(page.slice(begin,end),context);
    return{context,body:element('.ucmp-replication-activity-body'),toggle:element('.ucmp-replication-activity-toggle')};
  }
  function respond(){const r=requests.at(-1);r.success({status:true,result:{ok:true,runs:[{id:1,status:'running',started:1}]}});r.finish();}
  let panel=mount();respond();assert.equal(panel.body.props.hidden,true,'Background polling must not open a closed panel');
  panel.toggle.events.click.call(panel.toggle);respond();assert.equal(panel.body.props.hidden,false,'An explicit click opens status');
  panel.toggle.events.click.call(panel.toggle);panel.context.window.ucmpReplicationActivityRefresh();respond();
  assert.equal(panel.body.props.hidden,true,'The next active-job poll must honor dismissal');
  panel=mount();respond();assert.equal(panel.body.props.hidden,true,'Dismissal survives page navigation');
  panel.toggle.events.click.call(panel.toggle);respond();panel=mount();respond();assert.equal(panel.body.props.hidden,false,'Explicitly open status survives page navigation');
}
const script=read('assets/js/ucmprovisioner.js');
const start=script.indexOf('  function pinSelectedOptions('),end=script.indexOf('\n  $(function',start);
assert.ok(start>=0&&end>start,'Use the actual selected-option sorter');
const context={};vm.createContext(context);vm.runInContext(script.slice(start,end),context);
const select={options:Array.from({length:100},(_,n)=>({value:String(n+1),text:'Phonebook '+String(n+1).padStart(3,'0'),selected:n===1||n===98})),appendChild(option){this.options.splice(this.options.indexOf(option),1);this.options.push(option);}};
const selected=()=>select.options.filter(option=>option.selected).map(option=>option.value);
context.pinSelectedOptions(select);
assert.deepEqual(selected(),['2','99']);
assert.deepEqual(select.options.slice(0,3).map(option=>option.value),['2','99','1']);
select.options.find(option=>option.value==='80').selected=true;
context.pinSelectedOptions(select);
assert.deepEqual(select.options.slice(0,3).map(option=>option.value),['2','80','99']);
select.options.find(option=>option.value==='2').selected=false;
context.pinSelectedOptions(select);
assert.deepEqual(selected(),['80','99']);
assert.equal(new Set(select.options).size,100,'Sorting must preserve every original option');
select.options.forEach(option=>{option.selected=false;});context.pinSelectedOptions(select);
assert.equal(select.options[0].value,'1');assert.equal(select.options[99].value,'100');
const books=read('views/web_phonebook_settings.php'),phones=read('views/phones.php'),operator=read('assets/js/operator-workflow.js'),f2b=read('views/fail2ban.php');
for(const field of ['name','extension'])assert.ok(books.includes('name="web_phonebook[fields][]" value="'+field+'"'),'Required field submits even when its option is disabled');
assert.ok(books.includes('name="web_phonebook[books][]" multiple'),'Phonebook form submits multiple values');
assert.ok(phones.includes('ucm-assignment-details')&&phones.includes('Deployment details'),'Assignment evidence stays available behind details');
assert.ok(operator.includes(".text('More actions')")&&operator.includes(".text('Advanced identity actions')"),'Secondary actions are grouped');
assert.ok(operator.includes('aria-labelledby="ucm-operator-title"'),'Operator dialog announces its title');
assert.ok(f2b.includes("#ucm-f2b-jails tr[data-jail]")&&f2b.includes('View clients / unban'),'Jail row and explicit client action remain available');
assert.ok(f2b.includes("confirm('Unban '+ip+' from '+jail+' on '+siteName()")&&f2b.includes("call({op:'unban',jail:jail,ip:ip}"),'Unban keeps exact jail/IP confirmation and request');
let unbanClick,confirmed=false,prompt='',rendered=null,hidden=false;
const requests=[],client={ip:'192.0.2.10'},detail={jail:'sshd',banned:[{ip:'192.0.2.11'}]};
let returnedDetail=detail;
const unbanContext={document:{},state:{detail:{jail:'sshd'}},siteName:()=> 'Test PBX',confirm(message){prompt=message;return confirmed;},feedback(){},renderDetail(value){rendered=value;},call(payload){requests.push(payload);return{done(fn){fn({message:'Synthetic result',detail:returnedDetail});return this;},fail(){return this;}};},$(element){return element===client?{data:key=>client[key]}:element==='#ucm-f2b-detail'?{hide(){hidden=true;}}:{on(event,selector,handler){unbanClick=handler;}};}};
vm.createContext(unbanContext);
vm.runInContext(f2b.split('\n').find(line=>line.includes("$(document).on('click','.ucm-f2b-unban'")),unbanContext);
unbanClick.call(client);assert.equal(requests.length,0,'Cancel must not send a request');
assert.ok(prompt.includes('192.0.2.10 from sshd on Test PBX'),'Confirmation names the exact client, jail and site');
confirmed=true;unbanClick.call(client);
assert.equal(JSON.stringify(requests),JSON.stringify([{op:'unban',jail:'sshd',ip:'192.0.2.10'}]));
assert.equal(rendered,detail,'Successful response refreshes only the returned jail detail');
unbanContext.state.detail=null;unbanClick.call(client);assert.equal(requests.length,1,'Missing jail state cannot unban');
unbanContext.state.detail={jail:'sshd'};returnedDetail=undefined;unbanClick.call(client);
assert.equal(requests.length,2,'A completed unban without refreshed detail must not be retried');
assert.equal(unbanContext.state.detail,null);assert.equal(hidden,true,'Hide stale client controls when refresh fails');
const resultContext={};vm.createContext(resultContext);
vm.runInContext(script.slice(script.indexOf('  function escapeHtml('),script.indexOf('  function doRebuild(')),resultContext);
const partialHtml=resultContext.renderApplyResult({message:'Files published',stages:{files_published:true,pbx_activated:true,device_observed:null},
  restarts:[{ext:'4308',ok:true,message:'Dispatched'},{ext:'4309',ok:false,message:'No routable address <script>'}]},false);
assert.ok(partialHtml.includes('Partially completed.')&&partialHtml.includes('published and verified')&&partialHtml.includes('PBX activation: completed'));
assert.ok(partialHtml.includes('1 phone reload request(s) dispatched.')&&partialHtml.includes('1 failed or unconfirmed.'));
assert.ok(partialHtml.includes('4309: failed or unconfirmed')&&partialHtml.includes('&lt;script&gt;')&&!partialHtml.includes('<script>'));
assert.ok(!partialHtml.includes('Phones will re-register'),'Dispatch cannot claim observed device acceptance');
assert.ok(resultContext.renderApplyResult({message:'No files published'},false).includes('<strong>Failed.</strong>'));
console.log('Usability selected-order, preserved-action and partial build result assertions passed');
const patchView=read('views/patches.php'),patchControls=[{disabled:false},{disabled:true}],patchRequests=[];
let patchMessage='';
const patchContext={local:true,reloading:false,message(value){patchMessage=value;},$:()=>({each(fn){patchControls.forEach(control=>fn.call(control));}}),window:{ucmpAjax(options){
  const pending={options,done(fn){this.success=fn;return this;},fail(fn){this.failure=fn;return this;},always(fn){this.finish=fn;return this;}};patchRequests.push(pending);return pending;
}}};
vm.createContext(patchContext);
vm.runInContext(patchView.slice(patchView.indexOf('  var busy='),patchView.indexOf('  function request(',patchView.indexOf('  var busy='))),patchContext);
patchContext.submit({op:'import'},()=>{});patchContext.submit({op:'sync'},()=>{});
assert.equal(patchRequests.length,1,'Only one Patch Manager mutation may be pending');
assert.ok(patchControls.every(control=>control.disabled));
patchRequests[0].failure({});patchRequests[0].finish();
assert.ok(patchMessage.includes('Refresh inventory'),'A lost response must give an actionable message');
assert.deepEqual(patchControls.map(control=>control.disabled),[false,true],'Previously protected buttons must remain disabled');
patchContext.submit({op:'capture'},()=>{patchContext.reloading=true;});
patchRequests[1].success({});patchRequests[1].finish();patchContext.submit({op:'capture'},()=>{});
assert.equal(patchRequests.length,2,'Keep mutation controls busy until the success reload');
console.log('Patch Manager pending-request and failure recovery assertions passed');

// Execute the actual shared modal function with responses deliberately reversed.
const rebuildElements=new Map(),rebuildRequests=[];
function rebuildElement(key) {
  if(!rebuildElements.has(key))rebuildElements.set(key,{length:1,properties:{},events:{},html(value){this.content=value;return this;},text(value){this.label=value;return this;},prop(name,value){if(arguments.length===1)return this.properties[name];this.properties[name]=value;return this;},show(){return this;},hide(){return this;},modal(){return this;},off(){this.events={};return this;},on(name,fn){this.events[name]=fn;return this;},is(){return false;},removeClass(){return this;},addClass(){return this;},trigger(){return this;}});
  return rebuildElements.get(key);
}
const rebuildContext={rebuildRequestGeneration:0,rebuildApplying:false,ensureRebuildModal(){},renderRebuildPlan:r=>r.plan_hash,renderApplyResult:()=>'',escapeHtml:value=>String(value),document:{},$:rebuildElement,window:{ucmpAjax(options){const request={options,done(fn){this.success=fn;return this;},fail(fn){this.failure=fn;return this;}};rebuildRequests.push(request);return request;}}};
vm.createContext(rebuildContext);
vm.runInContext(script.slice(script.indexOf('  function doRebuild('),script.indexOf("  $(document).on('click', '#ucm-rebuild-toggle-raw'")),rebuildContext);
rebuildContext.doRebuild('11',1,false);rebuildContext.doRebuild('22',2,false);
const previewResult=hash=>({status:true,result:{ok:true,phones:[{}],plan_hash:hash}});
rebuildRequests[1].success(previewResult('second-plan'));rebuildRequests[0].success(previewResult('first-plan'));
assert.equal(rebuildElement('#ucm-rebuild-modal-body').content,'second-plan','An older preview must not retarget the visible Apply action');
rebuildElement('#ucm-rebuild-apply-btn').events.click();rebuildElement('#ucm-rebuild-apply-btn').events.click();rebuildContext.doRebuild('33',3,false);
assert.equal(rebuildRequests.length,3,'Only one apply may own the shared modal');
assert.equal(rebuildRequests[2].options.data.ids,'22');assert.equal(rebuildRequests[2].options.data.plan_hash,'second-plan');
rebuildRequests[2].failure();assert.equal(rebuildContext.rebuildApplying,false);
assert.ok(rebuildElement('#ucm-rebuild-modal-body').content.includes('Reconcile'),'An uncertain apply must request reconciliation');
console.log('Rebuild stale-preview and single-apply assertions passed');
const cutoverHandlers={},cutoverRequests=[],cutoverElements=new Map();
function cutoverElement(key){
  if(typeof key==='object'&&key.properties)return key;
  if(!cutoverElements.has(key))cutoverElements.set(key,{properties:{},values:{id:1},data(name,value){if(arguments.length===1)return this.values[name];this.values[name]=value;return this;},removeData(name){delete this.values[name];return this;},prop(name,value){if(arguments.length===1)return this.properties[name];this.properties[name]=value;return this;},text(value){this.label=value;return this;},attr(){return this;},removeClass(){return this;},addClass(){return this;},toggleClass(){return this;},on(event,selector,fn){cutoverHandlers[selector]=fn;return this;}});
  return cutoverElements.get(key);
}
const cutoverContext={cutoverApplying:false,cutoverPreviewGeneration:0,document:{},$:cutoverElement,window:{confirm:()=>true,setTimeout(){},ucmpAjax(options){const request={options,done(fn){this.success=fn;return this;},fail(fn){this.failure=fn;return this;},always(fn){this.finish=fn;return this;}};cutoverRequests.push(request);return request;}}};
vm.createContext(cutoverContext);
vm.runInContext(script.slice(script.indexOf("  $(document).on('click', '#ucm-tftp-cutover-preview'"),script.indexOf("  $(document).on('click', '.ucm-tftp-permissions'")),cutoverContext);
const cutoverPreview=cutoverHandlers['#ucm-tftp-cutover-preview'],cutoverApply=cutoverHandlers['#ucm-tftp-cutover-apply'],previewButton=cutoverElement('#ucm-tftp-cutover-preview'),applyButton=cutoverElement('#ucm-tftp-cutover-apply');
cutoverPreview.call(previewButton);cutoverPreview.call(previewButton);
const cutoverResponse=hash=>({status:true,result:{ok:true,plan_hash:hash,source_mode:'docker',target_mode:'pbx',files:1,bytes:10}});
cutoverRequests[1].success(cutoverResponse('new-plan'));cutoverRequests[0].success(cutoverResponse('old-plan'));
assert.equal(applyButton.values['plan-hash'],'new-plan','Late cutover preview must not replace the current plan');
cutoverApply.call(applyButton);cutoverApply.call(applyButton);cutoverPreview.call(previewButton);
assert.equal(cutoverRequests.length,3,'A cutover apply cannot be duplicated or overlapped by another preview');
assert.equal(cutoverRequests[2].options.data.plan_hash,'new-plan');
cutoverRequests[2].failure({});cutoverRequests[2].finish();cutoverApply.call(applyButton);
assert.equal(cutoverRequests.length,3,'An uncertain cutover cannot reuse its old Apply action');
assert.ok(cutoverElement('#ucm-tftp-cutover-feedback').label.includes('unconfirmed'));
console.log('Cutover stale-preview and lost-response assertions passed');
const page=read('page.ucmprovisioner.php');
const submitStart=page.indexOf("    $(document).on('click', '#action-bar input"),submitEnd=page.indexOf('    window.ucmpReplicationResults',submitStart);
let submitClick,submitCount=0,formBusy=false,formValid=false;
const submitForm={length:1,data(name,value){if(arguments.length===1)return formBusy;formBusy=value;return this;},removeData(){formBusy=false;return this;},attr(){return '';},get(){return{reportValidity:()=>formValid};},submit(){submitCount++;return this;},trigger(event){submitCount++;event.preventDefault();return this;}};
const submitJquery=key=>key==='form.fpbx-submit'?submitForm:{on(event,selector,fn){submitClick=fn;}};
submitJquery.Event=()=>({prevented:false,preventDefault(){this.prevented=true;},isDefaultPrevented(){return this.prevented;}});
const submitContext={document:{},$:submitJquery};vm.createContext(submitContext);
vm.runInContext(page.slice(submitStart,submitEnd).replace('<?php echo json_encode($active); ?>',"'phones'"),submitContext);
submitClick({preventDefault(){}});assert.equal(submitCount,0,'Invalid form fields must stop action-bar submission');
formValid=true;submitClick({preventDefault(){}});submitClick({preventDefault(){}});
assert.equal(submitCount,2,'A cancelled form review must not strand the next submit');
console.log('Action-bar validity and cancelled-submit assertions passed');
let selectedTrunk='original';
const trunkSelect=cutoverElement('#ucm-trunk-endpoint');
trunkSelect.val=function(value){if(!arguments.length)return selectedTrunk;selectedTrunk=value;return this;};
trunkSelect.empty=function(){selectedTrunk='';return this;};trunkSelect.append=function(){return this;};
const option=cutoverElement('<option>');option.val=function(){return this;};
const trunkStart=script.indexOf("  $(document).on('click', '.ucm-refresh-trunks'"),trunkEnd=script.indexOf("  $(document).on('change', '#ucm-check-all'",trunkStart);
vm.runInContext(script.slice(trunkStart,trunkEnd),cutoverContext);
const trunkButton=cutoverElement('trunk-button');cutoverHandlers['.ucm-refresh-trunks'].call(trunkButton);
cutoverRequests.at(-1).success({status:false,message:'Fixture read failed'});cutoverRequests.at(-1).finish();
assert.equal(selectedTrunk,'original','Failed trunk inventory must retain the selected endpoint');
cutoverHandlers['.ucm-refresh-trunks'].call(trunkButton);selectedTrunk='newer-selection';
cutoverRequests.at(-1).success({status:true,result:{ok:true,trunks:[{endpoint:'newer-selection'}]}});cutoverRequests.at(-1).finish();
assert.equal(selectedTrunk,'newer-selection','Trunk inventory must preserve a selection edited while the request was pending');
cutoverContext.meshApplying=false;cutoverContext.renderMeshPlan=()=>'';
cutoverElement('#ucm-detail-result').show=function(){return this;};
vm.runInContext(script.slice(script.indexOf("  $(document).on('click', '.ucm-meshsync'"),script.indexOf("  $(document).on('click', '.ucm-preview'")),cutoverContext);
const meshButton=cutoverElement('mesh-button'),meshClick=cutoverHandlers['.ucm-meshsync'];
meshClick.call(meshButton,{preventDefault(){}});const meshPreview=cutoverRequests.at(-1);
meshPreview.success({status:true,result:{ok:true,plan_hash:'fixture',participants:{one:{changed:true}}}});meshPreview.finish();
const meshCommit=cutoverRequests.at(-1),meshCount=cutoverRequests.length;
meshClick.call(meshButton,{preventDefault(){}});assert.equal(cutoverRequests.length,meshCount,'Mesh commit owns the action until its receipt arrives');
meshCommit.failure();meshCommit.finish();
assert.equal(cutoverContext.meshApplying,false);assert.equal(meshButton.properties.disabled,false);
assert.ok(cutoverElement('#ucm-action-result').label.includes('unconfirmed'));
console.log('Trunk refresh and mesh lost-response assertions passed');

const configurationView=read('views/sites.php'),configurationRequests=[],configurationNodes=new Map(),configurationLoads=[];
function configurationElement(key){
  if(!configurationNodes.has(key))configurationNodes.set(key,{properties:{},children:[],empty(){this.children=[];return this;},append(...children){this.children.push(...children);return this;},text(value){this.label=String(value);return this;},prop(name,value){this.properties[name]=value;return this;},attr(){return this;},toggleClass(){return this;}});
  if(String(key).startsWith('<')){const element=configurationNodes.get(key);configurationNodes.delete(key);return element;}
  return configurationNodes.get(key);
}
let configurationFeedback='',configurationDraws=0;
const configurationContext={selected:{id:7},jobs:[],busy:false,dirty:false,editorGeneration:1,refreshGeneration:0,siteNames:{2:'Branch <two>',3:'Branch three'},$:configurationElement,
  load(job){configurationLoads.push(job);configurationContext.editorGeneration++;},drawList(){configurationDraws++;},feedback(message){configurationFeedback=message;},
  window:{confirm:()=>true,ucmpAjax(options){const request={options,done(fn){this.success=fn;return this;},fail(fn){this.failure=fn;return this;},always(fn){this.finish=fn;return this;}};configurationRequests.push(request);return request;}}};
vm.createContext(configurationContext);
vm.runInContext(configurationView.slice(configurationView.indexOf('      function setBusy('),configurationView.indexOf('      function pickerRows(')),configurationContext);
vm.runInContext(configurationView.slice(configurationView.indexOf('      function refresh(selectId'),configurationView.indexOf("      $('#ucm-cfg-new').on")),configurationContext);
const runConfiguration={command:'configurationsync',op:'run',id:7,force:0};
configurationContext.mutate(runConfiguration);configurationContext.mutate(runConfiguration);
assert.equal(configurationRequests.length,1,'Only one configuration mutation may be pending');
configurationRequests[0].success({status:true,result:{ok:false,needs_confirmation:true,destination_summary:'1 succeeded, 1 need review',destination_results:[{site_id:2,status:'unknown',details:[{message:'Conflict <script>'}]},{site_id:3,status:'success',details:[{message:'Imported'}]}]}});configurationRequests[0].finish();
assert.equal(configurationContext.busy,true,'Confirmed conflict continuation must keep mutation ownership');
assert.equal(configurationRequests[1].options.data.force,1);assert.equal(configurationRequests[1].options.data.id,7);
const configurationText=node=>[node.label||'',...node.children.map(configurationText)].join(' ');
const resultText=configurationText(configurationElement('#ucm-cfg-results'));
assert.ok(resultText.includes('Branch <two> — Needs review')&&resultText.includes('Branch three — Succeeded'),'Each site keeps its own safe-text result');
configurationRequests[1].failure();configurationRequests[1].finish();
assert.equal(configurationContext.busy,false);assert.ok(configurationFeedback.includes('unconfirmed'));
configurationContext.refresh(7,1);const olderConfigurationRead=configurationRequests.at(-1);
configurationContext.refresh(8,1);const newerConfigurationRead=configurationRequests.at(-1);
newerConfigurationRead.success({status:true,result:{ok:true,jobs:[{id:8}]}});
olderConfigurationRead.success({status:true,result:{ok:true,jobs:[{id:7}]}});
assert.equal(configurationLoads.length,1);assert.equal(configurationLoads[0].id,8,'Older list responses must not replace the selected job');
const currentGeneration=configurationContext.editorGeneration;
configurationContext.refresh(8,currentGeneration);const editedConfigurationRead=configurationRequests.at(-1);
configurationContext.editorGeneration++;
editedConfigurationRead.success({status:true,result:{ok:true,jobs:[{id:8,name:'saved'}]}});
assert.equal(configurationLoads.length,1,'Job reload must preserve edits made while the request was pending');
assert.equal(configurationDraws,1);
configurationContext.mutate(runConfiguration);const receiptFailureRequest=configurationRequests.at(-1),requestCount=configurationRequests.length;
receiptFailureRequest.success({status:true,result:{ok:true,reporting_error:'Storage unavailable',destination_summary:'2 succeeded',destination_results:[]}});receiptFailureRequest.finish();
assert.equal(configurationRequests.length,requestCount,'Known run outcomes must not be replaced by an older saved receipt after a recording failure');
assert.ok(configurationText(configurationElement('#ucm-cfg-results')).includes('Storage unavailable'));
console.log('Configuration per-site results, request ownership and preserved-editor assertions passed');

const groupsView=read('views/groups.php'),groupEsc=text=>String(text).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
function groupOptionNode(tag){return{children:[],value:'',label:'',selected:false,append(child){this.children.push(child);return this;},val(v){this.value=v;return this;},text(v){this.label=v;return this;},prop(key,v){this[key]=v;return this;},html(){return this.children.map(child=>'<option value="'+groupEsc(child.value)+'"'+(child.selected?' selected':'')+'>'+groupEsc(child.label)+'</option>').join('');}};}
groupOptionNode.each=(values,fn)=>Object.keys(values).forEach(key=>fn(key,values[key]));
const groupOptions={$:groupOptionNode,SITES:[{id:2,name:'</option><script>unsafe</script>'}],FEATURES:{blf:'BLF <label>'}};
vm.runInNewContext(groupsView.slice(groupsView.indexOf('      function featureOptions('),groupsView.indexOf('      function loadExtensions(')).replace(/<\?php[\s\S]*?\?>/g,'Local'),groupOptions);
assert.equal(groupOptions.siteOptions(2),'<option value="">Local</option><option value="2" selected>&lt;/option&gt;&lt;script&gt;unsafe&lt;/script&gt;</option>');
assert.ok(groupOptions.featureOptions('blf').includes('BLF &lt;label&gt;'));
console.log('Group option labels remain text and preserve selection');

// Run the real account editor through a capacity reduction and back, with an unavailable extension.
const phoneRows=[],phoneFields=new Map(); let phoneLimit=8,phoneKeys=8,phoneDect=false;
const phoneNode=(tag='',classes='')=>({tag,classes,children:[],properties:{},value:'',label:''});
const phoneDescendants=node=>node.children.flatMap(child=>[child,...phoneDescendants(child)]);
function phoneCollection(nodes) {
  const set={length:nodes.length,nodes,each(fn){nodes.forEach((node,index)=>fn.call(node,index,node));return this;},
    val(value){if(arguments.length){nodes.forEach(node=>{node.value=String(value);});return this;}const node=nodes[0];if(!node)return null;
      if(node.tag==='select')return node.children.some(child=>child.value===node.value)?node.value:(node.value===''&&node.children.length?node.children[0].value:null);return node.value;},
    text(value){nodes.forEach(node=>{node.label=String(value);});return this;},
    prop(name,value){if(arguments.length===1)return nodes[0]?.properties[name];nodes.forEach(node=>{node.properties[name]=value;});return this;},
    attr(){return this;},data(name){return {accounts:phoneLimit,keys:phoneKeys,family:'cisco_xml'}[name];},
    append(...children){nodes.forEach(node=>{children.forEach(child=>node.children.push(...(child.nodes||[child])));});return this;},
    empty(){nodes.forEach(node=>{node.children=[];node.value='';});return this;},
    find(selector){const matches=nodes.flatMap(phoneDescendants).filter(node=>selector==='option'?node.tag==='option':node.classes.split(' ').includes(selector.slice(1)));return phoneCollection(matches);},
    filter(fn){return phoneCollection(nodes.filter((node,index)=>fn.call(node,index,node)));},
    remove(){nodes.forEach(node=>{const index=phoneRows.indexOf(node);if(index>=0)phoneRows.splice(index,1);});return this;},
    show(){return this;},hide(){return this;},toggle(){return this;}};
  return set;
}
function phoneJquery(value) {
  if(typeof value==='object')return phoneCollection([value]);
  if(value.startsWith('<')){const tag=/^<(\w+)/.exec(value)[1],classes=/class="([^"]*)"/.exec(value)?.[1]||'';return phoneCollection([phoneNode(tag,classes)]);}
  if(value==='\u0023ucm-phone-lines-table tbody tr:not(.ucm-primary-line-row)')return phoneCollection(phoneRows);
  if(value==='#ucm-phone-lines-table tbody')return{append(row){phoneRows.push(...row.nodes);return this;}};
  if(!phoneFields.has(value))phoneFields.set(value,phoneNode());return phoneCollection([phoneFields.get(value)]);
}
phoneJquery.each=(rows,fn)=>rows.forEach((row,index)=>fn(index,row));
const phoneEditorContext={$:phoneJquery,allSipExtensions:[{id:'4001',description:'Known',driver:'sip'}],isDectBase:()=>phoneDect,
  endpointDriverLabel:()=> 'CHAN_SIP',updatePhoneEditorSummary(){},renderDectRoutes(){}};
vm.createContext(phoneEditorContext);
vm.runInContext(phones.slice(phones.indexOf('      function getAccountLimit()'),phones.indexOf("      $(document).on('click', '#ucm-phone-add-line'")),phoneEditorContext);
const savedPhoneLine={account:6,button:7,extension:'4002',label:'Retained line'};
phoneEditorContext.addPhoneLineRow(savedPhoneLine);phoneEditorContext.serializePhoneLines();
const phoneSavedLines=()=>JSON.parse(phoneFields.get('#ucm-phone-lines-json').value);
assert.deepEqual(phoneSavedLines(),[savedPhoneLine],'Missing extension choices must remain selected');
phoneLimit=1;phoneKeys=1;phoneEditorContext.updatePhoneLinesPanel();phoneEditorContext.serializePhoneLines();
assert.deepEqual(phoneSavedLines(),[savedPhoneLine],'A smaller preview model cannot erase accounts or line appearances');
phoneDect=true;phoneEditorContext.updatePhoneLinesPanel();phoneEditorContext.serializePhoneLines();
phoneDect=false;phoneLimit=8;phoneKeys=8;phoneEditorContext.updatePhoneLinesPanel();phoneEditorContext.serializePhoneLines();
assert.deepEqual(phoneSavedLines(),[savedPhoneLine],'Switching through a DECT preview must preserve the desk-phone draft');
phoneRows.length=0;phoneLimit=1;phoneKeys=1;phoneEditorContext.addPhoneLineRow(savedPhoneLine);phoneEditorContext.serializePhoneLines();
assert.deepEqual(phoneSavedLines(),[savedPhoneLine],'Opening a saved account against an unavailable model must not drop it');
phoneCollection([phoneRows[0]]).find('.ucm-line-ext').val('');phoneEditorContext.serializePhoneLines();
assert.equal(phoneSavedLines().length,1,'An incomplete row must reach validation instead of disappearing from the payload');
assert.equal(phoneSavedLines()[0].extension,'');
console.log('Phone account capacity, missing-extension and model-toggle preservation fixtures passed');

const templateView=read('views/templates.php'),templateRequests=[];
let templateSite='2',templateModel='7841',templateProfile='cisco:78xx',templateWrites=0,templateSerializes=0;
const templateControl={length:1,data(key,value){if(arguments.length===1)return key==='saved'?'saved-book':'';templateWrites++;return this;},
  val(value){if(!arguments.length)return '';templateWrites++;return this;},find(){return this;},first(){return this;},clone(){return this;},siblings(){return this;},not(){return this;},add(){return this;},filter(){return this;}};
for(const method of ['prop','empty','append','text','remove','toggleClass','addClass','removeClass','attr'])templateControl[method]=function(){templateWrites++;return this;};
const templateJquery=selector=>selector==='[name=site_id]'?{val:()=>templateSite}:templateControl;
const templateModelControl={data:key=>({id:7,model:templateModel,vendor:'Cisco',fwprofile:templateProfile,tzprofile:templateProfile})[key]};
const templateContext={$:templateJquery,family:()=> 'cisco_xml',model:()=>templateModelControl,modelKey:()=> 'Cisco|'+templateModel,
  firmwareSelections:{},inactiveFirmwareSelections:{},timezoneSelections:{},kemFirmwareSelections:{},initialSettings:{},siteDefaultMap:()=>({}),
  serializeSettings(){templateSerializes++;},renderInheritance(){},loadKemFirmwarePicker(){},
  tftpCall(payload){const request={payload,done(fn){this.success=fn;return this;},fail(fn){this.failure=fn;return this;},always(fn){this.finish=fn;return this;}};templateRequests.push(request);return request;}};
vm.createContext(templateContext);
vm.runInContext(templateView.slice(templateView.indexOf('  function showLegacyAsset('),templateView.indexOf('  function mediaCall(')),templateContext);
vm.runInContext(templateView.slice(templateView.indexOf('  var catalogRequests='),templateView.indexOf('  function renderLabelPreview()')),templateContext);
for(const [name,next] of [['loadTimezoneVersions','loadFirmwarePickers'],['loadFirmwarePickers','loadKemFirmwarePicker']]) {
  vm.runInContext(templateView.slice(templateView.indexOf('  function '+name+'('),templateView.indexOf('  function '+next+'(')),templateContext);
  templateContext[name]();const old=templateRequests.at(-1);
  templateSite='3';templateModel='8861';templateProfile='cisco:88xx';templateContext[name]();const fresh=templateRequests.at(-1);
  let writes=templateWrites,serializes=templateSerializes;
  old.success({status:true,result:{ok:true,bundles:[],assets:[]}});old.failure();old.finish();
  assert.equal(templateWrites,writes,name+': another site/model cannot repaint or unlock current controls');
  assert.equal(templateSerializes,serializes,name+': stale completion cannot serialize a newer draft');
  fresh.success({status:true,result:{ok:true,bundles:[],assets:[]}});fresh.finish();
  assert.ok(templateWrites>writes,name+': current response still populates its picker');
  templateContext[name]();const olderSameContext=templateRequests.at(-1);templateContext[name]();
  writes=templateWrites;olderSameContext.success({status:true,result:{ok:true,bundles:[],assets:[]}});olderSameContext.finish();
  assert.equal(templateWrites,writes,name+': earlier requests for the same context cannot replace the newest request');
}
const firmwareRequest=templateRequests.find(request=>request.payload.op==='firmware_inventory');
assert.equal(firmwareRequest.payload.profile,'cisco:88xx');assert.ok(!('model_id' in firmwareRequest.payload),'Remote inventories use stable compatibility profiles, never controller numeric IDs');
let templateSavedSettings='';
const mapContext={modelKey:()=> 'Cisco|7841',family:()=> 'cisco_xml',isGhp63x:()=>false,dialRules:()=>[],ghpSpeedDials:()=>[],scheduleFields:{},
  firmwareSelections:{'cisco:retired-model':{load:'retained-load'}},inactiveFirmwareSelections:{'cisco:retired-model':'retained-inactive'},
  timezoneSelections:{'unavailable-runtime':'retained-timezone'},wallpaperSelections:{'unavailable-screen':'retained.png'},hostedImageSelections:{'unavailable-profile':'https://example.invalid/retained.png'},hostedRingtoneSelection:'',
  $(selector){if(selector==='#ucm-advanced-settings')return{val:()=> '{}'};if(selector==='#ucm-tpl-settings-json')return{val(value){templateSavedSettings=value;}};return{each(){return this;},filter(){return this;}};}};
mapContext.$.each=(object,fn)=>Object.entries(object).forEach(([key,value])=>fn(key,value));
vm.createContext(mapContext);vm.runInContext(templateView.slice(templateView.indexOf('  function serializeSettings()'),templateView.indexOf('  function syncDirectoryLabelMode(')),mapContext);
assert.equal(mapContext.serializeSettings(),true);
const retainedTemplate=JSON.parse(templateSavedSettings);
assert.equal(retainedTemplate.firmware_by_profile['cisco:retired-model'],'retained-load');
assert.equal(retainedTemplate.inactive_firmware_by_profile['cisco:retired-model'],'retained-inactive');
assert.equal(retainedTemplate.cisco_timezone_by_profile['unavailable-runtime'],'retained-timezone');
assert.equal(retainedTemplate.cisco_wallpaper_by_profile['unavailable-screen'],'retained.png');
assert.equal(retainedTemplate.hosted_image_by_profile['unavailable-profile'],'https://example.invalid/retained.png');
console.log('Template stale-catalog, stable-profile and unavailable-policy preservation fixtures passed');


// Exercise the shared scoped replication lifecycle with delayed and lost responses.
function configurationEditorFixture(job) {
  const pending=[],messages=[],rendered=[],loads=[],lists=[],controls=[{disabled:false},{disabled:true}];
  let current=job,box;
  function node() { return {children:[],insertAfter(){box=this;return this;},empty(){this.children=[];return this;},
    append(...items){this.children.push(...items);return this;},text(value){this.label=value;return this;}}; }
  function jq(value) {
    if(value==='.ucmp-module-shell :input')return{each(fn){controls.forEach(control=>fn.call(control));}};
    if(typeof value==='object')return{prop(key,value2){if(arguments.length===1)return value[key];value[key]=value2;return this;}};
    return node();
  }
  const scope={$:jq,window:{confirm:()=>false,ucmpReplicationResults(result){rendered.push(result);return result;},
    ucmpAjax({data}){const call={data,done(fn){this.success=fn;return this;},fail(fn){this.failure=fn;return this;}};pending.push(call);return call;}}};
  vm.createContext(scope);
  vm.runInContext(script.slice(script.indexOf('  window.ucmpConfigurationEditor ='),script.indexOf('  function call(params')),scope);
  const editor=scope.window.ucmpConfigurationEditor({prefix:'test',current:()=>current,scopes:()=>['groups'],
    feedback(message,bad){messages.push({message,bad});},remember(value){current=value;},list(value){lists.push(value);},
    load(value,preserve){current=value;loads.push({value,preserve});}});
  return {editor,pending,messages,rendered,loads,lists,controls,current:()=>current,box:()=>box};
}
let syncFixture=configurationEditorFixture(null);
const syncPayload={command:'configurationsync',op:'save',id:0,scopes:['groups'],group_ids:[7],target_site_ids:[2,3]};
syncFixture.editor.save(syncPayload,true);syncFixture.editor.save(syncPayload,true);
assert.equal(syncFixture.pending.length,1,'A second click must not issue a duplicate save');
assert.deepEqual(syncFixture.controls.map(control=>control.disabled),[true,true]);
const savedSyncJob={id:41,scopes:['groups'],group_ids:[7],target_site_ids:[2,3]};
syncFixture.pending[0].success({status:true,result:{ok:true,job:savedSyncJob}});
assert.equal(syncFixture.current().id,41,'The save receipt establishes identity before any inventory refresh');
assert.equal(syncFixture.pending[1].data.op,'run','Push follows the known save without depending on an unrelated list request');
assert.equal(syncFixture.pending[1].data.id,41);
const partialSync={ok:false,status:'partial',message:'One site succeeded; one failed',destination_results:[{site_id:2,status:'success'},{site_id:3,status:'error'}]};
syncFixture.pending[1].success({status:true,result:partialSync});
assert.equal(syncFixture.rendered.at(-1),partialSync,'Every destination receipt reaches the result renderer');
assert.equal(syncFixture.pending[2].data.op,'list');
syncFixture.pending[2].failure();
assert.equal(syncFixture.current().id,41);assert.equal(syncFixture.rendered.at(-1),partialSync);
assert.match(syncFixture.messages.at(-1).message,/One site succeeded; one failed; job history refresh failed/);
assert.deepEqual(syncFixture.controls.map(control=>control.disabled),[false,true],'Restore original disabled state after the complete operation');
assert.equal(syncFixture.pending.filter(call=>call.data.op==='run').length,1,'A reporting failure cannot replay the run');
syncFixture=configurationEditorFixture({id:42,scopes:['groups','templates'],group_ids:[7],template_ids:[8]});
syncFixture.editor.save(syncPayload,true);
assert.equal(syncFixture.pending.length,0,'A scoped editor cannot discard an unseen job scope');
assert.match(syncFixture.messages.at(-1).message,/complete job in Sites/);
syncFixture=configurationEditorFixture(savedSyncJob);
syncFixture.editor.save({...syncPayload,id:41},false);
syncFixture.pending[0].success({status:true,result:{ok:false,message:'A destination is disabled'}});
assert.equal(syncFixture.loads.length,0,'Rejected saves leave the editable draft intact');
assert.equal(syncFixture.pending.length,1);assert.equal(syncFixture.editor.busy(),false);
syncFixture.editor.mutate({command:'configurationsync',op:'resume',id:41});
syncFixture.editor.mutate({command:'configurationsync',op:'delete',id:41});
assert.equal(syncFixture.pending.length,2,'Resume and delete share the operation lock');
syncFixture.pending[1].failure();
syncFixture.editor.save({...syncPayload,id:41},true);
assert.equal(syncFixture.pending.length,2,'An unknown mutation outcome cannot be retried automatically or by a second click');
assert.match(syncFixture.messages.at(-1).message,/no completion receipt/);
syncFixture=configurationEditorFixture(savedSyncJob);
syncFixture.editor.save({...syncPayload,id:41},false);
syncFixture.pending[0].success({status:true,result:{ok:true,job:savedSyncJob,message:'Saved'}});
syncFixture.pending[1].success({status:true,result:{ok:true,jobs:'invalid'}});
assert.equal(syncFixture.editor.busy(),false,'A malformed list response cannot strand the editor lock');
assert.equal(syncFixture.current().id,41);assert.equal(syncFixture.loads.length,0);

const retainedChoices={'.choices':[{value:'1',checked:false},{value:'2',checked:true}]};
const retentionScope={window:{},$(selector){const onlyChecked=selector.endsWith(':checked'),rows=retainedChoices[selector.replace(':checked','')]||[];return{map(fn){return{get:()=>rows.filter(row=>!onlyChecked||row.checked).map(row=>fn.call(row))};}};}};
vm.createContext(retentionScope);vm.runInContext(script.slice(script.indexOf('  window.ucmpRetainedSelection ='),script.indexOf('  // The three scoped editors')),retentionScope);
assert.deepEqual(Array.from(retentionScope.window.ucmpRetainedSelection('.choices',[1,2,99])),['2','99'],'Explicitly unchecked visible entries are removed; unavailable saved entries survive');
const fullSyncContext={selected:{id:41,scopes:['groups','templates','settings_clock_date'],target_site_ids:[99],template_ids:[8],group_ids:[7]},
  window:{ucmpSettingsFieldsRead:()=>['cisco_transport'],ucmpRetainedSelection(selector,saved){return saved||[];}},$(selector){return{val:()=>selector==='#ucm-cfg-id'?'41':'1',is:()=>false,map(){return{get:()=>[]};}};}};
vm.createContext(fullSyncContext);vm.runInContext(read('views/sites.php').split('\n').find(line=>line.startsWith('      function payload(op){')),fullSyncContext);
const fullSyncPayload=fullSyncContext.payload('save');
assert.deepEqual(Array.from(fullSyncPayload.template_ids),[8]);assert.deepEqual(Array.from(fullSyncPayload.group_ids),[7]);
assert.deepEqual(Array.from(fullSyncPayload.target_site_ids),[99]);
assert.deepEqual(Array.from(fullSyncPayload.settings_categories),['settings_clock_date']);
assert.deepEqual(Array.from(fullSyncPayload.setting_keys),['cisco_transport']);
assert.equal(fullSyncPayload.setting_keys_present,1);
console.log('Shared replication save/run, mixed-scope, partial-result, unknown-outcome and filter preservation fixtures passed');

const legacyRaw={firmware:'old-load',firmware_profile:'cisco:78xx',inactive_firmware:'old-inactive',cisco_default_wallpaper:'old-wallpaper.png',hosted_image_url:'https://example.invalid/old.png',legacy_logo_url:'https://example.invalid/old.bmp'};
mapContext.$=selector=>selector==='#ucm-advanced-settings'?{val:()=>JSON.stringify(legacyRaw)}:selector==='#ucm-tpl-settings-json'?{val(value){templateSavedSettings=value;}}:{each(){return this;},filter(){return this;}};
mapContext.$.each=(object,fn)=>Object.entries(object).forEach(([key,value])=>fn(key,value));
mapContext.serializeSettings();
const legacySaved=JSON.parse(templateSavedSettings);
for(const key of Object.keys(legacyRaw))assert.equal(legacySaved[key],legacyRaw[key],'Opening a profile picker cannot erase legacy '+key);
let legacyPickerValue='',legacyPickerDisabled=false;
const legacyPicker={empty(){return this;},append(){return this;},prop(name,value){if(name==='disabled')legacyPickerDisabled=value;return this;},data(){return this;}};
const legacyContext={$:selector=>selector==='#ucm-advanced-settings'?{val:()=>JSON.stringify(legacyRaw)}:{val(){return this;},text(value){legacyPickerValue=value;return this;}}};
vm.createContext(legacyContext);vm.runInContext(templateView.slice(templateView.indexOf('  function showLegacyAsset('),templateView.indexOf('  function mediaCall(')),legacyContext);
assert.equal(legacyContext.showLegacyAsset(legacyPicker,['firmware']),true);
assert.equal(legacyPickerDisabled,true);assert.match(legacyPickerValue,/old-load/);
const settledReads=[];let settledCatalogs=null;
for(let n=0;n<2;n++)settledReads.push({done(fn){this.success=fn;return this;},fail(fn){this.failure=fn;return this;},always(fn){this.finish=fn;return this;}});
const mediaReadContext={};vm.createContext(mediaReadContext);vm.runInContext(templateView.slice(templateView.indexOf('  function readMediaCatalogs('),templateView.indexOf('  function loadMediaPickers(')),mediaReadContext);
mediaReadContext.readMediaCatalogs(settledReads,results=>{settledCatalogs=results;});
settledReads[0].failure();settledReads[0].finish();assert.equal(settledCatalogs,null,'One failed request cannot discard the other in-flight media catalog');
const goodCatalog={status:true,result:{ok:true,items:[{name:'retained.png',url:'https://example.invalid/retained.png'}]}};
settledReads[1].success(goodCatalog);settledReads[1].finish();assert.equal(settledCatalogs[1],goodCatalog);assert.equal(settledCatalogs[0].status,false);
console.log('Legacy template value preservation and partially failed media catalog fixtures passed');

function savedSelectionJquery(value){const node=phoneJquery(value);node.is=tag=>node.nodes[0]?.tag===tag;return node;}
const savedSelectionContext={$:savedSelectionJquery,sites:[{id:2,name:'Available'}]};
vm.createContext(savedSelectionContext);
vm.runInContext(templateView.slice(templateView.indexOf('  function selectSavedValue('),templateView.indexOf('  function phonebookSourceSiteId(')),savedSelectionContext);
const missingSite=savedSelectionContext.siteSelect(99);
assert.equal(missingSite.val(),'99','An unavailable key destination cannot become the local site');
const missingSetting=savedSelectionJquery('<select>');
savedSelectionContext.selectSavedValue(missingSetting,'saved-unavailable');
assert.equal(missingSetting.val(),'saved-unavailable');
savedSelectionContext.selectSavedValue(missingSetting,'saved-unavailable');
assert.equal(missingSetting.find('option').length,1,'Repeated initialization must not duplicate saved choices');
legacyRaw.cisco_timezone_bundle_key='legacy-global-timezone';
mapContext.serializeSettings();
assert.equal(JSON.parse(templateSavedSettings).cisco_timezone_bundle_key,'legacy-global-timezone','A single profile selection cannot erase the global timezone policy');
let sourceChoice='missing-site',sourceId=0,phonebookCalls=0,phonebookMessage='';
const bookSource={val:()=>sourceChoice,find(){return {data:()=>sourceId};}};
const bookControl={val:()=> 'saved-book',data:()=>'',prop(){return this;}};
const bookStatus={addClass(){return this;},text(value){phonebookMessage=value;return this;}};
const bookContext={catalogRead:()=>()=>true,$:selector=>selector==='#ucm-phonebook-source'?bookSource:selector==='#ucm-phonebook-source-status'?bookStatus:selector==='[name=site_id]'?{val:()=>2}:bookControl,
  window:{ucmpAjax(){phonebookCalls++;throw Error('Unexpected catalog substitution');}}};
vm.createContext(bookContext);
vm.runInContext(templateView.slice(templateView.indexOf('  function phonebookSourceSiteId('),templateView.indexOf('  function addKey(')),bookContext);
bookContext.loadPhonebooks();
assert.equal(phonebookCalls,0);assert.match(phonebookMessage,/saved phonebook source is unavailable/);
sourceChoice='';assert.equal(bookContext.phonebookSourceSiteId(),2);
sourceChoice='known-site';sourceId=3;assert.equal(bookContext.phonebookSourceSiteId(),3);
console.log('Unavailable template selectors, phonebook sources and global timezone preservation fixtures passed');

const settingsView=read('views/settings.php'),defaultOptionContext={$:phoneJquery};
vm.createContext(defaultOptionContext);
vm.runInContext(settingsView.slice(settingsView.indexOf('  function defaultOptions('),settingsView.indexOf('  function renderDefaultRules(')),defaultOptionContext);
const absentDefault=defaultOptionContext.defaultOptions([{value:'known',label:'Known'}],'unavailable');
assert.equal(absentDefault.val(),'unavailable','Default template selectors must retain missing saved values');
let serializedDefault='';
const defaultRuleContext={type:'model',index:0,rule:{target_vendor:'Cisco',target_family:'cisco_xml'},defaultRules:[],
  targetSelect:{find:()=>({data:key=>key==='unavailable'?1:undefined}),val:()=> 'Cisco|Unavailable'},
  template:{val:()=> '999'},role:{val:()=> 'legacy-role'},enabled:{is:()=>false},
  $:()=>({val(value){serializedDefault=value;}})};
vm.createContext(defaultRuleContext);
vm.runInContext(settingsView.split('\n').find(line=>line.includes('      function sync(){')),defaultRuleContext);
defaultRuleContext.sync();
assert.deepEqual(JSON.parse(serializedDefault),[{scope_type:'model',template_id:999,target_vendor:'Cisco',target_family:'cisco_xml',target_series:'',target_model:'Cisco|Unavailable',target_role:'legacy-role',enabled:0}],
  'Changing another field must not erase missing default-template references or their family/vendor metadata');
console.log('Default-template unavailable-choice and metadata preservation fixtures passed');

// Execute the real IP renderer: live changes, multiple contacts and stale fallbacks.
const ipValues={},ipAttrs={'data-saved-ip':'192.0.2.4'};
const ipCell={attr(key,value){if(arguments.length===1)return ipAttrs[key];ipAttrs[key]=value;return this;},find(selector){return{text(value){ipValues[selector]=value;return this;}};}};
const ipRow={find:()=>ipCell},ipContext={};
vm.createContext(ipContext);vm.runInContext(phones.slice(phones.indexOf('  function paintPhoneIp('),phones.indexOf('  function paintLiveStatus(')),ipContext);
ipContext.paintPhoneIp(ipRow,{registered:true,ip:'192.0.2.8'},false);
assert.equal(ipValues['.ucm-phone-ip'],'192.0.2.8');assert.equal(ipAttrs['data-sort'],'192.0.2.8');
assert.equal(ipValues['.ucm-phone-ip-source'],'PBX contact');
ipContext.paintPhoneIp(ipRow,{registered:true,contacts:[{ip:'192.0.2.8'},{ip:'2001:db8::1'},{ip:'192.0.2.8'}]},false);
assert.equal(ipValues['.ucm-phone-ip'],'192.0.2.8, 2001:db8::1');assert.match(ipValues['.ucm-phone-ip-source'],/Multiple/);
ipContext.paintPhoneIp(ipRow,null,true);
assert.equal(ipValues['.ucm-phone-ip'],'192.0.2.4');assert.match(ipValues['.ucm-phone-ip-source'],/Saved address.*check failed/);
ipAttrs['data-saved-ip']='';ipContext.paintPhoneIp(ipRow,{},false);
assert.equal(ipValues['.ucm-phone-ip'],'Unknown');assert.match(ipValues['.ucm-phone-ip-source'],/no live contact/);
console.log('Phone IP live, multiple-contact and saved-address fallback assertions passed');

const liveTexts={'#ucm-action-result':'Build partially completed: one phone failed'};
const liveContext={phoneSort:{column:-1},$(selector){return{each(){return this;},removeClass(){return this;},addClass(){return this;},toggleClass(){return this;},text(value){liveTexts[selector]=value;return this;}};}};
vm.createContext(liveContext);vm.runInContext(phones.slice(phones.indexOf('  function paintLiveStatus('),phones.indexOf('  function refreshLiveStatus(')),liveContext);
liveContext.paintLiveStatus({1:{9001:{}}},{});
assert.equal(liveTexts['#ucm-action-result'],'Build partially completed: one phone failed');
assert.match(liveTexts['#ucm-live-status-result'],/Status as of/);
liveContext.paintLiveStatus({},{1:'Unavailable'});
assert.equal(liveTexts['#ucm-action-result'],'Build partially completed: one phone failed');
assert.match(liveTexts['#ucm-live-status-result'],/1 site\(s\) were unavailable/);
const manualRow={sourceKey:'manual:fixture',fields:[{field:'display_name',value:'Incomplete contact'},{field:'extension',value:''}]};
const manualContext={$:value=>{
  if(value==='#ucm-book-manual-list .ucm-book-manual-row')return{each(fn){fn.call(manualRow);}};
  if(value===manualRow)return{data:()=>manualRow.sourceKey,find(){return{each(fn){manualRow.fields.forEach(field=>fn.call(field));}};}};
  return{data:()=>value.field,val:()=>value.value};
}};
manualContext.$.trim=value=>String(value||'').trim();vm.createContext(manualContext);
vm.runInContext(read('views/directory.php').split('\n').find(line=>line.startsWith('  function manualEntries()')),manualContext);
const manualSerialized=manualContext.manualEntries();
assert.equal(manualSerialized.length,1);assert.equal(manualSerialized[0].extension,'');assert.equal(manualSerialized[0].source_key,'manual:fixture');
console.log('Live-status result isolation and incomplete manual-contact preservation fixtures passed');

{
  let assignmentWrites=0,assignmentMessage='';
  const assignmentRaw={'#ucm-default-firmware-json':'{"saved":"load"}','#ucm-default-timezone-json':'{}','#ucm-default-wallpaper-json':'{}','#ucm-default-hosted-json':'{}'};
  const assignmentMapContext={defaultMapError:'',firmware:{changed:'candidate'},timezone:{},wallpaper:{},hosted:{},$:id=>({
    val(value){if(arguments.length){assignmentWrites++;assignmentRaw[id]=value;return this;}return assignmentRaw[id];},
    addClass(){return this;},text(value){assignmentMessage=value;return this;}
  })};
  vm.createContext(assignmentMapContext);
  vm.runInContext(settingsView.split('\n').filter(line=>line.startsWith('  function map(')||line.startsWith('  function save()')).join('\n'),assignmentMapContext);
  for(const malformed of ['{"broken":','null','["not-an-assignment-map"]']){
    assignmentMapContext.defaultMapError='';assignmentWrites=0;assignmentRaw['#ucm-default-firmware-json']=malformed;
    assignmentMapContext.map('#ucm-default-firmware-json');
    assert.equal(assignmentMapContext.save(),false,'Invalid saved assignments must cancel form submission');
    assert.equal(assignmentWrites,0,'No hidden assignment field may be replaced after a parse failure');
    assert.equal(assignmentRaw['#ucm-default-firmware-json'],malformed);assert.match(assignmentMessage,/original values were retained/);
  }
  assignmentMapContext.defaultMapError='';assignmentRaw['#ucm-default-firmware-json']='[]';
  assert.equal(Object.keys(assignmentMapContext.map('#ucm-default-firmware-json')).length,0,'Legacy empty maps remain valid');
  assignmentMapContext.save();assert.equal(assignmentWrites,4);assert.equal(JSON.parse(assignmentRaw['#ucm-default-firmware-json']).changed,'candidate');
}
{
  const assignmentRequests=[];let assignmentRenders=0;
  function assignmentRequest(data){const request={data,done(fn){this.success=fn;return this;},fail(fn){this.failure=fn;return this;},always(fn){this.finish=fn;return this;}};assignmentRequests.push(request);return request;}
  const assignmentReadContext={assignmentReads:{},defaultAssetState:{kind:'firmware'},tftp:data=>assignmentRequest({transport:'tftp',...data}),media:data=>assignmentRequest({transport:'media',...data}),
    renderAssignmentMatrix(){assignmentRenders++;},$:{Deferred(){return{resolve(){return this;},promise(){return this;}};}}};
  vm.createContext(assignmentReadContext);
  vm.runInContext(settingsView.slice(settingsView.indexOf('  function loadAssignmentInventory('),settingsView.indexOf("  $('[data-default-kind]')")),assignmentReadContext);
  assignmentReadContext.loadAssignmentInventory('firmware');assignmentReadContext.loadAssignmentInventory('firmware');
  assignmentRequests[1].success({result:{ok:true,identity:'latest'}});assignmentRequests[1].finish();
  assignmentRequests[0].success({result:{ok:true,identity:'stale'}});assignmentRequests[0].failure();assignmentRequests[0].finish();
  assert.equal(assignmentReadContext.defaultAssetState.firmware.identity,'latest');assert.equal(assignmentReadContext.defaultAssetState.firmwareError,'');assert.equal(assignmentRenders,1);
  assignmentReadContext.loadAssignmentInventory('firmware');assert.equal(assignmentRequests.length,2,'A successful cached inventory is reused');
  assignmentReadContext.loadAssignmentInventory('cisco_timezone');assignmentReadContext.loadAssignmentInventory('hosted_wallpaper');
  assert.deepEqual(assignmentRequests.map(request=>request.data),[{transport:'tftp',op:'firmware_inventory'},{transport:'tftp',op:'firmware_inventory'},{transport:'tftp',op:'timezone_versions'},{transport:'media',op:'inventory'}],
    'Hosted assignments use the shared Media inventory while firmware and timezone use TFTP catalogs');
  assignmentRequests[3].success({result:{ok:true,identity:'hosted',background_choices:[]}});assignmentRequests[2].success({result:{ok:true,identity:'timezone'}});
  assert.equal(assignmentReadContext.defaultAssetState.hosted.identity,'hosted');assert.equal(assignmentReadContext.defaultAssetState.timezone.identity,'timezone');
  assignmentReadContext.defaultAssetState.hosted=null;
  assignmentReadContext.loadAssignmentInventory('hosted_wallpaper');
  assignmentRequests.at(-1).success({result:{ok:true,items:[{selectable:true,name:'loose.png'}]}});
  assert.equal(assignmentReadContext.defaultAssetState.hosted,null,'Older peers cannot silently repopulate background choices from raw files');
  assert.match(assignmentReadContext.defaultAssetState.hostedError,/updated source site/);
}
console.log('Invalid settings preservation and stale assignment inventory fixtures passed');

// Execute the real endpoint UI resolver with every radio choice.
{
  const radios=['inherit','udp','tcp','tls'].map(value=>({value,checked:value==='inherit',disabled:false}));
  let family='cisco_xml', driver='inherit', port='0';
  function collection(rows){return {filter(selector){return collection(rows.filter(row=>selector===':checked'?row.checked:selector.includes('"'+row.value+'"')));},
    val:()=>rows[0]?.value,prop(name,value){for(const row of rows){if(name==='checked'&&value)radios.forEach(other=>other.checked=false);row[name]=value;}return this;}};}
  const neutral={text(){return this;},toggle(){return this;},toggleClass(){return this;}};
  const scope={siteEndpointDefaults:{cisco_transport:'udp',cisco_sip_port:5060,cisco_cnf_sip_port:5060,non_cisco_driver:'pjsip',non_cisco_transport:'udp',non_cisco_sip_port:5160},
    endpointDriverLabel:value=>value,$(selector){
      if(selector==='#ucm-model option:selected')return {data:key=>key==='family'?family:family.startsWith('cisco')?'cisco':'yealink'};
      if(selector==='#ucm-driver')return {val(value){if(value!==undefined){driver=value;return this;}return driver;},prop(){return this;}};
      if(selector==='#ucm-transport input')return collection(radios);
      if(selector==='#ucm-sip-port')return {val:()=>port};
      if(selector==='#ucm-extension-select option:selected')return {data:()=>family.startsWith('cisco')?'sip':'pjsip'};
      return neutral;
    }};
  const source=read('views/phones.php'),start=source.indexOf('      function refreshEndpointProfile()'),end=source.indexOf("      $('#ucm-driver,",start);
  vm.createContext(scope);vm.runInContext(source.slice(start,end),scope);
  for(family of ['cisco_xml','yealink'])for(const value of ['udp','tcp','tls','inherit']){
    radios.forEach(r=>r.checked=r.value===value);const resolved=scope.refreshEndpointProfile();
    assert.equal(radios.find(r=>r.checked).value,value,'Explicit transport must remain selected');
    assert.ok(radios.every(r=>!r.disabled),'Supported choices must stay usable');
    assert.ok(resolved.label.includes((value==='inherit'?'udp':value).toUpperCase()));
  }
  family='cisco_cnf';radios.forEach(r=>r.checked=r.value==='tls');scope.refreshEndpointProfile();
  assert.equal(radios.find(r=>r.checked).value,'inherit');assert.ok(radios.find(r=>r.value==='tls').disabled);
  family='cisco_xml';scope.refreshEndpointProfile();assert.ok(radios.every(r=>!r.disabled),'Changing models restores supported transports');
  console.log('Transport override radios and model transitions passed');
}
