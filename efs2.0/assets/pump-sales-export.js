'use strict';
// Offline A4 exports. Raster pages preserve browser-shaped Tamil text without a PDF-font dependency.
async function meterExportData(kind){
  const station=S.settings.station_name||'Ezy Filling System',currency=S.settings.currency||'LKR';
  if(kind==='day'){
    const day=meterDraft().sale_date,pending=meterUi.pending.map(r=>({...r}));
    const result=meterUi.entryOnly?{rows:[],control_no:meterControl(day)}:await api('pump_day',undefined,{sale_date:day});
    const rows=[...result.rows,...pending.map(r=>({...r,pending:true}))];
    return {station,title:'Day Sales',subtitle:day+' · '+result.control_no+(pending.length?' · DRAFT: includes pending lines':''),filename:'EFS-Day-Sales-'+day+'.pdf',headers:['Pump code','Pump name / Fuel','Liters','Price ('+currency+')','Amount ('+currency+')'],widths:[.13,.37,.13,.16,.21],rows:rows.map(r=>[r.pump_code+(r.pending?' (pending)':''),(r.pump_name||r.pump_code)+' · '+(r.product_name||r.item_description)+'\n'+r.employee_name+' · '+r.shift_type+'\n'+dec(r.starting_ml,3)+' → '+dec(r.closing_ml,3),liters(r.liters_ml),money(r.price_cents),money(r.amount_cents)]),total:'Total: '+money(rows.reduce((n,r)=>n+Number(r.amount_cents),0),true),note:pending.length?'Pending lines must be saved to appear in Sales Report.':'Saved sales · '+rows.length+' lines'};
  }
  const from=meterUi.from,to=meterUi.to,result=await api('pump_day_report',undefined,{from,to,export:'1'}),financial=Object.hasOwn(result.totals,'profit_cents');
  return {station,title:'Sales Report',subtitle:(from||'All dates')+' to '+(to||'latest'),filename:'EFS-Sales-Report-'+(from||'all')+'-'+(to||meterMeta().today)+'.pdf',headers:['Date','Sales control no','Total ('+currency+')',...(financial?['Profit ('+currency+')']:[])],widths:financial?[.2,.3,.25,.25]:[.25,.4,.35],rows:result.rows.map(r=>[r.sale_date,r.control_no,money(r.amount_cents),...(financial?[money(r.profit_cents)]:[])]),total:'Total: '+money(result.totals.amount_cents,true)+(financial?'    Profit: '+money(result.totals.profit_cents,true):''),note:result.totals.count+' saved days · Entire selected date range'};
}
function meterPrintHtml(data){
  const font=new URL('assets/efs-tamil.ttf',document.baseURI).href;
  return `<!doctype html><html><head><meta charset="utf-8"><title>${h(data.title+' · '+data.subtitle)}</title><style>@font-face{font-family:EFSTamil;src:url('${h(font)}');font-display:swap;unicode-range:U+0B80-0BFF,U+200C-200D}@page{size:A4 portrait;margin:12mm}*{box-sizing:border-box}body{font:12px/1.5 Arial,EFSTamil,sans-serif;color:#172d32;margin:0}h1{font-size:22px;margin:0 0 5px;overflow-wrap:anywhere}h2{font-size:16px;margin:8px 0}p{margin:5px 0}table{width:100%;border-collapse:collapse;table-layout:fixed;margin:14px 0}th{background:#e6f0ed;text-align:left}th,td{padding:7px;border-bottom:1px solid #cbd9d6;overflow-wrap:anywhere;white-space:pre-line;vertical-align:top}thead{display:table-header-group}tr{break-inside:avoid}tfoot{display:table-row-group}.total{font-size:14px;font-weight:bold;text-align:right;padding:12px 0;border-top:2px solid #246854}.note{color:#526b65}.toolbar{margin:0 0 20px}.toolbar button{padding:8px 14px}@media print{.toolbar{display:none}}</style></head><body><div class="toolbar"><button onclick="window.print()">Print / Save as PDF</button></div><h1>${h(data.station)}</h1><h2>${h(data.title)}</h2><p>${h(data.subtitle)}</p><table><colgroup>${data.widths.map(w=>`<col style="width:${w*100}%">`).join('')}</colgroup><thead><tr>${data.headers.map(x=>`<th>${h(x)}</th>`).join('')}</tr></thead><tbody>${data.rows.map(row=>`<tr>${row.map(x=>`<td>${h(x)}</td>`).join('')}</tr>`).join('')||`<tr><td colspan="${data.headers.length}">No sales in this selection.</td></tr>`}</tbody></table><p class="total">${h(data.total)}</p><p class="note">${h(data.note)}</p></body></html>`;
}
function meterPdfBytes(images){
  const encoder=new TextEncoder(),parts=[],offsets=[0];let length=0;
  const push=v=>{const bytes=typeof v==='string'?encoder.encode(v):v;parts.push(bytes);length+=bytes.length;};
  const object=(id,head,stream)=>{offsets[id]=length;push(id+' 0 obj\n'+head);if(stream){push('\nstream\n');push(stream);push('\nendstream');}push('\nendobj\n');};
  push('%PDF-1.4\n%âãÏÓ\n');object(1,'<< /Type /Catalog /Pages 2 0 R >>');object(2,'<< /Type /Pages /Count '+images.length+' /Kids ['+images.map((_,i)=>(3+i*3)+' 0 R').join(' ')+'] >>');
  images.forEach((image,i)=>{const id=3+i*3,commands=encoder.encode('q\n595.28 0 0 841.89 0 0 cm\n/Im0 Do\nQ');object(id,`<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595.28 841.89] /Resources << /XObject << /Im0 ${id+2} 0 R >> >> /Contents ${id+1} 0 R >>`);object(id+1,'<< /Length '+commands.length+' >>',commands);object(id+2,'<< /Type /XObject /Subtype /Image /Width 1240 /Height 1754 /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length '+image.length+' >>',image);});
  const xref=length;push('xref\n0 '+offsets.length+'\n0000000000 65535 f \n');for(let i=1;i<offsets.length;i++)push(String(offsets[i]).padStart(10,'0')+' 00000 n \n');push('trailer\n<< /Size '+offsets.length+' /Root 1 0 R >>\nstartxref\n'+xref+'\n%%EOF\n');return new Blob(parts,{type:'application/pdf'});
}
async function meterMakePdf(data){
  await document.fonts.load('18px "EFS Tamil"','தமிழ்');await document.fonts.ready;
  const canvas=document.createElement('canvas');canvas.width=1240;canvas.height=1754;const ctx=canvas.getContext('2d'),images=[],margin=70,width=1100,lineHeight=26;let y=0,page=0;
  const font=(bold=false,size=18)=>{ctx.font=(bold?'bold ':'')+size+'px Arial, "EFS Tamil", sans-serif';};
  const wrap=(text,maxWidth)=>{
    const out=[];for(const paragraph of String(text??'').split('\n')){
      let line='';const segments=typeof Intl.Segmenter==='function'?[...new Intl.Segmenter(undefined,{granularity:'grapheme'}).segment(paragraph)].map(s=>s.segment):Array.from(paragraph);
      for(const char of segments){if(line&&ctx.measureText(line+char).width>maxWidth){out.push(line);line=char;}else line+=char;}out.push(line);
    }return out;
  };
  const finish=()=>{font(false,15);ctx.fillStyle='#536c65';ctx.fillText('Ezy Filling System · '+data.title,margin,1706);ctx.textAlign='right';ctx.fillText('Page '+page,1170,1706);ctx.textAlign='left';const raw=atob(canvas.toDataURL('image/jpeg',.9).split(',')[1]);images.push(Uint8Array.from(raw,c=>c.charCodeAt(0)));};
  const header=()=>{
    page++;ctx.fillStyle='#fff';ctx.fillRect(0,0,1240,1754);ctx.fillStyle='#163f34';font(true,26);y=78;
    for(const line of wrap(data.station,width)){ctx.fillText(line,margin,y);y+=34;}font(true,24);ctx.fillText(data.title,margin,y+12);y+=48;font(false,17);ctx.fillStyle='#536c65';for(const line of wrap(data.subtitle,width)){ctx.fillText(line,margin,y);y+=26;}y+=18;
    ctx.fillStyle='#e7f2ed';ctx.fillRect(margin,y,width,44);ctx.fillStyle='#163f34';font(true);let x=margin;data.headers.forEach((title,i)=>{ctx.fillText(title,x+10,y+28);x+=width*data.widths[i];});y+=44;
  };
  header();const rows=data.rows.length?data.rows:[['No sales in this selection.',...data.headers.slice(1).map(()=>'')]];
  for(const row of rows){
    font();const wrapped=row.map((cell,i)=>wrap(cell,width*data.widths[i]-20)),height=Math.max(...wrapped.map(lines=>lines.length))*lineHeight+20;
    if(y+height>1610){finish();header();font();}ctx.fillStyle='#203c34';let x=margin;wrapped.forEach((lines,i)=>{lines.forEach((line,n)=>ctx.fillText(line,x+10,y+26+n*lineHeight));x+=width*data.widths[i];});y+=height;ctx.strokeStyle='#dce5e1';ctx.beginPath();ctx.moveTo(margin,y);ctx.lineTo(1170,y);ctx.stroke();
  }
  if(y+140>1640){finish();header();}y+=40;ctx.fillStyle='#163f34';font(true,22);for(const line of wrap(data.total,width)){ctx.fillText(line,margin,y);y+=30;}font(false,17);ctx.fillStyle='#536c65';for(const line of wrap(data.note,width)){ctx.fillText(line,margin,y+22);y+=26;}finish();return meterPdfBytes(images);
}
function downloadMeterPdf(blob,name){const url=URL.createObjectURL(blob),a=document.createElement('a');a.href=url;a.download=name;a.click();setTimeout(()=>URL.revokeObjectURL(url),60000);}
async function exportMeterSales(action,button){
  if(meterUi.saving||meterUi.loading)return;if(meterUi.uncertain){toast('Retry Save to confirm the pending request before exporting.');return;}const [kind,mode]=action.split('-');let popup=null;
  if(mode==='print'){popup=window.open('','_blank');if(!popup){toast('Allow pop-ups to open the A4 print view.');return;}popup.opener=null;popup.document.write('<p>Preparing print view…</p>');}
  button.disabled=true;
  try{
    const data=await meterExportData(kind);
    if(mode==='print'){popup.document.open();popup.document.write(meterPrintHtml(data));popup.document.close();await popup.document.fonts.load('11px EFSTamil','தமிழ்');await popup.document.fonts.ready;await new Promise(resolve=>popup.requestAnimationFrame(()=>popup.requestAnimationFrame(resolve)));popup.document.body.dataset.printReady='1';popup.focus();popup.print();return;}
    const blob=await meterMakePdf(data),file=new File([blob],data.filename,{type:'application/pdf'});
    if(mode==='share'&&navigator.canShare?.({files:[file]})){try{await navigator.share({files:[file],title:data.title});return;}catch(e){if(e.name==='AbortError')return;}}
    downloadMeterPdf(blob,data.filename);toast(mode==='share'?'PDF downloaded. Attach this file in WhatsApp.':'PDF downloaded.');
  }catch(e){popup?.close();toast(e.message);}finally{button.disabled=false;}
}
