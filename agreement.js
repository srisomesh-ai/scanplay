/* ScanPlay agreement: shared renderer + signature pad (used by admin.html, studio.html, agreement.html) */
(function(){
const esc=t=>String(t??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const inr=n=>'₹'+Number(n||0).toLocaleString('en-IN');
const num=n=>Number(n||0).toLocaleString('en-IN');

/* default terms the admin form starts from */
window.AGR_DEFAULTS={role:'distributor',territory:'',promoPrice:30,promoQty:1000,stdPrice:50,minFirst:1000,minReorder:1000,
  m1:1000,m2:1500,m3:2000,promoters:10,promoterDays:60,maxPromoterPrice:60,recPromoterPrice:'40–50',termMonths:12,noticeDays:30,retentionMonths:12,notes:''};

window.agreementHtml=function(g){
  const t=g.terms||{}, p=t.party||{}, s=t.scanplay||{}, isDist=(t.role||p.role)!=='promoter';
  const roleWord=isDist?'Distributor':'Promoter', who=isDist?'Distributor':'Promoter';
  const signedBlock=g.status==='signed'
    ? `<div class="agr-sig"><div><div class="agr-sigbox">${g.sign_img?`<img src="${g.sign_img}" alt="signature">`:g.scan?'<span class="agr-note">Signed copy uploaded</span>':''}</div><b>${esc(g.sign_name||p.person)}</b><br><span class="agr-note">${esc(p.name)} · signed ${new Date(g.signed_at*1000).toLocaleString('en-IN')} · ${g.sign_kind==='drawn'?'digital signature':'scanned copy'}</span></div>
        <div><div class="agr-sigbox">${g.admin_img?`<img src="${g.admin_img}" alt="ScanPlay signature">`:''}</div><b>${esc(s.name||'ScanPlay')}</b><br><span class="agr-note">${esc(s.business||'ScanPlay LLP')}${g.admin_signed_at?' · signed '+new Date(g.admin_signed_at*1000).toLocaleDateString('en-IN'):''}</span></div></div>`
    : `<div class="agr-sig"><div><div class="agr-sigbox agr-empty">${who} signature</div><b>${esc(p.person)}</b><br><span class="agr-note">${esc(p.name)}</span></div>
        <div><div class="agr-sigbox">${g.admin_img?`<img src="${g.admin_img}" alt="ScanPlay signature">`:'<span class="agr-empty">ScanPlay signature</span>'}</div><b>${esc(s.name||'ScanPlay')}</b><br><span class="agr-note">${esc(s.business||'ScanPlay LLP')}</span></div></div>`;
  const circ=isDist?`<li><b>Circulate tokens regularly</b> — minimum tokens supplied to Promoters and end customers per calendar month: <b>Month 1: ${num(t.m1)} · Month 2: ${num(t.m2)} · Month 3 onwards: ${num(t.m3)}</b> (Month 1 is the first full calendar month after signing).</li>
      <li><b>Build the Promoter network</b> — appoint at least <b>${num(t.promoters)} active Promoters</b> in the Territory within the first <b>${num(t.promoterDays)} days</b>. An "active" Promoter has bought tokens and activated at least one photo in the last 30 days.</li>`
    :`<li><b>Circulate tokens regularly</b> — minimum tokens supplied to customers per calendar month: <b>Month 1: ${num(t.m1)} · Month 2: ${num(t.m2)} · Month 3 onwards: ${num(t.m3)}</b>.</li>`;
  return `<div class="agr">
  <div class="agr-head"><img src="assets/brand/logo-horizontal.png" alt="ScanPlay"><h1>${roleWord.toUpperCase()} AGREEMENT</h1><div class="agr-note">${esc(t.number||'')} · ${isDist?'Territory: ':'Area: '}${esc(t.territory||'')}</div></div>
  <p>This ${roleWord} Agreement ("Agreement") is made on <b>${esc(t.date||'')}</b> between:</p>
  <p><b>${esc(s.business||'ScanPlay LLP')}</b>, Visakhapatnam, Andhra Pradesh, India, represented by ${esc(s.name||'its Founder')} ("ScanPlay"); and</p>
  <p><b>${esc(p.name)}</b>${p.address?', '+esc(p.address):''}, represented by <b>${esc(p.person)}</b>${p.email?' ('+esc(p.email)+(p.phone?' · '+esc(p.phone):'')+')':''} ("${who}").</p>

  <h2>1. What ScanPlay is</h2>
  <p>ScanPlay is a web platform (scanplay.in) that makes printed photos play video: a printed picture is scanned with a phone camera and a video plays on it, without any app. Access is sold in tokens. <b>One token = one photo + one video.</b> Tokens never expire and are consumed when a photo is activated; a spent token is not refunded if the photo is later deleted.</p>

  <h2>2. Appointment and ${isDist?'territory':'area'}</h2>
  <p>ScanPlay appoints the ${who} as its Authorised ${roleWord} for <b>${esc(t.territory||'')}</b> ("${isDist?'Territory':'Area'}"). ${isDist?'The Distributor may appoint Promoters (photographers, event managers, print shops and similar businesses) within the Territory and supply tokens to them. ScanPlay may appoint other distributors outside the Territory and may itself supply large or institutional customers directly, after informing the Distributor.':'The Promoter sells ScanPlay to its own customers and supplies them tokens. Appointment is non-exclusive.'}</p>

  <h2>3. Token pricing</h2>
  <table class="agr-tbl"><tr><th>Tokens purchased</th><th>Price per token (to ${who})</th></tr>
  <tr><td>First ${num(t.promoQty)} tokens (promotional)</td><td>${inr(t.promoPrice)}</td></tr>
  <tr><td>Every token after the first ${num(t.promoQty)}</td><td>${inr(t.stdPrice)}</td></tr></table>
  <p>Prices are <b>exclusive of GST</b>, which is charged as applicable. ScanPlay may revise the standard price with ${num(t.noticeDays)} days' written notice; the promotional price for the first ${num(t.promoQty)} tokens is fixed.</p>

  <h2>4. Minimum purchase and payment</h2>
  <ul><li><b>Minimum first order: ${num(t.minFirst)} tokens.</b> Subsequent orders: minimum ${num(t.minReorder)} tokens.</li>
  <li>Tokens are credited to the ${who}'s ScanPlay account only after full payment is received. No credit is extended.</li>
  <li>Every credit and transfer is recorded in the ScanPlay token ledger, which is the record of account for both parties.</li></ul>

  <h2>5. Regular circulation</h2>
  <p>The purpose of the appointment is active distribution, not stock-holding. The ${who} agrees to:</p>
  <ul>${circ}
  <li>Promote ScanPlay in the ${isDist?'Territory':'Area'}: demonstrations to businesses, sharing ScanPlay promotional material, and display of the ScanPlay Authorised ${roleWord} certificate at the place of business.</li>
  <li>Keep accurate accounts of every token supplied — to whom, how many, at what price — and share them with ScanPlay on request. The ScanPlay partner panel is to be used for all transfers so the ledger stays complete.</li>
  <li>Take part in promotional campaigns announced by ScanPlay, using any promotional tokens ScanPlay provides for free trials and demonstrations.</li></ul>

  ${isDist?`<h2>6. Pricing to Promoters</h2>
  <ul><li>The Distributor sets its own selling price to Promoters, but must price reasonably so that Promoters can sell profitably. ScanPlay's recommended price to Promoters is ₹${esc(t.recPromoterPrice)} per token; the Distributor shall not charge Promoters more than ${inr(t.maxPromoterPrice)} per token without ScanPlay's written consent.</li>
  <li>Promoters are free partners: they may stop buying at any time, and the Distributor shall not impose penalties, lock-ins or minimum commitments on Promoters beyond what ScanPlay publishes.</li>
  <li>The Distributor shall not sell tokens outside the Territory or to another distributor's Promoters.</li></ul>`
  :`<h2>6. Pricing to customers</h2>
  <ul><li>The Promoter sets its own price to customers and keeps the margin.</li><li>The Promoter shall not resell tokens to other promoters or distributors.</li></ul>`}

  <h2>7. Responsibilities of ScanPlay</h2>
  <ul><li>Keep the platform running, maintain uploaded content for at least ${num(t.retentionMonths)} months from activation, and provide the partner panel, invite links and admin support.</li>
  <li>Credit tokens within one working day of receiving payment.</li>
  <li>List the ${who} on scanplay.in under "Partners near me" and issue the Authorised ${roleWord} certificate.</li>
  <li>Provide promotional material and first-line technical support.</li></ul>

  <h2>8. Restrictions</h2>
  <ul><li>Tokens move only downward: ScanPlay → Distributor → Promoter → customer. Tokens cannot be returned upward, resold to other partners, or refunded, except as stated in clause 10.</li>
  <li>The ${who} shall not misrepresent ScanPlay, promise features that do not exist, or use the ScanPlay name and logo for anything other than promoting ScanPlay.</li>
  <li>The ${who} shall not copy, reverse-engineer or build a competing photo-video scanning service during this Agreement and for 12 months after it ends.</li>
  <li>Customer content uploaded to ScanPlay belongs to the customer; the ${who} shall not use it for any purpose without the customer's permission.</li></ul>

  <h2>9. Term</h2>
  <p>This Agreement is valid for <b>${num(t.termMonths)} months</b> from the date above and renews for further ${num(t.termMonths)}-month periods unless either party gives ${num(t.noticeDays)} days' written notice. Either party may end it immediately if the other breaches it and does not correct the breach within 15 days of written notice. ScanPlay may end the Agreement if the monthly circulation minimum${isDist?' or the Promoter target':''} in clause 5 is not met for two consecutive months.</p>

  <h2>10. On ending the Agreement</h2>
  <ul><li>The ${who} stops representing itself as a ScanPlay ${roleWord.toLowerCase()} and returns or destroys promotional material.</li>
  <li>Tokens already supplied to ${isDist?'Promoters and ':''}customers remain valid and continue to work.</li>
  <li>Unused tokens in the ${who}'s own account: if the Agreement ends by ScanPlay's notice without breach, ScanPlay will buy back unused tokens at the price paid; otherwise the ${who} may continue to supply its unused tokens to existing ${isDist?'Promoters':'customers'} for 60 days, after which they lapse.</li></ul>

  ${t.notes?`<h2>11. Additional terms</h2><p>${esc(t.notes).replace(/\n/g,'<br>')}</p><h2>12. General</h2>`:'<h2>11. General</h2>'}
  <p>This Agreement is the whole agreement between the parties on this subject and can be changed only in writing signed by both. It is governed by the laws of India; courts at Visakhapatnam have jurisdiction. Neither party is the employee, agent or franchisee of the other. Notices may be given by email to the addresses above.</p>
  <h2>Signed and agreed</h2>
  ${signedBlock}
  ${g.scan?`<h2>Uploaded signed copy</h2>${g.scan.startsWith('data:application/pdf')?`<a href="${g.scan}" download="agreement-${esc(g.id)}-signed.pdf">Download the uploaded PDF</a>`:`<img src="${g.scan}" style="max-width:100%;border:1px solid #ddd;border-radius:8px">`}`:''}
  </div>`;
};

window.AGR_CSS=`.agr{font:500 14px/1.55 'Plus Jakarta Sans',system-ui,sans-serif;color:#141032;text-align:left}
.agr-head{text-align:center;margin-bottom:18px}.agr-head img{width:150px;display:block;margin:0 auto 8px}.agr-head h1{font-size:22px;margin:0 0 2px;letter-spacing:.02em}
.agr h2{font-size:14px;color:#7C3AED;margin:18px 0 6px}.agr p{margin:0 0 8px}.agr ul{margin:0 0 8px 20px;padding:0}.agr li{margin:0 0 5px}
.agr-tbl{border-collapse:collapse;width:100%;margin:6px 0 8px;font-size:13px}.agr-tbl th,.agr-tbl td{border:1px solid #E8E4F4;padding:7px 10px;text-align:left}.agr-tbl th{background:#F3EEFF}
.agr-note{font-size:12px;color:#5B5670}.agr-sig{display:grid;grid-template-columns:1fr 1fr;gap:18px;margin-top:8px}.agr-sig>div{font-size:13px}
.agr-sigbox{height:80px;border-bottom:1.5px solid #141032;margin-bottom:6px;display:flex;align-items:flex-end}.agr-sigbox img{max-height:76px;max-width:100%}.agr-empty{color:#b5b0c8;font-size:12px;font-style:italic;align-self:center}
@media print{.agr{font-size:12.5px}.agr h2{page-break-after:avoid}}`;

/* ---- signature pad ---- */
window.sigPad=function(canvas){
  const ctx=canvas.getContext('2d'); let drawing=false, has=false, last=null;
  const fit=()=>{ const r=canvas.getBoundingClientRect(); const dpr=window.devicePixelRatio||1; canvas.width=r.width*dpr; canvas.height=r.height*dpr; ctx.scale(dpr,dpr); ctx.lineWidth=2.2; ctx.lineCap='round'; ctx.lineJoin='round'; ctx.strokeStyle='#141032'; };
  fit();
  const pos=e=>{ const r=canvas.getBoundingClientRect(); const p=e.touches?e.touches[0]:e; return [p.clientX-r.left,p.clientY-r.top]; };
  const down=e=>{ e.preventDefault(); drawing=true; last=pos(e); };
  const move=e=>{ if(!drawing) return; e.preventDefault(); const p=pos(e); ctx.beginPath(); ctx.moveTo(last[0],last[1]); ctx.lineTo(p[0],p[1]); ctx.stroke(); last=p; has=true; };
  const up=()=>{ drawing=false; };
  canvas.addEventListener('pointerdown',down); canvas.addEventListener('pointermove',move); window.addEventListener('pointerup',up);
  canvas.style.touchAction='none';
  return { clear(){ ctx.save(); ctx.setTransform(1,0,0,1,0,0); ctx.clearRect(0,0,canvas.width,canvas.height); ctx.restore(); has=false; }, isEmpty(){ return !has; }, data(){ return has?canvas.toDataURL('image/png'):''; } };
};
})();
