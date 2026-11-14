'use strict';
const previousPumpSalesPage=salesPage,previousMeterWire=wireWorkspace,previousMeterReceivables=pumpReceivablesPage;
const meterUi={owner:'',draft:null,pending:[],edit:null,pendingEdit:'',saving:false,loading:false,needsReload:false,uncertain:false,key:'',fingerprint:'',rows:[],entryOnly:false,total:0,historyOffset:0,from:'',to:''};
const meterMeta=()=>S.meter_sales_meta||{today:todayLocal(),pumps:[],employees:[]};
const meterControl=day=>'DS-'+day.replaceAll('-','');
function meterMilli(value){const s=String(value??'').trim();if(!/^\d{1,10}(?:\.\d{1,3})?$/.test(s))return null;const [a,b='']=s.split('.');return Number(a)*1000+Number(b.padEnd(3,'0'));}
function meterStorageKey(){return 'efs.pump-day.v1.'+(S?.user?.id||meterUi.owner);}
function persistMeterDraft(){try{sessionStorage.setItem(meterStorageKey(),JSON.stringify({draft:meterUi.draft,entryOnly:meterUi.entryOnly,pending:meterUi.pending,key:meterUi.key,fingerprint:meterUi.fingerprint,uncertain:meterUi.uncertain}));}catch{}}
function resetMeterDraft(){try{sessionStorage.removeItem(meterStorageKey());}catch{}Object.assign(meterUi,{owner:'',draft:null,pending:[],edit:null,pendingEdit:'',key:'',fingerprint:'',needsReload:false,uncertain:false,rows:[],entryOnly:false,total:0,loading:false,from:'',to:'',historyOffset:0});}
function initMeterDraft(){
  if(meterUi.owner===S.user.id&&meterUi.draft)return;
  meterUi.owner=S.user.id;let saved=null;try{saved=JSON.parse(sessionStorage.getItem(meterStorageKey())||'null');}catch{}
  meterUi.draft={sale_date:meterMeta().today,shift_type:'',employee_id:'',pump_id:'',closing_meter:''};
  if(saved?.draft&&/^\d{4}-\d{2}-\d{2}$/.test(saved.draft.sale_date)){
    meterUi.entryOnly=saved.entryOnly===true;
    for(const k of Object.keys(meterUi.draft))meterUi.draft[k]=String(saved.draft[k]??'');
    if(Array.isArray(saved.pending)&&saved.pending.length<=200)meterUi.pending=saved.pending.filter(r=>r&&typeof r==='object'&&typeof r.id==='string'&&r.sale_date===saved.draft.sale_date);
    meterUi.uncertain=!!saved.uncertain&&meterUi.pending.length>0;meterUi.key=typeof saved.key==='string'?saved.key:'';meterUi.fingerprint=typeof saved.fingerprint==='string'?saved.fingerprint:'';
  }
}
function meterDraft(){initMeterDraft();return meterUi.draft;}
function hasMeterDraft(){return !!meterUi.pending.length||!!meterUi.edit||!!meterUi.pendingEdit||!!(meterUi.draft&&(meterUi.draft.pump_id||meterUi.draft.closing_meter));}
function meterBusy(){return meterUi.saving||meterUi.loading||meterUi.needsReload;}
function clearMeterLine(){meterUi.draft.pump_id='';meterUi.draft.closing_meter='';meterUi.edit=null;meterUi.pendingEdit='';persistMeterDraft();}
function meterSource(){
  const d=meterDraft(),old=meterUi.edit?.row||meterUi.pending.find(r=>r.id===meterUi.pendingEdit),pump=meterMeta().pumps.find(p=>p.id===d.pump_id),product=S.products.find(p=>p.id===pump?.product_id);
  if(old)return {pump,product_id:old.product_id,pump_code:old.pump_code,pump_name:old.pump_name||pump?.name||old.pump_code,product_name:old.product_name||old.item_description,opening:Number(old.starting_ml),price:Number(old.price_cents)};
  if(!pump||!product)return null;
  const prior=meterUi.pending.filter(r=>r.pump_id===d.pump_id).at(-1);
  return {pump,product_id:product.id,pump_code:pump.code,pump_name:pump.name,product_name:product.name,opening:prior?Number(prior.closing_ml):Number(pump.meter_opening_ml),price:Number(product.price_cents)};
}
function meterAvailable(){
  const pump=meterSource()?.pump;if(!pump)return null;
  let stock=Number(pump.product_stock_ml),tank=pump.tank_id?Number(pump.tank_stock_ml):Infinity;
  for(const r of meterUi.pending){if(r.product_id===pump.product_id)stock-=Number(r.liters_ml);if(pump.tank_id&&r.tank_id===pump.tank_id)tank-=Number(r.liters_ml);}
  return Math.max(0,Math.min(stock,tank,Number(pump.available_stock_ml)));
}
function meterPreviewHtml(){
  const d=meterDraft(),s=meterSource(),close=meterMilli(d.closing_meter),next=meterUi.edit?.next;
  if(!s||close===null||close<=s.opening)return '<span>Enter adds a line below. Save records the pending lines.</span>';
  const qty=close-s.opening;let html=`<strong>${liters(qty)} L</strong><span>× ${money(s.price)} = <strong>${money(integerLineTotal(qty,s.price),true)}</strong></span>`;
  if(next&&close!==Number(meterUi.edit.row.closing_ml))html+=`<span class="ms-boundary-note">Also adjusts ${h(next.sale_date)} / ${h(next.employee_name)}: next opening ${dec(close,3)} L, quantity ${liters(Number(next.closing_ml)-close)} L. Both saved sales update together.</span>`;
  return html;
}
function meterDayRows(){return [...meterUi.rows.filter(r=>!(meterUi.pending.length&&meterUi.key&&r.group_id===meterUi.key)),...meterUi.pending.map(r=>({...r,pending:true,can_edit:writer()}))];}
function meterDailyMarkup(){
  const rows=meterDayRows(),amount=rows.reduce((sum,r)=>sum+Number(r.amount_cents),0),qty=rows.reduce((sum,r)=>sum+Number(r.liters_ml),0);
  return table(['Pump code','Pump name / Fuel','Liters','Sales price','Amount','Action'],rows.map(r=>`<tr data-meter-row="${h(r.id)}" class="${r.pending?'ms-pending-row':''}"><td>${h(r.pump_code)}${r.pending?'<small class="ms-pending-label">Pending</small>':''}</td><td>${h(r.pump_name||r.pump_code)} · ${h(r.product_name||r.item_description)}<small class="ms-row-detail">${h(r.employee_name)} · ${h(r.shift_type)} · ${dec(r.starting_ml,3)} → ${dec(r.closing_ml,3)}</small></td><td>${liters(r.liters_ml)}</td><td>${money(r.price_cents)}</td><td>${money(r.amount_cents)}</td><td class="ms-row-actions">${r.can_edit?`<button class="btn small" type="button" ${meterBusy()?'disabled':''} ${r.pending?'data-ms-draft-edit':'data-ms-edit'}="${h(r.id)}">Edit</button>`:'—'}${r.pending?`<button class="btn ghost small" type="button" data-ms-remove="${h(r.id)}" ${meterBusy()?'disabled':''} aria-label="Remove pending ${h(r.pump_code)} sale">×</button>`:''}</td></tr>`),meterUi.entryOnly?'No pending sales. Add a pump sale above.':'No sales for this date. Add a pump sale above.')+
    `<div class="ms-total"><span>${rows.length} lines · ${liters(qty)} L${meterUi.pending.length?` · <b>${meterUi.pending.length} pending</b>`:''}</span><span>Total <strong>${money(amount,true)}</strong></span><button class="btn primary" type="button" data-ms-save ${!writer()||meterBusy()||!meterUi.pending.length?'disabled':''}>${icon('check')} ${meterUi.saving?'Saving…':'Save'}</button></div>`;
}
salesPage=function(credit){
  if(credit)return previousPumpSalesPage(credit);
  const d=meterDraft(),meta=meterMeta(),source=meterSource(),editing=meterUi.edit?.row||meterUi.pending.find(r=>r.id===meterUi.pendingEdit),available=meterAvailable();
  const pumps=[...meta.pumps];if(editing&&!pumps.some(p=>p.id===d.pump_id))pumps.push({id:d.pump_id,code:editing.pump_code,name:editing.pump_name||editing.pump_code,description:editing.item_description});
  const employees=meta.employees.filter(e=>e.shift_type===d.shift_type);if(editing&&!employees.some(e=>e.id===d.employee_id)&&d.shift_type===editing.shift_type)employees.push({id:editing.employee_id,code:'Saved',name:editing.employee_name});
  const pumpNames=options(pumps,p=>p.name+' · '+(S.products.find(x=>x.id===p.product_id)?.name||p.description||''),'Select pump name…',d.pump_id);
  return `<div class="pump-sales-page">${head('SALES','Pump Sales','Daily entry, totals and reports')}
  <section class="panel ms-form"><div class="panel-head"><h2>Daily sale</h2><small>${editing?meterUi.edit?'Editing saved sale':'Editing pending line':'Press Enter to add a line'}</small></div>
  ${meterUi.needsReload?'<div class="notice warn">Saved successfully. Reload readings to continue. <button class="btn small" data-ms-reload type="button">Reload readings</button></div>':''}
  <form id="meter-sales-form" novalidate><fieldset class="ms-fields" ${meterBusy()||meterUi.uncertain||!writer()?'disabled':''}>
  <div class="ms-header-grid">
    ${field('sale_date','Date','date',d.sale_date,`required min="2000-01-01" max="${h(meta.today)}" ${editing?'readonly':''}`)}
    ${field('control_no','Sales control no','text',meterControl(d.sale_date),'readonly')}
    ${select('shift_type','Shift',options([{id:'day',name:'Day'},{id:'night',name:'Night'}],'name','Select shift…',d.shift_type),'required')}
    ${select('employee_id','Employee',options(employees,e=>e.code+' · '+e.name,'Select employee…',d.employee_id),'required')}
    <label class="ms-stock">Available stock<output id="meter-available">${available===null?'—':liters(available)+' L'}</output></label>
  </div><div class="ms-reading-grid">
    ${select('pump_id','Select the pump',options(pumps,'code','Select pump code…',d.pump_id),editing?'disabled':'required')}
    ${select('pump_name','Pump name',pumpNames,editing?'disabled':'required')}
    ${field('starting_meter','Starting meter','text',source?dec(source.opening,3):'','readonly')}
    <label>Closing meter<div class="ms-closing-input"><input type="number" name="closing_meter" value="${h(d.closing_meter)}" step="0.001" min="0" inputmode="decimal" required><button class="btn primary" type="submit">${meterUi.edit?'Save changes':editing?'Update':'Add'}</button></div></label>
  </div><div class="ms-entry-footer"><div class="meter-preview" aria-live="polite">${meterPreviewHtml()}</div><button class="btn ghost small" data-ms-clear type="button">${editing?'Cancel edit':'Clear pump'}</button></div>
  </fieldset><div class="form-error" role="alert" tabindex="-1"></div></form></section>
  <section class="panel ms-day"><div class="panel-head"><div><h2>Day Sales</h2><small>${h(d.sale_date)} · ${h(meterControl(d.sale_date))}</small></div><div class="actions"><button class="btn small" type="button" data-ms-export="day-print">${icon('print')} A4 Print</button><button class="btn small" type="button" data-ms-export="day-pdf">PDF</button><button class="btn small" type="button" data-ms-export="day-share">Share PDF</button></div></div><div id="meter-today">Loading day sales…</div></section>
  <section class="panel ms-report"><div class="panel-head"><div><h2>Sales Report</h2><small>One saved total per day</small></div><div class="actions"><button class="btn small" type="button" data-ms-export="report-print">${icon('print')} A4 Print</button><button class="btn small" type="button" data-ms-export="report-pdf">PDF</button><button class="btn small" type="button" data-ms-export="report-share">Share PDF</button></div></div>
  <form id="meter-report-filter" class="ms-filters">${field('from','From','date',meterUi.from,'min="2000-01-01"')}${field('to','To','date',meterUi.to,'min="2000-01-01"')}<button class="btn small" type="submit">Filter</button><button class="btn ghost small" data-ms-reset-filter type="button">All dates</button></form><div id="meter-history">Loading sales report…</div></section></div>`;
};
function showMeterFormError(message){const box=$('#meter-sales-form .form-error');if(box){box.textContent=message;if(message)box.focus();}}
function meterLinePayload(){
  const d=meterDraft(),s=meterSource(),close=meterMilli(d.closing_meter);
  if(!d.sale_date||!d.shift_type||!d.employee_id||!d.pump_id)throw Error('Select date, shift, employee and pump.');
  if(d.sale_date>meterMeta().today||d.sale_date<'2000-01-01')throw Error('Select a valid date up to today.');
  if(d.sale_date!==meterMeta().today&&!manager())throw Error('A manager can enter or edit previous-day sales.');
  if(!s)throw Error('Link an active product to this pump first.');
  if(close===null||close<=s.opening)throw Error('Closing meter must exceed starting meter, with at most 3 decimal places.');
  return {sale_date:d.sale_date,shift_type:d.shift_type,employee_id:d.employee_id,pump_id:d.pump_id,product_id:s.product_id,price_cents:String(s.price),starting_meter:dec(s.opening,3),closing_meter:dec(close,3)};
}
function recalculateMeterPending(rows){
  const last=new Map();return rows.map(r=>{const start=last.has(r.pump_id)?last.get(r.pump_id):Number(r.starting_ml),close=Number(r.closing_ml),qty=close-start;last.set(r.pump_id,close);if(!Number.isSafeInteger(qty)||qty<=0||qty>1000000000)throw Error('Pending meter readings must stay in order, with a positive volume on each line.');return {...r,starting_ml:start,liters_ml:qty,amount_cents:integerLineTotal(qty,Number(r.price_cents)),starting_meter:dec(start,3),closing_meter:dec(close,3)};});
}
function validateMeterPendingStock(rows){
  const products=new Map(),tanks=new Map();for(const r of rows){products.set(r.product_id,(products.get(r.product_id)||0)+r.liters_ml);if(r.tank_id)tanks.set(r.tank_id,(tanks.get(r.tank_id)||0)+r.liters_ml);}
  for(const p of meterMeta().pumps){if((products.get(p.product_id)||0)>Number(p.product_stock_ml)||(p.tank_id&&(tanks.get(p.tank_id)||0)>Number(p.tank_stock_ml)))throw Error('Insufficient stock for the pending sales. Check the available liters.');}
}
async function addMeterLine(){
  if(meterBusy()||!writer())return;
  if(meterUi.uncertain){showMeterFormError('Retry Save to confirm the previous request before changing pending lines.');return;}
  try{
    const payload=meterLinePayload();if(meterUi.edit){await updateSavedMeter(payload);return;}
    if(!meterUi.pendingEdit&&meterUi.pending.length>=200)throw Error('Save these 200 pending lines before adding more.');
    const s=meterSource(),old=meterUi.pending.find(r=>r.id===meterUi.pendingEdit),emp=meterMeta().employees.find(e=>e.id===payload.employee_id&&e.shift_type===payload.shift_type);
    if(!emp)throw Error('Select an active employee in this shift.');
    const row={...payload,id:old?.id||requestId(),pump_code:s.pump_code,pump_name:s.pump_name,product_name:s.product_name,employee_name:emp.name,starting_ml:s.opening,closing_ml:meterMilli(payload.closing_meter),tank_id:s.pump?.tank_id||null};
    const computed=recalculateMeterPending(old?meterUi.pending.map(r=>r.id===old.id?row:r):[...meterUi.pending,row]);validateMeterPendingStock(computed);meterUi.pending=computed;
    clearMeterLine();render();$('#meter-sales-form [name=pump_id]')?.focus();
  }catch(e){showMeterFormError(e.message);}
}
function beginPendingEdit(id){
  if(meterBusy()||meterUi.uncertain)return;if(meterUi.draft.pump_id||meterUi.edit){showMeterFormError('Add or clear the current pump entry first.');return;}
  const row=meterUi.pending.find(r=>r.id===id);if(!row)return;meterUi.pendingEdit=id;Object.assign(meterUi.draft,{sale_date:row.sale_date,shift_type:row.shift_type,employee_id:row.employee_id,pump_id:row.pump_id,closing_meter:dec(row.closing_ml,3)});render();$('#meter-sales-form [name=closing_meter]')?.focus();
}
function removePendingLine(id){
  if(meterBusy()||meterUi.uncertain)return;try{const row=meterUi.pending.find(r=>r.id===id);if(!row)return;if(meterUi.pending.some((r,i)=>r.pump_id===row.pump_id&&i>meterUi.pending.indexOf(row)))throw Error('Remove the later pending readings on this pump first, then remove this line.');meterUi.pending=meterUi.pending.filter(r=>r.id!==id);if(meterUi.pendingEdit===id)clearMeterLine();persistMeterDraft();render();}catch(e){showMeterFormError(e.message);}
}
async function refreshMeterState(){try{const state=await api('state');if(!S)return;S=state;meterUi.needsReload=false;}catch{meterUi.needsReload=true;}}
async function saveMeterDay(){
  if(meterBusy()||!meterUi.pending.length||!writer())return;
  if(meterUi.draft.pump_id||meterUi.draft.closing_meter){showMeterFormError('Press Enter to add the current line, or Clear pump, before saving.');return;}
  const entries=meterUi.pending.map(r=>({sale_date:r.sale_date,shift_type:r.shift_type,employee_id:r.employee_id,pump_id:r.pump_id,product_id:r.product_id,price_cents:r.price_cents,starting_meter:dec(r.starting_ml,3),closing_meter:dec(r.closing_ml,3)}));
  const payload={sale_date:meterUi.draft.sale_date,entries},fingerprint=JSON.stringify(payload);
  if(meterUi.fingerprint!==fingerprint||!meterUi.key)meterUi.key=requestId();meterUi.fingerprint=fingerprint;payload.request_key=meterUi.key;persistMeterDraft();meterUi.saving=true;render();
  try{
    await api('pump_day_save',payload);
    meterUi.entryOnly=true;
    meterUi.rows=[];
    meterUi.total=0;
    meterUi.uncertain=false;meterUi.pending=[];meterUi.key='';meterUi.fingerprint='';clearMeterLine();await refreshMeterState();meterUi.saving=false;if(S){render();toast('Day sales saved. Sales Report updated.');}}
  catch(e){meterUi.saving=false;meterUi.uncertain=/fetch|network|unavailable/i.test(e.message);persistMeterDraft();if(S){render();showMeterFormError(e.message+(meterUi.uncertain?' Retry Save to confirm this request.':''));}}finally{meterUi.saving=false;}
}
async function beginMeterEdit(id){
  if(meterBusy())return;if(hasMeterDraft()){showMeterFormError('Save pending lines and clear the current pump entry before editing a saved sale.');return;}meterUi.loading=true;render();
  try{const edit=await api('pump_meter_edit',undefined,{id});if(!S)return;meterUi.edit=edit;const r=edit.row;Object.assign(meterUi.draft,{sale_date:r.sale_date,shift_type:r.shift_type,employee_id:r.employee_id,pump_id:r.pump_id,closing_meter:dec(r.closing_ml,3)});meterUi.key='';meterUi.fingerprint='';meterUi.loading=false;render();$('#meter-sales-form [name=closing_meter]')?.focus();$('#meter-sales-form')?.scrollIntoView({block:'nearest'});}
  catch(e){meterUi.loading=false;if(S){render();showMeterFormError(e.message);}}
}
async function updateSavedMeter(payload){
  Object.assign(payload,{id:meterUi.edit.row.id,version:meterUi.edit.version,next_id:meterUi.edit.next?.id||''});const fingerprint=JSON.stringify(payload);if(meterUi.fingerprint!==fingerprint||!meterUi.key)meterUi.key=requestId();meterUi.fingerprint=fingerprint;payload.request_key=meterUi.key;meterUi.saving=true;render();
  try{await api('pump_meter_update',payload);meterUi.key='';meterUi.fingerprint='';clearMeterLine();await refreshMeterState();meterUi.saving=false;if(S){render();toast('Saved sale and daily totals updated.');}}
  catch(e){meterUi.saving=false;if(S){render();showMeterFormError(e.message);}}finally{meterUi.saving=false;}
}
async function loadMeterDay(){
  const box=$('#meter-today');if(!box)return;const day=meterDraft().sale_date;
  if(meterUi.entryOnly){box.innerHTML=meterDailyMarkup();return;}
  try{const r=await api('pump_day',undefined,{sale_date:day});if(document.contains(box)&&!meterUi.entryOnly&&day===meterUi.draft.sale_date){meterUi.rows=r.rows;meterUi.total=r.totals.amount_cents;box.innerHTML=meterDailyMarkup();}}
  catch(e){if(document.contains(box)&&!meterUi.entryOnly)box.innerHTML=`<div class="form-error">${h(e.message)}</div><button class="btn small" data-ms-load-day type="button">Reload day sales</button>`;}
}
function meterHistoryMarkup(r){
  return table(['Date','Sales control no','Total',...(manager()?['Profit']:[]),'Action'],r.rows.map(x=>`<tr><td>${h(x.sale_date)}</td><td>${h(x.control_no)}<small class="ms-row-detail">${x.line_count} lines · ${liters(x.liters_ml)} L</small></td><td>${money(x.amount_cents)}</td>${manager()?`<td>${money(x.profit_cents)}</td>`:''}<td><button type="button" class="btn small" data-ms-overview="${h(x.sale_date)}">Overview</button></td></tr>`),'No saved sales in this date range.')+`<div class="ms-report-total"><span>${r.totals.count} days</span><span>Total <strong>${money(r.totals.amount_cents,true)}</strong></span>${manager()?`<span>Profit <strong>${money(r.totals.profit_cents,true)}</strong></span>`:''}</div><div class="ms-pages"><button class="btn small" type="button" data-ms-page="${Math.max(0,r.offset-50)}" ${r.offset===0?'disabled':''}>Previous</button><span>${r.totals.count?`${r.offset+1}–${Math.min(r.offset+50,r.totals.count)} of ${r.totals.count}`:'0 days'}</span><button class="btn small" type="button" data-ms-page="${r.offset+50}" ${r.offset+50>=r.totals.count?'disabled':''}>Next</button></div>`;
}
async function loadMeterHistory(){
  const box=$('#meter-history');if(!box)return;const params={from:meterUi.from,to:meterUi.to,offset:meterUi.historyOffset},fingerprint=JSON.stringify(params);
  try{const r=await api('pump_day_report',undefined,params);if(document.contains(box)&&fingerprint===JSON.stringify({from:meterUi.from,to:meterUi.to,offset:meterUi.historyOffset})){meterUi.historyOffset=r.offset;box.innerHTML=meterHistoryMarkup(r);}}
  catch(e){if(document.contains(box))box.innerHTML=`<div class="form-error">${h(e.message)}</div>`;}
}
function selectMeterDay(day){
  if(meterBusy())return;
  if(hasMeterDraft()){showMeterFormError('Save pending lines and clear the pump entry before changing the date.');const input=$('#meter-sales-form [name=sale_date]');if(input)input.value=meterUi.draft.sale_date;return;}
  if(!/^\d{4}-\d{2}-\d{2}$/.test(day)||day<'2000-01-01'||day>meterMeta().today){showMeterFormError('Select a valid date up to today.');return;}
  meterUi.draft.sale_date=day;meterUi.entryOnly=false;meterUi.rows=[];persistMeterDraft();render();
}
wireWorkspace=function(){
  previousMeterWire();const form=$('#meter-sales-form');
  if(form){
    form.onsubmit=e=>{e.preventDefault();addMeterLine();};
    form.addEventListener('keydown',e=>{if(e.key==='Enter'&&!e.isComposing&&e.target.matches('input,select')){e.preventDefault();e.stopPropagation();if(!e.repeat)addMeterLine();}},true);
    form.addEventListener('input',e=>{if(e.target.name==='closing_meter'){meterUi.draft.closing_meter=e.target.value;$('.meter-preview',form).innerHTML=meterPreviewHtml();if(!meterUi.edit&&!meterUi.pendingEdit)persistMeterDraft();}});
    form.addEventListener('change',e=>{const d=meterDraft(),name=e.target.name;if(name==='sale_date'){selectMeterDay(e.target.value);return;}if(name==='shift_type'){d.shift_type=e.target.value;d.employee_id='';render();$('#meter-sales-form [name=employee_id]')?.focus();}else if((name==='pump_id'||name==='pump_name')&&!meterUi.edit&&!meterUi.pendingEdit){d.pump_id=e.target.value;d.closing_meter='';render();$('#meter-sales-form [name=closing_meter]')?.focus();}else if(name==='employee_id')d.employee_id=e.target.value;if(!meterUi.edit&&!meterUi.pendingEdit)persistMeterDraft();});
    $('[data-ms-clear]',form).onclick=()=>{if(!meterBusy()){clearMeterLine();render();}};
  }
  const reload=$('[data-ms-reload]');if(reload)reload.onclick=async()=>{reload.disabled=true;await refreshMeterState();if(S)render();};
  const day=$('#meter-today');if(day){loadMeterDay();day.onclick=e=>{const b=e.target.closest('button');if(!b||b.disabled)return;if(b.hasAttribute('data-ms-save'))saveMeterDay();else if(b.dataset.msEdit)beginMeterEdit(b.dataset.msEdit);else if(b.dataset.msDraftEdit)beginPendingEdit(b.dataset.msDraftEdit);else if(b.dataset.msRemove)removePendingLine(b.dataset.msRemove);else if(b.hasAttribute('data-ms-load-day'))loadMeterDay();};}
  const filter=$('#meter-report-filter');if(filter){filter.onsubmit=e=>{e.preventDefault();meterUi.from=filter.elements.from.value;meterUi.to=filter.elements.to.value;meterUi.historyOffset=0;loadMeterHistory();};$('[data-ms-reset-filter]',filter).onclick=()=>{meterUi.from='';meterUi.to='';meterUi.historyOffset=0;render();};}
  const report=$('#meter-history');if(report){loadMeterHistory();report.onclick=e=>{const b=e.target.closest('button');if(!b||b.disabled)return;if(b.dataset.msOverview){selectMeterDay(b.dataset.msOverview);$('.ms-day')?.scrollIntoView({block:'start'});}else if(b.hasAttribute('data-ms-page')){meterUi.historyOffset=Number(b.dataset.msPage);loadMeterHistory();}};}
  $$('[data-ms-export]').forEach(button=>button.onclick=()=>exportMeterSales(button.dataset.msExport,button));
  wireMeterCollections();
};
pumpReceivablesPage=function(){return `<section class="panel"><div class="panel-head"><h2>Employee pump sales collections</h2></div><p>Meter sales awaiting collection. Cash is recorded only when you confirm a collection.</p><div id="meter-due">Loading balances…</div></section>`+previousMeterReceivables();};
let meterDueOffset=0;
async function loadMeterDue(){
  const box=$('#meter-due');if(!box)return;const offset=meterDueOffset;
  try{const r=await api('pump_meter_report',undefined,{scope:'due',offset});if(!document.contains(box)||offset!==meterDueOffset)return;meterDueOffset=r.offset;
    box.innerHTML=table(['Date','Employee','Shift','Pump','Sales','Collected','Outstanding',''],r.rows.map(x=>`<tr><td>${h(x.sale_date)}</td><td>${h(x.employee_name)}</td><td>${h(x.shift_type)}</td><td>${h(x.pump_code)}</td><td>${money(x.amount_cents)}</td><td>${money(x.collected_cents)}</td><td>${money(x.amount_cents-x.collected_cents)}</td><td><button class="btn small" type="button" data-ms-collect="${h(x.id)}" data-due="${x.amount_cents-x.collected_cents}">Collect cash</button></td></tr>`),'No outstanding meter sales.')+`<p>Total outstanding: <strong>${money(r.totals.due_cents,true)}</strong></p><div class="actions"><button class="btn small" data-ms-duepage="${Math.max(0,r.offset-50)}" ${r.offset===0?'disabled':''}>Previous</button><button class="btn small" data-ms-duepage="${r.offset+50}" ${r.offset+50>=r.totals.count?'disabled':''}>Next</button></div>`;
  }catch(e){if(document.contains(box))box.textContent=e.message;}
}
function wireMeterCollections(){
  const due=$('#meter-due');if(due){loadMeterDue();due.onclick=e=>{
    const page=e.target.closest('[data-ms-duepage]');if(page&&!page.disabled){meterDueOffset=Number(page.dataset.msDuepage);loadMeterDue();return;}
    const button=e.target.closest('[data-ms-collect]');if(!button)return;
    const id=button.dataset.msCollect,key=requestId();
    formDialog('Collect employee cash',num('amount','Cash received',dec(Number(button.dataset.due)),'0.01',`required min="0.01" max="${dec(Number(button.dataset.due))}"`)+field('reference','Receipt reference','text','','required maxlength="160"'),'pump_meter_collect',data=>({...data,id,request_key:key}),'Confirm only cash actually received. This reduces the employee balance; it does not create another sale.');
  };}
}
const previousUnsavedBills=hasUnsavedBills;
hasUnsavedBills=function(){return previousUnsavedBills()||hasMeterDraft();};
if(S&&['pump-sales','pump-receivables'].includes(route))render();
