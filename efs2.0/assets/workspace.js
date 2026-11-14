'use strict';
// Workspace 1.1: explicit registers, settlement receivables and linked bank books.
const menuTree = [
  {id:'dashboard',label:'Dashboard',icon:'grid'},
  {label:'Work Space',icon:'box',children:[
    ['categories','Category','box'],['products','Products','box'],['pumps','Pumps','pump'],['employees','Employees','user'],
    ['shifts','Add Shifts','clock'],['vendors','Vendor','fleet'],['purchasing','Purchasing','box'],
    ['payables','Accounts Payable','wallet'],['vendor-statements','Vendor Statement','report'],['tanks','Tanks & Stock','tank']
  ]},
  {id:'customers',label:'Add Customers',icon:'user'},
  {label:'Sales',icon:'pump',children:[['pump-sales','Pump Sales','pump'],['credit-sales','Credit Sales','fleet']]},
  {label:'Receivables',icon:'wallet',children:[['accounts-receivable','Accounts Receivable','wallet'],['pump-receivables','Shift Shortages','pump'],['credit-receivables','Credit Receivables','wallet']]},
  {label:'Statements',icon:'report',children:[['pump-statements','Pumps','pump'],['customer-statements','Customers','user']]},
  {id:'expenses',label:'Expenses Entries',icon:'wallet'},
  {id:'banks',label:'Bank Admin',icon:'bank'},
  {id:'reports',label:'Reports',icon:'report'},
  {id:'users',label:'Users Admin',icon:'shield'},
  {id:'company',label:'Company Profile',icon:'settings'}
];
const routeAliases={billing:'pump-sales',fleet:'customers',inventory:'purchasing',settings:'company'};
const pageCatalog=menuTree.flatMap(g=>g.children?g.children.map(([id,label,icon])=>({id,label,icon,group:g.label})):g);
const managementPages=['categories','products','pumps','employees','vendors','purchasing','payables','vendor-statements','pump-receivables','banks','expenses','users'];
const allowedPage=id=>!managementPages.includes(id)||manager();
const isBilling=(r=route)=>['pump-sales','credit-sales'].includes(r);
let billValues={},statementData=null,statementRequest=0,skipBillStash=false;
const billDrafts={};
paths.bank='M3 10l9-7 9 7H3zM5 10v9M10 10v9M14 10v9M19 10v9M3 21h18';
paths.chevron='M9 5l7 7-7 7';
const todayLocal=()=>new Date().toLocaleDateString('en-CA',{timeZone:S.settings.timezone});
const sum=(rows,key)=>rows.reduce((v,r)=>v+Number(r[key]||0),0);
const panel=(title,content,actions='')=>`<section class="panel"><div class="panel-head"><h2>${title}</h2><div class="actions">${actions}</div></div>${content}</section>`;
const bankSelect=(selected='')=>select('bank_account_id','Bank account',options(S.bank_options||[],b=>b.code+' · '+b.name,'Select bank…',selected));
const searchTable=(placeholder='Search this register…')=>`<label class="register-search">${icon('search')}<input type="search" data-register-search placeholder="${placeholder}" aria-label="${placeholder}"></label><p class="form-hint search-status" role="status"></p>`;

function stashBill(){
  const f=$('#bill-form');if(!f)return;
  for(const el of f.elements)if(el.name)billValues[el.name]=el.type==='checkbox'?el.checked:el.value;
}
function clearBillDraft(){billValues={};skipBillStash=true;delete billDrafts[route];}
function hasUnsavedBills(){return (typeof arDirty==='function'&&arDirty())||cart.length>0||Object.entries(billDrafts).some(([r,d])=>r!==route&&d.cart.length>0)}
function changeRoute(next){
  stashBill();
  if(isBilling())billDrafts[route]={cart:[...cart],paymentMode,billKey,selectedNozzle,values:{...billValues}};
  route=routeAliases[next]||next;
  if(isBilling()){
    const d=billDrafts[route]||{cart:[],paymentMode:route==='credit-sales'?'credit':'cash',billKey:requestId(),selectedNozzle:'',values:{}};
    cart=d.cart;paymentMode=d.paymentMode;billKey=d.billKey;selectedNozzle=d.selectedNozzle;billValues={...d.values};
  }
  statementData=null;statementRequest++;render();window.scrollTo({top:0});$('#main')?.focus({preventScroll:true});
}
function menuLink(id,label,ico){return `<a href="#${id}" class="nav-link ${id===route?'active':''}" ${id===route?'aria-current="page"':''}>${icon(ico)}<span>${h(label)}</span>${id===route?'<i class="nav-active-dot"></i>':''}</a>`}
function navigation(){return menuTree.map((g,index)=>{
  if(!g.children)return allowedPage(g.id)?menuLink(g.id,g.label,g.icon):'';
  const children=g.children.filter(([id])=>allowedPage(id));if(!children.length)return '';
  const active=children.some(([id])=>id===route),open=active||sessionStorage.getItem('nav-'+index)==='open'||(g.label==='Work Space'&&route==='dashboard'&&sessionStorage.getItem('nav-'+index)===null);
  return `<details class="nav-section" data-nav-group="${index}" ${open?'open':''}><summary>${icon(g.icon)}<span>${h(g.label)}</span>${icon('chevron')}</summary><div class="subnav">${children.map(x=>menuLink(...x)).join('')}</div></details>`;
}).join('')}

