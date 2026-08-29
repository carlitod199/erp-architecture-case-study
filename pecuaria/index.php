<?php
/* Pecuária está FORA do escopo ativo da VERO Agro (jun/2026).
   Módulo removido do menu; acesso direto por URL é bloqueado.
   Arquivo preservado para histórico/reativação futura. */
require_once __DIR__ . '/../includes/auth.php';
header('Location: ' . BIOS_BASE . '/403?motivo=fora_escopo');
exit;
$PAGE_VIEW  = 'pecuaria';
$PAGE_TITLE = 'Pecuária';
require __DIR__ . '/../includes/agro_header.php';
?>
<main data-view-main="pecuaria" class="main" style="display:flex; flex-direction:column; min-width:0; grid-column:2; grid-row:1">
    <header class="topbar">
      <div class="crumb"><span class="a">VERO Agro</span><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#C3B49E" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg><span class="b" id="crumb">Pecuária · Rebanho</span></div>
      <div class="tb-right">
        <div class="pillsel"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="var(--accent)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg><select class="f-safra"><option value="2025-26">Safra 2025/26</option><option value="2024-25">Safra 2024/25</option><option value="all">Todas as safras</option></select><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#9A8C78" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg></div>
        <div class="pillsel"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="var(--accent)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21h18M5 21V8l7-5 7 5v13M9 21v-5h6v5"/></svg><select class="f-fazenda"><option value="boa-vista">Fazenda Boa Vista</option><option value="santa-helena">Fazenda Santa Helena</option><option value="all">Todas as fazendas</option></select><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#9A8C78" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg></div>
        <button class="iconbtn"><svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="#5A4F42" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9M13.7 21a2 2 0 0 1-3.4 0"/></svg><span class="dot">3</span></button>
      </div>
    </header>

    <div class="content">
      <div class="tabs" id="tabs">
        <button class="tab active" data-scr="rebanho"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 8c0-2 1-3 2-3 1.5 0 2 1 3 1s1.5-1 3-1 1.5 1 3 1 1.5-1 3-1c1 0 2 1 2 3 0 4-3 9-8 9s-8-5-8-9Z"/></svg>Rebanho</button>
        <button class="tab" data-scr="lotes"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2 2 7l10 5 10-5-10-5Z"/><path d="m2 17 10 5 10-5M2 12l10 5 10-5"/></svg>Lotes</button>
        <button class="tab" data-scr="mov"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M7 8h12l-3-3M17 16H5l3 3"/></svg>Movimentações</button>
        <button class="tab" data-scr="pesagem"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 4v16M7 20h10M12 6 5 9m7-3 7 3"/><path d="M3 12a2 2 0 0 0 4 0L5 9Zm14 0a2 2 0 0 0 4 0l-2-3Z"/></svg>Pesagem</button>
        <button class="tab" data-scr="sanidade"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="m18 4 2 2M15 5l4 4M6 18l9-9-3-3-9 9 .8 2.2L6 18Z"/></svg>Sanidade</button>
      </div>

      <!-- ===== REBANHO ===== -->
      <section class="screen active" data-screen="rebanho">
        <div class="kpis">
          <div class="kpi"><div class="bar" style="background:var(--accent)"></div>
            <div class="h"><span>Cabeças</span><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#C3B49E" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 8c0-2 1-3 2-3 1.5 0 2 1 3 1s1.5-1 3-1 1.5 1 3 1 1.5-1 3-1c1 0 2 1 2 3 0 4-3 9-8 9s-8-5-8-9Z"/></svg></div>
            <div class="v" id="m-cab">—</div>
            <div class="f"><span class="chip pos"><svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="m6 15 6-6 6 6"/></svg>+48 mês</span>
              <svg width="78" height="26" viewBox="0 0 78 26" fill="none" preserveAspectRatio="none"><polyline points="0,21 11,20 22,16 33,17 44,11 55,12 66,7 78,5" stroke="var(--accent)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg></div></div>

          <div class="kpi"><div class="bar" style="background:var(--olive)"></div>
            <div class="h"><span>Peso médio</span><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#C3B49E" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 4v16M7 20h10M12 6 5 9m7-3 7 3"/></svg></div>
            <div class="v" id="m-peso">363<small>kg</small></div>
            <div class="f"><span class="chip pos"><svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="m6 15 6-6 6 6"/></svg><span id="m-gmd">GMD 0,57</span></span>
              <svg width="78" height="26" viewBox="0 0 78 26" fill="none" preserveAspectRatio="none"><polyline points="0,19 13,18 26,16 39,14 52,12 65,9 78,7" stroke="var(--olive)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg></div></div>

          <div class="kpi"><div class="bar" style="background:var(--accent-3)"></div>
            <div class="h"><span>Valor do rebanho</span><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#C3B49E" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v10M9.5 9.5h4a1.8 1.8 0 0 1 0 3.5h-3a1.8 1.8 0 0 0 0 3.5h4"/></svg></div>
            <div class="v" id="m-ativo">—</div>
            <div class="sub"><span style="font-weight:600">Ativo biológico · a custo</span><span>R$/cab 2.677</span></div></div>

          <div class="kpi"><div class="bar" style="background:var(--amber)"></div>
            <div class="h"><span>Custo R$/@</span><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#C3B49E" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="5" y="3" width="14" height="18" rx="2"/><path d="M8 7h8"/></svg></div>
            <div class="v" id="m-custoarr">110<small>/@</small></div>
            <div class="sub"><span id="m-custoarr-sub" style="font-weight:600">62% da meta de custo</span><span>meta 178/@</span></div>
            <div class="prog"><i id="m-custoarr-prog" style="width:62%"></i></div></div>
        </div>

        <div class="row2">
          <div class="card">
            <div class="card-h"><h2>Lotes ativos</h2><span class="ey">toque para abrir</span></div>
            <div class="card-b tight">
              <div class="thead"><div>Lote</div><div>Categoria</div><div class="col-h">Cabeças</div><div>Custo/cab.</div><div class="col-h">Peso · GMD</div><div></div></div>
              <div id="lotesAtivos"></div>
            </div>
          </div>
          <div class="card">
            <div class="card-h"><h2>Composição</h2><span class="ey">por categoria</span></div>
            <div class="card-b">
              <div class="compbar" id="compbar"></div>
              <div class="leg" id="leg"></div>
            </div>
          </div>
        </div>
      </section>

      <!-- ===== LOTES ===== -->
      <section class="screen" data-screen="lotes">
        <div style="display:flex;justify-content:flex-end"><button class="btn" onclick="goForm('entrada_compra')"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>Nova entrada</button></div>
        <div class="card">
          <div class="card-h"><h2>Todos os lotes</h2><span class="ey" id="lotes-count">4 lotes · 455 cabeças</span></div>
          <div class="card-b tight">
            <div class="thead"><div>Lote</div><div>Categoria</div><div class="col-h">Cabeças</div><div>Custo/cab.</div><div class="col-h">Peso · GMD</div><div></div></div>
            <div id="lotesTodos"></div>
          </div>
        </div>
      </section>

      <!-- ===== MOVIMENTAÇÕES ===== -->
      <section class="screen" data-screen="mov">
        <div class="card"><div class="card-h"><h2>Nova movimentação</h2><span class="ey">entrada · saída · transferência</span></div>
          <div class="card-b"><div class="form">
            <div class="seg" id="movType">
              <button data-t="entrada_compra" class="on"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12l7 7 7-7"/></svg>Compra</button>
              <button data-t="saida_venda"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 19V5M5 12l7-7 7 7"/></svg>Venda</button>
              <button data-t="entrada_transferencia"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M7 8h12l-3-3M17 16H5l3 3"/></svg>Transferência</button>
              <button data-t="entrada_nascimento"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>Nascimento</button>
            </div>
            <div class="frow">
              <div class="fld"><label>Lote <span class="rq">*</span></label><select id="mLote"></select></div>
              <div class="fld"><label>Data <span class="rq">*</span></label><input type="date" id="mData" value="2026-06-20"></div>
            </div>
            <div class="frow">
              <div class="fld"><label>Categoria</label><select id="mCat"><option>Bezerro(a)</option><option selected>Garrote</option><option>Boi gordo</option><option>Novilha</option><option>Vaca</option><option>Touro</option></select></div>
              <div class="fld"><label>Quantidade (cabeças) <span class="rq">*</span></label><input type="number" id="mQtd" value="40" min="1"></div>
            </div>
            <div class="frow">
              <div class="fld"><label>Peso total (kg)</label><input type="number" id="mPeso" value="12480"></div>
              <div class="fld"><label>Peso médio</label><input id="mPesoMed" readonly value="312,0 kg"></div>
            </div>
            <div class="frow" data-when="valor">
              <div class="fld"><label id="mPriceLab">Valor por cabeça (R$)</label><input type="number" id="mVU" value="2400"></div>
              <div class="fld"><label>Base do preço</label><select id="mBase"><option value="cabeca" selected>Por cabeça</option><option value="arroba">Por arroba (@)</option><option value="kg">Por kg</option></select></div>
            </div>
            <div class="frow" data-when="valor">
              <div class="fld"><label>Valor total</label><input id="mTotal" readonly value="R$ 96.000,00"></div>
              <div class="fld" id="mPartyField"><label id="mPartyLab">Fornecedor</label><select id="mParty"><option>Fazenda São Jorge</option><option>Leilão Agropecuária MOC</option><option>Pecuarista J. Andrade</option></select></div>
            </div>
            <div class="frow hidden" data-when="destino">
              <div class="fld"><label>Lote destino <span class="rq">*</span></label><select id="mDest"></select></div><div class="fld"></div>
            </div>
            <div class="fld full" style="margin-bottom:14px"><label>Documento (NF / GTA)</label><input id="mDoc" placeholder="ex.: GTA 0451-2026"></div>
            <div class="fx" id="mFx"><svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="6" width="18" height="12" rx="2"/><circle cx="12" cy="12" r="2.4"/><path d="M6 9.5v5M18 9.5v5"/></svg><div id="mFxText"></div></div>
            <div class="ffoot"><button class="btn ghost" onclick="go('mov')">Cancelar</button><button class="btn" id="mSave"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12.5 10 17l9-10"/></svg>Registrar</button></div>
          </div></div>
        </div>
        <div class="card"><div class="card-h"><h2>Movimentações recentes</h2><span class="ey">razão do rebanho</span></div>
          <div class="card-b"><div class="ledger" id="movLedger"></div></div></div>
      </section>

      <!-- ===== PESAGEM ===== -->
      <section class="screen" data-screen="pesagem">
        <div class="card"><div class="card-h"><h2>Nova pesagem</h2><span class="ey">peso médio · GMD</span></div>
          <div class="card-b"><div class="form">
            <div class="frow">
              <div class="fld"><label>Lote <span class="rq">*</span></label><select id="pLote"></select></div>
              <div class="fld"><label>Data <span class="rq">*</span></label><input type="date" id="pData" value="2026-06-20"></div>
            </div>
            <div class="frow">
              <div class="fld"><label>Tipo</label><select id="pTipo"><option value="amostral" selected>Amostral</option><option value="total">Total do lote</option><option value="individual">Individual</option></select></div>
              <div class="fld"><label>Cabeças pesadas</label><input type="number" id="pQtd" value="30"></div>
            </div>
            <div class="frow">
              <div class="fld"><label>Peso total (kg) <span class="rq">*</span></label><input type="number" id="pPeso" value="9540"></div>
              <div class="fld"><label>Peso médio</label><input id="pMed" readonly value="318,0 kg"></div>
            </div>
            <div class="fx"><svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 4v16M7 20h10M12 6 5 9m7-3 7 3"/></svg><div>Sem efeito financeiro. Atualiza o <b>peso médio do rebanho</b> e calcula o <b>GMD</b> contra a última pesagem do lote — insumo para o custo por arroba produzida.</div></div>
            <div class="ffoot"><button class="btn ghost" onclick="go('pesagem')">Limpar</button><button class="btn" onclick="toast('Pesagem registrada','peso médio do lote atualizado para 318,0 kg · GMD recalculado')"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12.5 10 17l9-10"/></svg>Registrar pesagem</button></div>
          </div></div>
        </div>
        <div class="card"><div class="card-h"><h2>Pesagens recentes</h2><span class="ey">kg médio · GMD</span></div>
          <div class="card-b"><div class="ledger" id="pesLedger"></div></div></div>
      </section>

      <!-- ===== SANIDADE ===== -->
      <section class="screen" data-screen="sanidade">
        <div class="card"><div class="card-h"><h2>Novo evento sanitário</h2><span class="ey">consumo + custo</span></div>
          <div class="card-b"><div class="form">
            <div class="frow">
              <div class="fld"><label>Lote <span class="rq">*</span></label><select id="sLote"></select></div>
              <div class="fld"><label>Data <span class="rq">*</span></label><input type="date" id="sData" value="2026-06-20"></div>
            </div>
            <div class="frow">
              <div class="fld"><label>Tipo</label><select id="sTipo"><option value="vacinacao" selected>Vacinação</option><option value="vermifugacao">Vermifugação</option><option value="medicacao">Medicação</option><option value="exame">Exame</option><option value="manejo">Manejo</option></select></div>
              <div class="fld"><label>Produto (estoque de insumos)</label><select id="sProd"><option>Vacina Aftosa</option><option>Vacina Clostridial</option><option>Ivermectina 1%</option><option>Carrapaticida pour-on</option></select></div>
            </div>
            <div class="frow">
              <div class="fld"><label>Cabeças aplicadas</label><input type="number" id="sCab" value="60"></div>
              <div class="fld"><label>Custo (produto + serviço)</label><input id="sCusto" value="R$ 540,00"></div>
            </div>
            <div class="fx"><svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 8 12 3 3 8l9 5 9-5Z"/><path d="M3 8v8l9 5 9-5V8"/></svg><div>Gera <b>baixa no estoque de insumos</b> (custo médio móvel do produto) e lança o <b>custo no lote</b> via custeio — entra direto no rateio por cabeça.</div></div>
            <div class="ffoot"><button class="btn ghost" onclick="go('sanidade')">Limpar</button><button class="btn" onclick="toast('Evento sanitário registrado','baixa de 60 doses no estoque · custo de R$ 540,00 lançado no lote')"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12.5 10 17l9-10"/></svg>Registrar evento</button></div>
          </div></div>
        </div>
        <div class="card"><div class="card-h"><h2>Eventos recentes</h2><span class="ey">produto · custo</span></div>
          <div class="card-b"><div class="ledger" id="sanLedger"></div></div></div>
      </section>
    </div>
  </main>
<?php require __DIR__ . '/../includes/agro_footer.php'; ?>
