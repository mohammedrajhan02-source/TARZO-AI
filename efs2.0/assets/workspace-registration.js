'use strict';
// Work Space 1.4: persisted registers and linked, same-page entry forms.
const registration={category:'',product:'',employee:'',vendor:'',purchase:null,pumpDrafts:null,pumpEdits:{}};
const regArea=(name,label,value='',extra='')=>`<label>${label}<textarea name="${name}" rows="2" ${extra}>${h(value)}</textarea></label>`;
const regId=id=>`<input type="hidden" name="id" value="${h(id||'')}">`;

const regActions=(label,reset='',leading='')=>`
  <div class="form-error" role="alert" tabindex="-1"></div>
  <div class="registration-actions">
    ${leading}
    ${reset?btn('Cancel edit',reset,'','ghost'):''}
    <button class="btn primary" type="submit">
      ${icon('check')}${label}
    </button>
  </div>`;

const regHeader=(step,title,description)=>`<div class="registration-heading"><span>${step}</span><div><h2>${title}</h2><p class="sub">${description}</p></div></div>`;

function workspaceCategoriesPage(){
  const c=(S.categories||[]).find(x=>x.id===registration.category)||{};
 return '<div class="category-kicker"></div>'+
    `<section class="panel registration-panel category-compact">${regHeader('01',c.id?'Edit category':'Add category','')}<form id="category-form">${regId(c.id)}<div class="form-grid">${field('code','Code','text',c.code||'','required maxlength="32" placeholder="e.g. LUB"')}${field('name','Name','text',c.name||'','required maxlength="160" placeholder="e.g. Lubricants"')}</div>
${regActions(
  c.id ? 'Update' : 'Add category',
  c.id ? 'reg-category-reset' : '',
  c.id ? btn(
    'Delete',
    'reg-category-delete',
    c.id,
    'small category-delete-button'
  ) : ''
)}
</form></section>`+
    `<div class="section-gap">${panel('Saved categories',searchTable('Search category code or name…')+table(['Code','Name','Products',''],(S.categories||[]).map(x=>`<tr><td><strong>${h(x.code)}</strong></td><td>${h(x.name)}</td><td>${S.products.filter(p=>p.category_id===x.id).length}</td><td>${btn('Edit','reg-category-edit',x.id,'small')}</td></tr>`),'No categories yet. Add your first category above.'))}</div>`;
}
function workspaceProductsPage(){
  const p=S.products.find(x=>x.id===registration.product)||{},categories=S.categories||[];
  // Calculate total selling value of all products
const totalPriceCents = (S.products || []).reduce(
    (total, item) => {
        const quantity = Number(item.stock_milli || 0);
        const sellingPrice = Number(item.price_cents || 0);

        return total + Math.round(
            quantity * sellingPrice / 1000
        );
    },
    0
);
 return ''+
    `<section class="panel registration-panel category-compact">${regHeader('02',p.id?'Edit product':'Add product','The Category list comes from your saved Work Space categories.')}<form id="product-form">${regId(p.id)}<div class="form-grid product-basic-grid">${field('sku','Item code','text',p.sku||'','required maxlength="40" placeholder="e.g. OIL-001"')}${field('name','Name','text',p.name||'','required maxlength="160"')}${num('quantity','Quantity',dec(p.stock_milli||0,3),(S.pumps||[]).some(pump=>pump.product_id===p.id)?'0.001':'1',`required ${p.has_transactions?'readonly':''}`)}</div><div class="product-price-grid">${num('cost','Cost price',dec(p.cost_cents||0),'0.01','required')}${num('price','Selling price',dec(p.price_cents||0),'0.01','required')}</div><div class="product-category-row">
  <div class="registration-category">
    ${select(
      'category_id',
      'Category',
      options(
        categories,
        c => c.code+' · '+c.name,
        'Select category…',
        p.category_id||''
      ),
      'required'
    )}
  </div>

  <div class="category-stock-value">
    <span>Total Value</span>
    <strong id="category-stock-value">
      ${money(0,true)}
    </strong>
  </div>
</div>${!categories.length?'<p class="notice warn">Add a <a href="#categories">Category</a> before saving your first product.</p>':''}${p.has_transactions?'<p class="form-hint">Quantity is the recorded stock balance. Receive additional stock through Purchasing.</p>':''}
${regActions(
  p.id?'Update':'Add product',
  p.id?'reg-product-reset':'',
  p.id?btn(
    'Delete',
    'reg-product-delete',
    p.id,
    'small product-delete-button'
  ):''
)}
</form></section>`+
    `<div class="section-gap">${panel('Product register',searchTable('Search item code, name or category…')+table(['Item code / Name','Category','Quantity','Cost price','Selling price','Current Balance',''],S.products.map(x=>`<tr><td><strong>${h(x.sku)}</strong><br><small>${h(x.name)}</small></td><td>${h(x.category_name||'Uncategorised')}</td><td>${liters(x.stock_milli)}</td><td>${money(x.cost_cents)}</td><td>${money(x.price_cents)}</td><td class="amount"><strong>${money(Math.round(Number(x.stock_milli || 0) * Number(x.price_cents || 0) / 1000), true)}</strong></td><td>
${btn('Edit','reg-product-edit',x.id,'small')}</td></tr>`).concat(
  S.products.length ? [
    `<tr class="product-register-total">
      <td colspan="5" style="text-align:right;">
        <strong>Total Price</strong>
      </td>
      <td class="amount">
        <strong>${money(totalPriceCents, true)}</strong>
      </td>
      <td></td>
    </tr>`
  ] : []
),'No products yet. Add an item using a saved category.')

)}</div>`+

    `<details class="panel registration-advanced section-gap"><summary>Fuel grades & selling rates</summary><div class="actions">${btn('Add fuel grade','master','fuel','','drop')}${btn('Update fuel rate','price','','','trend')}</div>${table(['Code','Fuel','Selling price / L'],S.fuels.map(f=>`<tr><td>${h(f.code)}</td><td>${h(f.name)}</td><td>${money(f.price_cents)}</td></tr>`))}</details>`;
}
function createPumpDraft(){
  const all=[...S.pumps,...(registration.pumpDrafts||[])],numbers=all.map(p=>Number(/^Pump\s+(\d+)$/i.exec(p.name||'')?.[1]||0));
  const number=Math.max(all.length,0,...numbers)+1;
  return {draft:requestId(),name:'Pump '+number,code:number===1?'P1001':number===2?'D1001':number===3?'K1001':'',description:number===1?'Petrol':number===2?'Diesel':number===3?'Kerosene':'',product_id:'',start_meter:'0.000',end_meter:'0.000',tank_id:''};
}
function pumpFuelBalances(pumpId) {
  // Count each connected tank only once.
  const tankIds = new Set(
    (S.nozzles || [])
      .filter(n => n.pump_id === pumpId)
      .map(n => n.tank_id)
  );

  const groups = new Map();

  for (const tank of (S.tanks || [])) {
    if (!tankIds.has(tank.id)) continue;

    const fuel = (S.fuels || []).find(
      f => f.id === tank.fuel_type_id
    );

    const key = tank.fuel_type_id || tank.id;

    if (!groups.has(key)) {
      groups.set(key, {
        code: fuel?.code || '—',
        name: fuel?.name || tank.fuel_name || 'Fuel',
        ml: 0,
        rate: Number(fuel?.price_cents || 0)
      });
    }

    groups.get(key).ml += Number(tank.balance_ml || 0);
  }

  return [...groups.values()];
}

