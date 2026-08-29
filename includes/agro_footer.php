</div><!-- /app -->
<aside class="drawer" id="drawer" aria-hidden="true">
  <div class="dh">
    <div class="tp">
      <div><div class="code" id="dCode"></div><h3 id="dName"></h3><div class="mt" id="dMeta"></div></div>
      <button class="xb" onclick="closeDrawer()" aria-label="Fechar"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 6l12 12M18 6 6 18"/></svg></button>
    </div>
    <div class="dmet" id="dMet"></div>
  </div>
  <div class="db"><div class="sl">Linha do tempo do lote</div><div class="ledger" id="dLedger"></div></div>
</aside>
<script>
/* ===== dados + mini-renderer das listas do Dashboard (sc-for) ===== */
var DC=(function(){
 var vals=[212,238,250,265,232,292,272,266,236,250,262,246],max=300,
  labels=['JAN','FEV','MAR','ABR','MAI','JUN','JUL','AGO','SET','OUT','NOV','DEZ'];
 var months=vals.map(function(v,i){return {label:labels[i],h:(v/max*100).toFixed(1)+'%'};});
 var prod=[{id:'3C',val:'99.000',w:'100%'},{id:'2B',val:'98.000',w:'99%'},{id:'4C',val:'81.000',w:'82%'},{id:'1A',val:'64.000',w:'65%'}];
 var up={mFg:'#0E7E72',mBg:'#DDEDEB'};
 var rows=[
  Object.assign({id:'3C',color:'#005059',crop:'Soja',area:'420 ha',yield:'72 sc/ha',cost:'R$ 4.180',rev:'R$ 6.900',margin:'39,4%'},up),
  Object.assign({id:'2B',color:'#00363D',crop:'Milho',area:'380 ha',yield:'168 sc/ha',cost:'R$ 3.950',rev:'R$ 6.200',margin:'36,3%'},up),
  {id:'4C',color:'#4E9CA1',crop:'Soja',area:'310 ha',yield:'68 sc/ha',cost:'R$ 4.320',rev:'R$ 6.500',margin:'33,5%',mFg:'#7A5410',mBg:'#FCF3E2'},
  {id:'1A',color:'#A7C9CB',crop:'Algodão',area:'260 ha',yield:'280 @/ha',cost:'R$ 5.100',rev:'R$ 7.800',margin:'34,6%',mFg:'#7A5410',mBg:'#FCF3E2'}
 ];
 return {months:months,prod:prod,rows:rows};
})();
document.querySelectorAll('sc-for[list][as]').forEach(function(el){
 var lm=el.getAttribute('list').match(/\{\{\s*(\w+)\s*\}\}/); if(!lm) return;
 var data=DC[lm[1]]||[], as=el.getAttribute('as'), tpl=el.innerHTML;
 var rx=new RegExp('\\{\\{\\s*'+as+'\\.(\\w+)\\s*\\}\\}','g');
 el.outerHTML=data.map(function(it){return tpl.replace(rx,function(_,k){return it[k]!=null?it[k]:'';});}).join('');
});

/* ===== formatadores + helpers DOM ===== */
var BRL=new Intl.NumberFormat('pt-BR',{style:'currency',currency:'BRL'});
var NUM=new Intl.NumberFormat('pt-BR');
function brl(v){return BRL.format(Math.round(v));}
function setTxt(id,t){var e=document.getElementById(id); if(e) e.textContent=t;}
function setHTML(id,h){var e=document.getElementById(id); if(e) e.innerHTML=h;}
function setW(id,p){var e=document.getElementById(id); if(e) e.style.width=p+'%';}
function empty(msg){return '<div style="padding:12px 4px;color:var(--muted2);font-size:12.5px">'+msg+'</div>';}

/* ===== filtro global (fazenda + safra) ===== */
var FILTER={faz:'boa-vista',safra:'2025-26'};

/* ===== rebanho ===== */
var CATCOLOR={'Bezerro(a)':'#4E9CA1','Garrote':'#005059','Boi gordo':'#00363D','Vaca':'#B57C1A','Novilha':'#C3B49E'};
var lotes=[
 {id:1,faz:'boa-vista',safra:'2025-26',code:'LOTE-2026-001',nome:'Recria · 1ª safra · Válvula 3C',cat:'Garrote',talhao:'Válvula 3C',cab:180,custoCab:2480,peso:312,gmd:0.72,status:'ativo'},
 {id:2,faz:'boa-vista',safra:'2025-26',code:'LOTE-2026-002',nome:'Engorda · terminação · Válvula 4C',cat:'Boi gordo',talhao:'Válvula 4C',cab:95,custoCab:3910,peso:498,gmd:0.58,status:'ativo'},
 {id:3,faz:'boa-vista',safra:'2025-26',code:'LOTE-2026-003',nome:'Cria · bezerrada · Válvula 2B',cat:'Bezerro(a)',talhao:'Válvula 2B',cab:60,custoCab:1150,peso:168,gmd:0.84,status:'ativo'},
 {id:4,faz:'boa-vista',safra:'2025-26',code:'LOTE-2026-004',nome:'Matrizes · Válvula 1A',cat:'Vaca',talhao:'Válvula 1A',cab:120,custoCab:2760,peso:430,gmd:0.21,status:'ativo'},
 {id:5,faz:'santa-helena',safra:'2025-26',code:'LOTE-2026-101',nome:'Cria · Válvula SH-2',cat:'Bezerro(a)',talhao:'Válvula SH-2',cab:110,custoCab:1090,peso:160,gmd:0.80,status:'ativo'},
 {id:6,faz:'santa-helena',safra:'2025-26',code:'LOTE-2026-102',nome:'Recria · Válvula SH-4',cat:'Garrote',talhao:'Válvula SH-4',cab:140,custoCab:2350,peso:300,gmd:0.70,status:'ativo'},
 {id:7,faz:'santa-helena',safra:'2024-25',code:'LOTE-2025-090',nome:'Engorda · Válvula SH-1',cat:'Boi gordo',talhao:'Válvula SH-1',cab:70,custoCab:3780,peso:486,gmd:0.55,status:'ativo'}
];
function fLotes(){return lotes.filter(function(l){return (FILTER.faz==='all'||l.faz===FILTER.faz)&&(FILTER.safra==='all'||l.safra===FILTER.safra);});}
function inFilter(id){return fLotes().some(function(l){return l.id===id;});}
var byId=function(id){return lotes.find(function(l){return l.id===id;});};

var events=[
 {lote:1,date:'18/06',kind:'entrada',title:'Compra de garrotes',sub:'+40 cab · Fazenda São Jorge',am:'+ '+brl(96000),tags:[['pagar','a pagar '+brl(96000)],['custo','custo no lote']]},
 {lote:2,date:'15/06',kind:'saida',title:'Venda de bois',sub:'−22 cab · 320/@ · Frigorífico Vale',am:'+ '+brl(233728),tags:[['receber','a receber '+brl(233728)],['custo','baixa CMV '+brl(86020)]]},
 {lote:3,date:'12/06',kind:'sanidade',title:'Vacinação — Aftosa',sub:'60 cab · Vacina Aftosa',am:'− '+brl(540),tags:[['estoque','baixa 60 doses'],['custo','custo no lote']]},
 {lote:1,date:'10/06',kind:'pesagem',title:'Pesagem amostral',sub:'30 cab · 312,0 kg médio',am:'GMD 0,72',tags:[]},
 {lote:4,date:'05/06',kind:'entrada',title:'Nascimento',sub:'+8 bezerros',am:'+ 8 cab',tags:[['custo','custo 0']]},
 {lote:1,date:'02/06',kind:'transfer',title:'Transferência recebida',sub:'+15 cab · de LOTE-2026-003',am:'+15 cab',tags:[['custo','custo transferido']]},
 {lote:1,date:'28/05',kind:'sanidade',title:'Vermifugação',sub:'165 cab · Ivermectina 1%',am:'− '+brl(380),tags:[['estoque','baixa estoque'],['custo','custo no lote']]},
 {lote:4,date:'20/05',kind:'entrada',title:'Compra de matrizes',sub:'+30 cab · Leilão MOC',am:'+ '+brl(81000),tags:[['pagar','a pagar '+brl(81000)],['custo','custo no lote']]},
 {lote:6,date:'14/06',kind:'sanidade',title:'Vacinação — Clostridial',sub:'140 cab · Vacina Clostridial',am:'− '+brl(1050),tags:[['estoque','baixa estoque'],['custo','custo no lote']]},
 {lote:5,date:'09/06',kind:'entrada',title:'Compra de bezerros',sub:'+110 cab · Fazenda Riacho',am:'+ '+brl(119900),tags:[['pagar','a pagar '+brl(119900)],['custo','custo no lote']]}
];
var pesagens=[
 {lote:1,date:'10/06',title:'LOTE-2026-001 · amostral (30)',sub:'312,0 kg médio',am:'GMD 0,72'},
 {lote:2,date:'08/06',title:'LOTE-2026-002 · amostral (20)',sub:'498,0 kg médio',am:'GMD 0,58'},
 {lote:3,date:'01/06',title:'LOTE-2026-003 · total',sub:'168,0 kg médio',am:'GMD 0,84'},
 {lote:4,date:'25/05',title:'LOTE-2026-004 · amostral (25)',sub:'430,0 kg médio',am:'GMD 0,21'},
 {lote:6,date:'07/06',title:'LOTE-2026-102 · amostral (35)',sub:'300,0 kg médio',am:'GMD 0,70'},
 {lote:7,date:'29/05',title:'LOTE-2025-090 · amostral (20)',sub:'486,0 kg médio',am:'GMD 0,55'}
];
var sanidade=[
 {lote:3,date:'12/06',title:'Vacinação · Aftosa',sub:'LOTE-2026-003 · 60 cab',am:brl(540)},
 {lote:1,date:'28/05',title:'Vermifugação · Ivermectina 1%',sub:'LOTE-2026-001 · 165 cab',am:brl(380)},
 {lote:2,date:'15/05',title:'Vacinação · Clostridial',sub:'LOTE-2026-002 · 95 cab',am:brl(712)},
 {lote:6,date:'14/06',title:'Vacinação · Clostridial',sub:'LOTE-2026-102 · 140 cab',am:brl(1050)}
];