function renderWorkspace(){
  if(typeof stashReceivableDraft==='function')stashReceivableDraft();
  if(!S)return;if(!skipBillStash&&$('#app').dataset.screenRoute===route)stashBill();skipBillStash=false;route=routeAliases[route]||route;
  if(!pageCatalog.some(p=>p.id===route)||!allowedPage(route))route='dashboard';
  const page=pageCatalog.find(p=>p.id===route);
  if(route==='credit-sales')paymentMode='credit';
  const screens={dashboard,'accounts-receivable':accountsReceivablePage,categories:workspaceCategoriesPage,products:productsPage,pumps:pumpsPage,employees:employeesPage,shifts:shiftsPage,vendors:vendorsPage,purchasing:purchasingPage,payables:payablesPage,'vendor-statements':()=>statementPage('vendor'),customers:customersPage,'pump-sales':()=>salesPage(false),'credit-sales':()=>salesPage(true),'pump-receivables':pumpReceivablesPage,'credit-receivables':creditReceivablesPage,'pump-statements':()=>statementPage('pump'),'customer-statements':()=>statementPage('customer'),expenses:expensesPage,banks:banksPage,reports:reportsPage,users:usersPage,company:companyPage,tanks:tanksPage};
  $('#app').innerHTML=`<aside class="sidebar" id="station-sidebar">${brandLockup()}<div class="station-mini">${icon('pump')}<div><strong>${h(S.settings.station_name)}</strong><small>EFS · STATION WORKSPACE</small></div></div><nav class="nav" aria-label="Station navigation">${navigation()}<button data-action="logout" class="nav-logout">${icon('logout')}<span>Logout System</span></button></nav><div class="sidebar-foot"><div class="avatar">${h(S.user.name.slice(0,2).toUpperCase())}</div><div><strong>${h(S.user.name)}</strong><small>${h(S.user.role)} · ${h(S.settings.currency)}</small></div>${tag('Local','good')}</div></aside><div class="nav-scrim" data-action="menu"></div><div class="workspace"><header class="topbar"><button type="button" class="btn icon-only menu-toggle" data-action="menu" aria-label="Open navigation" aria-controls="station-sidebar" aria-expanded="false">${icon('menu')}</button><div class="mobile-identity"><img src="${h(S.settings.station_logo || 'assets/mark.svg?v=1.4.0')}" alt="" width="26" height="26">EFS</div><div class="breadcrumb">${h(page.group||'Station')}<span>/</span><strong>${h(page.label)}</strong></div><div class="actions"><span class="date">${new Date().toLocaleDateString('en-GB',{timeZone:S.settings.timezone,weekday:'short',day:'2-digit',month:'short',year:'numeric'})}</span>${tag('Offline ready','good')}<span class="header-user">${icon('user')}<span class="header-user-name" title="${h(S.user.name)}">${h(S.user.name)}</span></span><button class="btn ghost icon-only" data-action="theme" aria-label="Switch day or night mode">${icon(theme==='dark'?'sun':'moon')}</button><button class="btn ghost icon-only" data-action="refresh" aria-label="Refresh local data">${icon('sync')}</button></div></header><main id="main" tabindex="-1"><div class="statement-product">Ezy Filling System (EFS)</div>${S.settings.demo_mode==='1'?'<div class="notice warn demo-banner">DEMO STATION · Sample records for training only.</div>':''}${!S.license.can_sell?`<div class="notice bad">${h(S.license.message)} ${btn('License','navigate-company','','small')}</div>`:''}${screens[route]()}<footer class="footer-note"><span>Ezy Filling System (EFS) · ${EFS_BRAND.version}</span><span>${S.totals.pending_sync} queued events · ${S.sync.last_sync?'Last sync '+date(S.sync.last_sync):'Local database'}</span></footer></main></div>`;
  $('#app').dataset.screenRoute=route;document.body.classList.remove('nav-open');wirePage();wireWorkspace();
}

function productsPage(){return workspaceProductsPage()}
function salesPage(credit){
  const rows=S.sales.filter(s=>credit?s.credit_cents>0:!s.credit_cents).slice(0,20);
  return billing()+`<div class="section-gap">${panel(credit?'Recent credit bills':'Recent non-credit bills',table(['Receipt / Time','Customer','Operator','Total',...(credit?['Credit amount']:[]),''],rows.map(s=>`<tr><td>${h(s.receipt_no)}<br><small>${date(s.created_at)}</small></td><td>${h(s.customer_name||'Walk-in')}</td><td>${h(s.user_name)}</td><td>${money(s.total_cents)}</td>${credit?`<td>${money(s.credit_cents)}</td>`:''}<td>${btn('Receipt','receipt',s.id,'small')}</td></tr>`)))}</div><p class="form-hint">Up to 20 matches from the latest 200 station bills. Use Reports for a complete date range. Bills with any credit allocation appear under Credit Sales.</p>`;
}
function pumpsPage(){return workspacePumpsPage()}
function employeesPage(){return workspaceEmployeesPage()}
function vendorsPage(){return workspaceVendorsPage()}
function quickLink(id,title,sub,ico){return `<a href="#${id}" class="quick-link">${icon(ico)}<div><strong>${title}</strong><small>${sub}</small></div>${icon('arrow')}</a>`}
function purchasingPage(){return workspacePurchasingPage()}
function payablesPage(){return accountsPayablePage()}

