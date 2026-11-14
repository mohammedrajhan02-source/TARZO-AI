'use strict';
// Product branding is separate from the station's own company name.
const EFS_BRAND=Object.freeze({name:'Ezy Filling System',short:'EFS',version:'2.0H'});
function brandLockup(extra='') {
  const logo =
    typeof S !== 'undefined' && S && S.settings
      ? S.settings.station_logo
      : '';

  const logoSource = logo || 'assets/mark.svg?v=1.4.0';

  return `
    <div class="brand efs-brand ${extra}"
         aria-label="Ezy Filling System (EFS)">
      <img
        src="${h(logoSource)}"
        alt="Station logo"
        width="44"
        height="44"
        style="object-fit:contain"
      >
      <div class="brand-wordmark">
        <strong>
          Ezy <span class="brand-badge">EFS</span>
        </strong>
        <small>Filling System</small>
      </div>
    </div>
  `;
}

function efsOverviewHeader(active){
  return `<section class="efs-command"><div><div class="eyebrow">Ezy Filling System / Station overview</div><h1>Every fill. Made easy.</h1><p class="sub">Your fuel, team and collections. One clear view.</p>${writer()?`<div class="actions">${btn('New pump sale','navigate-billing','','primary','plus')}${btn('Open shift','open-shift','','','clock')}</div>`:''}</div><div class="efs-command-emblem" aria-label="${active.length} open shifts">${icon('pump')}<div><strong>${String(active.length).padStart(2,'0')}</strong><small>open shift${active.length===1?'':'s'}</small>${tag('Local workspace','good')}</div></div></section>`;
}
function efsQuickActions(){
  const links=manager()?[['pump-sales','Pump sales','pump'],['credit-sales','Credit sales','fleet'],['purchasing','Add purchasing','box'],['reports','Reports & insights','report']]:writer()?[['pump-sales','Pump sales','pump'],['credit-sales','Credit sales','fleet'],['credit-receivables','Credit receivables','wallet'],['reports','Sales reports','report']]:[['tanks','Tanks & stock','tank'],['customers','Customers','fleet'],['customer-statements','Customer statements','wallet'],['reports','Sales reports','report']];
  return `<nav class="efs-shortcuts" aria-label="Common station actions">${links.map(([page,label,ico])=>`<a class="efs-shortcut" href="#${page}">${icon(ico)}<span>${label}</span>${icon('arrow')}</a>`).join('')}</nav>`;
}
