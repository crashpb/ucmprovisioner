(() => {
  'use strict';
  const root = document.getElementById('ucmp-webbook');
  if (!root) return;
  const $ = selector => root.querySelector(selector);
  const $$ = selector => [...root.querySelectorAll(selector)];
  const escape = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const normalize = value => String(value ?? '').toLowerCase().normalize('NFKC')
    .replace(/[۰-۹]/g,d=>'۰۱۲۳۴۵۶۷۸۹'.indexOf(d)).replace(/[٠-٩]/g,d=>'٠١٢٣٤٥٦٧٨٩'.indexOf(d))
    .replace(/ي/g,'ی').replace(/ك/g,'ک').replace(/\u200c/g,' ').trim();
  const digits = value => normalize(value).replace(/\D/g,'');
  const collator = new Intl.Collator(undefined,{numeric:true,sensitivity:'base'});
  let appearance=window.matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light';
  try { const saved=window.localStorage.getItem('ucmp-directory-theme'); if(['light','dark'].includes(saved))appearance=saved; } catch (_) {}
  const state = {layout:'table',appearance,query:'',site:'',department:'',sort:'name',page:1,
    selected:new Set(),output:null,scope:'filtered',preview:false,columns:new Set(),busy:false};
  let model = null, contacts = [], fields = {}, loadSequence = 0, loadController = null, printingMarkup = null;
  let filteredCacheKey = null, filteredCache = [], uniqueCache = new Map();
  const countLabel = count => count+' contact'+(count===1?'':'s');
  const pageSize = () => state.layout==='people'?12:25;
  const status = (message, error=false) => {
    $('#pb-status').hidden=!message; $('#pb-status').textContent=message;
    $('#pb-status').setAttribute('role',error?'alert':'status');
  };
  const sorted = rows => [...rows].sort((a,b)=>collator.compare(a[state.sort]||'',b[state.sort]||'')||collator.compare(a.name,b.name)||collator.compare(a.id,b.id));
  function queryMatch(contact,query) {
    const tokens=normalize(query).split(/\s+/).filter(Boolean), matched=new Set();
    let rank=0;
    for(const token of tokens) {
      let best=99;
      for(const key of Object.keys(fields)) {
        const value=normalize(contact[key]), numeric=['extension','mobile','employee'].includes(key)&&/^[+\d().-]+$/.test(token)&&digits(token)!=='';
        if(!value.includes(token)&&!(numeric&&digits(value).includes(digits(token))))continue;
        matched.add(fields[key]);
        best=Math.min(best,key==='extension'&&digits(value)===digits(token)&&digits(token)!==''?0:['name','alternate'].includes(key)&&value.startsWith(token)?1:2);
      }
      if(best===99)return {rank:99,fields:[]};
      rank+=best;
    }
    return {rank,fields:[...matched]};
  }
  const filtered = () => {
    const key=[state.query,state.site,state.department,state.sort].join('\0');
    if(filteredCacheKey===key)return filteredCache;
    const ranked=[];
    for(const contact of contacts){
      if(state.site&&contact.site!==state.site)continue;
      if(state.department&&contact.department!==state.department)continue;
      const match=queryMatch(contact,state.query);
      if(match.rank!==99)ranked.push({contact,rank:match.rank});
    }
    ranked.sort((a,b)=>a.rank-b.rank||collator.compare(a.contact[state.sort]||'',b.contact[state.sort]||'')||collator.compare(a.contact.name||'',b.contact.name||'')||collator.compare(a.contact.id||'',b.contact.id||''));
    filteredCache=ranked.map(item=>item.contact); filteredCacheKey=key;
    return filteredCache;
  };
  const outputRows = () => sorted(state.scope==='all'?contacts:state.scope==='selected'?contacts.filter(c=>state.selected.has(c.id)):filtered());
  const outputKeys = () => Object.keys(fields).filter(key=>state.columns.has(key));
  const unique = key => {
    if(!uniqueCache.has(key))uniqueCache.set(key,[...new Set(contacts.map(c=>c[key]||'').filter(Boolean))].sort(collator.compare));
    return uniqueCache.get(key);
  };
  const snapshotTime = () => model?.generated?new Date(model.generated*1000).toLocaleString():'Unknown publication time';
  const outputScope = () => state.scope==='filtered'?'Current results':state.scope==='selected'?'Selected contacts':'Whole phonebook';
  function endpoint(action,book='') {
    const url=new URL(window.location.href); url.search=''; url.hash='';
    url.searchParams.set('action',action); if(book)url.searchParams.set('book',book); return url;
  }
  async function responseOrError(response) {
    if(response.ok)return response;
    let message='The phonebook request failed. Please refresh or contact the administrator.';
    try { const payload=await response.json(); if(typeof payload.error==='string')message=payload.error; } catch (_) {}
    throw new Error(message);
  }
  async function load(book='') {
    if(state.busy)return;
    const sequence=++loadSequence;
    if(loadController)loadController.abort(); loadController=new AbortController();
    contacts=[]; model=null; fields={}; filteredCacheKey=null; filteredCache=[]; uniqueCache.clear(); state.selected.clear(); state.output=null;
    $('#pb-output').hidden=true; $('#pb-detail').hidden=true; $('#pb-printable').textContent='';
    status('Loading published phonebook…'); render();
    try {
      const response=await responseOrError(await fetch(endpoint('data',book),{credentials:'omit',cache:'no-store',signal:loadController.signal}));
      const data=await response.json(); if(sequence!==loadSequence)return;
      if(!data||!Array.isArray(data.contacts)||!Array.isArray(data.books)||!data.fields||typeof data.revision!=='string')throw new Error('Invalid directory response.');
      model=data; contacts=data.contacts; fields=data.fields; filteredCacheKey=null; filteredCache=[]; uniqueCache.clear();
      state.columns=new Set(Object.keys(fields).filter(key=>!['mobile','employee','label'].includes(key)));
      state.page=1;
      if(!unique('site').includes(state.site))state.site='';
      if(!unique('department').includes(state.department))state.department='';
      if(!fields[state.sort])state.sort='name';
      $('#pb-book').innerHTML=model.books.map(book=>'<option value="'+escape(book.key)+'">'+escape(book.name)+'</option>').join('');
      $('#pb-book').value=model.book; $('#pb-heading').textContent=model.title;
      status(''); render();
    } catch(error) {
      if(sequence!==loadSequence||error.name==='AbortError')return;
      status(error.message,true); render();
    }
  }
  function renderFilters() {
    for(const key of ['site','department']) {
      const label=key==='site'?'All sites':'All departments', enabled=!!fields[key];
      $('#pb-'+key).hidden=!enabled;
      $('#pb-'+key).innerHTML='<option value="">'+label+'</option>'+unique(key).map(value=>'<option value="'+escape(value)+'">'+escape(value)+'</option>').join('');
      $('#pb-'+key).value=state[key];
      $('#pb-'+key+'-links').innerHTML=enabled?['',...unique(key)].map(value=>'<button type="button" class="pb-side-button" data-filter="'+key+'" data-value="'+escape(value)+'" aria-pressed="'+(state[key]===value)+'"><span>'+escape(value||label)+'</span><span>'+contacts.filter(c=>!value||c[key]===value).length+'</span></button>').join(''):'';
      $('#pb-'+key+'-links').previousElementSibling.hidden=!enabled;
    }
    root.dataset.browse=String(!!(fields.site||fields.department));
    $('#pb-reset').hidden=!(state.query||state.site||state.department); $('#pb-clear-search').hidden=!state.query;
    const searchable=['name','extension','mobile','employee'].filter(key=>fields[key]).map(key=>fields[key].toLowerCase());
    $('#pb-search').placeholder=searchable.length?'Search '+searchable.join(', '):'Search contacts';
    $('#pb-sort').innerHTML=['name','extension','department'].filter(key=>fields[key]).map(key=>'<option value="'+key+'">'+(key==='name'?'Name A–Z':fields[key])+'</option>').join('');
    $('#pb-sort').value=state.sort;
  }
  const nameButton = c => '<button type="button" class="pb-name-button" data-contact="'+escape(c.id)+'" dir="auto">'+escape(c.name)+'</button><span class="pb-secondary">'+(state.query?'Matched: '+escape(queryMatch(c,state.query).fields.join(', ')):'')+(contacts.some(other=>other.id!==c.id&&other.extension===c.extension)?' · Shared extension — verify site':'')+'</span>';
  const checkbox = c => '<label class="pb-check"><input type="checkbox" data-select="'+escape(c.id)+'" '+(state.selected.has(c.id)?'checked':'')+' aria-label="Select '+escape(c.name)+'"></label>';
  function renderContacts() {
    const rows=filtered(), size=pageSize(), pages=Math.max(1,Math.ceil(rows.length/size)); state.page=Math.min(Math.max(1,state.page),pages);
    const page=rows.slice((state.page-1)*size,state.page*size), keys=Object.keys(fields);
    $('#pb-count').innerHTML='<strong>'+countLabel(rows.length)+'</strong><small> '+(rows.length!==contacts.length?'of '+contacts.length+' in this phonebook':'in this phonebook')+'</small>';
    $('#pb-selection').hidden=!state.selected.size;
    $('#pb-selected-count').textContent=state.selected.size+' selected'+(state.selected.size>rows.filter(c=>state.selected.has(c.id)).length?' · includes contacts outside these filters':'');
    if(!page.length) {
      $('#pb-contact-list').innerHTML='<div class="pb-empty"><strong>'+(model?'No matching contacts':'Directory not loaded')+'</strong>'+(model?'<span>Try another name, number or department.</span><br><button type="button" class="pb-reset" data-clear-filters>Clear search and filters</button>':'')+'</div>';
    } else if(state.layout==='table') {
      $('#pb-contact-list').innerHTML='<div class="pb-table-wrap"><table class="pb-table"><thead><tr><th class="pb-select-cell"><label class="pb-check"><input type="checkbox" id="pb-select-page" aria-label="Select this page" '+(page.every(c=>state.selected.has(c.id))?'checked':'')+'></label></th>'+keys.map(key=>'<th scope="col">'+escape(fields[key])+'</th>').join('')+'</tr></thead><tbody>'+page.map(c=>'<tr class="'+(state.selected.has(c.id)?'pb-selected-row':'')+'"><td class="pb-select-cell">'+checkbox(c)+'</td>'+keys.map(key=>'<td dir="auto">'+(key==='name'?nameButton(c):key==='extension'?'<span class="pb-number" dir="ltr">'+escape(c[key])+'</span>':escape(c[key]))+'</td>').join('')+'</tr>').join('')+'</tbody></table></div>';
      $('#pb-select-page').indeterminate=page.some(c=>state.selected.has(c.id))&&!page.every(c=>state.selected.has(c.id));
    } else {
      $('#pb-contact-list').innerHTML='<div class="pb-people">'+page.map(c=>'<article class="pb-person '+(state.selected.has(c.id)?'pb-selected-row':'')+'"><div class="pb-person-head"><span class="pb-avatar" aria-hidden="true">'+escape(c.name.split(' ').slice(0,2).map(word=>word[0]).join(''))+'</span>'+checkbox(c)+'</div>'+nameButton(c)+keys.filter(key=>!['name','extension'].includes(key)).map(key=>'<span class="pb-secondary" dir="auto"><strong>'+escape(fields[key])+':</strong> '+escape(c[key]||'—')+'</span>').join('')+'<div class="pb-person-bottom"><span class="pb-number" dir="ltr">'+escape(c.extension)+'</span><button type="button" class="pb-details-link" data-contact="'+escape(c.id)+'">View contact →</button></div></article>').join('')+'</div>';
    }
    $('#pb-page-range').textContent=rows.length?((state.page-1)*size+1)+'–'+Math.min(state.page*size,rows.length)+' of '+rows.length:'0 contacts';
    $('#pb-page-number').textContent=state.page+' / '+pages; $('#pb-prev').disabled=state.page===1; $('#pb-next').disabled=state.page===pages;
  }
  function render() {
    root.dataset.layout=state.layout; root.style.colorScheme=state.appearance; document.documentElement.style.colorScheme=state.appearance;
    const themeLabel='Switch to '+(state.appearance==='dark'?'light':'dark')+' mode';
    $('#pb-theme').setAttribute('aria-label',themeLabel); $('#pb-theme').title=themeLabel;
    $('#pb-theme').innerHTML='<svg class="pb-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" aria-hidden="true">'+(state.appearance==='dark'?'<circle cx="12" cy="12" r="4"/><path d="M12 2v2m0 16v2M2 12h2m16 0h2M5 5l1.5 1.5m11 11L19 19M5 19l1.5-1.5m11-11L19 5"/>':'<path d="M21 12a9 9 0 1 1-9-9 7 7 0 0 0 9 9z"/>')+'</svg>';
    $$('button[data-layout]').forEach(button=>button.setAttribute('aria-pressed',String(button.dataset.layout===state.layout)));
    $('#pb-print').disabled=!model||state.busy; $('#pb-export').disabled=!model||state.busy||model.export_enabled===false;
    $('#pb-book').disabled=!model||state.busy; $('#pb-search').disabled=!model;
    $('#pb-refresh').disabled=state.busy;
    $('#pb-published').textContent=model?'Snapshot generated · '+snapshotTime()+' | Accepted source · '+(model.accepted_at?new Date(model.accepted_at*1000).toLocaleString():'Unknown')+' | '+(model.policy_label||'Internal directory'):'';
    $('#pb-help').hidden=!model?.help_url; $('#pb-help').href=model?.help_url||'#';
    renderFilters(); renderContacts(); if(state.output)renderOutput();
  }
  function clearFilters() { state.query=''; state.site=''; state.department=''; state.page=1; $('#pb-search').value=''; render(); }
  function openContact(id) {
    const contact=contacts.find(c=>c.id===id); if(!contact)return;
    $('#pb-detail').hidden=false;
    $('#pb-detail').innerHTML='<header class="pb-dialog-head"><div><div class="pb-kicker">Contact details</div><h3 dir="auto">'+escape(contact.name)+'</h3></div><button type="button" class="pb-button pb-icon-only" data-close-detail aria-label="Close contact details">×</button></header><div class="pb-dialog-body"><dl class="pb-detail-grid">'+Object.keys(fields).filter(key=>key!=='name').map(key=>'<div><dt>'+escape(fields[key])+'</dt><dd dir="'+(['extension','mobile','employee'].includes(key)?'ltr':'auto')+'">'+escape(contact[key]||'Not listed')+'</dd></div>').join('')+'</dl><div class="pb-actions pb-detail-actions"><button type="button" class="pb-reset pb-copy" data-copy="name" data-copy-contact="'+escape(contact.id)+'">Copy name</button><button type="button" class="pb-reset pb-copy" data-copy="extension" data-copy-contact="'+escape(contact.id)+'">Copy number</button></div></div>';
    $('[data-close-detail]').focus();
  }
  function openOutput(type) {
    if(!model||(type==='excel'&&model.export_enabled===false))return;
    state.output=type; state.scope=state.selected.size?'selected':'filtered'; state.preview=false;
    $('#pb-output').hidden=false; $('#pb-detail').hidden=true;
    $('#pb-output-title').textContent=type==='print'?'Print phonebook':'Export to Excel';
    $('#pb-print-settings').hidden=type!=='print'; $('#pb-excel-note').hidden=type!=='excel';
    renderOutput(); $('#pb-close-output').focus();
  }
  function renderOutput() {
    if(state.scope==='selected'&&!state.selected.size)state.scope='filtered';
    $('#pb-scope-filtered').textContent='Current results ('+filtered().length+')'; $('#pb-scope-selected').textContent='Selected contacts ('+state.selected.size+')'; $('#pb-scope-all').textContent='Whole phonebook ('+contacts.length+')';
    $$('input[name="pb-output-scope"]').forEach(input=>{input.checked=input.value===state.scope;input.disabled=input.value==='selected'&&!state.selected.size;});
    $('#pb-output-columns').innerHTML=Object.entries(fields).map(([key,label])=>'<label class="pb-choice"><input type="checkbox" data-output-column="'+key+'" '+(state.columns.has(key)?'checked':'')+(key==='name'?' disabled':'')+'>'+escape(label)+'</label>').join('');
    $('#pb-output-options').hidden=state.preview; $('#pb-output-preview').hidden=!state.preview;
    $('#pb-back-options').hidden=!state.preview; $('#pb-preview-output').hidden=state.preview; $('#pb-execute-output').hidden=!state.preview;
    $('#pb-preview-output').textContent=state.output==='print'?'Preview printout':'Preview workbook'; $('#pb-preview-output').disabled=!outputRows().length;
    $('#pb-execute-output').textContent=state.busy?'Preparing…':state.output==='print'?'Print / Save PDF':'Download .xlsx'; $('#pb-execute-output').disabled=state.busy||!outputRows().length;
    $('#pb-output-summary').textContent=countLabel(outputRows().length)+' · '+outputKeys().length+' columns · '+(state.output==='print'?'A4 landscape':'.xlsx');
    if(state.preview)renderOutputPreview();
  }
  function outputTable(rows,keys) {
    return '<div class="pb-table-wrap"><table class="pb-table"><thead><tr>'+keys.map(key=>'<th scope="col">'+escape(fields[key])+'</th>').join('')+'</tr></thead><tbody>'+rows.map(c=>'<tr>'+keys.map(key=>'<td dir="auto">'+escape(c[key]||'')+'</td>').join('')+'</tr>').join('')+'</tbody></table></div>';
  }
  function printMarkup(rows,keys,scope,group) {
    const groups=group==='none'||!fields[group]?['']:[...new Set(rows.map(c=>c[group]||'Not specified'))].sort(collator.compare);
    return '<div class="pb-paper"><div class="pb-paper-head"><div><div class="pb-kicker">UCM Provisioner</div><h3>'+escape(model?.title||'Company directory')+'</h3></div><div class="pb-output-note">Printed '+escape(new Date().toLocaleString())+'<br>'+escape(scope)+' · '+countLabel(rows.length)+'<br>Snapshot: '+escape(snapshotTime())+'</div></div>'+groups.map(value=>(value?'<h3 class="pb-print-group-title">'+escape(value)+'</h3>':'')+outputTable(value?rows.filter(c=>(c[group]||'Not specified')===value):rows,keys)).join('')+'<div class="pb-paper-foot"><span>Internal directory</span><span>Read-only published snapshot</span></div></div>';
  }
  function renderOutputPreview() {
    const rows=outputRows(), keys=outputKeys();
    if(state.output==='excel') {
      // Preview is bounded; the download contains every contact in the chosen scope.
      $('#pb-output-preview').innerHTML='<div class="pb-sheet"><div class="pb-sheet-name">phonebook-'+escape(model.book)+'.xlsx<span class="pb-secondary">'+outputScope()+' · '+countLabel(rows.length)+(rows.length>25?' · First 25 shown in preview':'')+'</span></div>'+outputTable(rows.slice(0,25),keys)+'<div class="pb-sheet-tab">Contacts</div></div>';
    } else {
      $('#pb-output-preview').innerHTML=printMarkup(rows.slice(0,50),keys,outputScope()+(rows.length>50?' · First 50 shown in preview':''),$('#pb-print-group').value);
    }
  }
  async function executeOutput() {
    if(!model||state.busy)return;
    const rows=outputRows(), keys=outputKeys(), kind=state.output;
    const payload={book:model.book,revision:model.revision,scope:state.scope,columns:keys,ids:rows.map(c=>c.id)};
    const printout=kind==='print'?printMarkup(rows,keys,outputScope(),$('#pb-print-group').value):'';
    state.busy=true; status(''); render();
    try {
      if(kind==='print') {
        const latest=await (await responseOrError(await fetch(endpoint('data',model.book),{credentials:'omit',cache:'no-store'}))).json();
        if(latest.revision!==payload.revision)throw new Error('The phonebook changed. Refresh it and select the output again.');
        printingMarkup=printout;
        $('#pb-printable').innerHTML=printout;
        window.print();
      } else {
        const response=await responseOrError(await fetch(endpoint('export'),{method:'POST',credentials:'omit',cache:'no-store',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)}));
        if(!response.headers.get('Content-Type')?.includes('spreadsheetml.sheet'))throw new Error('The server did not return an Excel workbook.');
        const url=URL.createObjectURL(await response.blob()), link=document.createElement('a');
        link.href=url; link.download='phonebook-'+payload.book+'-'+new Date().toISOString().slice(0,10)+'.xlsx';
        document.body.appendChild(link); link.click(); link.remove(); setTimeout(()=>URL.revokeObjectURL(url),1000);
        status('Excel export prepared: '+countLabel(rows.length)+'.');
      }
    } catch(error) { status(error.message,true); }
    finally { printingMarkup=null; state.busy=false; render(); }
  }
  $('#pb-search').addEventListener('input',event=>{state.query=event.target.value;state.page=1;render();});
  root.addEventListener('click',async event=>{
    const button=event.target.closest('[data-copy]'); if(!button)return;
    const contact=contacts.find(c=>c.id===button.dataset.copyContact), key=button.dataset.copy;
    if(!contact||!['name','extension'].includes(key)||!fields[key])return;
    try { await navigator.clipboard.writeText(contact[key]); status((key==='name'?'Name':'Number')+' copied.'); }
    catch (_) { status('Copy requires HTTPS and clipboard permission. Select the displayed text to copy it manually.',true); }
  });
  $('#pb-clear-search').addEventListener('click',()=>{state.query='';state.page=1;$('#pb-search').value='';render();$('#pb-search').focus();});
  $('#pb-reset').addEventListener('click',clearFilters);
  $('#pb-theme').addEventListener('click',()=>{state.appearance=state.appearance==='light'?'dark':'light';try {window.localStorage.setItem('ucmp-directory-theme',state.appearance);} catch (_) {} render();});
  $('#pb-book').addEventListener('change',event=>load(event.target.value));
  $('#pb-refresh').addEventListener('click',()=>load(model?.book||''));
  ['site','department'].forEach(key=>$('#pb-'+key).addEventListener('change',event=>{state[key]=event.target.value;state.page=1;render();}));
  $('#pb-sort').addEventListener('change',event=>{state.sort=event.target.value;state.page=1;render();});
  $('#pb-prev').addEventListener('click',()=>{state.page--;render();}); $('#pb-next').addEventListener('click',()=>{state.page++;render();});
  $('#pb-select-results').addEventListener('click',()=>{filtered().forEach(c=>state.selected.add(c.id));render();});
  $('#pb-clear-selection').addEventListener('click',()=>{state.selected.clear();render();});
  $('#pb-print').addEventListener('click',()=>openOutput('print')); $('#pb-export').addEventListener('click',()=>openOutput('excel'));
  $('#pb-close-output').addEventListener('click',()=>{const trigger=state.output;state.output=null;$('#pb-output').hidden=true;$(trigger==='print'?'#pb-print':'#pb-export').focus();});
  $('#pb-preview-output').addEventListener('click',()=>{state.preview=true;renderOutput();$('#pb-back-options').focus();});
  $('#pb-back-options').addEventListener('click',()=>{state.preview=false;renderOutput();$('#pb-preview-output').focus();});
  $('#pb-print-group').addEventListener('change',()=>{if(state.preview)renderOutputPreview();});
  $('#pb-execute-output').addEventListener('click',executeOutput);
  root.addEventListener('click',event=>{
    const view=event.target.closest('button[data-layout]'); if(view){state.layout=view.dataset.layout;state.page=1;render();return;}
    const filter=event.target.closest('button[data-filter]'); if(filter){state[filter.dataset.filter]=filter.dataset.value;state.page=1;render();return;}
    const contact=event.target.closest('[data-contact]'); if(contact){openContact(contact.dataset.contact);return;}
    if(event.target.closest('[data-close-detail]')){$('#pb-detail').hidden=true;$('#pb-search').focus();}
    if(event.target.closest('[data-clear-filters]'))clearFilters();
  });
  root.addEventListener('change',event=>{
    const input=event.target;
    if(input.matches('[data-select]')){const id=input.dataset.select;input.checked?state.selected.add(id):state.selected.delete(id);render();$('[data-select="'+id+'"]').focus();}
    if(input.id==='pb-select-page'){filtered().slice((state.page-1)*pageSize(),state.page*pageSize()).forEach(c=>input.checked?state.selected.add(c.id):state.selected.delete(c.id));render();$('#pb-select-page').focus();}
    if(input.name==='pb-output-scope'){state.scope=input.value;renderOutput();}
    if(input.matches('[data-output-column]')){const key=input.dataset.outputColumn;input.checked?state.columns.add(key):state.columns.delete(key);renderOutput();$('[data-output-column="'+key+'"]').focus();}
  });
  root.addEventListener('keydown',event=>{if(event.key==='Escape'){if(!$('#pb-output').hidden)$('#pb-close-output').click();else if(!$('#pb-detail').hidden){$('#pb-detail').hidden=true;$('#pb-search').focus();}}});
  window.addEventListener('beforeprint',()=>{
    if(printingMarkup!==null){$('#pb-printable').innerHTML=printingMarkup;return;}
    if(!model){$('#pb-printable').textContent='No published phonebook loaded.';return;}
    const output=state.output==='print'&&state.preview;
    $('#pb-printable').innerHTML=printMarkup(output?outputRows():filtered(),outputKeys(),output?outputScope():'Current results',output?$('#pb-print-group').value:'none');
  });
  render(); load(new URL(window.location.href).searchParams.get('book')||'');
})();