function pumpFuelBalanceCell(pumpId) {
  const balances = pumpFuelBalances(pumpId);

  if (!balances.length) {
    return `
      <div class="pump-balance-empty">
        <strong>—</strong>
        <small>Fuel not linked</small>
      </div>
    `;
  }

  return balances.map(fuel => {
    const valueCents = fuel.rate > 0
      ? Math.round(fuel.ml * fuel.rate / 1000)
      : null;

    return `
      <div class="pump-fuel-balance">
        <div class="pump-fuel-top">
          <span
            class="pump-fuel-code"
            title="${h(fuel.name)}"
          >${h(fuel.code)}</span>

          <span class="pump-stock">
            ${liters(fuel.ml)} L
          </span>
        </div>

        <strong class="pump-current-value">
          ${valueCents === null ? '—' : money(valueCents, true)}
        </strong>

        <small>
          ${valueCents === null
            ? 'Set a selling rate'
            : `${money(fuel.rate, true)} / L`}
        </small>
      </div>
    `;
  }).join('');
}

function pumpProductCurrentBalance(p) {

  const product = (S.products || []).find(
    item => String(item.id) === String(p.product_id || '')
  );

  if (!product) {
    return '<span class="pump-balance-unlinked">—</span>';
  }

  const quantity = Number(product.stock_milli || 0) / 1000;

  const sellingPrice = Number(product.price_cents || 0);

  const totalCents = Math.round(quantity * sellingPrice);

  return `
    <strong class="pump-product-current-balance">
      ${money(totalCents, true)}
    </strong>
  `;
}

function pumpRegistrationCard(p, draft = false) {
  const key = draft ? p.draft : p.id;
  const formId = 'pump-row-form-' + key;

  const values = draft ? p : {
    id: p.id,
    name: p.name,
    code: p.code,
    description: p.description,
    product_id: p.product_id || '',
    start_meter: dec(p.start_meter_ml || 0, 3),
    end_meter: dec(p.end_meter_ml || 0, 3),
    ...(registration.pumpEdits[p.id] || {})
  };

  const nozzles = draft
    ? []
    : S.nozzles.filter(n => n.pump_id === p.id);

  const locked = !draft && !!p.has_history;
  
  const productLocked = !draft && Boolean(p.product_id);

  const linkedProduct = (S.products || []).find(
    product =>
      String(product.id) === String(productLocked ? p.product_id : values.product_id || '')
  );


  const input = (name, label, value, extra = '') => `
    <input
      form="${h(formId)}"
      name="${name}"
      aria-label="${h(values.name + ' — ' + label)}"
      value="${h(String(value ?? ''))}"
      ${extra}
    >
  `;

  const connection = nozzles.length
    ? nozzles.map(n => `
        <div class="pump-table-connection">
          <strong>${h(n.code)}</strong>
          <small>${h(n.fuel_name)} · ${h(n.tank_code)}</small>
        </div>
      `).join('')
    : `
      <select
        form="${h(formId)}"
        name="tank_id"
        aria-label="${h(values.name + ' — Supply tank')}"
      >
        ${options(
          S.tanks,
          t => t.code + ' · ' + t.fuel_name,
          'Connect later',
          values.tank_id || ''
        )}
      </select>
    `;

  return `
    <tr>
      
<th scope="row">
  <div class="pump-title-inline">

    <span class="pump-icon-badge">
      ${icon('pump')}
    </span>

    <strong class="pump-table-name">
      ${h(values.name)}
    </strong>

    ${tag(
      draft ? 'Not saved' : nozzles.length ? 'Connected' : 'Setup',
      draft ? 'warn' : nozzles.length ? 'good' : ''
    )}

  </div>
</th>


      <td>
        ${input(
          'code',
          'Code',
          values.code,
          'type="text" required maxlength="24" placeholder="e.g. P1001"'
        )}
      </td>
       <td>
         ${productLocked ? `
           <div class="pump-product-locked">
             <input
               class="pump-product-readonly"
               type="text"
               aria-label="${h(values.name + ' — Linked product (locked)')}"
               value="${h(linkedProduct ? linkedProduct.sku + ' - ' + linkedProduct.name : 'Unavailable product - ' + p.product_id)}"
               readonly
               title="This product mapping cannot be changed"
             >
             <span class="pump-product-lock" title="Product mapping locked">
               ${icon('shield')} Locked
             </span>
           </div>
           <input
             form="${h(formId)}"
             type="hidden"
             name="product_id"
             value="${h(p.product_id)}"
           >
         ` : `
           <select
             form="${h(formId)}"
             name="product_id"
             aria-label="${h(values.name + ' — Product')}"
             ${draft ? 'required' : ''}
           >
             <option value="">${draft ? 'Select product…' : 'No product linked'}</option>
             ${(S.products || []).filter(product => Number(product.active) === 1).map(product => `
               <option value="${h(product.id)}" ${String(product.id) === String(values.product_id || '') ? 'selected' : ''}>
                 ${h(product.sku + ' - ' + product.name)}
               </option>
             `).join('')}
           </select>
         `}
         <input
           form="${h(formId)}"
           type="hidden"
          name="description"
          value="${h(String(values.description ?? ''))}"
        >
      </td>

      <td>
        ${input(
          'start_meter',
          'Start meter in litres',
          values.start_meter,
          `type="number" min="0" step="0.001" required
           ${locked ? 'readonly title="Update live readings in Shifts"' : ''}`
        )}
      </td>

      <td>
        ${input(
          'end_meter',
          'End meter in litres',
          values.end_meter,
          `type="number" min="0" step="0.001" required
           ${locked ? 'readonly title="Update live readings in Shifts"' : ''}`
        )}
      </td>

     
<td class="pump-balance-cell">
  ${pumpProductCurrentBalance(p)}
</td>


      

      <td>
        <form
          id="${h(formId)}"
          class="pump-registration-form"
          data-pump-key="${h(key)}"
          data-draft="${draft ? '1' : '0'}"
        >
          ${regId(values.id)}

          <input
            type="hidden"
            name="name"
            value="${h(values.name)}"
          >

          
<div class="pump-row-actions">
  <button class="btn primary small" type="submit">
    ${icon('check')} Save
  </button>

  ${btn(
    'Delete',
    'reg-pump-delete',
    key,
    'small pump-delete-button'
  )}
</div>


          <div
            class="form-error"
            role="alert"
            tabindex="-1"
          ></div>
        </form>
      </td>
    </tr>
  `;
}