function customersPage(){return fleetPage().replace('Fleet & credit','Customer register').replace('CUSTOMER ACCOUNTS','WORK SPACE / CUSTOMERS')}
function creditReceivablesPage(){
  const aging=new Map((S.credit_aging||[]).map(a=>[a.id,a]));
  return head('RECEIVABLES / CREDIT','Turn credit into collections.','Receive payments against the oldest outstanding customer invoices.',writer()?btn('Receive payment','customer-receipt','','primary','plus'):'')+
  `<div class="kpis compact-kpis">${kpi('Credit outstanding',money(S.totals.credit_cents),h(S.settings.currency)+' · Customer ledger','wallet')}${kpi('Customers owing',S.customers.filter(c=>c.balance_cents>0).length,'Accounts with open balances','user')}${kpi('Unpaid invoices',sum(S.credit_aging||[],'unpaid_count'),'Partial payments included','report')}</div>`+
  panel('Outstanding customer balances',searchTable()+table(['Customer','Outstanding','Unpaid invoices','Oldest invoice','Terms',''],S.customers.filter(c=>c.balance_cents>0).map(c=>{let a=aging.get(c.id),over=a?.oldest_at&&(Date.now()-Date.parse(a.oldest_at)>c.credit_days*86400000);return `<tr><td><strong>${h(c.name)}</strong><br><small>${h(c.code)}</small></td><td>${money(c.balance_cents)}</td><td>${a?.unpaid_count||0}</td><td>${date(a?.oldest_at)}</td><td>${tag(over?'Overdue':c.credit_days+' days',over?'bad':'')}</td><td><div class="actions">${writer()?btn('Collect','customer-receipt',c.id,'small'):''}${btn('Statement','open-customer-statement',c.id,'small')}</div></td></tr>`}),'No customer debt outstanding.'))+
  `<div class="section-gap">${panel('Recent customer collections',table(['Date','Customer','Method','Reference','Amount'],(S.customer_receipts||[]).map(r=>`<tr><td>${date(r.occurred_at)}</td><td>${h(r.customer_name)}</td><td>${h(r.payment_method||'Legacy entry')}</td><td>${h(r.reference)}</td><td>${money(r.credit_cents)}</td></tr>`)))}</div>`;
}
function pumpReceivablesPage(){
  const rows=S.pump_receivables||[];
  return head('RECEIVABLES / PUMPS','Close the collection gap.','Recover approved shortages from completed shifts.',btn('Receive shortage payment','pump-receipt','','primary','plus'))+
  `<div class="kpis compact-kpis">${kpi('Outstanding shortage',money(sum(rows,'outstanding_cents')),'Completed shifts only','wallet')}${kpi('Recovered',money(sum(rows,'recovered_cents')),'Recorded collections','check')}${kpi('Shifts with balance',rows.filter(s=>s.outstanding_cents>0).length,'Awaiting collection','clock')}</div>`+
  '<div class="notice section-bottom">Pump receivables are shift shortages awaiting recovery. A shift covering several pumps has one shared balance. This screen does not create payroll deductions or count a collection as another sale.</div>'+
  panel('Shift shortage ledger',table(['Shift / Employee','Pumps','Original shortage','Recovered','Outstanding',''],rows.map(s=>`<tr><td><strong>${h(s.label)}</strong><br><small>${h(s.user_name)} · ${date(s.created_at)}</small></td><td>${h(s.pump_codes)}</td><td>${money(s.shortage_cents)}</td><td class="positive">${money(s.recovered_cents)}</td><td class="${s.outstanding_cents?'negative':'positive'}">${money(s.outstanding_cents)}</td><td>${s.outstanding_cents?btn('Collect','pump-receipt',s.id,'small'):tag('Settled','good')}</td></tr>`),'No completed shifts with shortages.'))+
  `<div class="section-gap">${panel('Recovery receipts',table(['Date','Shift / Employee','Method','Reference','Amount'],(S.pump_receipts||[]).map(r=>`<tr><td>${date(r.created_at)}</td><td>${h(r.label)}<br><small>${h(r.user_name)}</small></td><td>${h(r.method)}</td><td>${h(r.reference)}</td><td>${money(r.amount_cents)}</td></tr>`)))}</div>`;
}
function expensesPage(){return head('OPERATING COSTS','Expenses Entries','Record every payment with its category and funding source.',btn('Add expense','expense','','primary','plus'))+
  panel('Expense register · latest 100',searchTable()+table(['Date / Category','Paid from','Amount','Note'],S.expenses.map(e=>`<tr><td><strong>${h(e.category)}</strong><br><small>${date(e.created_at)}</small></td><td>${h(e.payment_method==='bank'?(S.banks||[]).find(b=>b.id===e.bank_account_id)?.name||'Bank · legacy entry':'Shift cash drawer')}</td><td>${money(e.amount_cents)}</td><td>${h(e.note)}</td></tr>`)))+
  '<p class="form-hint">Cash expenses reduce the selected shift’s expected cash. Bank expenses reduce the selected bank balance.</p>'}