function row(l){return '<div class="trow" onclick="openDrawer('+l.id+')">'
 +'<div><div class="code">'+l.code+'</div><div class="rname">'+l.nome+'</div></div>'
 +'<div><span class="tag cat">'+l.cat+'</span></div>'
 +'<div class="cell col-h">'+NUM.format(l.cab)+'</div>'
 +'<div class="cell">'+brl(l.custoCab)+'</div>'
 +'<div class="cell col-h">'+l.peso+' kg · '+l.gmd.toFixed(2).replace('.',',')+'</div>'
 +'<div class="chev"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg></div></div>';}

function ledger(list){return list.map(function(e){
 var tg=(e.tags||[]).map(function(t){
   var p='<rect x="3" y="6" width="18" height="12" rx="2"/><circle cx="12" cy="12" r="2.4"/>';
   if(t[0]==='estoque') p='<path d="M21 8 12 3 3 8l9 5 9-5Z"/>';
   if(t[0]==='custo') p='<rect x="5" y="3" width="14" height="18" rx="2"/><path d="M8 7h8"/>';
   return '<span class="tg '+t[0]+'"><svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">'+p+'</svg>'+t[1]+'</span>';}).join('');
 return '<div class="le"><span class="d dot-'+e.kind+'"></span><span class="when">'+e.date+'</span>'
   +'<div><div class="ti">'+e.title+'</div><div class="su">'+e.sub+'</div>'+(tg?'<div class="tags">'+tg+'</div>':'')+'</div>'
   +'<span class="am">'+e.am+'</span></div>';}).join('');}

function simple(list,kind){return list.map(function(e){return '<div class="le"><span class="d dot-'+kind+'"></span><span class="when">'+e.date+'</span>'
 +'<div><div class="ti">'+e.title+'</div><div class="su">'+e.sub+'</div></div><span class="am">'+e.am+'</span></div>';}).join('');}

function render(){
 var L=fLotes();
 var tc=L.reduce(function(s,l){return s+l.cab;},0);
 var ta=L.reduce(function(s,l){return s+l.cab*l.custoCab;},0);
 var pkg=L.reduce(function(s,l){return s+l.cab*l.peso;},0);
 var pmed=tc?pkg/tc:0, gmd=tc?L.reduce(function(s,l){return s+l.cab*l.gmd;},0)/tc:0;
 var arr=pkg/15, custoArr=arr?ta/arr:0, metaArr=178, pct=Math.min(100,Math.round(custoArr/metaArr*100));
 setTxt('m-cab',NUM.format(tc));
 setHTML('m-ativo','R$ '+(ta/1e6).toFixed(2).replace('.',',')+'<small>mi</small>');
 setHTML('m-peso',Math.round(pmed)+'<small>kg</small>');
 setTxt('m-gmd','GMD '+gmd.toFixed(2).replace('.',','));
 setHTML('m-custoarr',Math.round(custoArr)+'<small>/@</small>');
 setTxt('m-custoarr-sub',pct+'% da meta de custo'); setW('m-custoarr-prog',pct);

 var rh=L.length?L.map(row).join(''):empty('Nenhum lote para esta seleção.');
 setHTML('lotesAtivos',rh); setHTML('lotesTodos',rh);
 setTxt('lotes-count',L.length+(L.length===1?' lote · ':' lotes · ')+NUM.format(tc)+' cabeças');

 var bar='',leg='',byCat={};
 L.slice().sort(function(a,b){return b.cab-a.cab;}).forEach(function(l){bar+='<span style="width:'+(tc?(l.cab/tc*100).toFixed(1):0)+'%;background:'+(CATCOLOR[l.cat]||'#C3B49E')+'" title="'+l.cat+'"></span>';});
 L.forEach(function(l){byCat[l.cat]=(byCat[l.cat]||0)+l.cab;});
 Object.keys(byCat).forEach(function(c){leg+='<div class="r"><span class="sw" style="background:'+(CATCOLOR[c]||'#C3B49E')+'"></span>'+c+'<span class="n">'+NUM.format(byCat[c])+' cab</span></div>';});
 setHTML('compbar',bar||''); setHTML('leg',leg||empty('—'));

 var ev=events.filter(function(e){return inFilter(e.lote);}).sort(function(a,b){return 0;});
 setHTML('movLedger',ev.length?ledger(ev):empty('Nenhuma movimentação no período para esta seleção.'));
 var ps=pesagens.filter(function(e){return inFilter(e.lote);});
 setHTML('pesLedger',ps.length?simple(ps,'pesagem'):empty('Nenhuma pesagem para esta seleção.'));
 var sn=sanidade.filter(function(e){return inFilter(e.lote);});
 setHTML('sanLedger',sn.length?simple(sn,'sanidade'):empty('Nenhum evento para esta seleção.'));

 var opts=L.map(function(l){return '<option value="'+l.id+'">'+l.code+' · '+l.cat+'</option>';}).join('')||'<option value="">—</option>';
 ['mLote','pLote','sLote','mDest'].forEach(function(id){var s=document.getElementById(id); if(s) s.innerHTML=opts;});
}

/* ===== navegação por tabs (Pecuária) ===== */
var titles={rebanho:'Rebanho',lotes:'Lotes',mov:'Movimentações',pesagem:'Pesagem',sanidade:'Sanidade'};
function go(scr){
 document.querySelectorAll('[data-screen]').forEach(function(s){s.classList.toggle('active',s.dataset.screen===scr);});
 document.querySelectorAll('#tabs .tab').forEach(function(b){b.classList.toggle('active',b.dataset.scr===scr);});
 setTxt('crumb','Pecuária · '+(titles[scr]||''));
 var c=document.querySelector('[data-view-main="pecuaria"] .content'); if(c) c.scrollIntoView({block:'start',behavior:'smooth'});
}
function goForm(t){go('mov'); setMovType(t);}
var tabsEl=document.getElementById('tabs'); if(tabsEl) tabsEl.addEventListener('click',function(e){var b=e.target.closest('.tab'); if(b) go(b.dataset.scr);});

/* ===== drawer do lote ===== */
function openDrawer(id){
 var l=byId(id); if(!l) return; var ac=l.cab*l.custoCab;
 setTxt('dCode',l.code); setTxt('dName',l.nome.split(' · ')[0]);
 setHTML('dMeta','<span>'+l.talhao+'</span><span>·</span><span>'+l.cat+'</span><span class="tag ativo">'+l.status+'</span>');
 setHTML('dMet',met('Cabeças',NUM.format(l.cab))+met('Peso médio',l.peso+' kg')+met('GMD',l.gmd.toFixed(2).replace('.',',')+' kg/d')
  +met('Custo/cab.',brl(l.custoCab))+met('Custo acum.',brl(ac))+met('Ativo biol.',brl(ac)));
 var ev=events.filter(function(e){return e.lote===id;});
 setHTML('dLedger',ev.length?ledger(ev):'<div class="su" style="padding:8px 0">Sem eventos para este lote.</div>');
 document.getElementById('scrim').classList.add('open');
 var d=document.getElementById('drawer'); d.classList.add('open'); d.setAttribute('aria-hidden','false');
}
function met(l,v){return '<div><div class="l">'+l+'</div><div class="v">'+v+'</div></div>';}
function closeDrawer(){document.getElementById('scrim').classList.remove('open');var d=document.getElementById('drawer');d.classList.remove('open');d.setAttribute('aria-hidden','true');}
document.addEventListener('keydown',function(e){if(e.key==='Escape') closeDrawer();});