function workspacePumpsPage() {
  if (registration.pumpDrafts === null) {
    registration.pumpDrafts = [];

    while (S.pumps.length + registration.pumpDrafts.length < 3) {
      registration.pumpDrafts.push(createPumpDraft());
    }
  }

  const savedRows = [...S.pumps]
    .sort((a, b) => a.name.localeCompare(
      b.name,
      undefined,
      { numeric: true }
    ))
    .map(p => pumpRegistrationCard(p))
    .join('');

  const draftRows = registration.pumpDrafts
    .map(p => pumpRegistrationCard(p, true))
    .join('');

  return `
    <style>
    
/* Pump table column widths */

.pump-register-table th:nth-child(1) { width: 14%; }
.pump-register-table th:nth-child(2) { width: 14%; }
.pump-register-table th:nth-child(3) { width: 19%; }
.pump-register-table th:nth-child(4) { width: 17%; }
.pump-register-table th:nth-child(5) { width: 17%; }
.pump-register-table th:nth-child(6) { width: 19%; }

/* Save and Delete side by side */

.pump-register-table .pump-row-actions {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 6px;
}

.pump-register-table .pump-row-actions .btn {
    min-width: 0;
    width: 100%;
    min-height: 32px;
    padding: 6px 8px;
    font-size: 11px;
    justify-content: center;
}

/* Delete button appearance */

.pump-register-table .pump-delete-button {
    background: rgba(225,75,75,0.08);
    border: 1px solid rgba(225,75,75,0.45);
    color: #ff9090;
}

.pump-register-table .pump-delete-button:hover {
    background: rgba(225,75,75,0.18);
}

      .pump-table-toolbar {
    display: flex;
    justify-content: flex-end;
    margin-top: 18px;
    margin-bottom: 6px;
}

      .pump-table-panel {
        min-width: 0;
        padding: 0;
        overflow: hidden;
      }

      .pump-table-scroll {
        width: 100%;
        overflow-x: auto;
      }

      .pump-register-table {
        width: 100%;
        min-width: 1080px;
        border-collapse: collapse;
      }

      .pump-register-table th,
      .pump-register-table td {
        padding: 16px 12px;
        text-align: left;
        vertical-align: middle;
        border-bottom: 1px solid var(--border, #294344);
      }

      .pump-register-table thead th {
        white-space: nowrap;
        font-size: 12px;
        font-weight: 600;
        letter-spacing: 0.02em;
      }

      .pump-register-table tbody th {
        min-width: 110px;
      }

      .pump-register-table tbody tr:last-child > * {
        border-bottom: 0;
      }

      .pump-register-table tbody tr:hover {
        background: rgba(100, 210, 180, 0.04);
      }

      .pump-table-name {
        display: block;
        margin-bottom: 8px;
        white-space: nowrap;
        font-size: 14px;
      }

      .pump-register-table input,
      .pump-register-table select {
        box-sizing: border-box;
        width: 100%;
        min-height: 42px;
        margin: 0;
      }

      .pump-register-table input[name="code"] {
        min-width: 150px;
      }

      .pump-register-table input[type="number"] {
        min-width: 140px;
      }

      .pump-register-table input[name="description"],
      .pump-register-table select {
        min-width: 160px;
      }

      .pump-register-table input[readonly] {
        opacity: 0.7;
        cursor: default;
      }

      .pump-register-table .pump-product-locked {
        position: relative;
      }

      .pump-register-table .pump-product-readonly {
        padding-right: 78px;
        color: var(--text, #e8f0ee);
        background: var(--surface-2, #102223);
        border-color: var(--border, #294344);
      }

      .pump-register-table .pump-product-lock {
        position: absolute;
        top: 50%;
        right: 10px;
        display: inline-flex;
        align-items: center;
        gap: 4px;
        color: var(--muted, #a4b7b2);
        font-size: 11px;
        transform: translateY(-50%);
        pointer-events: none;
      }

      .pump-register-table .pump-product-lock .icon {
        width: 14px;
        height: 14px;
      }

      .pump-table-connection strong,
      .pump-table-connection small {
        display: block;
      }

      .pump-table-connection small {
        margin-top: 4px;
        opacity: 0.75;
      }

      .pump-register-table .pump-registration-form {
        min-width: 130px;
        margin: 0;
      }

      .pump-register-table button[type="submit"] {
        white-space: nowrap;
      }

      .pump-register-table .form-error:empty {
        display: none;
      }

      .pump-register-table .form-error {
        max-width: 190px;
        margin-top: 8px;
        white-space: normal;
      }

      .pump-register-table:focus-visible,
      .pump-table-scroll:focus-visible {
        outline: 2px solid #69dfbd;
        outline-offset: -2px;
      }
        
/* EFS - COMPACT PUMP TABLE DESIGN */

/* Add Pump Button Area */
.pump-table-toolbar {
    margin-bottom: 10px;
}

.pump-table-toolbar .btn {
    min-height: 36px;
    padding: 7px 12px;
    font-size: 11px;
}

/* Table Width */
.pump-register-table {
    min-width: 990px;
    table-layout: fixed;
}

/* Column Widths */
.pump-register-table th:nth-child(1) { width: 13%; }
.pump-register-table th:nth-child(2) { width: 13%; }
.pump-register-table th:nth-child(3) { width: 14%; }
.pump-register-table th:nth-child(4) { width: 14%; }
.pump-register-table th:nth-child(5) { width: 15%; }
.pump-register-table th:nth-child(6) { width: 15%; }
.pump-register-table th:nth-child(7) { width: 16%; }

/* Compact Table Header */
.pump-register-table thead th {
    padding: 10px 8px;
    font-size: 10px;
    background: var(--panel2);
    color: var(--muted);
}

/* Reduce Pump Row Height */
.pump-register-table tbody > tr > th,
.pump-register-table tbody > tr > td {
    padding: 6px 8px;
    height: 48px;
}

/* Pump Name */
.pump-table-name {
    display: inline-block;
    font-size: 12px;
    margin: 0 5px 0 0;
    vertical-align: middle;
}

/* Small Setup / Connected Badge */
.pump-register-table tbody th > .tag {
    display: inline-flex;
    vertical-align: middle;
    padding: 2px 5px;
    font-size: 8px;
    line-height: 1.2;
}

/* Smaller Input Boxes */
.pump-register-table input,
.pump-register-table select {
    width: 100%;
    height: 36px;
    min-height: 36px;
    min-width: 0;
    padding: 6px 8px;
    font-size: 11px;
    border-radius: 8px;
    font-variant-numeric: tabular-nums;
}

/* Remove Old Minimum Width Restrictions */
.pump-register-table input[name="code"],
.pump-register-table input[type="number"],
.pump-register-table input[name="description"],
.pump-register-table select {
    min-width: 0;
}

/* Compact Action Area */
.pump-register-table .pump-registration-form {
    min-width: 0;
    margin: 0;
}

/* Smaller Save Changes Button */
.pump-register-table button[type="submit"] {
    width: 100%;
    min-height: 34px;
    padding: 6px 7px;
    gap: 5px;
    border-radius: 8px;
    font-size: 10.5px;
}

.pump-register-table button[type="submit"] .icon {
    width: 14px;
    height: 14px;
}

/* Subtle Alternating Row Color */
.pump-register-table tbody tr:nth-child(even) {
    background: color-mix(
        in srgb,
        var(--accent) 2%,
        transparent
    );
}

/* Highlight Row During Editing */
.pump-register-table tbody tr:focus-within {
    background: color-mix(
        in srgb,
        var(--accent) 7%,
        transparent
    );
}

    </style>

    

    <section class="panel pump-table-panel">
      <div
        class="pump-table-scroll"
        tabindex="0"
        role="region"
        aria-label="Pump register"
      >
        <table
          id="pump-registration-grid"
          class="pump-register-table"
          aria-label="Pump registration"
        >
          <thead>
            <tr>
              
<th scope="col">Pump</th>
<th scope="col">Code</th>
<th scope="col">Description</th>
<th scope="col">Start meter · L</th>

<th scope="col">End meter · L</th>
<th scope="col">Current Balance</th>
<th scope="col">Action</th>


            </tr>
          </thead>

          <tbody>
            ${savedRows}
            ${draftRows}
          </tbody>
       
        </table>
      </div>
    </section>

    <div class="pump-table-toolbar">
      ${btn('Add Pump', 'reg-pump-add', '', 'primary', 'plus')}
    </div>

    <details class="panel registration-advanced section-gap">
      <summary>Tank & nozzle connections</summary>

      <div class="actions">
        ${btn('Add storage tank', 'master', 'tank', '', 'plus')}
        ${btn('Add nozzle', 'master', 'nozzle', '', 'pump')}
        ${btn('Tank stock & dips', 'navigate-tanks', '', '', 'tank')}
      </div>

      ${table(
        ['Tank', 'Fuel', 'Book stock', 'Capacity'],
        S.tanks.map(t => `
          <tr>
            <td>${h(t.code)}</td>
            <td>${h(t.fuel_name)}</td>
            <td>${liters(t.balance_ml)} L</td>
            <td>${liters(t.capacity_ml)} L</td>
          </tr>
        `)
      )}
    </details>
  `;
}
  
