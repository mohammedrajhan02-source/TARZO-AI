'use strict';
// Drafts stay in memory only. Money sent to PHP is decimal text; previews use cents.
const apState={vendor:'',date:'',type:'cash',chequeType:'own_cheque',drafts:{},keys:{},filters:null,statement:null,token:0,saving:false};
const apVendor=()=>S.suppliers.find(v=>v.id===apState.vendor);
function apCents(value){const s=String(value??'').trim();if(!/^\d{1,10}(\.\d{1,2})?$/.test(s))return null;const [a,b='']=s.split('.');const n=Number(a)*100+Number(b.padEnd(2,'0'));return n<=100000000000?n:null;}
function apDate(value){return new Date(value).toLocaleDateString('en-GB',{timeZone:S.settings.timezone||'Asia/Colombo',day:'2-digit',month:'short',year:'numeric'});}
function apCapture(){for(const id of ['ap-add-form','ap-payment-form']){const f=$('#'+id);if(f)apState.drafts[id]=Object.fromEntries([...f.elements].filter(e=>e.name&&!['radio','checkbox','submit','button'].includes(e.type)).map(e=>[e.name,e.value]));}}
function accountsPayablePage(){
  apState.date||=todayLocal();
  if(!S.suppliers.some(v=>v.id===apState.vendor))apState.vendor='';
  const filters=apState.filters||{id:apState.vendor,from:todayLocal().slice(0,8)+'01',to:todayLocal()};
  return `<div class="ap-page"><header class="ap-heading"><div><div class="eyebrow">VENDOR ACCOUNTS</div><h1>Accounts Payable</h1></div><span class="tag">${S.suppliers.filter(v=>v.balance_cents>0).length} vendors with a balance</span></header>
  <section class="ap-context panel" aria-label="Payment account"><div>${field('ap_date','Transaction date','date',apState.date,`id="ap-date" required max="${todayLocal()}"`)}</div><div>${select('ap_vendor','Vendor',options(S.suppliers,v=>`${v.code} · ${v.name}`,'Select vendor',apState.vendor),'id="ap-vendor" required')}</div><div class="ap-current"><span>Current balance <small>${h(S.settings.currency)}</small></span><strong id="ap-current-balance">${apVendor()?money(apVendor().balance_cents):'—'}</strong><small>Amount payable to this vendor</small></div></section>
  ${!S.suppliers.length?'<div class="notice">Add a vendor in <a href="#vendors">Work Space → Vendors</a> to begin.</div>':''}
  <div class="ap-columns"><section class="panel ap-card"><div class="ap-card-title"><span class="ap-icon">${icon('plus')}</span><div><h2>Add payable</h2><small>Record an additional amount owed</small></div></div>
  <form id="ap-add-form">${field('description','Description','text','','required maxlength="400" placeholder="e.g. Transport charge · October"')}${num('amount',`Amount (${h(S.settings.currency)})`,'','0.01','required min="0.01" max="1000000000" placeholder="0.00"')}<div class="ap-equation"><span>Current balance <b id="ap-add-current">—</b></span><span>New payable <b id="ap-add-amount">—</b></span><div><strong>Balance after entry</strong><strong id="ap-after-add">—</strong></div></div><p class="ap-hint">Fuel and product purchases already update this balance. Add only charges that have not been recorded.</p><div class="form-error" role="alert" tabindex="-1"></div><button class="btn primary ap-submit" type="submit">${icon('plus')} Add payable</button></form></section>
  <section class="panel ap-card"><div class="ap-card-title"><span class="ap-icon">${icon('wallet')}</span><div><h2>Vendor payment</h2><small>Pay against the current balance</small></div></div><form id="ap-payment-form">
  <fieldset class="ap-choice"><legend>Payment type</legend>${[['cash','Cash'],['cheque','Cheque'],['bank','Bank transfer']].map(([v,l])=>`<label><input type="radio" name="payment_type" value="${v}" ${apState.type===v?'checked':''}><span>${l}</span></label>`).join('')}</fieldset>
  <fieldset class="ap-choice ap-subchoice" id="ap-cheque-types"><legend>Cheque type</legend>${[['own_cheque','Own cheque'],['party_cheque','Party cheque']].map(([v,l])=>`<label><input type="radio" name="cheque_type" value="${v}" ${apState.chequeType===v?'checked':''}><span>${l}</span></label>`).join('')}</fieldset>
  <div data-ap-fields="bank"><div class="ap-fields">${select('bank_account_id','Own bank account',options((S.banks||[]).filter(b=>b.active),b=>`${b.code} · ${b.name}`),'required')}${field('bank_account_no','Account number','text','','readonly')}</div>${field('bank_name','Bank name','text','','readonly')}</div>
  <div data-ap-fields="own_cheque"><div class="ap-fields">${field('cheque_branch','Branch','text','','required maxlength="120"')}${field('cheque_no','Cheque number','text','','required maxlength="64"')}${field('cheque_date','Cheque date','date',todayLocal(),'required')}</div><p class="ap-hint">The bank book is reduced when issued, including post-dated cheques. Bank clearance is not automatic.</p></div>
  <div data-ap-fields="party_cheque"><div class="ap-party-select">${select('party_cheque_id','Select cheque in hand',options(S.party_cheques||[],c=>`${c.cheque_no} · ${c.holder} · ${money(c.amount_cents)}`),'required')}${btn('Register cheque','ap-register-cheque','','small','plus')}</div><div class="ap-fields">${field('party_no','Cheque number','text','','readonly')}${field('party_date','Cheque date','text','','readonly')}${field('party_holder','Cheque holder','text','','readonly')}${field('party_bank','Bank / branch','text','','readonly')}</div><p class="ap-hint">The full cheque value is transferred to this vendor once. Your own bank balance is unchanged.</p></div>
  <div class="ap-fields ap-payment-values">${num('amount',`Payment amount (${h(S.settings.currency)})`,'','0.01','required min="0.01" max="1000000000" placeholder="0.00"')}${field('reference','Payment reference','text','','required maxlength="160" placeholder="Voucher / receipt reference"')}</div><div class="ap-payment-result"><span>Balance after payment</span><strong id="ap-after-payment">—</strong></div><div class="form-error" role="alert" tabindex="-1"></div><button class="btn primary ap-submit" type="submit">${icon('check')} Record payment</button></form></section></div>
  <section class="panel ap-statement"><div class="ap-statement-heading"><div><h2>Vendor statement</h2><small>Opening balance, credit entries and payments</small></div><div class="actions"><button type="button" class="btn small" data-action="ap-print" disabled>${icon('print')} Print</button><button type="button" class="btn small" data-action="ap-csv" disabled>${icon('download')} CSV</button></div></div>
  <form id="ap-statement-form"><div class="ap-filters">${select('id','Vendor',options(S.suppliers,v=>`${v.code} · ${v.name}`,'Select vendor',filters.id),'required')}${field('from','From','date',filters.from,'required')}${field('to','To','date',filters.to,'required')}<button class="btn" type="submit">Filter</button></div><div class="form-error" role="alert" tabindex="-1"></div></form><div id="ap-statement-results" aria-live="polite"><div class="ap-empty">${icon('report')}<strong>Select a vendor to view their statement.</strong><small>All saved entries are included, with a running balance.</small></div></div></section></div>`;
}
function apSwitchFields(){
  const f=$('#ap-payment-form');if(!f)return;
  const method=apState.type==='cheque'?apState.chequeType:apState.type;
  const types=$('#ap-cheque-types');types.hidden=apState.type!=='cheque';for(const el of $$('input',types))el.disabled=types.hidden;
  for(const box of $$('[data-ap-fields]',f)){const k=box.dataset.apFields,visible=k==='bank'?['bank','own_cheque'].includes(method):k===method;box.hidden=!visible;for(const el of $$('input,select,button',box))el.disabled=!visible;}
  const bank=(S.banks||[]).find(b=>b.id===f.elements.bank_account_id.value);
  f.elements.bank_name.value=bank?.bank_name||'';f.elements.bank_account_no.value=bank?.account_ref||'';
  const cheque=(S.party_cheques||[]).find(c=>c.id===f.elements.party_cheque_id.value);
  f.elements.party_no.value=cheque?.cheque_no||'';f.elements.party_date.value=cheque?.cheque_date||'';f.elements.party_holder.value=cheque?.holder||'';f.elements.party_bank.value=cheque?`${cheque.bank_name} · ${cheque.branch}`:'';
  f.elements.amount.readOnly=method==='party_cheque';
  if(method==='party_cheque')f.elements.amount.value=cheque?dec(cheque.amount_cents):'';
  apPreview();
}
function apPreview(){
  const v=apVendor(),balance=v?.balance_cents,add=apCents($('#ap-add-form')?.elements.amount.value),pay=apCents($('#ap-payment-form')?.elements.amount.value);
  $('#ap-current-balance').textContent=v?money(balance):'—';$('#ap-add-current').textContent=v?money(balance):'—';$('#ap-add-amount').textContent=add===null?'—':money(add);
  $('#ap-after-add').textContent=v&&add!==null?money(balance+add):'—';const result=$('#ap-after-payment');result.textContent=v&&pay!==null?money(balance-pay):'—';result.classList.toggle('negative',v&&pay!==null&&pay>balance);
}
function apSetExport(enabled){for(const b of $$('[data-action="ap-print"],[data-action="ap-csv"]'))b.disabled=!enabled;}
async function apLoadStatement(){
  const f=$('#ap-statement-form');if(!f)return;
  const token=++apState.token;apState.statement=null;apSetExport(false);
  const filters=Object.fromEntries(new FormData(f));apState.filters=filters;
  if(!filters.id){$('#ap-statement-results').innerHTML='<div class="ap-empty">Select a vendor to view their statement.</div>';return;}
  $('#ap-statement-results').innerHTML='<div class="ap-empty">Loading statement…</div>';
  await submit(f,async()=>{
    try{const data=await api('ledger_statement',undefined,{kind:'vendor',...filters});if(token!==apState.token||!document.contains(f))return;apState.statement=data;$('#ap-statement-results').innerHTML=apStatementMarkup(data);apSetExport(true);}
    catch(e){if(token!==apState.token||!document.contains(f))return;$('#ap-statement-results').innerHTML='<div class="ap-empty">Statement could not be loaded. Try Filter again.</div>';throw e;}
  });
}
function apStatementMarkup(r){
  return `<div class="ap-ledger-caption"><strong>${h(r.account.code)} · ${h(r.account.name)}</strong><span>${h(r.from)} → ${h(r.to)} · ${h(S.settings.currency)}</span></div><div class="table-wrap"><table class="ap-ledger"><thead><tr><th>Date</th><th>Description / Reference</th><th>Credit / Added</th><th>Paid</th><th>Balance</th></tr></thead><tbody><tr class="ap-opening"><td>${h(r.from)}</td><td>Opening balance</td><td>—</td><td>—</td><td>${money(r.opening_cents)}</td></tr>${r.rows.map(x=>`<tr><td>${h(apDate(x.occurred_at))}</td><td><strong>${h(x.description)}</strong><small>${h(x.reference)}</small></td><td>${x.debit_cents?money(x.debit_cents):'—'}</td><td class="positive">${x.credit_cents?money(x.credit_cents):'—'}</td><td>${money(x.balance_cents)}</td></tr>`).join('')}${r.rows.length?'':'<tr><td colspan="5" class="ap-no-entries">No transactions in this period.</td></tr>'}</tbody><tfoot><tr><th colspan="2">Period totals / Closing balance</th><td>${money(r.debits_cents)}</td><td>${money(r.credits_cents)}</td><td>${money(r.closing_cents)}</td></tr></tfoot></table></div>`;
}
function wireAccountsPayable(){
  if(!$('#ap-add-form'))return;
  for(const id of ['ap-add-form','ap-payment-form']){const f=$('#'+id);for(const [name,value] of Object.entries(apState.drafts[id]||{}))if(f.elements[name])f.elements[name].value=value;apState.keys[id]||=requestId();f.addEventListener('input',()=>{apCapture();apPreview();});f.onsubmit=e=>{e.preventDefault();apSave(id);};}
  $('#ap-date').onchange=e=>{apState.date=e.target.value;};
  $('#ap-vendor').onchange=e=>{apState.vendor=e.target.value;apPreview();$('#ap-statement-form').elements.id.value=apState.vendor;apLoadStatement();};
  const payment=$('#ap-payment-form');payment.addEventListener('change',e=>{if(e.target.name==='payment_type'){apState.type=e.target.value;payment.elements.amount.value='';}if(e.target.name==='cheque_type'){apState.chequeType=e.target.value;payment.elements.amount.value='';}apSwitchFields();apCapture();});
  apSwitchFields();
  const sf=$('#ap-statement-form');sf.onsubmit=e=>{e.preventDefault();apLoadStatement();};sf.addEventListener('change',()=>{apState.token++;apState.statement=null;apSetExport(false);$('#ap-statement-results').innerHTML='<div class="ap-empty">Apply the filter to load this statement.</div>';});
  apLoadStatement();
}
async function apSave(id){
  if(apState.saving)return;
  const form=$('#'+id);apCapture();
  await submit(form,async()=>{
    if(!$('#ap-vendor').reportValidity()||!$('#ap-date').reportValidity())return;
    const supplier_id=apState.vendor,date=apState.date,adding=id==='ap-add-form';
    let data=Object.fromEntries(new FormData(form));
    if(!adding){const method=apState.type==='cheque'?apState.chequeType:apState.type;data={amount:data.amount,reference:data.reference,method,...(method==='cash'?{}:method==='party_cheque'?{party_cheque_id:data.party_cheque_id}:{bank_account_id:data.bank_account_id,...(method==='own_cheque'?{cheque_no:data.cheque_no,cheque_date:data.cheque_date,cheque_branch:data.cheque_branch}:{})})};}
    data={...data,supplier_id,date,request_key:apState.keys[id]};
    apState.saving=true;const controls=$$('#ap-vendor,#ap-date,.ap-page button[type="submit"]');controls.forEach(e=>e.disabled=true);
    try{
      await api(adding?'vendor_payable':'supplier_payment',data);
      apState.drafts[id]={};apState.keys[id]=requestId();form.reset();
      if(!adding){apState.type='cash';apState.chequeType='own_cheque';}
      // A committed entry must not be resubmitted if the subsequent state refresh fails.
      try{await refresh();toast(adding?'Payable added. Vendor balance updated.':'Payment recorded. Vendor balance updated.');}
      catch(e){apSwitchFields();throw Error('Entry saved. Refresh local data to see the updated balance. '+e.message);}
    }finally{apState.saving=false;controls.forEach(e=>e.disabled=false);}
  });
}
function apRegisterCheque(){
  apCapture();
  formDialog('Register party cheque in hand',field('holder','Cheque holder / received from','text','','required maxlength="160"')+field('source_reference','Original receipt / source reference','text','','required maxlength="160"')+field('bank_name','Cheque bank','text','','required maxlength="160"')+field('branch','Branch','text','','required maxlength="120"')+field('account_no','Drawer account number','text','','required maxlength="64"')+field('cheque_no','Cheque number','text','','required maxlength="64"')+field('cheque_date','Cheque date','date',todayLocal(),'required')+num('amount',`Full cheque value (${h(S.settings.currency)})`,'','0.01','required min="0.01" max="1000000000"')+'<label class="span2"><span><input type="checkbox" name="confirmed_in_hand" value="1" required> This cheque is in hand, transferable, and already accounted for at its source.</span></label>','party_cheque',d=>d,'This custody register does not collect customer debt or post money into a bank. Register an existing cheque only; do not record it as cash.',r=>{apState.type='cheque';apState.chequeType='party_cheque';apState.drafts['ap-payment-form']={...(apState.drafts['ap-payment-form']||{}),party_cheque_id:r.id};render();},'Register cheque');
}
function apExport(){
  const r=apState.statement;if(!r)throw Error('Load a statement first.');
  const rows=[['Ezy Filling System · Vendor statement'],['Station',S.settings.station_name],['Vendor',r.account.code,r.account.name],['Period',r.from,r.to],['Currency',S.settings.currency],['Opening balance',dec(r.opening_cents)],['Date UTC','Description','Reference','Credit / Added','Paid','Balance'],...r.rows.map(x=>[x.occurred_at,x.description,x.reference,dec(x.debit_cents),dec(x.credit_cents),dec(x.balance_cents)]),['Period totals','','',dec(r.debits_cents),dec(r.credits_cents),dec(r.closing_cents)]];
  const csv='\uFEFF'+rows.map(row=>row.map(v=>{v=String(v??'');if(/^[=+@\-\t\r]/.test(v))v="'"+v;return '"'+v.replaceAll('"','""')+'"';}).join(',')).join('\r\n');
  const url=URL.createObjectURL(new Blob([csv],{type:'text/csv;charset=utf-8'})),a=document.createElement('a');a.href=url;a.download=`EFS-Vendor-Statement-${r.from}-${r.to}.csv`;a.click();setTimeout(()=>URL.revokeObjectURL(url),1000);
}
function apPrint(){
  const r=apState.statement;if(!r)throw Error('Load a statement first.');
  const w=window.open('','_blank','width=1000,height=720');if(!w)throw Error('Allow the statement print window in your browser.');
  w.opener=null;w.document.write(`<!doctype html><html><head><meta charset="utf-8"><title>Vendor statement</title><style>body{font:13px Arial,sans-serif;color:#122c24;margin:28px}h1{font-size:22px;margin:0 0 6px}h2{font-size:16px;margin:0 0 24px}.ap-ledger-caption{display:flex;justify-content:space-between;margin-bottom:15px}table{border-collapse:collapse;width:100%}th,td{padding:10px 8px;border-bottom:1px solid #d4ddd9;text-align:left}th{background:#eef5f1}td:nth-child(n+3),th:nth-child(n+3){text-align:right;font-variant-numeric:tabular-nums}small{display:block;color:#50645b;margin-top:3px}tfoot{font-weight:bold}tr{break-inside:avoid}button{padding:8px 18px;margin-bottom:20px}@media print{button{display:none}body{margin:0}@page{size:A4 landscape;margin:12mm}thead{display:table-header-group}}</style></head><body><button id="print-button">Print statement</button><h1>${h(S.settings.station_name)}</h1><h2>Vendor statement</h2>${apStatementMarkup(r)}</body></html>`);w.document.close();w.document.getElementById('print-button').onclick=()=>w.print();w.focus();w.print();
}
async function accountsPayableAction(action){
  switch(action){case 'ap-register-cheque':apRegisterCheque();return true;case 'ap-print':apPrint();return true;case 'ap-csv':apExport();return true;}return false;
}