/* ===== formulário de movimentação ===== */
var movType='entrada_compra';
var MM={
 entrada_compra:{price:'Valor por cabeça (R$)',party:'Fornecedor',party2:1,dest:0,valor:1,fx:'<b>Compra.</b> Ao salvar, gera um título em <b>Contas a pagar</b> e lança o <b>custo de aquisição no lote</b> (custeio). Origem: <span class="mono">pecuaria_movimentacao</span>.'},
 saida_venda:{price:'Valor por arroba (R$)',party:'Cliente / frigorífico',party2:1,dest:0,valor:1,fx:'<b>Venda.</b> Gera <b>Contas a receber</b>, reconhece a receita e <b>baixa o CMV</b> proporcional. Resultado entra na DRE.'},
 entrada_transferencia:{party2:0,dest:1,valor:0,fx:'<b>Transferência interna.</b> Sem efeito financeiro. Move cabeças entre lotes e <b>transfere o custo acumulado</b> proporcional.'},
 entrada_nascimento:{party2:0,dest:0,valor:0,fx:'<b>Nascimento.</b> Sem financeiro. Soma cabeças na categoria Bezerro(a) com <b>custo zero</b> (ou custo estimado, se configurado).'}
};
function setMovType(t){
 movType=t;
 document.querySelectorAll('#movType button').forEach(function(b){b.classList.toggle('on',b.dataset.t===t);});
 var m=MM[t];
 document.querySelectorAll('[data-when="valor"]').forEach(function(el){el.classList.toggle('hidden',!m.valor);});
 document.querySelectorAll('[data-when="destino"]').forEach(function(el){el.classList.toggle('hidden',!m.dest);});
 var pf=document.getElementById('mPartyField');
 if(m.party2){pf.classList.remove('hidden');setTxt('mPartyLab',m.party);} else pf.classList.add('hidden');
 if(m.price) setTxt('mPriceLab',m.price);
 setHTML('mFxText',m.fx);
 recalc();
}
var mtEl=document.getElementById('movType'); if(mtEl) mtEl.addEventListener('click',function(e){var b=e.target.closest('button'); if(b) setMovType(b.dataset.t);});
function recalc(){
 var q=+document.getElementById('mQtd').value||0, p=+document.getElementById('mPeso').value||0, vu=+document.getElementById('mVU').value||0, base=document.getElementById('mBase').value;
 document.getElementById('mPesoMed').value=q?((p/q).toFixed(1).replace('.',',')+' kg'):'—';
 var t=base==='cabeca'?vu*q:base==='arroba'?vu*(p/15):vu*p;
 document.getElementById('mTotal').value=brl(t);
}
['mQtd','mPeso','mVU','mBase'].forEach(function(id){var e=document.getElementById(id); if(e) e.addEventListener('input',recalc);});
var msv=document.getElementById('mSave'); if(msv) msv.addEventListener('click',function(){
 var sel=document.getElementById('mLote'); var code=sel.options.length?sel.options[sel.selectedIndex].text.split(' · ')[0]:'lote';
 var M={entrada_compra:['Compra registrada','título de '+document.getElementById('mTotal').value+' em Contas a pagar · custo atribuído ao '+code],
  saida_venda:['Venda registrada','título de '+document.getElementById('mTotal').value+' em Contas a receber · CMV baixado do '+code],
  entrada_transferencia:['Transferência registrada','cabeças e custo movidos entre lotes'],
  entrada_nascimento:['Nascimento registrado','cabeças somadas ao '+code]};
 toast(M[movType][0],M[movType][1]);
});

/* ===== Patrimônio ===== */
var PAT={
 items:[
  {faz:'boa-vista',nome:'Colheitadeira CR 5.85',cat:'Maquinário',aquis:950000,atual:712000,dep:'25%',manut:'12/07/2026',due:0},
  {faz:'boa-vista',nome:'Pulverizador autopropelido',cat:'Maquinário',aquis:620000,atual:489000,dep:'21%',manut:'28/06/2026',due:1},
  {faz:'boa-vista',nome:'Trator John Deere 6110J',cat:'Maquinário',aquis:380000,atual:284000,dep:'25%',manut:'05/08/2026',due:0},
  {faz:'boa-vista',nome:'Plantadeira 36 linhas',cat:'Maquinário',aquis:280000,atual:200000,dep:'29%',manut:'—',due:0},
  {faz:'boa-vista',nome:'Armazém de grãos',cat:'Benfeitoria',aquis:420000,atual:388000,dep:'—',manut:'—',due:0},
  {faz:'boa-vista',nome:'Barracão de máquinas',cat:'Benfeitoria',aquis:180000,atual:162000,dep:'—',manut:'—',due:0},
  {faz:'boa-vista',nome:'Curral e brete',cat:'Benfeitoria',aquis:95000,atual:62000,dep:'—',manut:'20/06/2026',due:1},
  {faz:'boa-vista',nome:'Caminhão graneleiro',cat:'Veículo',aquis:340000,atual:238000,dep:'30%',manut:'—',due:0},
  {faz:'boa-vista',nome:'Caminhonete L200',cat:'Veículo',aquis:210000,atual:160000,dep:'24%',manut:'—',due:0},
  {faz:'santa-helena',nome:'Trator Massey 4292',cat:'Maquinário',aquis:320000,atual:245000,dep:'23%',manut:'18/07/2026',due:0},
  {faz:'santa-helena',nome:'Galpão sede',cat:'Benfeitoria',aquis:260000,atual:240000,dep:'—',manut:'—',due:0},
  {faz:'santa-helena',nome:'Caminhonete Hilux',cat:'Veículo',aquis:230000,atual:178000,dep:'23%',manut:'—',due:0}
 ],
 catColor:{'Maquinário':'#4E9CA1','Benfeitoria':'#005059','Veículo':'#A7C9CB','Rebanho':'#2A767C'},
 alerts:[
  {faz:'boa-vista',t:'Pulverizador autopropelido',s:'Revisão de bicos e pressão',d:'28/06 · em 8 dias',due:0},
  {faz:'boa-vista',t:'Curral e brete',s:'Manutenção de cercas e porteiras',d:'20/06 · hoje',due:1},
  {faz:'boa-vista',t:'Colheitadeira CR 5.85',s:'Preparação pré-safra',d:'12/07 · em 22 dias',due:0},
  {faz:'santa-helena',t:'Trator Massey 4292',s:'Troca de óleo e filtros',d:'18/07 · em 28 dias',due:0}
 ]
};
function fItems(){return PAT.items.filter(function(i){return FILTER.faz==='all'||i.faz===FILTER.faz;});}
function fAlerts(){return PAT.alerts.filter(function(a){return FILTER.faz==='all'||a.faz===FILTER.faz;});}
function pmi(v){return 'R$ '+(v/1e6).toFixed(2).replace('.',',');}
function pSet(id,v){setHTML(id,pmi(v)+'<small>mi</small>');}
function pCatRow(name,sub,val,pct,bio){
 return '<div class="pcat-r'+(bio?' bio':'')+'">'
  +'<div><span class="swdot" style="background:'+PAT.catColor[name]+'"></span><span style="font-weight:'+(bio?'600':'500')+';color:var(--ink2)">'+name+'</span>'+(bio?' <span class="pkpi-tag" style="margin-left:6px">Ativo rural</span>':'')+'</div>'
  +'<div class="cell" style="color:var(--muted)">'+sub+'</div>'
  +'<div class="cell">'+brl(val)+'</div>'
  +'<div class="cell" style="color:var(--muted)">'+(pct*100).toFixed(1).replace('.',',')+'%</div></div>';
}
function renderPat(){
 var L=fLotes(); var It=fItems();
 var tc=L.reduce(function(s,l){return s+l.cab;},0);
 var bio=L.reduce(function(s,l){return s+l.cab*l.custoCab;},0);
 var byCat={}; It.forEach(function(it){byCat[it.cat]=(byCat[it.cat]||0)+it.atual;});
 var imob=Object.keys(byCat).reduce(function(s,k){return s+byCat[k];},0);
 var total=imob+bio||1;
 pSet('p-total',imob+bio); pSet('p-imob',imob); pSet('p-bio',bio);
 setTxt('p-bio-cab',NUM.format(tc)+' cabeças · do custeio');
 setTxt('p-bio-pct',Math.round(bio/total*100)+'% do total');
 setTxt('p-bio-lotecount',L.length+(L.length===1?' lote ativo':' lotes ativos')+' · atualizado por movimentação/pesagem');

 var html='';
 ['Maquinário','Benfeitoria','Veículo'].forEach(function(c){
   var n=It.filter(function(i){return i.cat===c;}).length;
   if(n) html+=pCatRow(c,n+(n>1?' itens':' item'),byCat[c],byCat[c]/total,false);
 });
 html+=pCatRow('Rebanho',NUM.format(tc)+' cabeças',bio,bio/total,true);
 setHTML('p-cat',html);

 var comp=[['Maquinário',byCat['Maquinário']||0],['Rebanho',bio],['Benfeitoria',byCat['Benfeitoria']||0],['Veículo',byCat['Veículo']||0]];
 var bar='',leg='';
 comp.forEach(function(c){ if(!c[1]) return;
   bar+='<span style="width:'+(c[1]/total*100).toFixed(1)+'%;background:'+PAT.catColor[c[0]]+'" title="'+c[0]+'"></span>';
   leg+='<div class="r"><span class="sw" style="background:'+PAT.catColor[c[0]]+'"></span>'+c[0]+'<span class="n">'+brl(c[1])+'</span></div>';});
 setHTML('p-comp',bar); setHTML('p-leg',leg);

 var bl=L.map(function(l){return '<div class="pcat-r" style="grid-template-columns:1.4fr 1fr .7fr 1fr"><div class="code">'+l.code+'</div><div><span class="tag cat">'+l.cat+'</span></div><div class="cell">'+NUM.format(l.cab)+'</div><div class="cell">'+brl(l.cab*l.custoCab)+'</div></div>';}).join('');
 bl=(bl||empty('Sem lotes nesta seleção.'))+'<div class="pcat-r" style="grid-template-columns:1.4fr 1fr .7fr 1fr;border-top:1px solid var(--border);border-bottom:none"><div style="color:var(--ink2);font-weight:600">Total · ativo biológico</div><div></div><div class="cell" style="font-weight:600">'+NUM.format(tc)+'</div><div class="cell" style="color:var(--accent);font-weight:600">'+brl(bio)+'</div></div>';
 setHTML('p-bio-lotes',bl);

 setHTML('p-items',It.map(function(it){
   var m=it.manut==='—'?'<span style="color:var(--faint)">—</span>':('<span'+(it.due?' style="color:var(--danger);font-weight:600"':'')+'>'+it.manut+'</span>');
   return '<div class="pit-r"><div style="font-weight:500;color:var(--ink2)">'+it.nome+'</div><div><span class="tag cat" style="background:#E0EFEF;color:#1E5A60">'+it.cat+'</span></div><div class="cell">'+brl(it.aquis)+'</div><div class="cell">'+brl(it.atual)+'</div><div class="cell" style="color:var(--muted)">'+it.dep+'</div><div class="cell">'+m+'</div></div>';
 }).join(''));

 var al=fAlerts();
 setHTML('p-alerts',al.length?al.map(function(a){
   return '<div class="alert-row"><div class="alert-ic'+(a.due?' due':'')+'"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 9v4M12 17h.01M10.3 3.9 2 18a2 2 0 0 0 1.7 3h16.6a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z"/></svg></div><div style="flex:1"><div style="font-weight:600;font-size:13px;color:var(--ink2)">'+a.t+'</div><div style="font-size:11.5px;color:var(--muted)">'+a.s+'</div></div><div style="font-family:var(--num);font-size:11px;color:'+(a.due?'var(--danger)':'var(--muted2)')+';white-space:nowrap">'+a.d+'</div></div>';
 }).join(''):empty('Nenhum alerta para esta seleção.'));
}