function workspaceEmployeesPage(){

  const e=(S.employees||[]).find(
    x=>x.id===registration.employee
  )||{};

  return `<section class="panel registration-panel employee-compact">

    ${regHeader(
      '04',
      e.id?'Edit employee':'Add employee',
      ''
    )}

    <form id="employee-form">

      ${regId(e.id)}

      <div class="employee-form-fields">

        <div class="employee-field-code">
          ${field(
            'code',
            'Employee CodeNo',
            'text',
            e.code||'',
            'required maxlength="32"'
          )}
        </div>

        <div class="employee-field-name">
          ${field(
            'name',
            'Employee Name',
            'text',
            e.name||'',
            'required maxlength="160"'
          )}
        </div>

        <div class="employee-field-shift">
          <label>
            Employee Shift
            <select name="shift_type" required>
              <option value="day"
                ${(e.shift_type||'day')==='day'?'selected':''}>
                Day
              </option>
              <option value="night"
                ${e.shift_type==='night'?'selected':''}>
                Night
              </option>
            </select>
          </label>
        </div>

        <div class="employee-field-address">
          ${regArea(
            'address',
            'Employee address',
            e.address||'',
            'maxlength="1000"'
          )}
        </div>

        <div class="employee-field-contact">
          ${field(
            'phone',
            'Employee Contact',
            'tel',
            e.phone||'',
            'maxlength="50"'
          )}
        </div>

      </div>

      ${regActions(
        e.id?'Save employee':'Add employee',
        e.id?'reg-employee-reset':''
      )}

    </form>

  </section>`+

  `<div class="section-gap">
    ${panel(
      'Employee register',
      searchTable('Search employee code, name or contact…')+
      table(
        ['CodeNo / Name','Address','Shift','Contact','Status',''],
        (S.employees||[]).map(x=>`
          <tr>
            <td>
              <strong>${h(x.code)}</strong>
              <br><small>${h(x.name)}</small>
            </td>
            <td>${h(x.address||'—')}</td>
            <td>${tag(x.shift_type==='night'?'Night':'Day')}</td>
            <td>${h(x.phone||'—')}</td>
            <td>${tag(
              x.active?'Active':'Inactive',
              x.active?'good':''
            )}</td>
            <td>
              <div class="actions">
               ${btn('Edit','employee',x.id,'small')}

${btn(
  'Delete',
  'reg-employee-delete',
  x.id,
  'small employee-delete-button'
)}

${btn(
  x.active?'Deactivate':'Activate',
  'employee-status',
  x.id,
  'small'
)}
              </div>
            </td>
          </tr>
        `)
      )
    )}
  </div>
  <p class="form-hint">
    Day/Night records the employee's preferred shift.
    Link a system login in Users Admin to let the
    employee operate a shift.
  </p>`;

}
function workspaceVendorsPage(){
  const v=S.suppliers.find(x=>x.id===registration.vendor)||{};
  
return ''+

    `<section class="panel registration-panel vendor-registration">${regHeader('05',v.id?'Edit vendor':'Add vendor','Current Balance is the opening payable when creating a new vendor.')}<form id="vendor-form">${regId(v.id)}<div class="vendor-code-row">${field('code','Code No','text',v.code||'','required maxlength="64" placeholder="e.g. V001"')}</div><div class="form-grid vendor-name-row">${field('current_balance','Current Balance','number',dec(v.balance_cents||0),`step="0.01" required ${v.id?'readonly':''}`)}${field('name','Name','text',v.name||'','required maxlength="160"')}</div>
<div class="form-grid vendor-address-row">
  ${field('phone','Contact No','tel',v.phone||'',
    'maxlength="80" placeholder="e.g. 0771234567"')}

  ${field('city','City','text',v.city||'',
    'maxlength="160"')}

  ${regArea('address','Address',v.address||'',
    'maxlength="1000"')}
</div>
${v.id?'<p class="form-hint">This balance includes posted purchases and payments. Change it through Purchasing or Accounts Payable.</p>':''}${regActions(
  v.id ? 'Save vendor' : 'Add vendor',
  v.id ? 'reg-vendor-reset' : '',
  v.id ? btn(
    'Delete vendor',
    'reg-vendor-delete',
    v.id,
    'small vendor-delete-button'
  ) : ''
)}</form></section>`+
    `<div class="section-gap">${panel('Vendor register',searchTable('Search vendor code, name or city…')+table(['Code No','Name','Current Balance','Address / City',''],S.suppliers.map(x=>`<tr><td><strong>${h(x.code)}</strong></td><td>${h(x.name)}</td><td class="amount">${money(x.balance_cents)}</td><td>${h(x.address||'—')}<br><small>${h(x.city)}</small></td><td><div class="actions">${btn('Edit','reg-vendor-edit',x.id,'small')}${btn('Statement','open-vendor-statement',x.id,'small','report')}</div></td></tr>`),'No vendors yet. Add a vendor above to use it in Purchasing.'))}</div>`;
}
function purchaseItemFields(kind){
  const fuel=kind==='fuel';
  return `<div class="form-grid">${select(fuel?'tank_id':'product_id',fuel?'Tank / Fuel':'Product',options(fuel?S.tanks:S.products,x=>fuel?x.code+' · '+x.fuel_name:x.sku+' · '+x.name,'Select '+(fuel?'tank':'product')+'…'),'required data-purchase-item')}${num(fuel?'liters':'quantity',fuel?'Quantity · L':'Quantity · units','1',fuel?'0.001':'1','required')}${num(fuel?'cost_per_liter':'cost',fuel?'Cost price / L':'Cost price per unit','0.00','0.01','required')}<div class="purchase-total"><small>Purchase total · ${h(S.settings.currency)}</small><strong id="purchase-total">0.00</strong></div></div>`;
}

