'use strict';
const purchaseLegacyPage=workspacePurchasingPage, purchaseLegacyWire=wireWorkspaceRegistration;
const poIcons={
 cart:'M2 3h3l3 12h11l3-9H6M10 20h.01M18 20h.01',search:'M21 21l-5-5M18 10a8 8 0 1 1-16 0 8 8 0 0 1 16 0',
 edit:'M4 16l-1 5 5-1L20 8l-4-4zM14 6l4 4',trash:'M3 6h18M9 6V3h6v3M6 6l1 15h10l1-15M10 10v7m4-7v7',
 eye:'M2 12s3-7 10-7 10 7 10 7-3 7-10 7S2 12 2 12zM15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0',
 reset:'M3 10a9 9 0 1 1 1 8M3 4v6h6',plus:'M12 5v14M5 12h14',check:'M4 12l5 5 11-12'};
function poIcon(key){return poIcons[key]?`<svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="${poIcons[key]}"/></svg>`:icon(key);}
function poButton(label,key,color,attributes='',type='button'){
 return `<button type="${type}" class="po-btn po-${color}" ${attributes}>${poIcon(key)}${label}</button>`;
}
const poStar=' <b class="po-star" aria-hidden="true">*</b>';
const poNumber=value=>Number(value).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2});
const poDate=iso=>String(iso||'').split('-').reverse().join('/');
let purchaseDraft=null,poFilter={},poOffset=0,poOwner='',poCategory='';
function poDraft(){
 if(poOwner!==S.user.id){purchaseDraft=null;poFilter={};poOffset=0;poOwner=S.user.id;poCategory='';}
 return purchaseDraft||=( {date:S.purchase_defaults.date,vendor:'',own:'',search:'',items:[],editIndex:-1,po:'',version:'',key:requestId(),fingerprint:'',busy:false,uncertain:false} );
}
const poProducts=()=>S.products.filter(p=>Number(p.active)&&poCategory&&p.category_id===poCategory);
const poChoice=p=>p.sku+' · '+p.name;
const poStock=p=>'Current stock: '+liters(p.stock_milli);
const poSuggestion=p=>poChoice(p)+' · '+poStock(p);
function poProductOptions(){
 return poProducts().map(p=>`<option value="${h(poSuggestion(p))}"></option>`).join('');
}
function poMatch(search){
 const text=search.trim().toLowerCase();
 if(!text)return null;
 const hits=poProducts().filter(p=>[p.sku,p.name,poChoice(p),poSuggestion(p)].some(v=>v.toLowerCase()===text));
 return hits.length===1?hits[0]:null;
}
const poDayHeading=()=>poDraft().po||S.purchase_defaults.po_number;
function poLineValue(item){
 try{const qty=exactMilli(item.quantity),rate=Math.round(Number(item.cost)*100);return integerLineTotal(qty,rate);}catch{return 0;}
}
function poRows(d){
 return d.items.map((line,i)=>{
  const editing=i===d.editIndex;
  function cell(key,label,value){
   if(!editing||(key==='sale_price'&&line.kind==='fuel'))return value!==''&&value!=null?poNumber(value):'—';
   return `<input aria-label="${label}" name="${key}" type="number" min="${key==='quantity'?'0.001':key==='cost'?'0.01':'0'}" step="${key==='quantity'?'0.001':'0.01'}" value="${h(value)}" ${key==='sale_price'&&line.id&&!value?'':'required'}>`;
  }
  const editor=editing?`<tr class="po-extras" data-index="${i}"><td colspan="8"><div class="po-line-options">
    ${field('paid_amount','Paid now (Rs.)','number',line.paid_amount,'min="0" step="0.01"')}
    ${select('bank_account_id','Bank account',options(poBanks(line),b=>b.name,'Select bank…',line.bank_account_id))}
    <label>Notes<textarea name="notes" rows="1" maxlength="1000">${h(line.notes)}</textarea></label>
    ${poButton('Done','check','blue','data-po-line="done"')}</div></td></tr>`:'';
  return `<tr data-index="${i}" data-pb-row><td>${i+1}</td><td>${h(line.sku)}</td><td><strong>${h(line.name)}</strong></td>
    <td class="num">${cell('quantity','Unit (Ltr)',line.quantity)}</td><td class="num">${cell('cost','Cost (Rs.)',line.cost)}</td><td class="num">${cell('sale_price','Sale Price (Rs.)',line.sale_price)}</td>
    <td class="num" data-po-value>${money(poLineValue(line))}</td><td><div class="po-actions">${poButton('','edit','blue','data-po-line="edit" aria-label="Edit item"')}${poButton('','trash','red','data-po-line="delete" aria-label="Delete item"')}</div></td></tr>${editor}`;
 }).join('')||'<tr><td colspan="8" class="po-empty">Select a category and search for a product to add your first item.</td></tr>';
}
function poBanks(line){
 const banks=[...(S.bank_options||[])];
 if(line.bank_account_id&&!banks.some(b=>b.id===line.bank_account_id))banks.push({id:line.bank_account_id,name:(S.banks||[]).find(b=>b.id===line.bank_account_id)?.name||'Saved bank account'});
 return banks;
}
function poCardTitle(iconName,title){return `<div class="po-band">${poIcon(iconName)}<h2>${title}</h2></div>`;}
workspacePurchasingPage=function(){
 if(registration.purchase)return purchaseLegacyPage();
 const d=poDraft(),vendorOptions=selected=>options(S.suppliers||[],v=>v.name,'Select vendor…',selected);
 return `<div class="po-screen"><h1>Purchase Management</h1>
 <section class="po-card">${poCardTitle('cart','Purchase Details')}
 <form id="purchase-batch-form" class="po-body"><fieldset ${d.busy||d.uncertain?'disabled':''}>
 <div class="po-head-grid">
 ${field('purchase_date','Date'+poStar,'date',d.date,'required')}
 ${field('po_number','PO Number'+poStar,'text',poDayHeading(),'readonly required')}
 ${field('own','Own PO Number','text',d.own,'maxlength="80" placeholder="e.g. 20261103"')}
 ${select('supplier_id','Vendor Name'+poStar,vendorOptions(d.vendor),'required')}
 ${poButton('Add Vendor','plus','green','data-po-vendor')}</div>
 <div class="po-search-grid">
 ${select('category_id','Select Product Category'+poStar,options(S.categories||[],x=>x.name,'Select category…',poCategory))}
 <label>Search Product (Item Code / Item Name) <span class="po-stock-hint" id="po-stock" aria-live="polite"></span><span class="po-search-box">${poIcon('search')}<input name="product_search" list="po-products" aria-describedby="po-stock" autocomplete="off" placeholder="Search product..." value="${h(d.search)}"></span></label>
 <datalist id="po-products">${poProductOptions()}</datalist>
 ${poButton('Add Item','plus','blue','data-po-add')}</div>
 <div class="po-scroll" tabindex="0" aria-label="Purchase items"><table class="po-items"><thead><tr>
 ${['#','Item Code','Item Name','Unit (Ltr)','Cost (Rs.)','Sale Price (Rs.)','Value (Rs.)','Action'].map((col,i)=>`<th class="${i>=3&&i<=6?'num':''}">${col}</th>`).join('')}
 </tr></thead><tbody>${poRows(d)}</tbody><tfoot><tr><th colspan="3" class="num">Total</th><td class="num" data-po-units></td><td colspan="2"></td><td class="num" id="purchase-batch-total"></td><td></td></tr></tfoot></table></div>
 </fieldset><div class="po-buttons">
 ${d.po?poButton('Void PO','trash','red','data-po-void '+(d.busy||d.uncertain?'disabled':'')):''}
 ${poButton(d.busy?'Saving…':d.uncertain?'Retry Purchase':d.po?'Save changes':'Purchase','cart','green','data-po-save '+(d.busy?'disabled':''),'submit')}
 ${poButton('Clear','reset','gray','data-po-clear '+(d.busy||d.uncertain?'disabled':''))}</div>
 <div class="form-error" role="alert" tabindex="-1"></div></form></section>
 <section class="po-card">${poCardTitle('report','Purchase Report')}<div class="po-body">
 <form id="po-filter" class="po-filter">
 ${field('from','Date From','date',poFilter.from||'')}${field('to','Date To','date',poFilter.to||'')}
 ${select('vendor','Vendor Name',options(S.suppliers||[],v=>v.name,'All Vendors',poFilter.vendor||''))}
 ${field('po','PO Number','text',poFilter.po||'','placeholder="Enter PO Number..." maxlength="80"')}
 ${poButton('Search','search','blue','','submit')}${poButton('Print','print','purple','data-po-print-report')}
 </form><div id="po-report" aria-live="polite">Loading purchases…</div></div></section></div>`;
};
function poTotals(){
 const form=$('#purchase-batch-form');if(!form)return;
 const d=poDraft();let units=0,total=0;
 d.items.forEach((r,i)=>{units+=Math.round((Number(r.quantity)||0)*1000);const value=poLineValue(r);total+=value;const td=$(`tr[data-pb-row][data-index="${i}"] [data-po-value]`,form);if(td)td.textContent=money(value);});
 $('[data-po-units]',form).textContent=poNumber(units/1000);$('#purchase-batch-total',form).textContent=money(total);
}
function poValidate(line){
 const units=exactMilli(line.quantity);
 if(!units||!/^\d+(\.\d{1,2})?$/.test(line.cost)||Number(line.cost)<=0)throw Error('Enter a positive quantity and cost.');
 integerLineTotal(units,Math.round(Number(line.cost)*100));
 if(line.kind!=='fuel'&&!(line.id&&line.sale_price==='')&&(!/^\d+(\.\d{1,2})?$/.test(line.sale_price)||Number(line.sale_price)>100000))throw Error('Enter a valid sale price.');
 if(Number(line.paid_amount)>0&&!line.bank_account_id)throw Error('Select a bank account for the payment.');
 if(Number(line.paid_amount)*100>poLineValue(line)+0.001)throw Error('Payment exceeds the item value.');
}
function poFormError(message){const el=$('#purchase-batch-form .form-error');if(el){el.textContent=message;if(message)el.focus();}}
function poUpdateStockHint(form){
 const product=poMatch(form.elements.product_search.value);
 $('#po-stock',form).textContent=product?poStock(product):'';
}
function poResetSearch(d,form){
 d.search='';
 form.elements.product_search.value='';
 $('#po-stock',form).textContent='';
}
function poRenderLines(d,form){
 $('.po-items tbody',form).innerHTML=poRows(d);
 poTotals();
 poFormError('');
}
function poFinishLine(d){
 poValidate(d.items[d.editIndex]);
 d.editIndex=-1;
 const form=$('#purchase-batch-form');
 poResetSearch(d,form);
 poRenderLines(d,form);
 form.elements.product_search.focus();
}
function poAddItem(){
 const d=poDraft();
 if(!poCategory)throw Error('Select a product category first.');
 if(!d.search.trim())throw Error('Search for an item code or name first.');
 const p=poMatch(d.search);if(!p)throw Error('Choose a matching product from the search suggestions.');
 if(d.editIndex>=0)poValidate(d.items[d.editIndex]);
 d.items.push({product_id:p.id,sku:p.sku,name:p.name,kind:'product',quantity:'1.00',cost:dec(p.cost_cents),sale_price:dec(p.price_cents),paid_amount:'0.00',bank_account_id:'',notes:''});
 d.editIndex=d.items.length-1;
 const form=$('#purchase-batch-form');
 poResetSearch(d,form);
 poRenderLines(d,form);
 const input=form.elements.quantity;input.focus();input.select();
}
function poSavePayload(d){
 if(!d.date||!d.vendor||!d.items.length)throw Error('Select a date and vendor and add at least one item.');
 if(d.search.trim())throw Error('Click Add Item or clear the product search before saving.');
 d.items.forEach(poValidate);
 return {po:d.po,version:d.version,supplier_id:d.vendor,purchase_date:d.date,company_bill_no:d.own,
 items:d.items.map(({id,product_id,quantity,cost,sale_price,paid_amount,bank_account_id,notes})=>({id:id||'',product_id,quantity,cost,sale_price,paid_amount,bank_account_id,notes}))};
}
async function poSubmit(voidAll=false){
 const d=poDraft();if(d.busy)return;
 let payload;try{payload=voidAll?{po:d.po,version:d.version,void_all:true}:poSavePayload(d);}catch(e){poFormError(e.message);return;}
 d.retryVoid=voidAll;
 const fingerprint=JSON.stringify(payload);if(d.fingerprint&&d.fingerprint!==fingerprint)d.key=requestId();d.fingerprint=fingerprint;
 d.busy=true;render();let result;
 try{result=await api('purchase_order_save',{...payload,request_key:d.key});}
 catch(e){d.busy=false;d.uncertain=/fetch|network|unavailable/i.test(e.message);if(S){render();poFormError(e.message+(d.uncertain?' Retry Purchase to confirm the same order.':''));}return;}
 purchaseDraft=null;poFilter={};poOffset=0;
 try{await refresh();toast(result.po+(voidAll?' voided':' saved'));}
 catch(e){if(S){render();poFormError(result.po+' saved. Reload to refresh stock and the next PO number.');}}
}
function poReportTable(result,actions=true){
 const columns=['#','PO No','Date','Vendor Name','Amount (Rs.)',...(actions?['Action']:[])];
 const rows=result.rows.map((r,i)=>`<tr><td>${result.offset+i+1}</td><td><strong>${h(r.con_bill_no)}</strong>${Number(r.active_lines)?'':' <small>Voided</small>'}</td><td>${h(poDate(r.purchase_date))}</td><td>${h(r.vendor_name)}</td><td class="num"><strong>${money(r.total_cents)}</strong></td>
 ${actions?`<td><div class="po-actions po-pills" data-number="${h(r.con_bill_no)}">
 ${poButton('View','eye','green','data-po-action="view"')}${poButton('Edit','edit','blue','data-po-action="edit" '+(Number(r.active_lines)?'':'disabled'))}${poButton('Print','print','purple','data-po-action="print"')}</div></td>`:''}</tr>`).join('');
 return `<div class="po-scroll" tabindex="0" aria-label="Purchase report"><table><thead><tr>${columns.map(c=>`<th class="${c.startsWith('Amount')?'num':''}">${c}</th>`).join('')}</tr></thead><tbody>${rows||`<tr><td class="po-empty" colspan="${columns.length}">No purchases match this search.</td></tr>`}</tbody></table></div>`;
}
async function poLoadReport(){
 const el=$('#po-report');if(!el)return;
 const token=requestId();el.dataset.token=token;
 try{
  const result=await api('purchase_order_report',undefined,{...poFilter,offset:poOffset});
  if(!el.isConnected||el.dataset.token!==token)return;
  el.innerHTML=poReportTable(result)+((result.more||poOffset)?`<div class="po-pages">${poButton('Previous','report','gray','data-po-page="-50" '+(poOffset?'':'disabled'))}${poButton('Next','report','gray','data-po-page="50" '+(result.more?'':'disabled'))}</div>`:'');
 }catch(e){if(el.isConnected&&el.dataset.token===token)el.textContent=e.message;}
}
function poDetails(order){
 const first=order.rows.find(r=>!r.voided_at)||order.rows[0];
 let total=0;
 const rows=order.rows.map(r=>{if(!r.voided_at)total+=r.total_cents;
 return `<tr><td>${h(r.sku)}</td><td>${h(r.item_name)}${r.voided_at?' (voided)':''}</td><td class="num">${poNumber(r.quantity_milli/1000)}</td><td class="num">${money(r.cost_cents)}</td><td class="num">${r.sale_price_cents===null?'—':money(r.sale_price_cents)}</td><td class="num">${money(r.total_cents)}</td></tr>`;}).join('');
 return `<div class="po-document"><p><strong>${h(first.vendor_name)}</strong> · ${h(poDate(first.purchase_date))}</p><p>PO Number <strong>${h(order.po)}</strong>${first.invoice_no!==order.po?' · Own PO '+h(first.invoice_no):''}</p>
 <div class="po-scroll"><table><thead><tr><th>Item Code</th><th>Item Name</th><th>Unit (Ltr)</th><th>Cost (Rs.)</th><th>Sale Price (Rs.)</th><th>Value (Rs.)</th></tr></thead><tbody>${rows}</tbody><tfoot><tr><th colspan="5">Total (Rs.)</th><td class="num">${money(total)}</td></tr></tfoot></table></div></div>`;
}
async function poPrint(number){
 const win=window.open('','_blank');if(!win)throw Error('Allow pop-ups to print purchases.');
 win.document.title=number||'Purchase Report';win.document.body.textContent='Preparing print view…';
 try{
  const printFilter={...poFilter};
  const contents=number?poDetails(await api('purchase_order',undefined,{po:number})):poReportTable(await api('purchase_order_report',undefined,{...printFilter,print:1}),false);
  if(win.closed)return;
  const title=number?'Purchase order '+number:'Purchase Report';
  const filters=[printFilter.from&&'From '+poDate(printFilter.from),printFilter.to&&'To '+poDate(printFilter.to),printFilter.vendor&&S.suppliers.find(v=>v.id===printFilter.vendor)?.name,printFilter.po].filter(Boolean);
  win.document.open();win.document.write(`<!doctype html><html><head><meta charset="utf-8"><title>${h(title)}</title><style>@page{size:A4;margin:12mm}*{box-sizing:border-box}body{font:13px/1.45 Roboto,Arial,sans-serif;color:#0B1F4B}h1{font-size:22px}table{border-collapse:collapse;width:100%}td,th{padding:7px;border:1px solid #ccd4e1;text-align:left}th{background:#eef3fb}td.num,th.num{text-align:right}thead{display:table-header-group}tr{break-inside:avoid}button{padding:7px 12px}@media print{button{display:none}}</style></head><body><button type="button">Print / Save PDF</button><p>${h(S.settings.station_name)}</p><h1>${h(title)}</h1>${!number&&filters.length?'<p>'+h(filters.join(' · '))+'</p>':''}${contents}</body></html>`);win.document.close();
  win.document.querySelector('button').onclick=()=>win.print();win.focus();win.print();
 }catch(e){if(!win.closed)win.document.body.textContent=e.message;throw e;}
}
async function poOpen(number,mode){
 const current=poDraft();if(mode==='print')return poPrint(number);
 if(mode==='edit'&&(current.busy||current.uncertain))throw Error('Finish saving the current order first.');
 if(mode==='edit'&&(current.items.length||current.po||current.vendor||current.own)&&!window.confirm('Discard the current draft and open this PO?'))return;
 const order=await api('purchase_order',undefined,{po:number});
 if(!$('#purchase-batch-form')||current!==purchaseDraft||current.busy)return;
 if(mode==='view'){
  showDialog('Purchase order '+number,poDetails(order)+`<div class="po-buttons">${poButton('Print','print','purple','data-po-doc-print')}</div>`);
  $('[data-po-doc-print]').onclick=()=>poPrint(number).catch(e=>toast(e.message));return;
 }
 const rows=order.rows.filter(r=>!r.voided_at),first=rows[0];if(!first)throw Error('This PO has been voided.');
 purchaseDraft=null;const d=poDraft();
 Object.assign(d,{date:first.purchase_date,vendor:first.supplier_id,own:first.invoice_no===number?'':first.invoice_no,po:number,version:order.version,
 items:rows.map(r=>({id:r.id,kind:r.kind,product_id:r.product_id||'',sku:r.sku,name:r.item_name,quantity:dec(r.quantity_milli,3),cost:dec(r.cost_cents),sale_price:r.sale_price_cents===null?'':dec(r.sale_price_cents),
 paid_amount:dec(r.paid_cents),bank_account_id:r.bank_account_id||'',notes:r.notes||''}))});
 render();$('#purchase-batch-form').scrollIntoView({block:'start'});$('#purchase-batch-form [name=own]').focus({preventScroll:true});
}
wireWorkspaceRegistration=function(){
 purchaseLegacyWire();
 const form=$('#purchase-batch-form');if(!form)return;
 const d=poDraft();
 form.oninput=e=>{
  const input=e.target,row=input.closest('[data-index]');
  if(row&&input.name){d.items[Number(row.dataset.index)][input.name]=input.value;poTotals();return;}
  const keys={supplier_id:'vendor',own:'own',purchase_date:'date',product_search:'search'};
  if(keys[input.name])d[keys[input.name]]=input.value;
  if(input.name==='product_search')poUpdateStockHint(form);
 };
 form.onchange=e=>{
  const input=e.target;
  if(input.name==='category_id'){
   poCategory=input.value;
   poResetSearch(d,form);
   $('#po-products',form).innerHTML=poProductOptions();
  }
 };
 form.onclick=e=>{
  const b=e.target.closest('button');if(!b||d.busy||d.uncertain)return;
  try{
   if(b.hasAttribute('data-po-add'))poAddItem();
   else if(b.hasAttribute('data-po-void')){
    if(window.confirm('Void '+d.po+' and reverse its recorded stock and payments?'))poSubmit(true);
   }else if(b.hasAttribute('data-po-clear')){purchaseDraft=null;render();}
   else if(b.hasAttribute('data-po-vendor')){
    formDialog('Add vendor',field('code','Vendor code'+poStar,'text','','required maxlength="64"')+field('name','Vendor name'+poStar,'text','','required maxlength="160"')+field('phone','Phone','tel','','maxlength="80"'),
       'vendor',data=>data,'',v=>{d.vendor=v.id;render();},'Add Vendor');
   }else if(b.dataset.poLine){
    if(b.dataset.poLine==='done'){poFinishLine(d);return;}
    const i=Number(b.closest('[data-index]').dataset.index);
    if(b.dataset.poLine==='delete'){d.items.splice(i,1);d.editIndex=-1;}
    else{if(d.editIndex>=0)poValidate(d.items[d.editIndex]);d.editIndex=i;}
    poRenderLines(d,form);if(d.editIndex>=0)$('#purchase-batch-form [name=quantity]').focus();
   }
  }catch(err){poFormError(err.message);}
 };
 form.onkeydown=e=>{if(e.key!=='Enter'||e.isComposing||e.target.closest('textarea,button')||e.target.name==='category_id')return;e.preventDefault();if(e.repeat)return;
  try{if(e.target.name==='product_search')poAddItem();else if(e.target.closest('[data-index]'))poFinishLine(d);}catch(err){poFormError(err.message);}};
 form.onsubmit=e=>{e.preventDefault();poSubmit(d.uncertain&&d.retryVoid);};
 const filter=$('#po-filter');
 function applyFilter(){
  const f=Object.fromEntries(new FormData(filter));
  if(f.from&&f.to&&f.from>f.to){filter.elements.to.setCustomValidity('Date To must follow Date From.');filter.reportValidity();return false;}
  poFilter=f;poOffset=0;return true;
 }
 filter.oninput=()=>filter.elements.to.setCustomValidity('');
 filter.onsubmit=e=>{e.preventDefault();if(applyFilter())poLoadReport();};
 $('[data-po-print-report]',filter).onclick=()=>{if(applyFilter()){poLoadReport();poPrint('').catch(e=>toast(e.message));}};
 $('#po-report').onclick=e=>{const b=e.target.closest('button');if(!b||b.disabled)return;
  if(b.dataset.poAction)poOpen(b.closest('[data-number]').dataset.number,b.dataset.poAction).catch(e=>toast(e.message));
  else if(b.dataset.poPage){poOffset=Math.max(0,poOffset+Number(b.dataset.poPage));poLoadReport();}
 };
 poUpdateStockHint(form);poTotals();poLoadReport();
};
const priorPoUnsavedBills=hasUnsavedBills;
hasUnsavedBills=function(){return priorPoUnsavedBills()||!!purchaseDraft?.items.length;};
function resetPurchaseDraft(){purchaseDraft=null;poOwner='';poCategory='';poFilter={};poOffset=0;}
if(S&&route==='purchasing')render();
