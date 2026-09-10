// InlineComp – rijderprofiel-grafiek (draait ná `const DATA = {...}` in profiel.php).
// Geport uit docs_internal/mockup-rijderprofiel.html, met dynamische as (min/max
// datum + yMax uit de data) en lege-groep-afhandeling. Klassering op Y (omgekeerd:
// 1 onderaan → dalende lijn = beter), sprint/lang-toggle, tijd↔klassering (bij één
// sprint-afstand), 🇳🇱-only-toggle.
(function(){
  const svg=document.getElementById('chart'), tip=document.getElementById('tip'),
        legendEl=document.getElementById('legend'), metricrow=document.getElementById('metricrow'),
        metricHint=document.getElementById('metric-hint'), ymetricEl=document.getElementById('ymetric'),
        btnRang=document.getElementById('btn-rang'), btnTijd=document.getElementById('btn-tijd'),
        nlToggle=document.getElementById('nl-toggle'), nlCheck=document.getElementById('nl-check'),
        leegEl=document.getElementById('leeg-chart'),
        btnSprint=document.getElementById('btn-sprint'), btnLang=document.getElementById('btn-lang');
  const NS="http://www.w3.org/2000/svg";
  const W=920,H=460, M={t:26,r:104,b:44,l:56};
  const PW=W-M.l-M.r, PH=H-M.t-M.b;

  // ── Dynamische as uit de data ─────────────────────────────────────────────
  const allSeries=[...Object.values(DATA.sprint||{}), ...Object.values(DATA.lang||{})];
  const allPts=allSeries.flatMap(s=>s.p||[]);
  const heeftData=allPts.length>0;
  let minT, maxT, minYear, maxYear, yMax=6;
  if(heeftData){
    const times=allPts.map(p=>Date.parse(p.d));
    minT=Math.min(...times); maxT=Math.max(...times);
    if(minT===maxT){ minT-=180*864e5; maxT+=180*864e5; }
    else { const pad=(maxT-minT)*0.05; minT-=pad; maxT+=pad; }
    minYear=new Date(minT).getFullYear(); maxYear=new Date(maxT).getFullYear();
    const maxRank=Math.max(...allPts.map(p=>Math.max(p.r||1, p.rn||1)));
    yMax=Math.max(6, maxRank+2);
  }
  const x = d => M.l + (Date.parse(d)-minT)/(maxT-minT)*PW;
  const y = r => M.t + (yMax-r)/(yMax-1)*PH;               // 1 onderaan
  const el=(n,a)=>{const e=document.createElementNS(NS,n);for(const k in a)e.setAttribute(k,a[k]);return e;};

  function yTicks(){
    const step = yMax<=12 ? 2 : (yMax<=30 ? 5 : 10);
    const t=[1]; for(let r=step; r<=yMax; r+=step) t.push(r); return t;
  }

  const leeg = g => Object.keys(DATA[g]||{}).length===0;
  let group = leeg('sprint') ? (leeg('lang') ? 'sprint' : 'lang') : 'sprint';
  let yMetric="rang", nlOnly=false;
  const hidden=new Set();

  if(leeg('sprint')) btnSprint.disabled=true;
  if(leeg('lang'))   btnLang.disabled=true;

  const visNames=()=>Object.keys(DATA[group]).filter(n=>!hidden.has(n));
  const hasTime=n=>DATA[group][n] && DATA[group][n].p.some(p=>p.t!=null);
  const rangOf=pt=>(nlOnly && pt.rn!=null) ? pt.rn : pt.r;

  function updateMetricRow(){
    const vis=visNames();
    const single = group==='sprint' && vis.length===1 && hasTime(vis[0]);
    if(yMetric==='tijd' && !single) yMetric='rang';
    metricrow.style.display='flex';
    nlToggle.style.display = (yMetric==='tijd') ? 'none' : 'inline-flex';
    nlCheck.checked = nlOnly;
    if(single){
      ymetricEl.hidden=false;
      metricHint.textContent=vis[0]+' — bekijk op:';
      btnRang.setAttribute('aria-pressed', yMetric==='rang');
      btnTijd.setAttribute('aria-pressed', yMetric==='tijd');
    } else {
      ymetricEl.hidden=true;
      metricHint.textContent = (group==='sprint') ? 'Tip: laat via de legenda één afstand over om ’m op tijd te vergelijken.' : '';
    }
  }

  function drawX(){
    for(let yr=minYear; yr<=maxYear; yr++){
      const mid=Date.parse(yr+"-07-01");
      if(mid<minT||mid>maxT) continue;
      const xx=M.l+(mid-minT)/(maxT-minT)*PW;
      svg.appendChild(el("line",{class:"gridline",x1:xx,x2:xx,y1:M.t,y2:M.t+PH,opacity:.5}));
      const t=el("text",{class:"axis-tick x",x:xx,y:M.t+PH+22}); t.textContent=yr; svg.appendChild(t);
    }
  }

  function render(){
    if(leeg(group)){
      svg.style.display='none'; legendEl.style.display='none'; metricrow.style.display='none';
      leegEl.style.display='block'; return;
    }
    svg.style.display='block'; legendEl.style.display='flex'; leegEl.style.display='none';
    updateMetricRow();
    svg.innerHTML="";
    const series=DATA[group], vis=visNames(), pts=[];
    const useTime = yMetric==='tijd' && vis.length===1 && hasTime(vis[0]);

    if(useTime){
      const name=vis[0], s=series[name], tp=s.p.filter(p=>p.t!=null);
      let tmin=Math.min(...tp.map(p=>p.t)), tmax=Math.max(...tp.map(p=>p.t));
      if(tmin===tmax){tmin-=1000;tmax+=1000;} else {const pad=(tmax-tmin)*.14; tmin-=pad; tmax+=pad;}
      const yT=ms=>M.t+(tmax-ms)/(tmax-tmin)*PH;
      for(let i=0;i<=4;i++){ const ms=tmin+(tmax-tmin)*i/4, yy=yT(ms);
        svg.appendChild(el("line",{class:"gridline",x1:M.l,x2:M.l+PW,y1:yy,y2:yy}));
        const t=el("text",{class:"axis-tick y",x:M.l-8,y:yy+4}); t.textContent=fmtTime(ms); svg.appendChild(t); }
      const yt=el("text",{class:"axis-title",x:M.l-8,y:M.t-11,"text-anchor":"start"}); yt.textContent="Tijd · sneller ▼"; svg.appendChild(yt);
      drawX();
      const path=tp.map((pt,i)=>(i?"L":"M")+x(pt.d)+","+yT(pt.t)).join(" ");
      if(tp.length>1) svg.appendChild(el("path",{class:"serie-line",d:path,stroke:s.color}));
      tp.forEach(pt=>{ svg.appendChild(el("circle",{class:"serie-dot",cx:x(pt.d),cy:yT(pt.t),r:4.5,fill:s.color})); pts.push({x:x(pt.d),y:yT(pt.t),name,color:s.color,pt,metric:'tijd'}); });
      const last=tp[tp.length-1]; const lb=el("text",{class:"serie-label",x:x(last.d)+9,y:yT(last.t)+4,fill:s.color}); lb.textContent=name; svg.appendChild(lb);
    } else {
      yTicks().forEach(r=>{ if(r>yMax) return; svg.appendChild(el("line",{class:"gridline",x1:M.l,x2:M.l+PW,y1:y(r),y2:y(r)})); const t=el("text",{class:"axis-tick y",x:M.l-8,y:y(r)+4}); t.textContent=r; svg.appendChild(t); });
      const yt=el("text",{class:"axis-title",x:M.l-8,y:M.t-11,"text-anchor":"start"}); yt.textContent="Klassering · 1 = beste ▼"+(nlOnly?" · alleen NL":""); svg.appendChild(yt);
      drawX();
      vis.forEach(name=>{
        const s=series[name];
        const path=s.p.map((pt,i)=>(i?"L":"M")+x(pt.d)+","+y(rangOf(pt))).join(" ");
        if(s.p.length>1) svg.appendChild(el("path",{class:"serie-line",d:path,stroke:s.color}));
        s.p.forEach(pt=>{ svg.appendChild(el("circle",{class:"serie-dot",cx:x(pt.d),cy:y(rangOf(pt)),r:4.5,fill:s.color})); pts.push({x:x(pt.d),y:y(rangOf(pt)),name,color:s.color,pt,metric:'rang'}); });
        const last=s.p[s.p.length-1]; const lb=el("text",{class:"serie-label",x:x(last.d)+9,y:y(rangOf(last))+4,fill:s.color}); lb.textContent=name; svg.appendChild(lb);
      });
    }
    buildLegend();
    hookHover(pts);
  }

  function buildLegend(){
    legendEl.innerHTML="";
    Object.keys(DATA[group]).forEach(name=>{
      const b=document.createElement("button");
      b.setAttribute("aria-pressed", hidden.has(name)?"false":"true");
      b.innerHTML='<span class="sw" style="background:'+DATA[group][name].color+'"></span>'+name;
      b.onclick=()=>{ hidden.has(name)?hidden.delete(name):hidden.add(name); render(); };
      legendEl.appendChild(b);
    });
  }

  let cross=null;
  function hookHover(pts){
    const overlay=el("rect",{x:M.l,y:M.t,width:PW,height:PH,fill:"transparent",style:"cursor:crosshair;touch-action:none"});
    svg.appendChild(overlay);
    const wrap=svg.parentElement;                 // .chartwrap
    const rect=()=>svg.getBoundingClientRect();
    function showAt(clientX,clientY){
      const r=rect(), sx=(clientX-r.left)/r.width*W, sy=(clientY-r.top)/r.height*H;
      let best=null,bd=1e9;
      pts.forEach(p=>{const d=(p.x-sx)**2+(p.y-sy)**2; if(d<bd){bd=d;best=p;}});
      if(!best||bd>80*80){hide();return;}
      if(cross) cross.remove();
      cross=el("line",{class:"crosshair",x1:best.x,x2:best.x,y1:M.t,y2:M.t+PH});
      svg.insertBefore(cross,overlay);
      const rr=rect();
      const cx=best.x/W*rr.width, cy=best.y/H*rr.height;
      tip.style.left=cx+"px"; tip.style.top=cy+"px"; tip.style.transform='translate(-50%,-112%)';
      const head='<div class="t-af"><span class="sw" style="background:'+best.color+'"></span>'+best.name+'</div>';
      let val;
      if(best.metric==='tijd'){
        val='<div class="t-rang">'+fmtTime(best.pt.t)+'</div><div class="t-sub">'+(best.pt.tr?best.pt.tr+' · ':'')+best.pt.c+' · '+fmt(best.pt.d)+'</div>';
      } else {
        const dispR=rangOf(best.pt);
        const nlNote=(nlOnly && best.pt.rn!=null && best.pt.rn!==best.pt.r)
          ? '<div class="t-nl">alleen NL · internationaal '+best.pt.r+'e</div>' : '';
        val='<div class="t-rang">'+dispR+'<span style="font-size:.9rem">e</span> plek</div><div class="t-sub">'+best.pt.c+' · '+fmt(best.pt.d)+'</div>'+nlNote;
      }
      tip.innerHTML=head+val+'<div class="t-comp">'+best.pt.w+'</div>';
      tip.style.opacity=1;
      // Binnen beeld houden: horizontaal klemmen + verticaal flippen als de
      // tooltip boven de grafiek uit zou steken (mobiel/randpunten).
      const wr=wrap.getBoundingClientRect(); let tr=tip.getBoundingClientRect();
      let dx=0;
      if(tr.left < wr.left+6)      dx=(wr.left+6)-tr.left;
      else if(tr.right > wr.right-6) dx=(wr.right-6)-tr.right;
      if(dx) tip.style.left=(cx+dx)+"px";
      tr=tip.getBoundingClientRect();
      if(tr.top < wr.top+4) tip.style.transform='translate(-50%,14%)';
    }
    function hide(){tip.style.opacity=0; if(cross){cross.remove();cross=null;}}
    overlay.addEventListener("mousemove",e=>showAt(e.clientX,e.clientY));
    overlay.addEventListener("mouseleave",hide);
    overlay.addEventListener("touchstart",e=>{const t=e.touches[0]; if(t){showAt(t.clientX,t.clientY); e.preventDefault();}},{passive:false});
    overlay.addEventListener("touchmove", e=>{const t=e.touches[0]; if(t){showAt(t.clientX,t.clientY); e.preventDefault();}},{passive:false});
  }

  function buildPR(){
    const tb=document.querySelector('#prtable tbody');
    const all={...DATA.sprint,...DATA.lang};
    const order=[...Object.keys(DATA.sprint),...Object.keys(DATA.lang)];
    if(!order.length){ tb.innerHTML='<tr><td colspan="3" class="pr-none">Nog geen uitslagen.</td></tr>'; return; }
    tb.innerHTML=order.map((name,idx)=>{
      const s=all[name], timed=s.p.filter(p=>p.t!=null);
      let tijd='<span class="pr-none">—</span>';
      if(timed.length){ const bt=timed.reduce((a,b)=>b.t<a.t?b:a);
        tijd='<span class="pr-big">'+fmtTime(bt.t)+'</span>'+
          '<span class="pr-ctx">'+(bt.tr?bt.tr+' · ':'')+bt.w+' · '+fmt(bt.d)+'</span>'; }
      const bp=s.p.reduce((a,b)=>b.r<a.r?b:a);
      return '<tr class="pr-row" data-i="'+idx+'"><td><span class="pr-af"><span class="sw" style="background:'+s.color+'"></span>'+name+'</span></td>'+
        '<td>'+tijd+'</td>'+
        '<td><span class="pr-big">'+bp.r+'e</span>'+
          '<span class="pr-ctx">'+bp.c+' · '+bp.w+' · '+fmt(bp.d)+'</span>'+
          '<span class="pr-more">details ›</span></td></tr>';
    }).join('');
    // Rij aantikbaar → info-box (vooral mobiel, waar de context-regel verborgen is).
    tb.querySelectorAll('.pr-row').forEach(row=>{
      row.addEventListener('click',()=>{
        const name=order[+row.dataset.i], s=all[name];
        const timed=s.p.filter(p=>p.t!=null);
        const bt=timed.length?timed.reduce((a,b)=>b.t<a.t?b:a):null;
        const bp=s.p.reduce((a,b)=>b.r<a.r?b:a);
        let h='<div class="prpop-af"><span class="sw" style="background:'+s.color+'"></span>'+name+'</div>';
        if(bt) h+='<div class="prp-blok"><div class="prp-lbl">Beste tijd</div><div class="prp-big">'+fmtTime(bt.t)+'</div><div class="prp-sub">'+(bt.tr?bt.tr+' · ':'')+bt.w+' · '+fmt(bt.d)+'</div></div>';
        h+='<div class="prp-blok"><div class="prp-lbl">Beste klassering</div><div class="prp-big">'+bp.r+'e</div><div class="prp-sub">'+bp.c+' · '+bp.w+' · '+fmt(bp.d)+'</div></div>';
        toonInfoPop(h);
      });
    });
  }

  function toonInfoPop(html){
    let ov=document.getElementById('prpop');
    if(!ov){ ov=document.createElement('div'); ov.id='prpop'; ov.className='prpop'; document.body.appendChild(ov); }
    ov.innerHTML='<div class="prpop-box">'+html+'<button class="prpop-sluit" type="button">Sluiten</button></div>';
    ov.style.display='flex';
    const sluit=()=>{ ov.style.display='none'; };
    ov.onclick=e=>{ if(e.target===ov) sluit(); };
    ov.querySelector('.prpop-sluit').onclick=sluit;
  }

  function fmtTime(ms){ ms=Math.round(ms); const s=Math.floor(ms/1000), m=Math.floor(s/60), sec=s%60, mmm=String(ms%1000).padStart(3,'0'); return m>0 ? m+':'+String(sec).padStart(2,'0')+'.'+mmm : sec+'.'+mmm; }
  function fmt(d){const [y,m,dd]=d.split("-"); const mn=["jan","feb","mrt","apr","mei","jun","jul","aug","sep","okt","nov","dec"]; return dd+" "+mn[+m-1]+" "+y;}

  function setGroup(g){
    if(leeg(g)) return;
    group=g; hidden.clear(); yMetric='rang';
    btnSprint.setAttribute("aria-pressed",g==='sprint');
    btnLang.setAttribute("aria-pressed",g==='lang');
    render();
  }
  btnSprint.onclick=()=>setGroup('sprint');
  btnLang.onclick=()=>setGroup('lang');
  btnRang.onclick=()=>{ yMetric='rang'; render(); };
  btnTijd.onclick=()=>{ yMetric='tijd'; render(); };
  nlCheck.onchange=()=>{ nlOnly=nlCheck.checked; render(); };

  btnSprint.setAttribute("aria-pressed", group==='sprint');
  btnLang.setAttribute("aria-pressed", group==='lang');
  render(); buildPR();
})();