function workspacePurchasingPage(){
  const defaults = S.purchase_defaults || {};
  const edit = registration.purchase;
  const kind = edit?.kind || 'product';
  const record = edit
    ? kind === 'fuel'
      ? (S.deliveries || []).find(x => String(x.id) === String(edit.id))
      : (S.product_purchases || []).find(x => String(x.id) === String(edit.id))
    : null;
  const product = kind === 'product' && record
    ? (S.products || []).find(x => x.id === record.product_id)
    : null;
  const bankOptions = [...(S.bank_options || [])];
  if(record?.bank_account_id&&!bankOptions.some(x=>x.id===record.bank_account_id)){
    const oldBank=(S.banks||[]).find(x=>x.id===record.bank_account_id);
    bankOptions.push(oldBank||{id:record.bank_account_id,code:'',name:record.bank_account_id});
  }

  const rows = [
    ...(S.deliveries || []).map(d => ({
      ...d,
      type: 'Fuel',
      kind: 'fuel',
      item: d.tank_code,
      quantity: liters(d.quantity_ml) + ' L'
    })),
    ...(S.product_purchases || []).map(p => ({
      ...p,
      type: 'Product',
      kind: 'product',
      item: p.product_name,
      quantity: liters(p.quantity_milli) + ' units'
    }))
  ].sort((a,b) => b.received_at.localeCompare(a.received_at));

  return `
    <section class="panel registration-panel purchase-registration">

      ${regHeader('06', edit ? 'Edit purchasing' : 'Add purchasing', '')}

      <form id="purchase-form">

        <input type="hidden" name="kind" value="${kind}">
        ${edit ? regId(edit.id) : ''}

        <div class="purchase-compact-grid">

          <div class="purchase-date-field">
            ${field(
              'purchase_date',
              'Date',
              'date',
              record?.purchase_date || defaults.date || todayLocal(),
              'required'
            )}
          </div>

          
<fieldset class="purchase-bills">
  <legend>Bill Details</legend>

  <div class="purchase-bills-grid">

    ${field(
      'con_bill_no',
      'Owner Bill No',
      'text',
      record?.con_bill_no || defaults.con_bill_no || '',
      'readonly placeholder="CON-2026-000003"'
    )}

    ${field(
      'company_bill_no',
      'Purchasing No',
      'text',
      record?.company_bill_no || record?.invoice_no || '',
      'required maxlength="80" placeholder="Purchasing bill number"'
    )}

  </div>
</fieldset>


          <div class="purchase-vendor-field">
            ${select(
              'supplier_id',
              'Vendor Code and name',
              options(
                S.suppliers || [],
                v => v.code + ' · ' + v.name,
                'Select vendor…',
                record?.supplier_id || ''
              ),
              'required'
            )}
            <small id="purchase-vendor-balance" class="form-hint"></small>
          </div>

          ${kind === 'fuel' ? `
            <div class="purchase-product-field">
              ${select(
                'tank_id',
                'Tank / Fuel',
                options(
                  S.tanks || [],
                  t => t.code + ' · ' + t.fuel_name,
                  'Select tank…',
                  record?.tank_id || ''
                ),
                'required'
              )}
            </div>
            <div class="purchase-quantity-field">
              ${num('liters','Quantity · L',record?dec(record.quantity_ml,3):'1','0.001','required')}
            </div>
            <div class="purchase-cost-field">
              ${num('cost_per_liter','Cost price / L',record?dec(record.cost_cents_per_l):'0.00','0.01','required')}
            </div>
          ` : `
            <div class="purchase-category-field">
              ${select(
                'category_id',
                'Category',
                options(
                  S.categories || [],
                  c => c.code + ' · ' + c.name,
                  'Select category…',
                  product?.category_id || ''
                ),
                'required'
              )}
            </div>
            <div class="purchase-product-field">
              ${select(
                'product_id',
                'Product',
                options(
                  (S.products || []).filter(p =>
                    String(p.category_id) === String(product?.category_id || '') &&
                    (Number(p.active) !== 0 || p.id === record?.product_id)
                  ),
                  p => p.sku + ' · ' + p.name,
                  'Select category first…',
                  record?.product_id || ''
                ),
                'required'
              )}
            </div>
            <div class="purchase-quantity-field">
              ${num('quantity','Quantity · units',record?dec(record.quantity_milli,3):'1','1','required')}
            </div>
            <div class="purchase-cost-field">
              ${num('cost','Cost price per unit',record?dec(record.cost_cents):'0.00','0.01','required')}
            </div>
          `}

          <div class="purchase-paid-field">
            ${num(
              'paid_amount',
              'Paid now by bank',
              record?dec(record.paid_cents):'0.00',
              '0.01',
              'required'
            )}

            <div id="purchase-bank-field" hidden>
              ${select(
                'bank_account_id',
                'Bank account',
                options(bankOptions,b=>b.code?b.code+' · '+b.name:b.name,'Select bank…',record?.bank_account_id||'')
              )}
            </div>
          </div>

          <div class="purchase-notes-field">
            ${regArea('notes', 'Notes', record?.notes || '', 'maxlength="1000"')}
          </div>

          <div class="purchase-total">
            <small>Purchase total · ${h(S.settings.currency)}</small>
            <strong id="purchase-total">0.00</strong>
          </div>

        </div>

        ${!S.suppliers.length
          ? '<p class="notice warn">Add a Vendor before saving a purchase.</p>'
          : ''}

        ${edit ? `
          <div class="form-error" role="alert" tabindex="-1"></div>
          <div class="registration-actions">
            ${record ? btn('Delete','reg-purchase-delete',kind+':'+record.id,'small category-delete-button') : ''}
            ${btn('Cancel Edit','reg-purchase-reset','','ghost')}
            <button class="btn primary" type="submit">${icon('check')}Update purchasing</button>
          </div>
        ` : regActions('Save purchasing')}

      </form>
    </section>` +

    `<div class="section-gap">
      ${panel(
        'Purchasing register',
        searchTable('Search owner bill, purchasing bill or vendor…') +
        table(
          [
            'Date',
'Owner Bill No',
'Purchasing Bill No',
'Vendor Code / Name',
'Type / Item',
'Quantity',
'Total',
'Paid',
'Action'
          ],
          rows.map(x => `
            <tr>
              <td>${h(x.purchase_date || '—')}</td>
              <td><strong>${h(x.con_bill_no || '—')}</strong></td>
              <td>${h(x.company_bill_no || x.invoice_no)}</td>
              <td>
                ${h(x.supplier_code || '')}
                <br><small>${h(x.supplier_name)}</small>
              </td>
              <td>
                ${tag(x.type)}
                <br><small>${h(x.item)}</small>
              </td>
              <td>${x.quantity}</td>
              <td>${money(x.total_cents)}</td>
             
<td>${money(x.paid_cents)}</td>
<td>${x.voided_at
  ? '<span class="tag warn">Voided</span>'
  : btn('Edit','reg-purchase-edit',x.type+':'+x.id,'small')
}</td>
</tr>

          `),
          'No purchases yet. Save your first purchase above.'
        )
      )}
    </div>`;
}

