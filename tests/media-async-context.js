'use strict';
const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const media=fs.readFileSync(require('node:path').join(__dirname,'../views/media.php'),'utf8');
const firmware=fs.readFileSync(require('node:path').join(__dirname,'../views/firmware.php'),'utf8');
for(const source of [media,firmware])for(const match of source.matchAll(/<script>([\s\S]*?)<\/script>/g))new vm.Script(match[1].replace(/<\?php[\s\S]*?\?>/g,'null'));
function extract(source,name){
  const start=source.indexOf('  function '+name+'(');assert.ok(start>=0,name+' exists');
  const next=source.indexOf('\n  function ',start+1);
  return source.slice(start,next<0?undefined:next).split(/\n  (?:var |\$\(|\[)/)[0];
}
function deferred(){
  let resolve,reject;const p=new Promise((yes,no)=>{resolve=yes;reject=no;});
  const wrap=p=>({then:(a,b)=>wrap(p.then(a,b)),done(a){p.then(a,()=>{});return this;},fail(a){p.catch(a);return this;},always(a){p.then(a,a);return this;},promise(){return this;}});
  return Object.assign(wrap(p),{resolve(value){resolve(value);return this;},reject(value){reject(value);return this;}});
}
const values={'#ucm-media-site':'1','#ucm-audio-family':'yealink','#ucm-audio-model':'t46s'},controls=[{disabled:false},{disabled:true}],sent=[],pending=[];
const actions=[],rendered=[];
const element={val(){return '';},is(){return false;},prop(){return this;},html(){return this;},empty(){return this;},text(value){rendered.push(value);return this;},append(){return this;},on(type,callback){actions.push(callback);return this;}};
function $(selector){return Object.assign({},element,{val(value){if(value!==undefined){values[selector]=value;return this;}return values[selector];}});}
$.extend=Object.assign;$.Deferred=deferred;
const context={$,mediaEpoch:0,mediaSiteEpoch:0,replicationCatalogRequest:0,mediaPanelLoaded:{replication:false},mediaOperation:null,mediaRoot:{find(){return{each(callback){controls.forEach(item=>callback.call(item));}};}},message(){},result:response=>response.result||{},loadCatalogSyncStates:()=>deferred().resolve(),FormData:class{constructor(){this.values={};}append(key,value){this.values[key]=value;}get(key){return this.values[key];}},window:{confirm:()=>true,ucmpAjax(options){sent.push(options.data);const request=deferred();pending.push(request);return request;}}};
for(const name of ['site','operationSite','beginMediaOperation','endMediaOperation','mediaRequest','syncAwareRequest','call','hostedRetirementRequest','hostedCall','audioAssetCall','upload','rejected','previewRetirement'])vm.runInNewContext(extract(media,name),context);
const tick=()=>new Promise(resolve=>setImmediate(resolve));
(async()=>{
  assert.equal(context.beginMediaOperation(),true);assert.deepEqual(controls.map(item=>item.disabled),[true,true]);
  assert.equal(context.beginMediaOperation(),false,'Concurrent Media mutation is rejected');
  values['#ucm-audio-family']='grandstream';values['#ucm-audio-model']='other';
  const upload=context.audioAssetCall({op:'upload',name:'tone.wav',content:'synthetic'});upload.fail(()=>{});
  assert.equal(sent.at(-1).site_id,'1');assert.equal(sent.at(-1).family,'yealink');assert.equal(sent.at(-1).model,'t46s');
  pending.shift().resolve({result:{ok:true}});await tick();context.endMediaOperation();assert.deepEqual(controls.map(item=>item.disabled),[false,true]);
  let staleAccepted=false;context.call({op:'list'}).done(()=>{staleAccepted=true;}).fail(()=>{});
  values['#ucm-media-site']='2';context.mediaEpoch++;pending.shift().resolve({result:{ok:true}});await tick();assert.equal(staleAccepted,false,'Old-site responses are ignored');
  values['#ucm-media-site']='1';
  context.retirement={site_id:'1',asset_id:5,replacement_asset_id:8,expected_revision:''};context.retirementPreviewRequest=0;
  context.previewRetirement();context.retirement.replacement_asset_id=9;context.previewRetirement();
  pending.shift().resolve({result:{ok:true,preview:{revision:'wrong',references:[],dependencies:[],blockers:[]}}});await tick();assert.equal(context.retirement.expected_revision,'','Old replacement preview must not authorize the new choice');
  pending.shift().resolve({result:{ok:true,preview:{revision:'correct',references:[],dependencies:[],blockers:[]}}});await tick();assert.equal(context.retirement.expected_revision,'correct');
  const preview={site_id:1,family:'cisco_cnf',model:'all',filename:'logo.bmp',revision:'hosted-revision',references:[],dependencies:[{filename:'editable-source.png'}],blockers:[],protected_bytes:0,reclaimable_bytes:50,can_retire:true};
  context.hostedCall({op:'special_catalog_delete',catalog_id:'logo'}).fail(()=>{});
  assert.equal(sent.at(-1).op,'retirement_preview');assert.equal(sent.at(-1).retire_op,'special_catalog_delete');
  pending.shift().resolve({result:{ok:true,retirement_preview:preview}});await tick();
  assert.equal(sent.at(-1).op,'special_catalog_delete');assert.equal(sent.at(-1).expected_revision,'hosted-revision');assert.equal(sent.at(-1).site_id,'1');
  pending.shift().resolve({result:{ok:true,recovery_id:'20260907120000-abcdef1234567890'}});await tick();assert.equal(context.mediaOperation,null);
  assert.ok(rendered.some(line=>String(line).includes('editable-source.png')),'Editable-source dependencies are rendered before retirement');
  const restore=actions.at(-1);values['#ucm-media-site']='2';const requestCount=sent.length;restore();assert.equal(sent.length,requestCount,'Restore cannot run on a different selected site');
  values['#ucm-media-site']='1';restore();assert.equal(sent.at(-1).op,'restore_retired');assert.equal(sent.at(-1).family,'cisco_cnf');assert.equal(sent.at(-1).model,'all');
  pending.shift().resolve({result:{ok:false,message:'fixture conflict'}});await tick();assert.equal(context.mediaOperation,null);
  context.hostedCall({op:'special_asset_delete',name:'logo.bmp'}).fail(()=>{});const protectedCount=sent.length;
  pending.shift().resolve({result:{ok:true,retirement_preview:Object.assign({},preview,{can_retire:false,references:[{type:'template',label:'protected template'}]})}});await tick();
  assert.equal(sent.length,protectedCount,'Protected preview does not invoke retirement');assert.equal(context.mediaOperation,null);
  context.window.confirm=()=>false;context.hostedCall({op:'special_asset_delete',name:'logo.bmp'}).fail(()=>{});const cancelCount=sent.length;
  pending.shift().resolve({result:{ok:true,retirement_preview:preview}});await tick();assert.equal(sent.length,cancelCount,'Cancelled preview never invokes retirement');assert.equal(context.mediaOperation,null);context.window.confirm=()=>true;
  let selected=[],shown=[];const firmwareContext={$:()=>element,setContext(){},targetProfiles:()=>selected,call:()=>deferred().resolve({result:{ok:true,bundles:[{profile:'one'},{profile:'two'}]}}),result:context.result,renderReplicationPackages(){},renderBundles:rows=>{shown=rows;},message(){},refreshFiles(){},refreshAssets(){},refreshTimezone(){},refreshLocale(){}};
  Object.assign(firmwareContext,{firmwareReadRequests:{},site:()=>1});
  for(const name of ['firmwareRead','refreshInventory'])vm.runInNewContext(extract(firmware,name),firmwareContext);
  selected=['one'];firmwareContext.refreshInventory();await tick();assert.equal(shown.length,1);assert.equal(shown[0].profile,'one','A single selected profile must filter inventory');
  selected=['one','two'];firmwareContext.refreshInventory();await tick();assert.equal(shown.length,2);
  selected=[];firmwareContext.refreshInventory();await tick();assert.equal(shown.length,2);

  // Every inventory section discards old-site and out-of-order completions.
  const readState={site:1,pending:[],rendered:[]},readContext={...firmwareContext,
    site:()=>readState.site,firmwareReadRequests:{},call:()=>{const d=deferred();readState.pending.push(d);return d;},
    renderReplicationPackages:rows=>readState.rendered.push(rows),renderBundles(){},message:text=>readState.rendered.push(text),
    renderAssets:rows=>readState.rendered.push(rows),renderTimezone:row=>readState.rendered.push(row),renderTimezoneAssets(){},renderTimezoneVersions(){},
    localeDirectory:()=>'',networkLocaleDirectory:()=>'',renderLocale:row=>readState.rendered.push(row),
    replicationCall:()=>{const d=deferred();readState.pending.push(d);return d;},renderFirmwareJobs:rows=>readState.rendered.push(rows)};
  vm.runInNewContext(extract(firmware,'firmwareRead'),readContext);
  for(const name of ['refreshInventory','refreshAssets','refreshTimezone','refreshLocale','loadFirmwareJobs']){
    vm.runInNewContext(extract(firmware,name),readContext);readState.site=1;readState.rendered=[];
    readContext[name]();const old=readState.pending.shift();readState.site=2;readContext[name]();const latest=readState.pending.shift();
    latest.resolve({result:{ok:true,bundles:[],assets:[],current:{latest:true},jobs:[]}});await tick();const accepted=JSON.stringify(readState.rendered);
    old.reject('old site failed');await tick();assert.equal(JSON.stringify(readState.rendered),accepted,name+' ignores a failed old-site request');
    readContext[name]();const first=readState.pending.shift();readContext[name]();const second=readState.pending.shift();
    second.resolve({result:{ok:true,bundles:[],assets:[],current:{latest:true},jobs:[]}});await tick();const newer=JSON.stringify(readState.rendered);
    first.resolve({result:{ok:false,message:'stale failure'}});await tick();assert.equal(JSON.stringify(readState.rendered),newer,name+' ignores an older same-site reply');
    // Keep subordinate refresh calls independent of this section's fixture.
    if(name!=='loadFirmwareJobs')readContext[name]=()=>{};
  }

  // Exercise the complete upload handler with context changes between requests.
  const uploadState={site:1,vendor:'Yealink',files:[],sent:[],pending:[],messages:[],handlers:{},timers:[],cleared:0};
  const uploadControls=[{disabled:false},{disabled:true}];
  function uploadJq(selector){return{0:{files:uploadState.files},val(value){if(value!==undefined){uploadState.cleared++;return this;}return '';},on(type,handler){uploadState.handlers[selector]=handler;return this;}};}
  uploadJq.extend=Object.assign;uploadJq.Deferred=deferred;
  const uploadContext={$:uploadJq,site:()=>uploadState.site,vendor:()=>uploadState.vendor,scope:()=>({key:'t4'}),targetModels:()=>['T46S'],uploadPath:()=> 'firmware/yealink/t4',
    firmwareOperation:null,firmwareRoot:{find:()=>({each(fn){uploadControls.forEach(c=>fn.call(c));}})},
    uploadLimit:0,uploadLimitLabel:'',allowed:/\.(loads|sbn)$/i,result:context.result,FormData:context.FormData,
    message:text=>uploadState.messages.push(text),refreshInventory(){},window:{setTimeout:fn=>uploadState.timers.push(fn),ucmpAjax(options){uploadState.sent.push(options.data);const d=deferred();uploadState.pending.push(d);return d;}}};
  for(const name of ['operationSite','beginFirmwareOperation','endFirmwareOperation','call','rescan','uploadOne','continueAssetScan'])vm.runInNewContext(extract(firmware,name),uploadContext);
  const uploadStart=firmware.indexOf("  $('#ucm-fw-upload').on"),uploadEnd=firmware.indexOf('\n  });',uploadStart)+7;
  vm.runInNewContext(firmware.slice(uploadStart,uploadEnd),uploadContext);
  const uploadHandler=uploadState.handlers['#ucm-fw-upload'];
  uploadState.files=[{name:'one.rom',size:1},{name:'two.rom',size:1}];uploadHandler();
  assert.deepEqual(uploadControls.map(c=>c.disabled),[true,true]);assert.equal(uploadState.sent[0].site_id,1);
  uploadHandler();assert.equal(uploadState.sent.length,1,'Overlapping upload cannot send another request');
  uploadState.pending.shift().resolve({result:{ok:true}});await tick();
  assert.equal(uploadState.sent.at(-1).values.site_id,1);
  uploadState.site=2;uploadState.vendor='Grandstream';uploadState.pending.shift().resolve({result:{ok:true}});await tick();
  const secondFile=uploadState.sent.at(-1).values;
  assert.equal(secondFile.site_id,1);assert.equal(secondFile.path,'firmware/yealink/t4/two.rom');
  assert.equal(JSON.parse(secondFile.declaration).vendor,'Yealink','A later file retains the original vendor declaration');
  uploadState.pending.shift().resolve({result:{ok:true}});await tick();assert.equal(uploadState.sent.at(-1).op,'firmware_rescan');assert.equal(uploadState.sent.at(-1).site_id,1);
  uploadState.pending.shift().resolve({result:{ok:true,count:1}});await tick();assert.equal(uploadContext.firmwareOperation,null);assert.deepEqual(uploadControls.map(c=>c.disabled),[false,true]);
  uploadState.site=1;uploadState.vendor='Yealink';uploadState.sent=[];uploadState.messages=[];uploadState.files.push({name:'three.rom',size:1});uploadHandler();
  uploadState.pending.shift().resolve({result:{ok:true}});await tick();uploadState.pending.shift().resolve({result:{ok:true}});await tick();
  uploadState.pending.shift().reject({});await tick();await tick();
  assert.equal(uploadState.sent.length,3,'Lost second upload stops the batch without replay or a third file');
  assert.ok(uploadState.messages.some(text=>text.includes('uncertain')&&text.includes('Confirmed uploads: one.rom')));
  assert.equal(uploadContext.firmwareOperation,null,'Failed batch releases controls');
  uploadState.sent=[];uploadContext.beginFirmwareOperation();uploadContext.continueAssetScan('');
  uploadState.pending.shift().resolve({result:{ok:true,complete:false,scan_token:'retained-scan'}});await tick();uploadState.site=2;uploadState.timers.shift()();
  assert.equal(uploadState.sent.at(-1).site_id,1);assert.equal(uploadState.sent.at(-1).scan_token,'retained-scan','Scan continuation keeps its originating site and token');
  uploadState.pending.shift().resolve({result:{ok:true,complete:true}});await tick();assert.equal(uploadContext.firmwareOperation,null);

  // Locale uploads capture paths and their verification profile before the first request.
  Object.assign(uploadContext,{localeDirectory:()=>uploadState.locale||'English_United_States',networkLocaleDirectory:()=>'',localeProfile:()=>uploadState.profile||'sip78',
    localeRequiredFiles:()=>['sip78.jar','g4-tones.xml'],localeUploadPath:name=>name==='sip78.jar'?'English_United_States':'',refreshLocale(){},refreshAssets(){}});
  const localeStart=firmware.indexOf("  $('#ucm-locale-upload').on"),localeEnd=firmware.indexOf('\n  });',localeStart)+7;
  vm.runInNewContext(firmware.slice(localeStart,localeEnd),uploadContext);
  uploadState.site=1;uploadState.vendor='Cisco';uploadState.sent=[];uploadState.messages=[];uploadState.files=[{name:'sip78.jar',size:1},{name:'g4-tones.xml',size:1}];
  uploadState.handlers['#ucm-locale-upload']();await tick();assert.equal(uploadState.sent.at(-1).path,'English_United_States');
  uploadState.site=2;uploadState.locale='Different';uploadState.profile='different';
  uploadState.pending.shift().resolve({result:{ok:true}});await tick();assert.equal(uploadState.sent.at(-1).values.path,'English_United_States/sip78.jar');assert.equal(uploadState.sent.at(-1).values.site_id,1);
  uploadState.pending.shift().resolve({result:{ok:true}});await tick();assert.equal(uploadState.sent.at(-1).values.path,'g4-tones.xml');assert.equal(uploadState.sent.at(-1).values.site_id,1);
  uploadState.pending.shift().resolve({result:{ok:true}});await tick();assert.equal(uploadState.sent.at(-1).user_locale,'English_United_States');assert.equal(uploadState.sent.at(-1).site_id,1);
  uploadState.pending.shift().resolve({result:{ok:true,profiles:{sip78:{status:'complete'}}}});await tick();assert.equal(uploadContext.firmwareOperation,null);assert.ok(uploadState.messages.includes('Locale companions uploaded and verified.'));

  const editorValues={},editorProperties={};
  function editorJq(selector){return{0:{scrollIntoView(){}},val(value){if(value!==undefined){editorValues[selector]=value;return this;}return editorValues[selector]||'';},prop(name,value){editorProperties[selector+'|'+name]=value;return this;}};}
  const jobEditor={$:editorJq,site:()=>1,selectedFirmwarePackagesBySite:{},firmwareSourceBundles:[],renderReplicationPackages(){},refreshFirmwareSchedule(){},refreshFirmwareJobTargets(){},updateTargetSelectionUi(){},firmwarePackageKey:(p,l)=>p+'|'+l};
  for(const name of ['editFirmwareJob','resetFirmwareJob'])vm.runInNewContext(extract(firmware,name),jobEditor);
  jobEditor.editFirmwareJob({id:7,changed_only:0,retry_unreachable:0,packages:[],schedule:{}});
  assert.equal(editorProperties['#ucm-fw-replication-changed|checked'],false);assert.equal(editorProperties['#ucm-fw-replication-retry|checked'],false);
  jobEditor.editFirmwareJob({id:8,changed_only:1,retry_unreachable:1,packages:[],schedule:{}});
  assert.equal(editorProperties['#ucm-fw-replication-changed|checked'],true);assert.equal(editorProperties['#ucm-fw-replication-retry|checked'],true);
  jobEditor.resetFirmwareJob();assert.equal(editorValues['#ucm-fw-job-id'],0);assert.equal(editorProperties['.ucm-fw-job-target|checked'],false);

  const blocked={};uploadContext.firmwareRoot[0]={addEventListener:(type,handler)=>{blocked[type]=handler;}};
  vm.runInNewContext(firmware.split('\n').find(line=>line.includes("firmwareRoot[0].addEventListener")),uploadContext);
  uploadContext.beginFirmwareOperation();for(const type of ['click','pointerdown','input','change','submit']){
    let stopped=0;blocked[type]({preventDefault(){stopped++;},stopImmediatePropagation(){stopped++;}});assert.equal(stopped,2,'Busy Firmware blocks '+type);
  }uploadContext.endFirmwareOperation();

  // A file row cannot outlive its site/path/request context or retarget Delete.
  const fw={site:1,vendor:'Cisco',path:'',pending:[],sent:[],actions:[],labels:[]};
  const fileNode=()=>({html(){fw.actions=[];fw.labels=[];return this;},empty(){fw.actions=[];fw.labels=[];return this;},append(){return this;},text(v){fw.labels.push(v);return this;},on(t,f){fw.actions.push(f);return this;},children(){return{length:fw.actions.length};}});
  const filesBody=fileNode();
  const fileContext={$:selector=>selector==='#ucm-fw-files-list'?filesBody:fileNode(),site:()=>fw.site,vendor:()=>fw.vendor,uploadPath:()=>fw.path,firmwareFilesRequest:0,
    allowed:/\.loads$/,result:context.result,confirm:()=>true,declarationSuffix:'.ucmp-firmware.json',message(){},rescan:()=>deferred().resolve(),
    beginFirmwareOperation:()=>true,endFirmwareOperation(){},refreshInventory(){},
    call(data){fw.sent.push(data);const d=deferred();fw.pending.push(d);return d;}};
  fileContext.$.Deferred=deferred;
  vm.runInNewContext(extract(firmware,'refreshFiles'),fileContext);
  const list=name=>({result:{ok:true,entries:[{name,size:1}]}});
  fileContext.refreshFiles();const fromA=fw.pending.shift();fw.site=2;fileContext.refreshFiles();const fromB=fw.pending.shift();
  fromB.resolve(list('b.loads'));await tick();fromA.resolve(list('a.loads'));await tick();
  assert.ok(fw.labels.includes('b.loads')&&!fw.labels.includes('a.loads'),'Old-site files cannot overwrite current rows');
  const oldDelete=fw.actions[0];fw.site=1;const sentBefore=fw.sent.length;oldDelete();assert.equal(fw.sent.length,sentBefore,'Old row cannot delete on a different site');
  fileContext.refreshFiles();const earlier=fw.pending.shift();fileContext.refreshFiles();const newer=fw.pending.shift();
  newer.resolve(list('new.loads'));await tick();earlier.resolve(list('old.loads'));await tick();
  assert.ok(fw.labels.includes('new.loads')&&!fw.labels.includes('old.loads'),'Same-site older response cannot replace newer rows');
  fw.actions[0]();assert.equal(fw.sent.at(-1).site_id,1);assert.equal(fw.sent.at(-1).path,'new.loads');
  fw.site=2;fw.pending.shift().resolve({result:{ok:true}});await tick();

  fw.site=1;fw.vendor='Yealink';fw.path='firmware/yealink/t4u';fileContext.allowed=/\.rom$/;
  fileContext.refreshFiles();fw.pending.shift().resolve({result:{ok:true,entries:[{name:'release.rom',size:1,declaration_paths:[fw.path+'/v123456789abc.json',fw.path+'/release.rom.ucmp-firmware.json']}]}});await tick();
  fw.actions[0]();assert.equal(fw.sent.at(-1).path,fw.path+'/release.rom');
  fw.pending.shift().resolve({result:{ok:true}});await tick();
  assert.equal(fw.sent.at(-1).path,fw.path+'/v123456789abc.json','Compact firmware metadata deletion follows the server inventory');
  fw.pending.shift().resolve({result:{ok:true}});await tick();
  assert.equal(fw.sent.at(-1).path,fw.path+'/release.rom.ucmp-firmware.json','Retained legacy metadata is included when the server reports it');
  fw.pending.shift().resolve({result:{ok:true}});await tick();

  // Successful inventory reads survive editor changes; each request owns its render.
  const catalogs={site:'1',family:'cisco_ringlist',model:'all',ext:'raw',rows:[],html:'',hidden:false,pending:[]};
  function node(){return{value:'',items:[],html(v){catalogs.html=v;catalogs.rows=[];return this;},empty(){catalogs.html='';catalogs.rows=[];return this;},append(...v){this.items.push(...v);return this;},text(v){this.value=v;return this;},prop(){return this;},children(){return{length:catalogs.rows.length};}};}
  const body=node();body.append=(...v)=>{catalogs.rows.push(...v);return body;};
  function jq(selector){
    if(selector==='#ucm-media-sync-catalogs')return body;
    const values={'#ucm-media-site':catalogs.site,'#ucm-audio-family':catalogs.family,'#ucm-audio-model':catalogs.model,'#ucm-ring-type':catalogs.ext};
    return Object.assign(node(),{val:()=>values[selector]});
  }
  jq.extend=Object.assign;jq.Deferred=deferred;
  jq.when=(...values)=>{const d=deferred();Promise.all(values).then(rows=>d.resolve(rows),e=>d.reject(e));const done=d.done;d.done=function(f){return done.call(d,rows=>f(...rows));};return d;};
  const inventory={$:jq,mediaEpoch:0,mediaSiteEpoch:0,replicationCatalogRequest:0,mediaPanelLoaded:{replication:false},
    mediaRoot:{find:()=>({prop:()=>catalogs.hidden})},site:()=>catalogs.site,operationSite:()=>catalogs.site,result:response=>response.result||{},
    rejected:reason=>deferred().reject(reason),replicationRow:(kind,id,name)=>({kind,id,name}),loadMediaJobs(){},message(){},
    window:{ucmpAjax(options){const d=deferred();catalogs.pending.push({data:options.data,d});return d;}}};
  for(const name of ['mediaRequest','loadReplicationCatalogs','loadMediaPanel'])vm.runInNewContext(extract(media,name),inventory);
  function reply(batch,specialFailure){
    batch.forEach(({data,d})=>d.resolve({result:data.op==='wallpaper_set_list'?{ok:true,sets:[{id:'one',name:'Retained catalog'}]}:
      data.op==='special_catalog_list'?(specialFailure||{ok:true,catalogs:[]}):{ok:true,entries:[]}}));
  }
  inventory.loadMediaPanel('replication',false);let batch=catalogs.pending.splice(0);
  inventory.mediaEpoch++;reply(batch);await tick();await tick();
  assert.ok(catalogs.rows.some(row=>row.name==='Retained catalog'),'Background editor change must not reject successful catalog reads');
  inventory.loadReplicationCatalogs();batch=catalogs.pending.splice(0);reply(batch,{ok:false,message:'Special unavailable'});await tick();await tick();
  assert.ok(catalogs.rows.some(row=>row.name==='Retained catalog'),'A failed family must preserve successful catalogs');
  assert.equal(inventory.mediaPanelLoaded.replication,false,'Application failure must enable retry');
  inventory.loadMediaPanel('replication',false);assert.equal(catalogs.pending.length,3,'Returning to a failed panel must retry');
  batch=catalogs.pending.splice(0);batch[0].d.reject('transport timeout');reply(batch.slice(1));await tick();await tick();
  assert.equal(inventory.mediaPanelLoaded.replication,false,'Transport failure must enable retry');
  inventory.loadReplicationCatalogs();const old=catalogs.pending.splice(0);
  inventory.loadReplicationCatalogs();const latest=catalogs.pending.splice(0);
  reply(latest);await tick();await tick();const renderedRows=JSON.stringify(catalogs.rows);
  old.forEach(({d})=>d.reject('old failure'));await tick();await tick();
  assert.equal(JSON.stringify(catalogs.rows),renderedRows,'Older failure must not overwrite newer success');
  assert.equal(inventory.mediaPanelLoaded.replication,true);
  inventory.loadReplicationCatalogs();batch=catalogs.pending.splice(0);catalogs.site='2';inventory.mediaSiteEpoch++;catalogs.rows=[{name:'New site'}];
  reply(batch);await tick();await tick();assert.deepEqual(catalogs.rows,[{name:'New site'}],'Old-site replies must never populate a new site');
  catalogs.hidden=true;inventory.loadMediaPanel('replication',true);assert.equal(catalogs.pending.length,0,'Hidden panel must not fetch inventory');

  // Retained source bytes and displayed pixels must describe the same upload.
  const sourceReads=[],decoded=[];
  Object.assign(context,{hostedSourceImage:{old:true},hostedSourceData:'old-bytes',hostedSourceName:'old.png',hostedSourceDirty:false,
    activeSpecialCatalogId:'existing',activeSpecialRevision:'before-save',hostedCoverageOk:true,specialPublishedName:'existing.bmp',renderHosted(){},
    fileBase64(){const read=deferred();sourceReads.push(read);return read;},
    Image:class{constructor(){decoded.push(this);}},URL:{revokeObjectURL(){}},
    document:{getElementById(){return{};}},legacyBmpBase64:()=> 'new-rendered-output',safeName:()=> 'existing',
    loadHosted(){},loadReplicationCatalogs(){}});
  vm.runInNewContext(extract(media,'loadSpecialSource'),context);
  context.loadSpecialSource({name:'new.png',type:'image/png'});await tick();
  assert.ok(context.mediaOperation,'File decoding holds the existing Media operation boundary');
  assert.equal(context.hostedSourceData,'old-bytes');assert.equal(decoded.length,0,'Pixels cannot change before source bytes are read');
  sourceReads.shift().resolve('new-bytes');await tick();
  assert.equal(decoded.at(-1).src,'data:image/png;base64,new-bytes');assert.equal(context.hostedSourceData,'old-bytes');
  decoded.at(-1).onload();await tick();await tick();
  assert.equal(context.hostedSourceData,'new-bytes');assert.equal(context.hostedSourceImage,decoded.at(-1));assert.equal(context.hostedSourceName,'new.png');
  assert.equal(context.mediaOperation,null);
  context.loadSpecialSource({name:'broken.png',type:'image/png'});sourceReads.shift().resolve('broken');await tick();decoded.at(-1).onerror();await tick();await tick();
  assert.equal(context.hostedSourceData,'new-bytes');assert.equal(context.hostedSourceName,'new.png');assert.equal(context.mediaOperation,null,'Decode failure releases controls and retains both old values');
  context.loadSpecialSource({name:'unreadable.png',type:'image/png'});sourceReads.shift().reject('read failed');await tick();await tick();
  assert.equal(context.hostedSourceData,'new-bytes');assert.equal(context.mediaOperation,null,'Source read failure also releases controls');
  const saveHandler=media.split('\n').find(line=>line.includes("$('#ucm-hosted-publish').on('click'"));
  vm.runInNewContext(saveHandler,context);const saveSpecial=actions.at(-1);values['#ucm-hosted-name']='existing';
  saveSpecial();assert.ok(context.mediaOperation,'Special catalog save holds the selected source and site');
  const specialCount=sent.length;saveSpecial();assert.equal(sent.length,specialCount,'Overlapping special saves cannot start');
  assert.equal(sent.at(-1).source,'new-bytes');assert.equal(sent.at(-1).content,'new-rendered-output');
  assert.equal(sent.at(-1).expected_manifest_sha256,'before-save');
  pending.shift().resolve({result:{ok:true,catalog:{catalog_id:'existing',published_name:'existing.bmp',manifest_sha256:'after-save'}}});await tick();await tick();
  assert.equal(context.activeSpecialRevision,'after-save');
  assert.equal(context.mediaOperation,null);assert.equal(context.hostedSourceDirty,false);
  saveSpecial();pending.shift().reject('lost response');await tick();await tick();assert.equal(context.mediaOperation,null,'Lost save response releases controls without replay');

  // Execute the real manual replication click handler, including mixed scopes.
  context.confirm=()=>true;context.loadRings=()=>{};
  vm.runInNewContext(extract(media,'removeCiscoRingtone'),context);
  context.removeCiscoRingtone('test.raw','raw');const deleteRequestCount=sent.length;
  context.removeCiscoRingtone('test.raw','raw');assert.equal(sent.length,deleteRequestCount,'Ringtone deletion cannot overlap');
  pending.shift().resolve({result:{ok:false,message:'Referenced ringtone'}});await tick();await tick();
  assert.equal(sent.length,deleteRequestCount,'A failed delete must not rebuild the ringtone index');
  assert.equal(context.mediaOperation,null);
  context.removeCiscoRingtone('test.raw','raw');pending.shift().resolve({result:{ok:true}});await tick();
  assert.equal(sent.at(-1).op,'regen_ringlist','Confirmed deletion rebuilds its index');
  pending.shift().resolve({result:{ok:false,message:'Index write failure'}});await tick();await tick();
  assert.equal(context.mediaOperation,null,'Late index failure releases UI controls without replay');

  // Execute the real manual replication click handler, including mixed scopes.
  const shownResults=[];
  Object.assign(context,{requireTargets:()=>[3,4],selectedSetIds:kind=>kind==='wallpaper'?['wall-one']:[],selectedRingtoneNames:()=>[],
    selectedAudioRows:()=>[{family:'yealink',model:'one',name:'a.wav'},{family:'grandstream',model:'two',name:'b.wav'}]});
  context.window.ucmpReplicationResults=r=>{shownResults.push(r);return element;};
  const manualStart=media.indexOf("  $('#ucm-media-sync-selected').on('click',function(){");
  vm.runInNewContext(media.slice(manualStart,media.indexOf('\n  function formatMediaJobTime',manualStart)),context);
  const manualSync=actions.at(-1);manualSync();await tick();
  assert.deepEqual(Array.from(sent.at(-1).target_site_ids),[3,4]);assert.equal(sent.at(-1).site_id,'1');
  const runningCount=sent.length;manualSync();assert.equal(sent.length,runningCount,'A second click cannot overlap manual replication');
  const firstOutcome={ok:false,destination_results:[{site_id:3,status:'failed'},{site_id:4,status:'success'}],message:'One destination failed'};
  pending.shift().resolve({result:firstOutcome});await tick();await tick();
  assert.equal(sent.at(-1).family,'yealink');assert.deepEqual(Array.from(sent.at(-1).target_site_ids),[4],'Later scopes use only confirmed successful destinations');
  pending.shift().resolve({result:{ok:true,destination_results:[{site_id:4,status:'success'}]}});await tick();await tick();
  assert.equal(sent.at(-1).family,'grandstream');assert.equal(sent.at(-1).site_id,'1','Later families retain the selected source');
  assert.equal(shownResults[0],firstOutcome,'The UI keeps the first per-destination result');
  const lastCount=sent.length;pending.shift().reject('lost final response');await tick();await tick();
  assert.equal(sent.length,lastCount,'Lost final response never replays the preceding transfers');
  assert.equal(shownResults.length,2,'Confirmed preceding results remain displayed after loss');assert.equal(context.mediaOperation,null);

  const wallpaperRequests=[],wallpaperMessages=[],wallpaperReceipts=[];
  let wallpaperBusy=false;
  function wallpaperJq(selector){return{length:1,val:()=> 'Fixture catalog',prop(){return this;},empty(){return this;},append(){return this;},
    text(value){if(selector==='<pre>')wallpaperReceipts.push(JSON.parse(value));return this;}};}
  wallpaperJq.Deferred=deferred;
  const wallpaperContext={$:wallpaperJq,wallpaperSaveUncertain:{},activeSetId:'',sourceDirty:true,sourceImage:{},profileSourceDirty:{},profileSourceRemoved:{},
    profile:()=>({key:'fixture'}),rememberTransform(){},safeName:()=> 'fixture_catalog',site:()=> '1',currentScope:()=>({models:['Fixture']}),
    beginMediaOperation(){if(wallpaperBusy)return false;wallpaperBusy=true;return true;},endMediaOperation(){wallpaperBusy=false;},
    rowFor:()=>({}),loadProfileImage:()=>deferred().resolve(),normalizedSource:()=>({mime:'image/png',source:'synthetic'}),
    renderedPair:()=>({enabled:true,invert:false,full:'synthetic',thumb:'synthetic',transform:{}}),targetModels:()=>['Fixture'],
    message(value){wallpaperMessages.push(value);},result:response=>response.result||{},
    call(data){const request=deferred();wallpaperRequests.push({data,request});return request;},
    loadWallpaperSets(){},loadLegacyBackgrounds(){},updateProfileState(){},renderStudio(){}};
  for(const name of ['rejected','loadProfileImages','retainProfileSources','saveWallpaperProfiles'])vm.runInNewContext(extract(media,name),wallpaperContext);
  const wallpaperProfiles=[1,2,3,4].map(n=>({key:'profile-'+n,dir:'fixture'}));
  wallpaperContext.saveWallpaperProfiles(wallpaperProfiles);await tick();
  assert.equal(wallpaperRequests[0].data.intent,'create');
  wallpaperContext.saveWallpaperProfiles(wallpaperProfiles);await tick();assert.equal(wallpaperRequests.length,1,'A pending wallpaper save cannot start twice');
  wallpaperRequests[0].request.resolve({result:{ok:true}});await tick();
  assert.equal(wallpaperContext.activeSetId,'fixture_catalog','A confirmed source creation establishes catalog identity before batches run');
  assert.equal(wallpaperContext.sourceDirty,false);
  wallpaperRequests[1].request.resolve({result:{ok:true,written:6}});await tick();
  wallpaperRequests[2].request.resolve({result:{ok:false,message:'Last profile rejected'}});await tick();
  assert.equal(wallpaperBusy,false);
  assert.match(wallpaperMessages.at(-1),/3 profile\(s\) confirmed saved/);
  assert.deepEqual(wallpaperReceipts.at(-1).map(row=>row.status),['succeeded','succeeded','failed']);
  wallpaperContext.saveWallpaperProfiles(wallpaperProfiles);await tick();
  assert.equal(wallpaperRequests[3].data.op,'put_media_pairs','Retrying a known failed batch must not recreate an already saved catalog');
  wallpaperRequests[3].request.reject('lost reply');await tick();
  const wallpaperCount=wallpaperRequests.length;
  wallpaperContext.saveWallpaperProfiles(wallpaperProfiles);await tick();
  assert.equal(wallpaperRequests.length,wallpaperCount,'An unknown save result blocks another mutation until reconciliation');
  assert.equal(wallpaperReceipts.at(-1)[0].status,'unknown');
  console.log('Delayed Media context, retirement revision, wallpaper partial saves and firmware profile behavior passed');
})().catch(error=>{console.error(error);process.exitCode=1;});