/* ===== troca de view + filtros ===== */
function showView(v){
 document.querySelectorAll('a.nv-link').forEach(function(x){x.classList.toggle('nv-on',x.getAttribute('data-view')===v);});
 document.querySelectorAll('[data-view-main]').forEach(function(m){m.style.display=(m.getAttribute('data-view-main')===v)?'flex':'none';});
 window.scrollTo({top:0,behavior:'smooth'});
}
function applyFilter(){render(); renderPat(); renderFazendas(); renderSafras(); renderAtividades(); renderColheita(); renderEstoque(); renderCompras(); renderMaquinas(); renderFiscal(); renderMIP(); renderContratos(); renderCusteio(); renderFinanceiro();}
function wireSel(cls,key){
 document.querySelectorAll(cls).forEach(function(s){
   s.value=FILTER[key];
   s.addEventListener('change',function(){FILTER[key]=s.value; document.querySelectorAll(cls).forEach(function(x){x.value=FILTER[key];}); applyFilter();});
 });
}
wireSel('.f-fazenda','faz'); wireSel('.f-safra','safra');

document.querySelectorAll('a.nv-link').forEach(function(a){a.addEventListener('click',function(e){e.preventDefault();showView(a.getAttribute('data-view'));});});
/* [VERO Agro] handler de navegação do protótipo removido — menu agora é multi-página com links reais (includes/sidebar.php) */

/* ===== toast ===== */
var tt;
function toast(t,s){setHTML('toastText','<b>'+t+'</b><div style="opacity:.85;font-size:12px;margin-top:1px">'+s+'</div>');var el=document.getElementById('toast');el.classList.add('show');clearTimeout(tt);tt=setTimeout(function(){el.classList.remove('show');},4200);}

/* ===== Núcleo agrícola: dados ===== */
var FAZNAME={'boa-vista':'Boa Vista','santa-helena':'Santa Helena'};
var CULT_COLOR={'Soja':'#005059','Milho':'#B57C1A','Algodão':'#A7C9CB','Pastagem':'#4E9CA1'};
function fname(f){return FAZNAME[f]||f;}

var talhoes=[
 {faz:'boa-vista',code:'1A',area:260,cult:'Algodão',solo:'Latossolo'},
 {faz:'boa-vista',code:'2B',area:380,cult:'Milho',solo:'Latossolo'},
 {faz:'boa-vista',code:'3C',area:420,cult:'Soja',solo:'Latossolo'},
 {faz:'boa-vista',code:'4C',area:310,cult:'Soja',solo:'Argissolo'},
 {faz:'santa-helena',code:'SH-1',area:290,cult:'Soja',solo:'Latossolo'},
 {faz:'santa-helena',code:'SH-2',area:240,cult:'Milho',solo:'Latossolo'},
 {faz:'santa-helena',code:'SH-4',area:180,cult:'Pastagem',solo:'Neossolo'}
];
var safras=[
 {faz:'boa-vista',safra:'2025-26',nome:'Soja BV 25/26',cult:'Soja',talhoes:'3C, 4C',area:730,un:'sc 60kg',periodo:'out/25 – mar/26',status:'em colheita',prodEsp:51800},
 {faz:'boa-vista',safra:'2025-26',nome:'Milho BV 25/26',cult:'Milho',talhoes:'2B',area:380,un:'sc 60kg',periodo:'nov/25 – abr/26',status:'em andamento',prodEsp:63840},
 {faz:'boa-vista',safra:'2025-26',nome:'Algodão BV 25/26',cult:'Algodão',talhoes:'1A',area:260,un:'@',periodo:'dez/25 – jun/26',status:'em andamento',prodEsp:72800},
 {faz:'santa-helena',safra:'2025-26',nome:'Soja SH 25/26',cult:'Soja',talhoes:'SH-1',area:290,un:'sc 60kg',periodo:'out/25 – mar/26',status:'em andamento',prodEsp:20300},
 {faz:'santa-helena',safra:'2025-26',nome:'Milho SH 25/26',cult:'Milho',talhoes:'SH-2',area:240,un:'sc 60kg',periodo:'nov/25 – abr/26',status:'em andamento',prodEsp:40320},
 {faz:'boa-vista',safra:'2024-25',nome:'Soja BV 24/25',cult:'Soja',talhoes:'3C, 4C',area:730,un:'sc 60kg',periodo:'out/24 – mar/25',status:'colhida',prodEsp:50100}
];
function apam(v){return '− '+brl(v);}
var apont=[
 {faz:'boa-vista',safra:'2025-26',date:'16/06',kind:'sanidade',title:'Pulverização — Sivanto Prime',sub:'Válvula 3C · Soja · 420 ha',val:18900,horas:0,insumo:1,tags:[['estoque','baixa estoque'],['custo','custo na válvula']]},
 {faz:'boa-vista',safra:'2025-26',date:'10/06',kind:'entrada',title:'Adubação de cobertura — Ureia',sub:'Válvula 2B · Milho · 380 ha',val:42500,horas:0,insumo:1,tags:[['estoque','baixa estoque'],['custo','custo na válvula']]},
 {faz:'boa-vista',safra:'2025-26',date:'02/06',kind:'transfer',title:'Plantio — semente Soja',sub:'Válvula 4C · 310 ha',val:96100,horas:0,insumo:1,tags:[['estoque','baixa estoque'],['custo','custo na safra']]},
 {faz:'boa-vista',safra:'2025-26',date:'28/05',kind:'pesagem',title:'Preparo de solo — hora-máquina',sub:'Válvula 1A · 260 ha · 84 h',val:15200,horas:84,insumo:0,tags:[['custo','custo na válvula']]},
 {faz:'santa-helena',safra:'2025-26',date:'14/06',kind:'sanidade',title:'Pulverização — fungicida',sub:'Válvula SH-1 · Soja · 290 ha',val:13050,horas:0,insumo:1,tags:[['estoque','baixa estoque'],['custo','custo na válvula']]}
];
var colheitas=[
 {faz:'boa-vista',safra:'2025-26',code:'3C',cult:'Soja',area:420,prod:30240,rend:72,date:'18/06',dest:'Armazém'},
 {faz:'boa-vista',safra:'2025-26',code:'4C',cult:'Soja',area:310,prod:21080,rend:68,date:'15/06',dest:'Armazém'},
 {faz:'boa-vista',safra:'2025-26',code:'2B',cult:'Milho',area:380,prod:63840,rend:168,date:'12/06',dest:'Armazém'},
 {faz:'santa-helena',safra:'2025-26',code:'SH-1',cult:'Soja',area:290,prod:20300,rend:70,date:'16/06',dest:'Armazém SH'}
];
function byFaz(a){return a.filter(function(x){return FILTER.faz==='all'||x.faz===FILTER.faz;});}
function byFS(a){return a.filter(function(x){return (FILTER.faz==='all'||x.faz===FILTER.faz)&&(FILTER.safra==='all'||x.safra===FILTER.safra);});}
function compo(map,total,colorfn,unit){
 var bar='',leg='';
 Object.keys(map).sort(function(a,b){return map[b]-map[a];}).forEach(function(k){
   bar+='<span style="width:'+(total?(map[k]/total*100).toFixed(1):0)+'%;background:'+colorfn(k)+'" title="'+k+'"></span>';
   leg+='<div class="r"><span class="sw" style="background:'+colorfn(k)+'"></span>'+k+'<span class="n">'+NUM.format(map[k])+' '+unit+'</span></div>';});
 return [bar,leg];
}

