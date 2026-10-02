'use strict';
const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict'),path=require('node:path');
const read=file=>fs.readFileSync(path.join(__dirname,'..',file),'utf8');
const settings=read('views/settings.php'),templates=read('views/templates.php');
const output=(catalog,model='ghp630')=>({catalog_id:catalog,catalog_name:catalog,name:catalog+'.jpg',filename:catalog+'.jpg',content_sha256:catalog,profile:'grandstream:480x320',family:'grandstream',model,url:'http://pbx.test/'+model+'/'+catalog+'.jpg',selectable:true});
const current=[output('Original'),output('Revamped'),output('Original','ghp630w'),output('Revamped','ghp630w')];
const loose={...output('loose'),catalog_id:'',catalog_name:''};
const inventory={ok:true,items:[...current,loose,{...loose,name:'old.jpg'}],background_choices:current};
const context={defaultAssetState:{hosted:inventory}};
vm.createContext(context);
vm.runInContext(settings.slice(settings.indexOf('  function uniqueAssignmentOptions('),settings.indexOf('  function assignmentInventoryState(')),context);
let choices=context.assignmentOptions('hosted_wallpaper',{id:'grandstream:480x320'},current[2].url);
assert.equal(choices.length,2,'Settings must show one current choice per catalog, despite duplicate model libraries and observed files');
assert.equal(choices[0].value,current[2].url,'A saved current URL in another compatible library must not be rewritten');
context.defaultAssetState.hosted={items:inventory.items};
assert.equal(context.assignmentOptions('hosted_wallpaper',{id:'grandstream:480x320'},'').length,0,'An old peer response must never fall back to raw files');

// Execute the background section of the actual Template loader with small DOM
// stand-ins; requests and options still use production filtering/rendering code.
let children=[],selected='',pending,allow=true,saved='http://pbx.test/ghp630/old.jpg';
const requestLog=[];
function option(value='',label='',disabled=false){return{value,label,disabled,val(v){if(v===undefined)return this.value;this.value=v;return this;},text(v){this.label=v;return this;},clone(){return option(this.value,this.label,this.disabled);}};}
function collection(rows){return{length:rows.length,first(){return rows[0]||option();},filter(fn){return collection(rows.filter(row=>fn.call(row)));},remove(){children=children.filter(child=>!rows.includes(child));return this;}};}
const picker={
  data(key,value){if(value!==undefined)return this;return {'media-family':'grandstream',key:'hosted_image_url'}[key];},
  find(query){return collection(children.filter(child=>query==='option'||(query==='option[value=""]'&&child.value==='')||(query==='option:disabled'&&child.disabled)));},
  empty(){children=[];return this;},append(child){children.push(child);return this;},prop(){return this;},toggleClass(){return this;},
  val(value){selected=value;return this;}
};
function $(selector){if(selector===picker)return picker;if(typeof selector==='object')return selector;if(selector==='.ucm-media-picker:visible')return{each(fn){fn.call(picker);}};if(selector.startsWith('<option'))return option('','',selector.includes('disabled'));throw Error(selector);}
const templateContext={$,catalogRead:()=>()=>allow,modelContext:()=>'',model:()=>({data:key=>({mediaprofile:'grandstream:480x320',model:'GHP630'})[key]}),
  hostedImageSelections:{'grandstream:480x320':saved},siteDefaultMap:()=>({}),selectedSiteDefaults:()=>({}),showLegacyAsset:()=>false,
  mediaCall(data){requestLog.push(data);return data;},readMediaCatalogs(requests,done){pending=done;},renderInheritance(){},serializeSettings(){}};
vm.createContext(templateContext);
const start=templates.indexOf('  function loadMediaPickers('),end=templates.indexOf("    $('.ucm-ringtone-picker:visible')",start);
vm.runInContext(templates.slice(start,end)+'\n  }',templateContext);
function run(response){children=[option()];templateContext.loadMediaPickers();pending([{status:true,result:response}]);}
run(inventory);
assert.deepEqual(requestLog.map(r=>r.op),['inventory'],'Background picker must use catalog-aware inventory, not raw family/model lists');
assert.equal(children.filter(o=>o.value&&!o.disabled).length,3,'Only two current backgrounds plus the explicitly retained saved legacy URL');
assert.equal(selected,saved);assert.ok(children.some(o=>o.label.includes('saved URL; not a current catalog choice')));
assert.ok(children.some(o=>o.label==='Revamped — Revamped.jpg'),'Display the owning catalog label');
assert.ok(!children.some(o=>o.value===loose.url),'Loose files cannot become choices');
run({ok:true,items:inventory.items});
assert.equal(children.filter(o=>o.value&&!o.disabled).length,1,'Old peers preserve the saved URL without offering arbitrary files');
assert.equal(selected,saved);assert.ok(children.some(o=>o.disabled&&o.label.includes('update the source site')));
children=[option()];templateContext.loadMediaPickers();allow=false;const before=children.slice();pending([{status:true,result:inventory}]);
assert.deepEqual(children,before,'A delayed response cannot populate another model or site');
console.log('Catalog-only background choices, model grouping, old-peer refusal, saved legacy URLs and stale responses passed');
