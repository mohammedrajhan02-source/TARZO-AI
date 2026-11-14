'use strict';
// Boundary tests: executes the actual UI logic with mocked DOM/API, not a browser.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
let sequence = 0, passes = 0, apiHandler;
const context = vm.createContext({
  console, setTimeout, window: {addEventListener(){}}, $:()=>null,
  writer:()=>true, todayLocal:()=> '2026-11-13', requestId:()=>String(++sequence),
  S:{user:{id:'u',role:'admin'},settings:{currency:'LKR'},customers:[{id:'c',code:'C1',name:'Transport',phone:'0771234567'}]},
  h:v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])),
  money:v=>(Number(v||0)/100).toFixed(2),
  table:(heads,rows)=>'<table>'+rows.join('')+'</table>', toast(){}, confirm:()=>true,
  api:(...args)=>apiHandler(...args)
});
vm.runInContext(fs.readFileSync(path.join(__dirname,'../assets/accounts-receivable.js'),'utf8'),context);
const run=code=>vm.runInContext(code,context);
const equal=(actual,expected,label)=>{assert.deepEqual(actual,expected);passes++;console.log('PASS '+label);};
const product={pump_id:'p',product_id:'pr',product_name:'Petrol',price_cents:40000};
const state={sale_date:'2026-11-13',pump_id:'p',total_cents:9000000,source_version:'v',allocation:null,pumps:[{id:'p',code:'P1',name:'Pump 1'}],products:[product],invoices:[],credit_remaining_cents:0};
context.fixture=state;
run("Object.assign(arUi,{day:'2026-11-13',pump:'p',data:fixture,header:{customer_id:'c',invoice_no:'CR1'}})");
(async()=>{
  equal(run("arScaled('60000.01')"),6000001,'Decimal currency stays in integer cents');
  equal(run("arLineAmount(arScaled('25',3),40000)"),1000000,'25 litres × 400 = 10,000');
  for(const input of ['-1','NaN','Infinity','1e3','1.001',''])assert.throws(()=>run(`arScaled(${JSON.stringify(input)})`));
  passes++;console.log('PASS Invalid decimal inputs rejected');
  const original=run("arTicket('invoice',{x:1}).request_key");
  equal(run("arTicket('invoice',{x:1}).request_key"),original,'Retry reuses the same request key');
  assert.notEqual(run("arTicket('invoice',{x:2}).request_key"),original);passes++;
  run("arUi.entry={product:'p|pr|40000',liters:'10'};arAddLine();arUi.entry={product:'p|pr|40000',liters:'15'};arAddLine()");
  equal(run('arUi.lines.length'),2,'Two Enter additions retain two pending items');
  equal(run('arPendingTotal()'),1000000,'Multi-product invoice sum');
  equal(run("JSON.stringify(arUi.header)"),JSON.stringify({customer_id:'c',invoice_no:'CR1'}),'Customer and invoice retained');
  equal(run("JSON.stringify(arUi.entry)"),JSON.stringify({product:'',liters:''}),'Only product entry resets');
  await run("arAction('line-edit',{dataset:{index:'0'}})");run("arUi.entry.liters='5';arAddLine()");
  equal(run('arPendingTotal()'),800000,'Editing a pending item replaces its amount');
  await run("arAction('line-remove',{dataset:{index:'0'}})");
  equal(run('arUi.lines.length'),1,'Remove pending item');
  run("arUi.allocation={cash:'60000.00',card:'20000.00',credit:'10000.00'};arUi.allocationDirty=true");
  apiHandler=async()=>{throw Error('Network failed');};
  await assert.rejects(run('arSaveAllocation()'),/Network/);
  equal(run('arUi.allocationDirty'),true,'Failed allocation preserves draft');
  equal(run('arUi.busy'),false,'Failed allocation releases save lock');
  const saved={...state,allocation:{id:'a',version:1,cash_cents:6000000,card_cents:2000000,credit_cents:1000000,difference_cents:0},credit_remaining_cents:1000000};
  apiHandler=async(action,payload)=>{assert.equal(action,'ar_allocation_save');assert.equal(payload.cash,'60000.00');return saved;};
  await run('arSaveAllocation()');
  equal(run('arUi.data.allocation.difference_cents'),0,'Successful save updates saved difference');
  const markup=run('arMarkup()');
  const offsets=['Cash (','Card Pay (','Credit Sales (','Total Amount','Difference</strong>'].map(t=>markup.indexOf(t));
  assert.ok(offsets.every((x,i)=>x>=0&&(!i||x>offsets[i-1])));passes++;
  assert.ok(markup.includes('id="ar-credit-form"'));passes++;
  apiHandler=async()=>{throw Error('Credit limit exceeded');};
  const before=run('JSON.stringify(arUi.lines)');await assert.rejects(run('arSaveInvoice()'),/Credit limit/);
  equal(run('JSON.stringify(arUi.lines)'),before,'Rejected invoice preserves pending items');
  let submitted;
  apiHandler=async(action,payload)=>{if(action==='ar_invoice_save'){submitted=payload;return {id:'i'};}if(action==='state')return context.S;return {...saved,invoices:[{id:'i',invoice_no:'CR1',customer_name:'Transport',total_cents:600000}]};};
  await run('arSaveInvoice()');
  equal(submitted.lines.length,1,'Final save posts the complete invoice once');
  equal(run('arUi.lines.length'),0,'Successful invoice clears draft');
  equal(run('arUi.data.invoices.length'),1,'Register refreshes after save');
  // Resolve an older load after the new date: it must not replace the new result.
  const pending=[];apiHandler=()=>new Promise(resolve=>pending.push(resolve));
  const oldLoad=run('arLoad()');run("arUi.day='2026-11-12'");const newLoad=run('arLoad()');
  pending[1]({...saved,sale_date:'2026-11-12'});await newLoad;pending[0](saved);await oldLoad;
  equal(run('arUi.data.sale_date'),'2026-11-12','Late network response cannot overwrite current date');
  run('resetReceivableDraft()');equal(run('arDirty()'),false,'Reset removes customer draft on sign-out');
  console.log('\n'+passes+' UI boundary checks passed (mocked API/DOM).');
})().catch(e=>{console.error(e);process.exitCode=1;});