function banksPage(){
  const banks=S.banks||[];
  return head('FINANCIAL CONTROL','Bank Admin','Account balances, internal transfers and manual statement matching.',btn('Add bank account','bank-account','','primary','plus')+btn('Record movement','bank-move','','','sync'))+
  `<div class="bank-grid">${banks.map(b=>`<section class="bank-card"><div class="between">${icon('bank')}${tag(b.allow_overdraft?'Overdraft enabled':'No overdraft')}</div><div class="eyebrow">${h(b.bank_name)} · ${h(b.code)}</div><h2>${h(b.name)}</h2><div class="bank-balance">${money(b.balance_cents)} <small>${h(S.settings.currency)}</small></div><div class="between"><small>${h(b.account_ref||'Account reference not supplied')}</small>${btn('Statement','open-bank-statement',b.id,'small')}</div></section>`).join('')||'<div class="panel empty">Add a bank account with its current opening balance to begin.</div>'}</div>`+
  `<div class="section-gap">${panel('Bank book · latest 100 entries',searchTable()+table(['Date / Bank','Reference / Type','Money in','Money out','Statement match',''],(S.bank_entries||[]).map(t=>`<tr><td>${date(t.occurred_at)}<br><small>${h(t.account_name)}</small></td><td>${h(t.reference)}<br><small>${h(t.kind.replaceAll('_',' '))}</small></td><td class="positive">${t.amount_cents>0?money(t.amount_cents):'—'}</td><td>${t.amount_cents<0?money(-t.amount_cents):'—'}</td><td>${t.matched_at?tag('Matched','good'):tag('Unmatched','warn')}<br><small>${h(t.statement_ref)}</small></td><td>${t.matched_at?'':btn('Match','bank-match',t.id,'small')}</td></tr>`)))}</div>`+
  '<p class="form-hint">Balances are from the station’s recorded bank book. Matching confirms a checked entry; it does not import bank feeds or perform a statement-ending-balance reconciliation. Card and QR settlements are recorded when funds actually reach the bank.</p>';
}
function usersPage(){return head('ACCESS CONTROL','Users Admin','Control system roles and link logins to employee records.',S.user.role==='admin'?btn('Add user','master','user','primary','plus')+btn('Link employee login','employee-link','','','user'):'')+
  panel('System users',table(['User','Username','Role','Employee','Access',''],(S.users||[]).map(u=>`<tr><td><strong>${h(u.name)}</strong></td><td>${h(u.username)}</td><td>${tag(u.role)}</td><td>${h((S.employees||[]).find(e=>e.user_id===u.id)?.name||'—')}</td><td>${tag(u.active?'Enabled':'Disabled',u.active?'good':'')}</td><td><div class="actions">${S.user.role==='admin'?btn('Manage','user-access',u.id,'small')+(u.id!==S.user.id?btn(u.active?'Disable':'Enable','user-status',u.id,'small'):''):''}</div></td></tr>`)))+
  `<div class="role-guide section-gap">${[['Admin','Owner controls, user roles and all operations.'],['Manager','Purchases, banks, employees, reports and shift closure.'],['Cashier','Assigned shift sales and customer collections.'],['Viewer','Read-only station overview, reports and customer records.']].map(([r,d])=>`<div><strong>${r}</strong><p class="sub">${d}</p></div>`).join('')}</div>`;
}
function companyPage(){
  // Keep the proven backup, cloud and licensing controls; master registers now have their own screens.
  const fragment=document.createElement('div');fragment.innerHTML=settingsPage();
  for(const section of $$('section',fragment)){if(['Station master data','Team access','Recent audit trail'].includes($('h2',section)?.textContent))section.remove();}
  const title=$('h1',fragment);if(title)title.textContent='Company Profile';
  const form=$('#settings-form',fragment),grid=$('.form-grid',form);
  if(grid)grid.insertAdjacentHTML('beforeend',field('email','Email','email',S.settings.email||'')+field('registration_no','Business registration / tax reference','text',S.settings.registration_no||''));
  if (grid) {
  grid.insertAdjacentHTML('beforeend', `
    <div class="station-logo-editor">

      <img
        id="station-logo-preview"
        src="${h(S.settings.station_logo || 'assets/mark.svg?v=1.4.0')}"
        alt="Saved station logo"
        width="64"
        height="64"
      >

      <div class="station-logo-controls">
        <label for="station-logo-file">Station logo</label>

        <input
          id="station-logo-file"
          type="file"
          accept="image/png,image/jpeg,image/webp"
          ${manager() ? '' : 'disabled'}
        >

        <small>
          PNG, JPG or WebP · Maximum 300 KB · Maximum 2048 × 2048 px
        </small>

        ${manager() ? `
          <button
            id="station-logo-save"
            class="btn primary small"
            type="button"
          >
            Upload & save logo
          </button>
        ` : ''}

        <p id="station-logo-message" role="status"></p>
      </div>

    </div>
  `);
}
  if(!manager())for(const el of $$('input',form))el.readOnly=true;
  return fragment.innerHTML+(S.audit?`<div class="section-gap">${panel('Recent activity',table(['Time','User','Action','Record type'],S.audit.slice(0,20).map(a=>`<tr><td>${date(a.created_at)}</td><td>${h(a.user_name||'System')}</td><td>${h(a.action)}</td><td>${h(a.entity_type)}</td></tr>`)))}</div>`:'');
}