function renderFazendas(){
 var T=byFaz(talhoes);
 var area=T.reduce(function(s,t){return s+t.area;},0);
 var cul={}; T.forEach(function(t){cul[t.cult]=(cul[t.cult]||0)+t.area;});
 var maior=T.reduce(function(m,t){return Math.max(m,t.area);},0);
 setTxt('fz-talhoes',T.length); setTxt('fz-area',NUM.format(area)); setTxt('fz-maior',NUM.format(maior)); setTxt('fz-cult',Object.keys(cul).length);
 setHTML('fz-table',T.length?T.map(function(t){return '<div class="trow t-faz"><div class="code">Válvula '+t.code+'</div><div class="cell" style="color:var(--ink2)"><span class="swdot" style="background:'+(CULT_COLOR[t.cult]||'#C3B49E')+'"></span>'+t.cult+'</div><div class="cell">'+NUM.format(t.area)+' ha</div><div class="cell" style="color:var(--muted)">'+t.solo+'</div><div class="cell" style="color:var(--muted)">'+fname(t.faz)+'</div></div>';}).join(''):empty('Sem válvulas.'));
 setHTML('fz-map',T.map(function(t){return '<div style="border:1px solid var(--border);border-top:3px solid '+(CULT_COLOR[t.cult]||'#C3B49E')+';border-radius:9px;padding:10px 12px;background:#fff"><div class="code">Válvula '+t.code+'</div><div style="font-size:11px;color:var(--muted);margin-top:2px">'+t.cult+'</div><div class="num" style="font-size:16px;font-weight:600;margin-top:6px">'+NUM.format(t.area)+'<span style="font-size:11px;color:var(--muted);font-weight:400"> ha</span></div></div>';}).join(''));
 var c=compo(cul,area,function(k){return CULT_COLOR[k]||'#C3B49E';},'ha');
 setHTML('fz-comp',c[0]); setHTML('fz-leg',c[1]);
}
function renderSafras(){
 var S=byFS(safras);
 setTxt('sf-ativas',S.filter(function(s){return s.status!=='colhida';}).length);
 setTxt('sf-area',NUM.format(S.reduce(function(s,x){return s+x.area;},0)));
 setTxt('sf-prod',NUM.format(S.reduce(function(s,x){return s+x.prodEsp;},0)));
 var cs={}; S.forEach(function(s){cs[s.cult]=1;}); setTxt('sf-cult',Object.keys(cs).length);
 setHTML('sf-table',S.length?S.map(function(s){
   var st=s.status==='colhida'?'<span class="tag" style="background:var(--track);color:var(--muted)">colhida</span>':(s.status==='em colheita'?'<span class="tag" style="background:var(--amber-bg);color:var(--amber-d)">em colheita</span>':'<span class="tag ativo">em andamento</span>');
   return '<div class="trow t-saf"><div><div class="code">'+s.nome+'</div><div class="rname">Válvulas '+s.talhoes+'</div></div><div class="cell" style="color:var(--ink2)"><span class="swdot" style="background:'+(CULT_COLOR[s.cult]||'#C3B49E')+'"></span>'+s.cult+'</div><div class="cell">'+NUM.format(s.area)+' ha</div><div class="cell" style="color:var(--muted)">'+s.un+'</div><div class="cell" style="color:var(--muted)">'+s.periodo+'</div><div>'+st+'</div></div>';
 }).join(''):empty('Sem safras para esta seleção.'));
 var foco=S.filter(function(s){return s.status!=='colhida';})[0]||S[0];
 var stage=foco?(foco.status==='em colheita'?3:2):0;
 var names=['Plantio','Tratos culturais','Colheita'];
 var st='';
 if(foco){ st='<div style="font-size:12px;color:var(--muted);margin-bottom:4px">'+foco.nome+' · '+foco.cult+'</div><div class="steps">';
   for(var i=1;i<=3;i++){var cl=i<stage?'done':(i===stage?'now':''); st+='<div class="step '+cl+'"><div class="sdot">'+(i<stage?'✓':i)+'</div><b>'+names[i-1]+'</b><span>'+(i<stage?'concluído':(i===stage?'em curso':'a fazer'))+'</span></div>';}
   st+='</div>';
 } else st=empty('Sem safra ativa.');
 setHTML('sf-andamento',st);
 var pc={}; S.forEach(function(s){pc[s.cult]=(pc[s.cult]||0)+s.prodEsp;});
 var tot=Object.keys(pc).reduce(function(a,k){return a+pc[k];},0);
 var c=compo(pc,tot,function(k){return CULT_COLOR[k]||'#C3B49E';},'sc');
 setHTML('sf-comp',c[0]); setHTML('sf-leg',c[1]);
}
function renderAtividades(){
 var A=byFS(apont);
 setTxt('at-count',A.length);
 setHTML('at-custo',brl(A.reduce(function(s,a){return s+a.val;},0)));
 setTxt('at-horas',NUM.format(A.reduce(function(s,a){return s+a.horas;},0)));
 setTxt('at-insumos',A.filter(function(a){return a.insumo;}).length);
 var L=A.map(function(a){return {kind:a.kind,date:a.date,title:a.title,sub:a.sub,am:apam(a.val),tags:a.tags};});
 setHTML('at-ledger',L.length?ledger(L):empty('Nenhum apontamento no período para esta seleção.'));
}
function renderColheita(){
 var C=byFS(colheitas);
 var prod=C.reduce(function(s,c){return s+c.prod;},0);
 var area=C.reduce(function(s,c){return s+c.area;},0);
 var rend=area?C.reduce(function(s,c){return s+c.rend*c.area;},0)/area:0;
 var PRICE={'Soja':130,'Milho':65,'Algodão':150};
 var rec=C.reduce(function(s,c){return s+c.prod*(PRICE[c.cult]||100);},0);
 setTxt('cl-prod',NUM.format(prod)); setTxt('cl-rend',Math.round(rend)); setTxt('cl-tal',C.length);
 setHTML('cl-rec','R$ '+(rec/1e6).toFixed(2).replace('.',',')+'<small>mi</small>');
 setHTML('cl-table',C.length?C.map(function(c){return '<div class="trow t-col"><div class="code">Válvula '+c.code+'</div><div class="cell" style="color:var(--ink2)"><span class="swdot" style="background:'+(CULT_COLOR[c.cult]||'#C3B49E')+'"></span>'+c.cult+'</div><div class="cell">'+NUM.format(c.area)+' ha</div><div class="cell">'+NUM.format(c.prod)+' sc</div><div class="cell">'+c.rend+' sc/ha</div><div class="cell" style="color:var(--muted)">'+c.date+'</div><div class="cell" style="color:var(--muted)">'+c.dest+'</div></div>';}).join(''):empty('Nenhuma colheita para esta seleção.'));
 var mx=Math.max.apply(null,C.map(function(c){return c.prod;}).concat([1]));
 setHTML('cl-bars',C.length?C.map(function(c){return '<div class="pbar"><span class="pl">Válvula '+c.code+'</span><div class="ptrack"><i style="width:'+(c.prod/mx*100).toFixed(0)+'%;background:'+(CULT_COLOR[c.cult]||'#C3B49E')+'"></i></div><span class="pv">'+NUM.format(c.prod)+' sc</span></div>';}).join(''):empty('—'));
}
/* ===== Suprimentos: dados ===== */
var CAT_COLOR={'Defensivo':'#005059','Fertilizante':'#B57C1A','Semente':'#4E9CA1','Veterinário':'#2A767C','Produção':'#A7C9CB'};
function money(v){return v>=1e6?('R$ '+(v/1e6).toFixed(2).replace('.',',')+'<small>mi</small>'):('R$ '+NUM.format(Math.round(v/1e3))+'<small>mil</small>');}

var estoque=[
 {faz:'boa-vista',nome:'Glifosato 480',cat:'Defensivo',saldo:'1.250 L',custo:'R$ 28,50/L',valor:35625,status:'ok'},
 {faz:'boa-vista',nome:'Sivanto Prime',cat:'Defensivo',saldo:'180 L',custo:'R$ 245,00/L',valor:44100,status:'ok'},
 {faz:'boa-vista',nome:'Ureia 45%',cat:'Fertilizante',saldo:'42.000 kg',custo:'R$ 2,98/kg',valor:125160,status:'ok'},
 {faz:'boa-vista',nome:'MAP',cat:'Fertilizante',saldo:'28.000 kg',custo:'R$ 4,15/kg',valor:116200,status:'ok'},
 {faz:'boa-vista',nome:'Semente Soja',cat:'Semente',saldo:'320 sc',custo:'R$ 380,00/sc',valor:121600,status:'ok'},
 {faz:'boa-vista',nome:'Vacina Aftosa',cat:'Veterinário',saldo:'40 doses',custo:'R$ 9,00/dose',valor:360,status:'baixo'},
 {faz:'boa-vista',nome:'Ivermectina 1%',cat:'Veterinário',saldo:'18 L',custo:'R$ 95,00/L',valor:1710,status:'baixo'},
 {faz:'santa-helena',nome:'Glifosato 480',cat:'Defensivo',saldo:'620 L',custo:'R$ 28,50/L',valor:17670,status:'ok'},
 {faz:'santa-helena',nome:'Ureia 45%',cat:'Fertilizante',saldo:'22.000 kg',custo:'R$ 2,98/kg',valor:65560,status:'ok'},
 {faz:'santa-helena',nome:'Semente Milho',cat:'Semente',saldo:'140 sc',custo:'R$ 520,00/sc',valor:72800,status:'ok'}
];
var estMov=[
 {faz:'boa-vista',date:'18/06',kind:'saida',title:'Saída — Sivanto Prime',sub:'−80 L · Pulverização Válvula 3C',am:'−80 L',tags:[['custo','custo na válvula']]},
 {faz:'boa-vista',date:'16/06',kind:'entrada',title:'Entrada — Ureia 45%',sub:'+20.000 kg · Pedido PC-2026-051',am:'+20.000 kg',tags:[['custo','custo médio recalc']]},
 {faz:'boa-vista',date:'12/06',kind:'saida',title:'Saída — Vacina Aftosa',sub:'−60 doses · Sanidade LOTE-2026-003',am:'−60 doses',tags:[['custo','custo no lote']]},
 {faz:'boa-vista',date:'10/06',kind:'saida',title:'Saída — Ureia 45%',sub:'−15.000 kg · Adubação Válvula 2B',am:'−15.000 kg',tags:[['custo','custo na válvula']]},
 {faz:'boa-vista',date:'05/06',kind:'transfer',title:'Transferência — Glifosato 480',sub:'200 L · Boa Vista → Santa Helena',am:'200 L',tags:[]},
 {faz:'boa-vista',date:'02/06',kind:'saida',title:'Saída — Semente Soja',sub:'−155 sc · Plantio Válvula 4C',am:'−155 sc',tags:[['custo','custo na safra']]},
 {faz:'santa-helena',date:'14/06',kind:'saida',title:'Saída — fungicida',sub:'−45 L · Pulverização Válvula SH-1',am:'−45 L',tags:[['custo','custo na válvula']]}
];
var solic=[
 {faz:'boa-vista',safra:'2025-26',item:'Fungicida triazol',qtd:'120 L',ctx:'Válvula 3C · Soja',quem:'João (campo)',status:'pendente'},
 {faz:'boa-vista',safra:'2025-26',item:'Adubo foliar',qtd:'40 L',ctx:'Válvula 2B · Milho',quem:'Carlos',status:'aprovada'},
 {faz:'santa-helena',safra:'2025-26',item:'Arame liso',qtd:'2.000 m',ctx:'Válvula SH-4 · Pasto',quem:'Pedro (campo)',status:'pendente'}
];
var pedidos=[
 {faz:'boa-vista',safra:'2025-26',code:'PC-2026-051',forn:'AgroInsumos MOC',itens:'Ureia 20t · Glifosato 500L',val:88400,status:'recebido'},
 {faz:'boa-vista',safra:'2025-26',code:'PC-2026-052',forn:'Sementes Vale',itens:'Semente Soja 200 sc',val:76000,status:'aberto'},
 {faz:'boa-vista',safra:'2025-26',code:'PC-2026-053',forn:'Vet Distribuidora',itens:'Vacina Aftosa 200 doses',val:1800,status:'aberto'},
 {faz:'santa-helena',safra:'2025-26',code:'PC-2026-060',forn:'AgroInsumos MOC',itens:'Ureia 12t',val:35760,status:'aberto'}
];

