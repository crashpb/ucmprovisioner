'use strict';
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
const source=fs.readFileSync(require('node:path').join(__dirname,'../assets/js/web-phonebook.js'),'utf8');
const normalizer=source.slice(source.indexOf('  const normalize ='),source.indexOf('  const collator ='));
const matcher=source.slice(source.indexOf('  function queryMatch('),source.indexOf('  const filtered ='));
const context={fields:{name:'Name',extension:'Extension',alternate:'Alternate name'},result:null};
vm.createContext(context);
vm.runInContext(normalizer+matcher+'\nresult=queryMatch;',context);
const match=context.result;
assert.equal(match({name:'Alex',extension:'۱۲۳'},'123').rank,0);
assert.equal(match({name:'Alex Example',extension:'456'},'alex').rank,1);
assert.equal(match({name:'Example Alex',extension:'456'},'alex').rank,2);
assert.equal(match({name:'Alice',extension:'456',alternate:'علی'},'علی').rank,1);
assert.equal(match({name:'Alice',extension:'456',mobile:'123'},'123').rank,99,'Hidden fields must never participate');
assert.equal(match({name:'Alice',extension:'456'},'alice missing').rank,99);
assert.equal(match({name:'ياسر كاظم',extension:'456'},'یاسر').rank,1);
assert.deepEqual(Array.from(match({name:'Alice',extension:'123'},'123').fields),['Extension']);
console.log('Projected-field, Persian normalization, exact-extension and name-prefix ranking passed');
const designer=fs.readFileSync(require('node:path').join(__dirname,'../views/directory.php'),'utf8');
const exclusionReader=designer.match(/function exclusions\(\)\{[^\n]+\}/)[0];
const exclusionsContext={retainedExclusions:new Set(['source-a:visible','source-b:off-page','legacy-object-key']),read:null};
vm.createContext(exclusionsContext);
vm.runInContext(exclusionReader+';read=exclusions;',exclusionsContext);
assert.deepEqual(Array.from(exclusionsContext.read()),['source-a:visible','source-b:off-page','legacy-object-key']);
exclusionsContext.retainedExclusions.delete('source-a:visible');
assert.deepEqual(Array.from(exclusionsContext.read()),['source-b:off-page','legacy-object-key']);
console.log('Designer exclusions retain off-page and legacy namespace entries');

// Execute the real preview callbacks and form handler with deferred AJAX replies.
const nodes=new Map(), requests=[];
function node(selector) {
  if(!nodes.has(selector))nodes.set(selector,{
    value:'',props:{},val(value){if(value===undefined)return this.value;this.value=value;return this;},
    prop(key,value){this.props[key]=value;return this;},text(){return this;},html(){return this;},
    empty(){return this;},append(){return this;},on(events,handler){this.handler=handler;return this;}
  });
  return nodes.get(selector);
}
const reviewContext={$:node,document:{},previewTimer:null,previewData:null,serializeBook(){},renderBookPreview(){},
  draft:{title:'Draft'},setTimeout(){return 1;},clearTimeout(){},
  window:{ucmpAjax(){const request={done(fn){this.resolve=fn;return this;},fail(fn){this.reject=fn;return this;}};requests.push(request);return request;}}
};
reviewContext.reviewDraft=()=>({...reviewContext.draft});
vm.createContext(reviewContext);
vm.runInContext(designer.slice(designer.indexOf('  var previewSequence=0;'),designer.indexOf("  $('#ucm-book-add-rule').on")),reviewContext);
const formLine=designer.split('\n').find(line=>line.includes("$('#ucm-phonebook-form').on('input change',function(e)"));
vm.runInContext(formLine.slice(0,formLine.indexOf(".on('submit'"))+';',reviewContext);
const form=node('#ucm-phonebook-form'),approval=node('#ucm-book-review-approved'),label=node('#ucm-book-review-approval'),revision=node('#ucm-book-review-revision');
function response(exceptional,revisionValue='review-1') {
  return {status:true,result:{review:{exceptional,revision:revisionValue,before_count:10,after_count:1,
    additions:[],removals:[],changed:[],publish_sites:[],sites:[],exposed_fields:[],reasons:[]}}};
}
reviewContext.requestBookPreview();
assert.equal(label.props.hidden,true);
requests[0].resolve(response(true));
assert.equal(label.props.hidden,false);
approval.prop('checked',true);
for(const type of ['input','change'])form.handler({type,target:{id:'ucm-book-review-approved'}});
assert.equal(approval.props.checked,true,'Approval must survive both checkbox events');
assert.equal(revision.value,'review-1');
reviewContext.draft.title='Edited';
form.handler({type:'input',target:{id:'draft-title'}});
assert.equal(approval.props.checked,false);
assert.equal(revision.value,'');
assert.equal(label.props.hidden,true);
reviewContext.requestBookPreview();
reviewContext.draft.title='Edited again';
form.handler({type:'change',target:{id:'draft-title'}});
requests[1].resolve(response(true,'stale'));
assert.equal(revision.value,'','An in-flight response must not restore an edited review');
reviewContext.requestBookPreview();
reviewContext.requestBookPreview();
requests[3].resolve(response(false,'current'));
requests[2].resolve(response(true,'older'));
assert.equal(revision.value,'current','An older response must not replace the current review');
assert.equal(label.props.hidden,true,'Ordinary reviews must hide approval');
console.log('Approval input/change, draft invalidation and out-of-order preview behavior passed');