const statementSelection={};
function statementPage(kind){
  const names={vendor:['Vendor Statement','Vendor','suppliers'],customer:['Customer Statements','Customer','customers'],pump:['Pump Statements','Pump','pumps'],bank:['Bank Statement','Bank account','banks']};
  const [title,label,source]=names[kind],today=todayLocal(),pref=statementSelection[kind]||{};
  return head('STATEMENTS / '+label.toUpperCase(),title,kind==='pump'?'Meter periods are grouped by their closing date; active periods use their opening date.':'Opening balance, dated entries and closing balance from the full ledger.')+
  panel('Statement filters',`<form id="ledger-form" data-kind="${kind}"><div class="statement-filters">${select('id',label,options(S[source]||[],x=>x.code?x.code+' · '+x.name:x.name,'Select…',pref.id||''),'required')}${field('from','From','date',pref.from||today.slice(0,8)+'01','required')}${field('to','To','date',pref.to||today,'required')}<button class="btn primary" type="submit">Load statement</button></div><div class="form-error" role="alert"></div></form>`)+
  `<div id="ledger-results" class="section-gap"><div class="panel empty">Select ${label.toLowerCase()} and dates to load the statement.</div></div>`;
}
function ledgerResult(r){
  if(r.kind==='pump')return panel(h(r.account.name)+' · Meter statement',`<p class="sub">${h(r.from)} → ${h(r.to)} · Only fuel meter periods; shop sales and shared shift cash are excluded.</p>`+table(['Nozzle / Shift','Operator','Opening L','Closing L','Tests L','Net L','Rate','Meter value','Billed value'],r.rows.map(x=>`<tr><td>${h(x.nozzle_code)}<br><small>${h(x.shift_label)} · ${date(x.ended_at||x.started_at)}</small></td><td>${h(x.operator_name)}</td><td>${liters(x.opening_ml)}</td><td>${x.closing_ml===null?'Open':liters(x.closing_ml)}</td><td>${liters(x.test_ml)}</td><td>${x.net_ml===null?'—':liters(x.net_ml)}</td><td>${money(x.rate_cents)}</td><td>${x.meter_value_cents===null?'—':money(x.meter_value_cents)}</td><td>${money(x.billed_cents)}</td></tr>`)),statementActions());
  const bank=r.kind==='bank';return `<div class="statement-print-heading"><h2>${h(S.settings.station_name)} · ${h(r.account.name)}</h2><p>${h(r.from)} → ${h(r.to)} · ${h(S.settings.currency)}</p></div><div class="kpis">${kpi('Opening balance',money(r.opening_cents),'Before '+h(r.from),'wallet')}${kpi(bank?'Money in':'Invoices / debits',money(r.debits_cents),'Selected period','trend')}${kpi(bank?'Money out':'Payments / credits',money(r.credits_cents),'Selected period','wallet')}${kpi('Closing balance',money(r.closing_cents),'At end of '+h(r.to),'report')}</div>`+
  panel(h(r.account.name)+' · '+h(r.from)+' → '+h(r.to),table(['Date','Reference / Description',bank?'Money in':'Debit',bank?'Money out':'Credit','Balance'],r.rows.map(x=>`<tr><td>${date(x.occurred_at)}</td><td>${h(x.reference)}<br><small>${h(x.description.replaceAll('_',' '))}</small></td><td>${x.debit_cents?money(x.debit_cents):'—'}</td><td>${x.credit_cents?money(x.credit_cents):'—'}</td><td class="amount">${money(x.balance_cents)}</td></tr>`)),statementActions());
}
function statementActions(){return btn('CSV','statement-csv','','small','download')+btn('Print','print','','small','print')}
function exportStatement(){
  if(!statementData)throw Error('Load a statement first.');const r=statementData,pump=r.kind==='pump';
  let rows=pump?[['Nozzle','Shift','Operator','Opened UTC','Closed UTC','Opening L','Closing L','Tests L','Net L','Rate','Meter value','Billed value'],...r.rows.map(x=>[x.nozzle_code,x.shift_label,x.operator_name,x.started_at,x.ended_at||'',dec(x.opening_ml,3),x.closing_ml===null?'':dec(x.closing_ml,3),dec(x.test_ml,3),x.net_ml===null?'':dec(x.net_ml,3),dec(x.rate_cents),x.meter_value_cents===null?'':dec(x.meter_value_cents),dec(x.billed_cents)])]:[
    ['Account',r.account.name],['Period',r.from,r.to],['Currency',S.settings.currency],['Opening balance',dec(r.opening_cents)],['UTC time','Reference','Description','Debit / In','Credit / Out','Balance'],...r.rows.map(x=>[x.occurred_at,x.reference,x.description,dec(x.debit_cents),dec(x.credit_cents),dec(x.balance_cents)]),['Closing balance',dec(r.closing_cents)]];
  const csv='\uFEFF'+rows.map(row=>row.map(v=>{v=String(v??'');if(/^[=+@\-\t\r]/.test(v))v="'"+v;return '"'+v.replaceAll('"','""')+'"'}).join(',')).join('\r\n');
  const url=URL.createObjectURL(new Blob([csv],{type:'text/csv;charset=utf-8'}));const a=document.createElement('a');a.href=url;a.download=`EFS-${r.kind}-${r.from}-${r.to}.csv`;a.click();setTimeout(()=>URL.revokeObjectURL(url),1000);
}