function statusTag(s){
 var m={'ok':['ativo','ok'],'baixo':['','baixo'],'pendente':['','pendente'],'aprovada':['ativo','aprovada'],'recebido':['ativo','recebido'],'aberto':['','aberto'],'pago':['','pago']};
 var t=m[s]||['',s];
 if(t[0]==='ativo') return '<span class="tag ativo">'+t[1]+'</span>';
 if(s==='ok'||s==='aprovada'||s==='recebido') return '<span class="tag ativo">'+t[1]+'</span>';
 return '<span class="tag" style="background:var(--amber-bg);color:var(--amber-d)">'+t[1]+'</span>';
}

function renderEstoque(){
 var E=byFaz(estoque), M=byFaz(estMov);
 setHTML('es-val',money(E.reduce(function(s,i){return s+i.valor;},0)));
 setTxt('es-itens',E.length); setTxt('es-mov',M.length);
 setTxt('es-alert',E.filter(function(i){return i.status==='baixo';}).length);
 setHTML('es-table',E.length?E.map(function(i){return '<div class="trow t-est"><div class="code">'+i.nome+'</div><div class="cell" style="color:var(--ink2)"><span class="swdot" style="background:'+(CAT_COLOR[i.cat]||'#C3B49E')+'"></span>'+i.cat+'</div><div class="cell">'+i.saldo+'</div><div class="cell" style="color:var(--muted)">'+i.custo+'</div><div class="cell">'+brl(i.valor)+'</div><div>'+statusTag(i.status)+'</div></div>';}).join(''):empty('Sem itens.'));
 setHTML('es-ledger',M.length?ledger(M):empty('Nenhuma movimentação para esta seleção.'));
 var cat={}; E.forEach(function(i){cat[i.cat]=(cat[i.cat]||0)+i.valor;});
 var tot=Object.keys(cat).reduce(function(a,k){return a+cat[k];},0);
 var c=compo(cat,tot,function(k){return CAT_COLOR[k]||'#C3B49E';},'');
 setHTML('es-comp',c[0]); setHTML('es-leg',(c[1]||'').replace(/ <\/span>/g,'</span>'));
}
function renderCompras(){
 var P=byFS(pedidos), S=byFS(solic);
 setTxt('co-abertos',P.filter(function(p){return p.status==='aberto';}).length);
 setHTML('co-val',money(P.reduce(function(s,p){return s+p.val;},0)));
 setTxt('co-pend',S.filter(function(s){return s.status==='pendente';}).length);
 setHTML('co-pagar',money(P.filter(function(p){return p.status==='aberto';}).reduce(function(s,p){return s+p.val;},0)));
 setHTML('co-solic',S.length?S.map(function(s,idx){
   var act=s.status==='aprovada'?'<span class="miniapprove done"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12.5 10 17l9-10"/></svg>aprovada</span>':'<button class="miniapprove" onclick="aprovarSolic(this)">Aprovar</button>';
   return '<div class="sreq"><div class="si"><div class="st">'+s.item+'</div><div class="ss">'+s.ctx+' · '+s.quem+'</div></div><div class="sq">'+s.qtd+'</div>'+act+'</div>';
 }).join(''):empty('Nenhuma solicitação para esta seleção.'));
 setHTML('co-table',P.length?P.map(function(p){return '<div class="trow t-ped"><div class="code">'+p.code+'</div><div class="cell" style="color:var(--ink2)">'+p.forn+'</div><div class="cell" style="color:var(--muted)">'+p.itens+'</div><div class="cell">'+brl(p.val)+'</div><div>'+statusTag(p.status)+'</div></div>';}).join(''):empty('Nenhum pedido para esta seleção.'));
}function aprovarSolic(btn){btn.outerHTML='<span class="miniapprove done"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12.5 10 17l9-10"/></svg>aprovada</span>';toast('Solicitação aprovada','vira pedido de compra · entra no fluxo do Financeiro · entrada no estoque ao receber');}

/* ===== Operação Fase 2: dados ===== */
function tg2(t,k){var st=k==='ok'?'background:var(--pos-bg);color:var(--pos)':k==='warn'?'background:var(--amber-bg);color:var(--amber-d)':k==='bad'?'background:#FCEBEB;color:var(--danger)':'background:var(--track);color:var(--muted)';return '<span class="tag" style="'+st+'">'+t+'</span>';}
var MAQ_COLOR={'Trator':'#005059','Colheitadeira':'#00363D','Pulverizador':'#4E9CA1','Implemento':'#A7C9CB','Veículo':'#B57C1A'};

var maquinas=[
 {faz:'boa-vista',code:'Colheitadeira CR 5.85',tipo:'Colheitadeira',valor:712000,horim:'4.820 h',custoh:'R$ 285/h',status:'ativa'},
 {faz:'boa-vista',code:'Pulverizador autopropelido',tipo:'Pulverizador',valor:489000,horim:'2.140 h',custoh:'R$ 198/h',status:'manutenção'},
 {faz:'boa-vista',code:'Trator John Deere 6110J',tipo:'Trator',valor:284000,horim:'6.310 h',custoh:'R$ 142/h',status:'ativa'},
 {faz:'boa-vista',code:'Plantadeira 36 linhas',tipo:'Implemento',valor:200000,horim:'—',custoh:'—',status:'ativa'},
 {faz:'boa-vista',code:'Caminhão graneleiro',tipo:'Veículo',valor:238000,horim:'188.000 km',custoh:'R$ 3,20/km',status:'ativa'},
 {faz:'santa-helena',code:'Trator Massey 4292',tipo:'Trator',valor:245000,horim:'3.150 h',custoh:'R$ 138/h',status:'ativa'}
];
var abast=[
 {faz:'boa-vista',date:'17/06',kind:'entrada',title:'Colheitadeira CR 5.85',sub:'480 L diesel · Safra Soja 25/26',litros:480,am:'− '+brl(3120),tags:[['custo','custo na safra']]},
 {faz:'boa-vista',date:'12/06',kind:'entrada',title:'Trator John Deere 6110J',sub:'220 L · Válvula 2B',litros:220,am:'− '+brl(1430),tags:[['custo','custo na válvula']]},
 {faz:'boa-vista',date:'08/06',kind:'entrada',title:'Pulverizador autopropelido',sub:'180 L · Safra Soja 25/26',litros:180,am:'− '+brl(1170),tags:[['custo','custo na safra']]},
 {faz:'boa-vista',date:'03/06',kind:'entrada',title:'Colheitadeira CR 5.85',sub:'510 L · Safra Soja 25/26',litros:510,am:'− '+brl(3315),tags:[['custo','custo na safra']]},
 {faz:'santa-helena',date:'14/06',kind:'entrada',title:'Trator Massey 4292',sub:'160 L · Válvula SH-2',litros:160,am:'− '+brl(1040),tags:[['custo','custo na válvula']]}
];
var maqAlerts=[
 {faz:'boa-vista',t:'Pulverizador autopropelido',s:'Em manutenção · revisão de bicos',d:'28/06 · em 8 dias',due:1},
 {faz:'boa-vista',t:'Colheitadeira CR 5.85',s:'Revisão pré-safra',d:'12/07 · em 22 dias',due:0},
 {faz:'santa-helena',t:'Trator Massey 4292',s:'Troca de óleo e filtros',d:'18/07 · em 28 dias',due:0}
];
var notas=[
 {faz:'boa-vista',num:'NFe 001235',tipo:'Saída',parte:'Cargill (soja)',valor:4180000,date:'18/06',status:'autorizada'},
 {faz:'boa-vista',num:'NFe 001234',tipo:'Saída',parte:'Frigorífico Vale (gado)',valor:233728,date:'15/06',status:'autorizada'},
 {faz:'boa-vista',num:'NFe 001236',tipo:'Saída',parte:'Coop. Regional (milho)',valor:2150000,date:'12/06',status:'pendente'},
 {faz:'boa-vista',num:'NF 88421',tipo:'Entrada',parte:'AgroInsumos MOC',valor:88400,date:'16/06',status:'importada'},
 {faz:'boa-vista',num:'NF 90112',tipo:'Entrada',parte:'Sementes Vale',valor:76000,date:'10/06',status:'importada'},
 {faz:'santa-helena',num:'NFe 002010',tipo:'Saída',parte:'Bunge (soja)',valor:1560000,date:'17/06',status:'autorizada'}
];
var mip=[
 {faz:'boa-vista',safra:'2025-26',talhao:'3C',cult:'Soja',praga:'Percevejo marrom',nivel:'alto',date:'16/06',acao:'Aplicar inseticida',defensivo:'Inseticida neonicotinoide',aplicar:1},
 {faz:'boa-vista',safra:'2025-26',talhao:'4C',cult:'Soja',praga:'Ferrugem asiática',nivel:'alto',date:'14/06',acao:'Aplicar fungicida',defensivo:'Fungicida triazol + estrobilurina',aplicar:1},
 {faz:'boa-vista',safra:'2025-26',talhao:'2B',cult:'Milho',praga:'Lagarta-do-cartucho',nivel:'médio',date:'12/06',acao:'Monitorar',defensivo:'',aplicar:0},
 {faz:'boa-vista',safra:'2025-26',talhao:'1A',cult:'Algodão',praga:'Bicudo',nivel:'baixo',date:'10/06',acao:'Armadilhas',defensivo:'',aplicar:0},
 {faz:'santa-helena',safra:'2025-26',talhao:'SH-1',cult:'Soja',praga:'Percevejo',nivel:'médio',date:'13/06',acao:'Monitorar',defensivo:'',aplicar:0}
];
var contratos=[
 {faz:'boa-vista',safra:'2025-26',code:'CV-2026-010',tipo:'Venda',parte:'Cargill',prod:'Soja',vol:25000,preco:'R$ 132/sc',valor:3300000,status:'em entrega',prog:60},
 {faz:'boa-vista',safra:'2025-26',code:'CV-2026-011',tipo:'Venda',parte:'Coop. Regional',prod:'Milho',vol:40000,preco:'R$ 66/sc',valor:2640000,status:'firmado',prog:0},
 {faz:'boa-vista',safra:'2025-26',code:'CB-2026-005',tipo:'Barter',parte:'AgroInsumos',prod:'Soja × Insumos',vol:5000,preco:'—',valor:660000,status:'liquidado',prog:100},
 {faz:'santa-helena',safra:'2025-26',code:'CV-2026-020',tipo:'Venda',parte:'Bunge',prod:'Soja',vol:12000,preco:'R$ 130/sc',valor:1560000,status:'firmado',prog:0}
];