// Retain the exact output selected before the asynchronous permission/revision check.
(async()=>{
  let reply, printed='', downloaded='', renders=0, printHandler;
  const outputNodes=new Map();
  const outputNode=selector=>{
    if(!outputNodes.has(selector))outputNodes.set(selector,{value:'department',innerHTML:''});
    return outputNodes.get(selector);
  };
  const outputContext={
    model:{book:'original',revision:'r1',title:'Original book'},printingMarkup:null,
    state:{busy:false,output:'print',scope:'filtered',preview:true},
    outputRows:()=>[{id:'original-contact'}],outputKeys:()=>['name'],outputScope:()=> 'Original scope',
    printMarkup:(rows,keys,scope)=>scope+':'+rows.map(row=>row.id).join(','),
    $:outputNode,status(){},render(){renders++;},endpoint:()=>'/fixture',responseOrError:async response=>response,
    fetch:()=>new Promise(resolve=>{reply=resolve;}),
    window:{addEventListener(event,handler){printHandler=handler;},print(){printHandler();printed=outputNode('#pb-printable').innerHTML;}},
    document:{body:{appendChild(){}},createElement(){return {click(){downloaded=this.download;},remove(){}};}},
    URL:{createObjectURL:()=> 'blob:fixture',revokeObjectURL(){}},setTimeout(){},Date
  };
  vm.createContext(outputContext);
  const execute=source.slice(source.indexOf('  async function executeOutput()'),source.indexOf("  $('#pb-search').addEventListener"));
  const beforePrint=source.slice(source.indexOf("  window.addEventListener('beforeprint'"),source.indexOf('  render(); load('));
  vm.runInContext(execute+beforePrint,outputContext);
  const printing=outputContext.executeOutput();
  assert.equal(outputContext.state.busy,true);
  assert.equal(renders,1,'Starting output must refresh all request-sensitive controls');
  outputContext.outputRows=()=>[{id:'later-contact'}];outputContext.outputScope=()=> 'Later scope';
  reply({json:async()=>({revision:'r1'})});await printing;
  assert.equal(printed,'Original scope:original-contact','beforeprint must not replace the checked output with a later selection');
  outputContext.state.output='excel';
  const exporting=outputContext.executeOutput();
  outputContext.model={book:'later',revision:'r2'};
  reply({headers:{get:()=> 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'},blob:async()=>({})});
  await exporting;
  assert.match(downloaded,/^phonebook-original-/,'An in-flight export must keep its original book filename');
  assert.equal(outputContext.state.busy,false);
  console.log('Web phonebook asynchronous print/export selection retention passed');
})().catch(error=>{console.error(error);process.exitCode=1;});