function workspaceEmployeeEdit(id=''){
  registration.employee=id;
  if(route==='employees'){render();$('#employee-form [name=code]')?.focus();}else location.hash='employees';
}
function bindRegistrationForm(form,action,done=()=>{}){
  if(!form)return;form.dataset.requestKey=requestId();
  form.onsubmit=async event=>{event.preventDefault();await submit(form,async()=>{
    const data={...Object.fromEntries(new FormData(form)),request_key:form.dataset.requestKey};
    const result=await api(action,data);done(result);await refresh();toast(action==='purchase'?'Saved · '+result.con_bill_no:'Saved successfully');
  });};
}
function wireWorkspaceRegistration(){
  bindRegistrationForm($('#category-form'),'category',()=>registration.category='');
  const productForm = $('#product-form');
  bindRegistrationForm(productForm,'product',()=>registration.product='');

if (productForm) {

  const updateCategoryValue = () => {

    const categoryId =
      productForm.elements.category_id.value;

    const totalCents = (S.products || [])
      .filter(item => item.category_id === categoryId)
      .reduce((total, item) => {

        const quantity = Number(item.stock_milli || 0) / 1000;

        const costCents = Number(item.cost_cents || 0);

        return total + Math.round(quantity * costCents);

      }, 0);

    $('#category-stock-value').textContent =
      money(totalCents, true);

  };

  productForm.elements.category_id.addEventListener(
    'change',
    updateCategoryValue
  );

  updateCategoryValue();

}
  bindRegistrationForm($('#employee-form'),'employee',()=>registration.employee='');
  bindRegistrationForm($('#vendor-form'),'vendor',()=>registration.vendor='');
  for(const form of $$('.pump-registration-form')){
    const key=form.dataset.pumpKey,isDraft=form.dataset.draft==='1';
    const stash=()=>{const values=Object.fromEntries(new FormData(form));if(isDraft){const p=registration.pumpDrafts.find(x=>x.draft===key);if(p)Object.assign(p,values);}else registration.pumpEdits[key]=values;};
    const pumpRow = form.closest('tr') || form;

pumpRow.addEventListener('input', stash);
pumpRow.addEventListener('change', stash);
    bindRegistrationForm(form,'pump',()=>{if(isDraft)registration.pumpDrafts=registration.pumpDrafts.filter(p=>p.draft!==key);else delete registration.pumpEdits[key];});
  }
  
  const form = $('#purchase-form');

  if (form) {
    form.dataset.requestKey=requestId();

    const update = () => {
      const fuel = form.elements.kind.value === 'fuel';
      const quantity = Number(form.elements[fuel ? 'liters' : 'quantity'].value || 0);
      const cost = Number(form.elements[fuel ? 'cost_per_liter' : 'cost'].value || 0);

      const total = Math.round(quantity * cost * 100);

      $('#purchase-total').textContent = money(total);

      const paid = Number(form.elements.paid_amount.value || 0) > 0;

      $('#purchase-bank-field').hidden = !paid;
      form.elements.bank_account_id.required = paid;

      const vendor = (S.suppliers || []).find(
        v => v.id === form.elements.supplier_id.value
      );

      $('#purchase-vendor-balance').textContent = vendor
        ? 'Current Balance · ' + money(vendor.balance_cents, true)
        : '';

    };

    const updateProducts = (preserveSelection=false) => {

      const categoryId = form.elements.category_id.value;
      const selected = preserveSelection ? form.elements.product_id.value : '';
      const editProductId = form.elements.id?.value
        ? (S.product_purchases || []).find(p => String(p.id) === String(form.elements.id.value))?.product_id
        : '';

      const matching = (S.products || []).filter(p =>
        categoryId &&
        String(p.category_id) === String(categoryId) &&
        (Number(p.active) !== 0 || p.id === editProductId)
      );

      form.elements.product_id.innerHTML = options(
        matching,
        p => p.sku + ' · ' + p.name,
        categoryId ? 'Select product…' : 'Select category first…',
        selected
      );

      if(!preserveSelection){
        const product = matching.find(p => p.id === form.elements.product_id.value);
        form.elements.cost.value = dec(product?.cost_cents || 0);
      }

      update();
    };

    if(form.elements.kind.value === 'product'){
      form.elements.category_id.addEventListener('change',()=>updateProducts());
      form.elements.product_id.addEventListener('change',()=>{
        const product=(S.products||[]).find(p=>p.id===form.elements.product_id.value);
        form.elements.cost.value=dec(product?.cost_cents||0);
        update();
      });
      updateProducts(true);
    }

    form.addEventListener('input', update);
    form.addEventListener('change', update);

    update();

    form.onsubmit=async event=>{
      event.preventDefault();
      const edit=Boolean(form.elements.id?.value);
      await submit(form,async()=>{
        const data={...Object.fromEntries(new FormData(form)),request_key:form.dataset.requestKey||requestId()};
        const result=await api(edit?'purchase_update':'purchase',data);
        registration.purchase=null;
        await refresh();
        toast(edit?'Purchasing updated':'Saved · '+(result.con_bill_no||result.company_bill_no||'purchasing'));
      });
    };

  }

}
async function workspaceRegistrationAction(action,id){
    if (action === 'reg-vendor-delete') {
    const vendor = (S.suppliers || []).find(
      item => String(item.id) === String(id)
    );

    if (!vendor) {
      throw Error('Vendor not found. Refresh and try again.');
    }

    showDialog('Delete vendor', `
      <form id="vendor-delete-form">
        <p>
          Permanently delete
          <strong>${h(vendor.code)} · ${h(vendor.name)}</strong>?
        </p>

        <p class="form-hint">
          Only vendors with zero balance and no purchasing,
          payment, payable or cheque history can be deleted.
          This cannot be undone.
        </p>

        <div class="form-error"
             role="alert"
             tabindex="-1"></div>

        <div class="form-actions">
          ${btn('Cancel', 'close-dialog', '', 'ghost')}

          <button type="submit"
                  class="btn vendor-delete-button">
            Yes, delete vendor
          </button>
        </div>
      </form>
    `);

    const deleteForm = document.getElementById('vendor-delete-form');
    const deleteKey = requestId();

    deleteForm.onsubmit = async event => {
      event.preventDefault();

      await submit(deleteForm, async () => {
        await api('vendor_delete', {
          id: vendor.id,
          request_key: deleteKey
        });

        document.getElementById('dialog').close();

        if (String(registration.vendor) === String(vendor.id)) {
          registration.vendor = '';
        }

        await refresh();
        toast('Vendor deleted successfully');
      });
    };

    return true;
  }
  const edits={'reg-category-edit':'category','reg-product-edit':'product','reg-vendor-edit':'vendor'};
  const resets={'reg-category-reset':'category','reg-product-reset':'product','reg-employee-reset':'employee','reg-vendor-reset':'vendor','reg-purchase-reset':'purchase'};
  if(edits[action]){registration[edits[action]]=id;render();window.scrollTo({top:0,behavior:'smooth'});return true;}
  if(resets[action]){registration[resets[action]]='';render();return true;}

  if(action==='reg-purchase-edit'){
    const [label,...parts]=String(id||'').split(':');
    const kind=label.toLowerCase()==='fuel'?'fuel':label.toLowerCase()==='product'?'product':'';
    const purchaseId=parts.join(':');
    const source=kind==='fuel'?S.deliveries:S.product_purchases;
    const record=(source||[]).find(x=>String(x.id)===purchaseId);
    if(!kind||!record||record.voided_at)throw Error('Purchasing record not found or already voided. Refresh and try again.');
    registration.purchase={kind,id:record.id};
    render();
    window.scrollTo({top:0,behavior:'smooth'});
    return true;
  }

  if(action==='reg-purchase-delete'){
    const [label,...parts]=String(id||'').split(':');
    const kind=label.toLowerCase()==='fuel'?'fuel':label.toLowerCase()==='product'?'product':'';
    const purchaseId=parts.join(':');
    const source=kind==='fuel'?S.deliveries:S.product_purchases;
    const record=(source||[]).find(x=>String(x.id)===purchaseId);
    if(!kind||!record||record.voided_at)throw Error('Purchasing record not found or already voided. Refresh and try again.');
    showDialog('Void purchasing record',`
      <div class="form-error" role="alert" tabindex="-1"></div><p>Void purchasing bill <strong>${h(record.company_bill_no||record.invoice_no)}</strong>?</p>
      <p class="form-hint">This keeps the purchase in history and records reversals for stock, vendor balance, and any bank payment. If its inventory has already been sold or withdrawn, the server will reject the void.</p>
      <div class="actions">
        ${btn('Cancel','close-dialog','','ghost')}
        ${btn('Confirm Void','reg-purchase-delete-confirm',kind+':'+purchaseId,'small category-delete-button')}
      </div>
    `);
    return true;
  }

  if(action==='reg-purchase-delete-confirm'){
    const [label,...parts]=String(id||'').split(':');
    const kind=label.toLowerCase()==='fuel'?'fuel':label.toLowerCase()==='product'?'product':'';
    const purchaseId=parts.join(':');
    if(!kind||!purchaseId)throw Error('Purchasing record not found. Refresh and try again.');
    const dialog=$('#dialog'),button=$('[data-action="reg-purchase-delete-confirm"]',dialog);
    if(button?.disabled)return true;
    const error=$('.form-error',dialog);if(error)error.textContent='';
    if(button)button.disabled=true;
    try{
      if(!button.dataset.requestKey)button.dataset.requestKey=requestId();
      await api('purchase_void',{id:purchaseId,kind,request_key:button.dataset.requestKey});
    }catch(e){if(error){error.textContent=e.message;error.focus();}return true;}
    finally{if(button)button.disabled=false;}
    $('#dialog').close();
    registration.purchase=null;
    await refresh();
    toast('Purchasing record voided; history retained.');
    return true;
  }
  
if(action==='reg-category-delete'){

  const category=(S.categories||[]).find(
    x=>String(x.id)===String(id)
  );

  if(!category){
    toast('Category not found. Refresh and try again.');
    return true;
  }

  const products=(S.products||[]).filter(
    p=>String(p.category_id)===String(id)
  );

  if(products.length){

    showDialog('Category cannot be deleted', `
      <p>
        <strong>${h(category.code)} · ${h(category.name)}</strong>
        is linked to ${products.length} product(s).
      </p>
      <p class="form-hint">
        Move or remove those products first.
        No category data has been deleted.
      </p>
      <div class="actions">
        ${btn('OK','close-dialog','','ghost')}
      </div>
    `);

    return true;
  }

  showDialog('Delete category', `
    <p>
      Delete
      <strong>${h(category.code)} · ${h(category.name)}</strong>
      permanently?
    </p>

    <p class="form-hint">
      Only empty, unlinked categories can be deleted.
      This cannot be undone.
    </p>

    <div class="actions">
      ${btn('No','close-dialog','','ghost')}
      ${btn(
        'Yes, Delete',
        'reg-category-delete-confirm',
        id,
        'category-delete-button'
      )}
    </div>
  `);

  return true;
}

if(action==='reg-category-delete-confirm'){

  await api('category_delete',{
    id,
    request_key:requestId()
  });

  $('#dialog').close();

  registration.category='';

  await refresh();

  toast('Category deleted successfully');

  return true;
}


  
if(action==='reg-product-delete'){

  const product=(S.products||[]).find(x=>x.id===id);

  if(!product){
    throw Error('Product not found. Refresh and try again.');
  }

  showDialog('Delete product', `
    <p>
      Delete <strong>${h(product.sku)} · ${h(product.name)}</strong>?
    </p>

    <p class="form-hint">
      This action is permanent. Products with stock,
      sales or purchasing history, or pump connections
      cannot be permanently deleted.
    </p>

    <div class="actions">
      ${btn('No','close-dialog','','ghost')}
      ${btn('Yes, Delete','reg-product-delete-confirm',id,'product-delete-button')}
    </div>
  `);

  return true;
}

if(action==='reg-product-delete-confirm'){

  await api('product_delete',{
    id,
    request_key:requestId()
  });

  $('#dialog').close();
  registration.product='';

  await refresh();

  toast('Product deleted');

  return true;
}
if(action === 'reg-employee-delete') {

  const employee = (S.employees || []).find(
    x => String(x.id) === String(id)
  );

  if (!employee) {
    throw Error(
      'Employee not found. Refresh and try again.'
    );
  }

  showDialog('Delete employee', `

    <p>
      Delete
      <strong>
        ${h(employee.code)} · ${h(employee.name)}
      </strong>
      permanently?
    </p>

    <p class="form-hint">
      The employee register entry will be deleted.
      A linked system login and previous shift history
      will remain. An active shift must be closed first.
      This action cannot be undone.
    </p>

    <div class="actions">

      ${btn(
        'No',
        'close-dialog',
        '',
        'ghost'
      )}

      ${btn(
        'Yes, Delete',
        'reg-employee-delete-confirm',
        id,
        'employee-delete-button'
      )}

    </div>

  `);

  return true;
}


if(action === 'reg-employee-delete-confirm') {

  await api('employee_delete', {
    id: id,
    request_key: requestId()
  });

  $('#dialog').close();

  if (
    String(registration.employee) === String(id)
  ) {
    registration.employee = '';
  }

  await refresh();

  toast('Employee deleted successfully');

  return true;
}

if(action==='reg-pump-delete'){

  const draft=(registration.pumpDrafts||[])
    .find(p=>p.draft===id);

  const pump=draft || S.pumps.find(p=>p.id===id);

  if(!pump){
    throw Error('Pump not found. Refresh and try again.');
  }

  showDialog('Delete pump', `
    <p>
      Delete <strong>${h(pump.name)}</strong>
      (${h(pump.code||'New pump')})?
    </p>

    <p class="form-hint">
      This action cannot be undone.
      Pumps linked to nozzles or shift records
      cannot be permanently deleted.
    </p>

    <div class="actions">
      ${btn('No','close-dialog','','ghost')}

      ${btn(
        'Yes, Delete',
        'reg-pump-delete-confirm',
        id,
        'pump-confirm-delete'
      )}
    </div>
  `);

  return true;
}

if(action==='reg-pump-delete-confirm'){

  const draft=(registration.pumpDrafts||[])
    .find(p=>p.draft===id);

  if(draft){

    registration.pumpDrafts=
      registration.pumpDrafts.filter(p=>p.draft!==id);

    $('#dialog').close();
    render();
    toast('Unsaved pump removed.');

  }else{

    await api('pump_delete',{
      id,
      request_key:requestId()
    });

    $('#dialog').close();

    delete registration.pumpEdits[id];

    await refresh();

    toast('Pump deleted.');
  }

  return true;
}

  if(action==='reg-pump-add'){
    registration.pumpDrafts.push(createPumpDraft());render();
    const cards=$$('.pump-registration-form');cards[cards.length-1]?.scrollIntoView({block:'center',behavior:'smooth'});return true;
  }
  return false;
}

init();