function wireWorkspace(){
  if(typeof wireAccountsReceivable==='function')wireAccountsReceivable();
  wireStationLogo();
  wireRecovery();
  for(const group of $$('[data-nav-group]'))group.addEventListener('toggle',()=>sessionStorage.setItem('nav-'+group.dataset.navGroup,group.open?'open':'closed'));
  for(const input of $$('[data-register-search]'))input.addEventListener('input',()=>{const section=input.closest('section'),q=input.value.toLowerCase();let count=0;for(const row of $$('tbody tr',section)){row.hidden=!row.textContent.toLowerCase().includes(q);if(!row.hidden)count++;}$('.search-status',section).textContent=q?`${count} matching records`:'';});
  if(isBilling()){
    const title=$('.page-head h1');if(title)title.textContent=route==='credit-sales'?'Credit Sales':'Pump Sales';
    if(route==='credit-sales')$$('[data-action=payment]').forEach(b=>{if(b.dataset.id!=='credit')b.hidden=true;});
    else $$('[data-action=payment]').forEach(b=>{if(b.dataset.id==='credit')b.hidden=true;});
    const form=$('#bill-form');if(form){
      for(const el of form.elements)if(el.name&&Object.hasOwn(billValues,el.name)){
        if((el.name==='cash_tendered'||el.name.startsWith('pay_'))&&!billValues._manualFields?.[el.name])continue;
        if(el.type==='checkbox')el.checked=billValues[el.name];else el.value=billValues[el.name];
      }
      form.addEventListener('input',e=>{if(e.target.name==='cash_tendered'||e.target.name.startsWith('pay_')){billValues._manualFields??={};billValues._manualFields[e.target.name]=true;}stashBill();});form.addEventListener('change',stashBill);
      const customer=$('[name=customer_id]',form),vehicle=$('[name=vehicle_id]',form);
      if(route==='credit-sales')customer.required=true;
      const filterVehicles=()=>{const prior=vehicle.value;vehicle.innerHTML=options(S.vehicles.filter(v=>v.customer_id===customer.value),'registration','No vehicle',prior);stashBill();};
      customer.addEventListener('change',filterVehicles);filterVehicles();
    }
  }
  wireWorkspaceRegistration();
  wireAccountsPayable();
  const form=$('#ledger-form');if(form){form.onsubmit=async e=>{e.preventDefault();const request=++statementRequest;const params={kind:form.dataset.kind,...Object.fromEntries(new FormData(form))};statementSelection[params.kind]=params;
    await submit(form,async()=>{const data=await api('ledger_statement',undefined,params);if(request!==statementRequest||!document.contains(form))return;statementData=data;$('#ledger-results').innerHTML=ledgerResult(data);});
  };if(form.elements.id.value)form.requestSubmit();}
}

function employeeDialog(id=''){workspaceEmployeeEdit(id)}
function paymentCollectionDialog(type,id=''){
  const pump=type==='pump',rows=pump?(S.pump_receivables||[]).filter(s=>s.outstanding_cents>0):S.customers.filter(c=>c.balance_cents>0);
  formDialog(pump?'Receive pump shortage payment':'Receive customer payment',select(pump?'settlement_id':'customer_id',pump?'Completed shift':'Customer',options(rows,r=>pump?r.label+' · '+r.user_name+' · due '+money(r.outstanding_cents):r.name+' · due '+money(r.balance_cents),'Select…',id),'required')+num('amount','Amount','','0.01','required')+select('method','Receive into','<option value="cash">Shift cash drawer</option><option value="bank">Bank account</option>')+select('shift_id','Shift · required for cash',options(availableShifts(),'label'))+bankSelect()+field('reference','Receipt / payment reference','text','','required'),pump?'pump_receipt':'customer_receipt',d=>d,pump?'Apply a received payment to the approved shortage. This reduces the receivable; it does not create new sales.':'Allocates to the oldest unpaid invoices. A bank collection increases that account; cash increases the selected drawer.');
}
function vendorPaymentDialog(id=''){apState.vendor=id;apState.filters=null;if(route==='payables')render();else location.hash='payables';}