function alertRow(a){return '<div class="alert-row"><div class="alert-ic'+(a.due?' due':'')+'"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 9v4M12 17h.01M10.3 3.9 2 18a2 2 0 0 0 1.7 3h16.6a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z"/></svg></div><div style="flex:1"><div style="font-weight:600;font-size:13px;color:var(--ink2)">'+a.t+'</div><div style="font-size:11.5px;color:var(--muted)">'+a.s+'</div></div><div style="font-family:var(--num);font-size:11px;color:'+(a.due?'var(--danger)':'var(--muted2)')+';white-space:nowrap">'+a.d+'</div></div>';}

function renderMaquinas(){
 var M=byFaz(maquinas), Ab=byFaz(abast), Al=byFaz(maqAlerts);
 setTxt('mq-count',M.length); setHTML('mq-val',money(M.reduce(function(s,m){return s+m.valor;},0)));
 setTxt('mq-comb',NUM.format(Ab.reduce(function(s,a){return s+a.litros;},0)));
 setTxt('mq-manut',M.filter(function(m){return m.status==='manutenção';}).length);
 setHTML('mq-table',M.length?M.map(function(m){return '<div class="trow t-maq"><div class="code">'+m.code+'</div><div class="cell" style="color:var(--ink2)"><span class="swdot" style="background:'+(MAQ_COLOR[m.tipo]||'#C3B49E')+'"></span>'+m.tipo+'</div><div class="cell">'+brl(m.valor)+'</div><div class="cell" style="color:var(--muted)">'+m.horim+'</div><div class="cell" style="color:var(--muted)">'+m.custoh+'</div><div>'+(m.status==='ativa'?tg2('ativa','ok'):tg2('manutenção','warn'))+'</div></div>';}).join(''):empty('Sem máquinas.'));
 setHTML('mq-ledger',Ab.length?ledger(Ab):empty('Nenhum abastecimento para esta seleção.'));
 setHTML('mq-alerts',Al.length?Al.map(alertRow).join(''):empty('Nenhum alerta para esta seleção.'));
}
function renderFiscal(){
 var N=byFaz(notas);
 var sa=N.filter(function(n){return n.tipo==='Saída';});
 setTxt('fs-emit',sa.length); setHTML('fs-fat',money(sa.reduce(function(s,n){return s+n.valor;},0)));
 setTxt('fs-ent',N.filter(function(n){return n.tipo==='Entrada';}).length);
 setTxt('fs-pend',N.filter(function(n){return n.status==='pendente';}).length);
 setHTML('fs-table',N.length?N.map(function(n){
   var k=n.status==='pendente'?tg2('pendente','warn'):(n.status==='cancelada'?tg2('cancelada','bad'):tg2(n.status,'ok'));
   var tp=n.tipo==='Saída'?'<span class="tag" style="background:var(--pos-bg);color:var(--pos)">Saída</span>':'<span class="tag" style="background:var(--track);color:var(--muted)">Entrada</span>';
   return '<div class="trow t-nf"><div class="code">'+n.num+'</div><div>'+tp+'</div><div class="cell" style="color:var(--ink2)">'+n.parte+'</div><div class="cell">'+brl(n.valor)+'</div><div class="cell" style="color:var(--muted)">'+n.date+'</div><div>'+k+'</div></div>';
 }).join(''):empty('Nenhuma nota para esta seleção.'));
}
function renderMIP(){
 var O=byFS(mip);
 setTxt('mp-mon',O.length); setTxt('mp-occ',O.length);
 setTxt('mp-alto',O.filter(function(o){return o.nivel==='alto';}).length);
 setTxt('mp-rec',O.filter(function(o){return o.aplicar;}).length);
 setHTML('mp-table',O.length?O.map(function(o){
   var nv=o.nivel==='alto'?tg2('alto','bad'):(o.nivel==='médio'?tg2('médio','warn'):tg2('baixo','ok'));
   return '<div class="trow t-mip"><div class="code">Válvula '+o.talhao+'</div><div class="cell" style="color:var(--ink2)"><span class="swdot" style="background:'+(CULT_COLOR[o.cult]||'#C3B49E')+'"></span>'+o.cult+'</div><div class="cell" style="color:var(--ink2)">'+o.praga+'</div><div>'+nv+'</div><div class="cell" style="color:var(--muted)">'+o.date+'</div><div class="cell" style="color:var(--muted)">'+o.acao+'</div></div>';
 }).join(''):empty('Nenhuma ocorrência para esta seleção.'));
 var rec=O.filter(function(o){return o.aplicar;});
 setHTML('mp-rec-list',rec.length?rec.map(function(o){
   return '<div class="recrow"><div class="recic"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2c3 4 5 7 5 11a5 5 0 0 1-10 0c0-4 2-7 5-11Z"/></svg></div><div class="ri"><div class="rt">'+o.praga+' · Válvula '+o.talhao+'</div><div class="rs">'+o.defensivo+' · janela ideal nos próximos 3 dias</div></div><button class="miniapprove" onclick="toast(\'Atividade criada\',\'aplicação na Válvula '+o.talhao+' · consome defensivo do estoque · custo na safra via custeio\')">Gerar atividade</button></div>';
 }).join(''):empty('Sem recomendações de aplicação no momento.'));
}
function renderContratos(){
 var C=byFS(contratos);
 setTxt('ct-ativos',C.filter(function(c){return c.status!=='liquidado';}).length);
 setTxt('ct-vol',NUM.format(C.reduce(function(s,c){return s+c.vol;},0)));
 setHTML('ct-val',money(C.reduce(function(s,c){return s+c.valor;},0)));
 setTxt('ct-pend',C.filter(function(c){return c.status!=='liquidado'&&c.prog<100;}).length);
 setHTML('ct-table',C.length?C.map(function(c){
   var tp=c.tipo==='Venda'?'<span class="tag" style="background:var(--pos-bg);color:var(--pos)">Venda</span>':(c.tipo==='Barter'?'<span class="tag" style="background:#E0EFEF;color:#1E5A60">Barter</span>':'<span class="tag" style="background:var(--amber-bg);color:var(--amber-d)">Compra</span>');
   var st=c.status==='liquidado'?tg2('liquidado','ok'):(c.status==='em entrega'?tg2('em entrega','warn'):tg2('firmado','neutral'));
   return '<div class="trow t-ctr"><div class="code">'+c.code+'</div><div>'+tp+'</div><div class="cell" style="color:var(--ink2)">'+c.parte+'</div><div class="cell" style="color:var(--muted)">'+c.prod+'</div><div class="cell">'+NUM.format(c.vol)+' sc</div><div class="cell">'+brl(c.valor)+'</div><div>'+st+'</div></div>';
 }).join(''):empty('Nenhum contrato para esta seleção.'));
 setHTML('ct-bars',C.length?C.map(function(c){
   var col=c.prog>=100?'var(--accent)':(c.prog>0?'var(--olive)':'var(--faint)');
   return '<div class="pbar"><span class="pl">'+c.code+'</span><div class="ptrack"><i style="width:'+c.prog+'%;background:'+col+'"></i></div><span class="pv">'+c.prog+'% · '+NUM.format(Math.round(c.vol*c.prog/100))+' sc</span></div>';
 }).join(''):empty('—'));
}
/* ===== Custos & Financeiro: dados ===== */
var COST_COLOR={'Fertilizantes':'#B57C1A','Defensivos':'#005059','Sementes':'#4E9CA1','Combustível/máq.':'#2A767C','Mão de obra':'#A7C9CB','Outros':'#DDD2BF'};
var custeioSafras=[
 {faz:'boa-vista',safra:'2025-26',cult:'Soja',area:730,custoHa:4220,recHa:6730},
 {faz:'boa-vista',safra:'2025-26',cult:'Milho',area:380,custoHa:3950,recHa:6200},
 {faz:'boa-vista',safra:'2025-26',cult:'Algodão',area:260,custoHa:5100,recHa:7800},
 {faz:'santa-helena',safra:'2025-26',cult:'Soja',area:290,custoHa:4100,recHa:6800},
 {faz:'santa-helena',safra:'2025-26',cult:'Milho',area:240,custoHa:3900,recHa:6100},
 {faz:'boa-vista',safra:'2024-25',cult:'Soja',area:730,custoHa:4050,recHa:6500}
];
var lancamentos=[
 {faz:'boa-vista',safra:'2025-26',date:'18/06',kind:'entrada',title:'Apontamento · Pulverização',sub:'Sivanto Prime · 420 ha',val:18900,alvo:'válvula 3C',origem:'apontamento'},
 {faz:'boa-vista',safra:'2025-26',date:'17/06',kind:'transfer',title:'Abastecimento · Colheitadeira',sub:'480 L diesel',val:3120,alvo:'safra Soja',origem:'maquina_abast'},
 {faz:'boa-vista',safra:'2025-26',date:'16/06',kind:'pesagem',title:'Saída de estoque · Ureia',sub:'20.000 kg',val:42500,alvo:'válvula 2B',origem:'estoque_mov'},
 {faz:'boa-vista',safra:'2025-26',date:'12/06',kind:'sanidade',title:'Sanidade · Vacina Aftosa',sub:'60 doses · LOTE-2026-003',val:540,alvo:'pecuária lote 003',origem:'pec_sanitario'},
 {faz:'boa-vista',safra:'2025-26',date:'02/06',kind:'entrada',title:'Apontamento · Plantio',sub:'Semente Soja · 310 ha',val:96100,alvo:'safra Soja',origem:'apontamento'},
 {faz:'boa-vista',safra:'2025-26',date:'28/05',kind:'transfer',title:'Apontamento · Preparo de solo',sub:'84 h-máquina',val:15200,alvo:'válvula 1A',origem:'apontamento'},
 {faz:'santa-helena',safra:'2025-26',date:'14/06',kind:'entrada',title:'Apontamento · Pulverização',sub:'Fungicida · 290 ha',val:13050,alvo:'válvula SH-1',origem:'apontamento'}
];
var apagar=[
 {faz:'boa-vista',desc:'Pedido PC-2026-052',parte:'Sementes Vale',venc:'30/06',val:76000},
 {faz:'boa-vista',desc:'Pedido PC-2026-053',parte:'Vet Distribuidora',venc:'05/07',val:1800},
 {faz:'boa-vista',desc:'Compra de garrotes',parte:'Fazenda São Jorge',venc:'18/07',val:96000},
 {faz:'boa-vista',desc:'Folha de pagamento',parte:'Recursos Humanos',venc:'30/06',val:84000},
 {faz:'santa-helena',desc:'Pedido PC-2026-060',parte:'AgroInsumos MOC',venc:'02/07',val:35760}
];
var areceber=[
 {faz:'boa-vista',desc:'NFe 001235',parte:'Cargill (soja)',venc:'18/07',val:4180000},
 {faz:'boa-vista',desc:'NFe 001234',parte:'Frigorífico Vale',venc:'15/07',val:233728},
 {faz:'boa-vista',desc:'Contrato CV-2026-010',parte:'Cargill (parcela)',venc:'25/07',val:1980000},
 {faz:'santa-helena',desc:'NFe 002010',parte:'Bunge (soja)',venc:'20/07',val:1560000}
];
var dre=[
 {faz:'boa-vista',receita:9297000,custo:5922000,despesa:680000,result:2695000},
 {faz:'santa-helena',receita:3436000,custo:2125000,despesa:240000,result:1071000}
];
function dreAll(){
 var D=byFaz(dre);
 return D.reduce(function(a,d){return {receita:a.receita+d.receita,custo:a.custo+d.custo,despesa:a.despesa+d.despesa,result:a.result+d.result};},{receita:0,custo:0,despesa:0,result:0});
}

function renderCusteio(){
 var S=byFS(custeioSafras), L=byFS(lancamentos);
 var custo=S.reduce(function(s,x){return s+x.custoHa*x.area;},0);
 var area=S.reduce(function(s,x){return s+x.area;},0);
 var rec=S.reduce(function(s,x){return s+x.recHa*x.area;},0);
 setHTML('cu-total',money(custo));
 setHTML('cu-ha',area?('R$ '+NUM.format(Math.round(custo/area))+'<small>/ha</small>'):'—');
 setTxt('cu-margem',rec?Math.round((rec-custo)/rec*100)+'%':'—');
 setTxt('cu-lanc',L.length);
 setHTML('cu-table',S.length?S.map(function(x){
   var c=x.custoHa*x.area, r=x.recHa*x.area, m=(r-c)/r*100;
   return '<div class="trow t-cust"><div><div class="code">'+x.cult+' '+x.safra.replace('20','').replace('-','/')+'</div></div><div class="cell">'+NUM.format(x.area)+' ha</div><div class="cell">'+brl(c)+'</div><div class="cell" style="color:var(--muted)">R$ '+NUM.format(x.custoHa)+'</div><div class="cell">'+brl(r)+'</div><div class="cell" style="color:var(--pos);font-weight:600">'+m.toFixed(1).replace('.',',')+'%</div></div>';
 }).join(''):empty('Sem safras para esta seleção.'));
 var split=[['Fertilizantes',.30],['Defensivos',.24],['Sementes',.14],['Combustível/máq.',.18],['Mão de obra',.10],['Outros',.04]];
 var bar='',leg='';
 split.forEach(function(p){var v=custo*p[1];
   bar+='<span style="width:'+(p[1]*100).toFixed(1)+'%;background:'+COST_COLOR[p[0]]+'" title="'+p[0]+'"></span>';
   leg+='<div class="r"><span class="sw" style="background:'+COST_COLOR[p[0]]+'"></span>'+p[0]+'<span class="n">'+brl(v)+'</span></div>';});
 setHTML('cu-comp',custo?bar:''); setHTML('cu-leg',custo?leg:empty('—'));
 setHTML('cu-ledger',L.length?ledger(L.map(function(l){return {kind:l.kind,date:l.date,title:l.title,sub:'origem: '+l.origem+' · '+l.sub,am:'− '+brl(l.val),tags:[['custo','alvo · '+l.alvo]]};})):empty('Nenhum lançamento para esta seleção.'));
}
function renderFinanceiro(){
 var AP=byFaz(apagar), AR=byFaz(areceber);
 var tp=AP.reduce(function(s,t){return s+t.val;},0), tr=AR.reduce(function(s,t){return s+t.val;},0);
 setHTML('fi-pagar',money(tp)); setHTML('fi-receber',money(tr));
 var pos=tr-tp;
 setHTML('fi-pos',(pos>=0?'+ ':'− ')+money(Math.abs(pos)).replace('R$ ','R$ '));
 var D=dreAll();
 setHTML('fi-result',money(D.result));
 setTxt('fi-margem',D.receita?Math.round(D.result/D.receita*100)+'%':'—');
 function firow(t,inflow){return '<div class="firow"><div><div class="code">'+t.desc+'</div><div class="rname">'+t.parte+'</div></div><div class="fm">venc '+t.venc+'</div><div class="fc"'+(inflow?' style="color:var(--pos)"':'')+'>'+brl(t.val)+'</div></div>';}
 setHTML('fi-ap',AP.length?AP.map(function(t){return firow(t,0);}).join(''):empty('Nada a pagar nesta seleção.'));
 setHTML('fi-ar',AR.length?AR.map(function(t){return firow(t,1);}).join(''):empty('Nada a receber nesta seleção.'));
 var margem=D.receita?Math.round(D.result/D.receita*100):0;
 setHTML('fi-dre',D.receita?('<div class="dre"><div class="drow"><span class="dl">Receita bruta</span><span class="dv">'+brl(D.receita)+'</span></div><div class="drow neg"><span class="dl">(−) Custo de produção</span><span class="dv">− '+brl(D.custo)+'</span></div><div class="drow neg"><span class="dl">(−) Despesas operacionais</span><span class="dv">− '+brl(D.despesa)+'</span></div><div class="drow tot"><span class="dl">(=) Resultado · margem '+margem+'%</span><span class="dv">'+brl(D.result)+'</span></div></div>'):empty('Sem dados.'));
 var fl=AP.map(function(t){return {kind:'saida',date:t.venc,title:t.desc,sub:'a pagar · '+t.parte,am:'− '+brl(t.val),tags:[]};})
   .concat(AR.map(function(t){return {kind:'entrada',date:t.venc,title:t.desc,sub:'a receber · '+t.parte,am:'+ '+brl(t.val),tags:[]};}));
 fl.sort(function(a,b){function k(d){var p=d.split('/');return p[1]*100+ +p[0];}return k(a.date)-k(b.date);});
 setHTML('fi-fluxo',fl.length?ledger(fl):empty('Sem vencimentos para esta seleção.'));
}
/* ===== init (multi-página: cada render roda isolado; ausência de elementos de outro módulo não aborta os demais) ===== */
[render, function(){setMovType('entrada_compra');}, renderPat, renderFazendas, renderSafras, renderAtividades, renderColheita, renderEstoque, renderCompras, renderMaquinas, renderFiscal, renderMIP, renderContratos, renderCusteio, renderFinanceiro].forEach(function(fn){ try{ fn(); }catch(e){} });
</script>

</body>
</html>