function productPurchaseDialog(){formDialog('Record product purchase',select('supplier_id','Vendor',options(S.suppliers),'required')+select('product_id','Product',options(S.products),'required')+field('invoice_no','Vendor invoice / line reference','text','','required')+num('quantity','Units received','','1','required')+num('cost','Purchase cost per unit','','0.01','required')+num('paid_amount','Paid now by bank','0.00')+bankSelect()+note('notes','Notes'),'product_purchase',d=>d,'Creates a vendor invoice and updates product stock and weighted cost. Select a bank if any amount is paid now.');}
function bankDialog(){formDialog('Add bank account',field('code','Account code','text','','required')+field('name','Display name','text','','required')+field('bank_name','Bank name','text','','required')+field('account_ref','Account reference / last digits')+field('opening_balance','Opening book balance','number','0.00','step="0.01" required')+'<label class="check"><input type="checkbox" name="allow_overdraft">Permit a negative recorded balance</label>','bank_account',d=>({...d,allow_overdraft:!!d.allow_overdraft}),'Opening balance is recorded once at account creation. Use the actual book balance at this point; historical entries are not automatically imported.');}
function bankMoveDialog(){
  formDialog('Record bank movement',bankSelect()+select('kind','Movement','<option value="shift_deposit">Cash deposit from an open shift</option><option value="shift_withdrawal">Bank withdrawal into an open shift</option><option value="transfer">Transfer to another bank account</option><option value="other_credit">Other money in · not a customer receipt</option><option value="other_debit">Other money out · not a vendor payment / expense</option>')+num('amount','Amount','','0.01','required')+select('to_account_id','Destination · transfers only',options(S.bank_options||[]))+select('shift_id','Shift · cash deposit / withdrawal',options(availableShifts(),'label'))+field('reference','Bank reference','text','','required')+note('note','Description / reason','required'),'bank_move',d=>d,'For customer receipts, vendor payments and expenses, use their dedicated screens so both ledgers update. Other movements affect only the bank book; they do not create revenue or expenses.');
}
function expenseDialog(){formDialog('Add expense entry',field('category','Expense category','text','','required')+num('amount','Amount','','0.01','required')+select('payment_method','Paid from','<option value="cash">Shift cash drawer</option><option value="bank">Bank account</option>')+select('shift_id','Shift · cash only',options(availableShifts(),'label'))+bankSelect()+field('note','Description / reference','text','','required'),'expense');}
function wireFinancialForm(action){
  const form=$('#modal-form');if(!form)return;
  const show=(name,visible)=>{const el=form.elements.namedItem(name);if(!el)return;el.closest('label').hidden=!visible;el.required=visible;};
  const update=()=>{
    if(['customer_receipt','pump_receipt','expense'].includes(action)){
      const bank=(form.elements.method?.value||form.elements.payment_method?.value)==='bank';show('bank_account_id',bank);show('shift_id',!bank);
    }else if(['delivery','product_purchase'].includes(action))show('bank_account_id',Number(form.elements.paid_amount.value)>0);
    else if(action==='supplier_payment')show('bank_account_id',true);
    else if(action==='bank_move'){
      const k=form.elements.kind.value;show('bank_account_id',true);show('to_account_id',k==='transfer');show('shift_id',k==='shift_deposit'||k==='shift_withdrawal');
    }
  };
  update();form.addEventListener('input',update);form.addEventListener('change',update);
}
function openStatement(kind,id){
  if(kind==='bank'){
    statementSelection.bank={id};showDialog('Bank statement',statementPage('bank'));wireLedgerModal();return;
  }
  statementSelection[kind]={id};location.hash={vendor:'vendor-statements',customer:'customer-statements',pump:'pump-statements'}[kind];
}
function wireLedgerModal(){
  const f=$('#ledger-form',$('#dialog'));f.onsubmit=async e=>{e.preventDefault();const token=++statementRequest;await submit(f,async()=>{const r=await api('ledger_statement',undefined,{kind:f.dataset.kind,...Object.fromEntries(new FormData(f))});if(token!==statementRequest||!$('#dialog').open)return;statementData=r;$('#ledger-results',$('#dialog')).innerHTML=ledgerResult(r);});};f.requestSubmit();
}
async function workspaceAction(action,id){
  if(await accountsPayableAction(action,id))return true;
  if(await workspaceRegistrationAction(action,id))return true;
  switch(action){
    case 'employee':employeeDialog(id);return true;
    case 'employee-status':{let e=S.employees.find(x=>x.id===id);await api('employee_status',{request_key:requestId(),id,active:!e.active});await refresh();return true;}
    case 'employee-link':formDialog('Link employee and login',select('employee_id','Employee',options(S.employees||[]),'required')+select('user_id','Login account',options((S.users||[]).filter(u=>u.role!=='viewer'),u=>u.username+' · '+u.name,'Unlink login')),'employee_link',d=>d,'Each login can link to one staff record. Unlink its existing staff record first if you need to move it. Open shifts must be closed before changing links.');return true;
    case 'user-access':{const u=S.users.find(u=>u.id===id);formDialog('Manage access · '+u.name,select('role','Role',options(['admin','manager','cashier','viewer'].map(r=>({id:r,name:r})),'name',null,u.role))+field('password','Reset password · leave blank to keep','password','','minlength="10" autocomplete="new-password"'),'user_access',d=>({...d,id}),'Role changes apply to the next request. Password resets change future sign-ins; they do not immediately end existing sessions.');return true;}
    case 'customer-receipt':paymentCollectionDialog('customer',id);return true;
    case 'pump-receipt':paymentCollectionDialog('pump',id);return true;
    case 'supplier-payment':vendorPaymentDialog(id);return true;
    case 'product-purchase':case 'product-restock':productPurchaseDialog();return true;
    case 'bank-account':bankDialog();return true;
    case 'bank-move':bankMoveDialog();return true;
    case 'bank-match':formDialog('Match bank entry',field('statement_ref','Bank statement / line reference','text','','required'),'bank_match',d=>({...d,id}),'Confirm that the date, amount and reference agree with your bank statement before matching.');return true;
    case 'expense':expenseDialog();return true;
    case 'open-vendor-statement':openStatement('vendor',id);return true;
    case 'open-customer-statement':case 'statement':openStatement('customer',id);return true;
    case 'open-bank-statement':openStatement('bank',id);return true;
    case 'statement-csv':exportStatement();return true;
  }return false;
}
function wireStationLogo() {
  const input = document.getElementById('station-logo-file');
  const button = document.getElementById('station-logo-save');
  const message = document.getElementById('station-logo-message');
  const form = document.getElementById('settings-form');

  if (!input || !button || !message || !form) return;

  const detailsButton = form.querySelector('button[type="submit"]');

  let saving = false;
  let selection = 0;

  const show = (text, state = '') => {
    message.textContent = text;
    message.dataset.state = state;
  };

  const preview = source => {
    const image = document.getElementById('station-logo-preview');
    if (image) image.src = source;
  };

  const saved = () =>
    S.settings.station_logo || 'assets/mark.svg?v=1.4.0';

  async function readLogo(file) {
    if (!['image/png', 'image/jpeg', 'image/webp'].includes(file.type)) {
      throw Error('Use a PNG, JPG or WebP image.');
    }

    if (!file.size || file.size > 300 * 1024) {
      throw Error('Choose an image between 1 byte and 300 KB.');
    }

    const data = await new Promise((resolve, reject) => {
      const reader = new FileReader();

      reader.onload = () => resolve(String(reader.result));
      reader.onerror = () =>
        reject(Error('Cannot read the selected image.'));

      reader.readAsDataURL(file);
    });

    await new Promise((resolve, reject) => {
      const image = new Image();

      image.onload = () => {
        if (
          !image.naturalWidth ||
          !image.naturalHeight ||
          image.naturalWidth > 2048 ||
          image.naturalHeight > 2048
        ) {
          reject(Error('Maximum logo dimensions: 2048 × 2048 pixels.'));
        } else {
          resolve();
        }
      };

      image.onerror = () =>
        reject(Error('This image cannot be displayed.'));

      image.src = data;
    });

    return data;
  }

  input.onchange = async () => {
    const version = ++selection;
    const file = input.files[0];

    if (!file) {
      preview(saved());
      show('');
      return;
    }

    show('Reading image…');

    try {
      const data = await readLogo(file);

      if (version !== selection || saving) return;

      preview(data);
      show('Preview ready. Click either Save button to save.', 'info');

    } catch (error) {
      if (version !== selection || saving) return;

      preview(saved());
      show(error.message, 'error');
    }
  };

  async function save(includeDetails) {
    if (saving) return;

    const file = input.files[0];

    if (!includeDetails && !file) {
      show('Choose a logo image first.', 'error');
      return;
    }

    const details = includeDetails
      ? Object.fromEntries(new FormData(form))
      : null;

    saving = true;
    ++selection;

    input.disabled = true;
    button.disabled = true;

    if (detailsButton) detailsButton.disabled = true;

    show('Saving…', 'info');

    let logoConfirmed = false;

    try {
      if (file) {
        const logo = await readLogo(file);

        const result = await api('station_logo', { logo });

        if (result?.saved !== true) {
          throw Error('Server did not confirm the logo save.');
        }

        const state = await api('state');

        if (state.settings?.station_logo !== logo) {
          throw Error(
            'Logo save could not be verified. Refresh and try again.'
          );
        }

        S.settings.station_logo = state.settings.station_logo;

        document.querySelectorAll(
          '#station-sidebar .efs-brand img, ' +
          '.mobile-identity img, ' +
          '#station-logo-preview'
        ).forEach(image => {
          image.src = logo;
        });

        input.value = '';
        logoConfirmed = true;
      }

      if (includeDetails) {
        await api('settings', details);
        await refresh();
      }

      show('Station logo saved and verified.', 'success');

      toast(
        includeDetails
          ? 'Station details saved'
          : 'Station logo saved'
      );

    } catch (error) {
      preview(saved());

      show(
        (logoConfirmed ? 'Logo saved, but details failed: ' : '') +
        error.message,
        'error'
      );

    } finally {
      saving = false;
      input.disabled = false;
      button.disabled = false;

      if (detailsButton) detailsButton.disabled = false;
    }
  }

  button.onclick = () => save(false);

  form.onsubmit = event => {
    event.preventDefault();
    return save(true);
  };
}
