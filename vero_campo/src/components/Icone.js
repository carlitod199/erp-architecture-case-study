import React from 'react';
import Svg, { Path, Circle } from 'react-native-svg';
import { cores } from '../theme';

// =====================================================================
// VERO Campo — Iconografia (Onda 2). Set SVG ÚNICO que substitui os emojis
// usados como ícone de UI (auditoria A2: emoji = maior ofensor de consistência).
//
// Regras do set: grid 24×24, traço LINEAR uniforme (strokeWidth 1.8),
// cantos/pontas arredondados (round). Cor via prop `cor` (default cores.ink),
// tamanho via `tam` (default 24). API: <Icone nome="estoque" tam={20} cor={...} />
//
// Emojis NÃO cobertos aqui (conteúdo/mensagem — não são ícone de UI): permanecem
// no texto. Se faltar um ícone, `nome` desconhecido renderiza null (nunca quebra).
// =====================================================================

export default function Icone({ nome, tam = 24, cor = cores.ink, tracado = 1.8, ...rest }) {
  const p = { fill: 'none', stroke: cor, strokeWidth: tracado, strokeLinecap: 'round', strokeLinejoin: 'round' };
  const box = { width: tam, height: tam, viewBox: '0 0 24 24', ...rest };

  switch (nome) {
    // ---- Navegação (tab bar) ----
    case 'inicio':
    case 'dia':
      return <Svg {...box}><Path {...p} d="M3 11l9-7 9 7" /><Path {...p} d="M5 10v9h14v-9" /></Svg>;
    case 'tarefas':
      return <Svg {...box}><Path {...p} d="M4 6h16M4 12h16M4 18h10" /><Path {...p} d="M18 16l2 2 3-3" /></Svg>;
    case 'mapa':
      return (
        <Svg {...box}>
          <Path {...p} d="M12 21c-4.6-5.2-7-8.8-7-12a7 7 0 0 1 14 0c0 3.2-2.4 6.8-7 12Z" />
          <Circle {...p} cx="12" cy="9" r="2.6" />
        </Svg>
      );
    case 'mais':
      return (
        <Svg {...box}>
          <Circle cx="5" cy="12" r="1.7" fill={cor} />
          <Circle cx="12" cy="12" r="1.7" fill={cor} />
          <Circle cx="19" cy="12" r="1.7" fill={cor} />
        </Svg>
      );

    // ---- Registro / operação ----
    case 'voz':
    case 'microfone':
      return (
        <Svg {...box}>
          <Path {...p} d="M12 3.5a2.8 2.8 0 0 0-2.8 2.8v4.6a2.8 2.8 0 0 0 5.6 0V6.3A2.8 2.8 0 0 0 12 3.5Z" />
          <Path {...p} d="M5.8 11a6.2 6.2 0 0 0 12.4 0" />
          <Path {...p} d="M12 17.2v3.3M8.5 20.5h7" />
        </Svg>
      );
    case 'irrigacao':
      return <Svg {...box}><Path {...p} d="M12 3.2c3.6 4 5.4 6.6 5.4 9.2a5.4 5.4 0 0 1-10.8 0c0-2.6 1.8-5.2 5.4-9.2Z" /></Svg>;
    case 'abastecimento':
      return (
        <Svg {...box}>
          <Path {...p} d="M3 22h11" />
          <Path {...p} d="M4 9h9" />
          <Path {...p} d="M13 22V4.5A1.5 1.5 0 0 0 11.5 3h-6A1.5 1.5 0 0 0 4 4.5V22" />
          <Path {...p} d="M13 13h2a2 2 0 0 1 2 2v2a2 2 0 0 0 2 2 2 2 0 0 0 2-2V9.8a2 2 0 0 0-.6-1.4L17 5" />
        </Svg>
      );
    case 'alvo':
    case 'monitoramento':
      return (
        <Svg {...box}>
          <Circle {...p} cx="12" cy="12" r="9" />
          <Circle {...p} cx="12" cy="12" r="4.6" />
          <Circle cx="12" cy="12" r="1.5" fill={cor} />
        </Svg>
      );
    case 'caixa':
    case 'estoque':
      return (
        <Svg {...box}>
          <Path {...p} d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z" />
          <Path {...p} d="M3.3 7l8.7 5 8.7-5" />
          <Path {...p} d="M12 22V12" />
          <Path {...p} d="M7.5 4.2l9 5.2" />
        </Svg>
      );
    case 'upload':
    case 'enviarLider':
      return (
        <Svg {...box}>
          <Path {...p} d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" />
          <Path {...p} d="M17 8l-5-5-5 5" />
          <Path {...p} d="M12 3v12" />
        </Svg>
      );
    case 'recebidos':
    case 'download':
      return (
        <Svg {...box}>
          <Path {...p} d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" />
          <Path {...p} d="M7 10l5 5 5-5" />
          <Path {...p} d="M12 15V3" />
        </Svg>
      );
    case 'poda':
      return (
        <Svg {...box}>
          <Circle {...p} cx="6" cy="6" r="3" />
          <Circle {...p} cx="6" cy="18" r="3" />
          <Path {...p} d="M20 4L8.12 15.88" />
          <Path {...p} d="M14.47 14.48L20 20" />
          <Path {...p} d="M8.12 8.12L12 12" />
        </Svg>
      );
    case 'aplicacao':
    case 'pulverizacao':
      return (
        <Svg {...box}>
          <Path {...p} d="M9 3h6" />
          <Path {...p} d="M10 3v6l-5.4 9.3A1 1 0 0 0 5.5 20h13a1 1 0 0 0 .9-1.7L14 9V3" />
          <Path {...p} d="M6.6 14h10.8" />
        </Svg>
      );
    case 'adubacao':
    case 'planta':
      return (
        <Svg {...box}>
          <Path {...p} d="M12 21v-9" />
          <Path {...p} d="M12 12C8.7 12 7 9.5 7 6.5 10.3 6.5 12 9 12 12Z" />
          <Path {...p} d="M12 10c0-3 1.7-5.5 5-5.5 0 3-1.7 5.5-5 5.5Z" />
        </Svg>
      );
    case 'colheita':
    case 'cesta':
      return (
        <Svg {...box}>
          {/* alça arqueada por cima */}
          <Path {...p} d="M7 11a5 5 0 0 1 10 0" />
          {/* boca da cesta */}
          <Path {...p} d="M3.8 11h16.4" />
          {/* bojo */}
          <Path {...p} d="M5.4 11l1.3 7.3a2 2 0 0 0 2 1.6h6.6a2 2 0 0 0 2-1.6L18.6 11" />
          {/* trama (diagonais + banda) */}
          <Path {...p} d="M9.2 11.4l.7 8M14.8 11.4l-.7 8M6.5 15h11" />
        </Svg>
      );
    case 'uva':
      return (
        <Svg {...box}>
          <Path {...p} d="M12 6.5V4c0-.8.7-1.5 1.9-1.5" />
          <Circle {...p} cx="9" cy="9.5" r="2" />
          <Circle {...p} cx="15" cy="9.5" r="2" />
          <Circle {...p} cx="12" cy="12.5" r="2" />
          <Circle {...p} cx="9" cy="15.5" r="2" />
          <Circle {...p} cx="15" cy="15.5" r="2" />
          <Circle {...p} cx="12" cy="18.5" r="2" />
        </Svg>
      );
    case 'maquina':
    case 'trator':
      return (
        <Svg {...box}>
          <Path fill={cor} stroke="none" d="M9 5.2a1 1 0 0 1 1-1h2.8a1 1 0 0 1 .93.64L15 8.2h2.7a1.8 1.8 0 0 1 1.8 1.8v2.2a4.3 4.3 0 0 0-6.02 3.5H10.1A4.3 4.3 0 0 0 5 12.3V10a1 1 0 0 1 1-1h3V5.2Z" />
          <Circle cx="6.8" cy="16.3" r="3.4" fill="none" stroke={cor} strokeWidth={tracado} />
          <Circle cx="6.8" cy="16.3" r="1" fill={cor} stroke="none" />
          <Circle cx="17.4" cy="17.4" r="2.4" fill="none" stroke={cor} strokeWidth={tracado} />
        </Svg>
      );
    case 'calculadora':
      // "cheio" (estilo app): corpo preenchido; visor e botões são FUROS
      // (fill-rule evenodd) — mostram o fundo, funciona em qualquer cor.
      return (
        <Svg {...box}>
          <Path
            fill={cor}
            stroke="none"
            fillRule="evenodd"
            d="M6.7 2.8H17.3A2.2 2.2 0 0 1 19.5 5V19A2.2 2.2 0 0 1 17.3 21.2H6.7A2.2 2.2 0 0 1 4.5 19V5A2.2 2.2 0 0 1 6.7 2.8Z
               M7.4 5.4H16.6A0.7 0.7 0 0 1 17.3 6.1V8.8A0.7 0.7 0 0 1 16.6 9.5H7.4A0.7 0.7 0 0 1 6.7 8.8V6.1A0.7 0.7 0 0 1 7.4 5.4Z
               M7.45 12.2a0.95 0.95 0 1 0 1.9 0 0.95 0.95 0 1 0-1.9 0Z
               M11.05 12.2a0.95 0.95 0 1 0 1.9 0 0.95 0.95 0 1 0-1.9 0Z
               M14.65 12.2a0.95 0.95 0 1 0 1.9 0 0.95 0.95 0 1 0-1.9 0Z
               M7.45 15.5a0.95 0.95 0 1 0 1.9 0 0.95 0.95 0 1 0-1.9 0Z
               M11.05 15.5a0.95 0.95 0 1 0 1.9 0 0.95 0.95 0 1 0-1.9 0Z
               M14.65 15.5a0.95 0.95 0 1 0 1.9 0 0.95 0.95 0 1 0-1.9 0Z
               M7.45 18.5a0.95 0.95 0 1 0 1.9 0 0.95 0.95 0 1 0-1.9 0Z
               M11.05 18.5a0.95 0.95 0 1 0 1.9 0 0.95 0.95 0 1 0-1.9 0Z
               M14.65 18.5a0.95 0.95 0 1 0 1.9 0 0.95 0.95 0 1 0-1.9 0Z"
          />
        </Svg>
      );
    case 'servico':
    case 'relogio':
      return (
        <Svg {...box}>
          <Circle {...p} cx="12" cy="12" r="9" />
          <Path {...p} d="M12 7v5l3.2 2" />
        </Svg>
      );

    // ---- Consulta / UI ----
    case 'compras':
    case 'carrinho':
      return (
        <Svg {...box}>
          <Path {...p} d="M2.5 3.5h2l2.2 11.1a1.5 1.5 0 0 0 1.5 1.2h8.6a1.5 1.5 0 0 0 1.5-1.2L21 6.5H6" />
          <Circle {...p} cx="9" cy="20" r="1.4" />
          <Circle {...p} cx="18" cy="20" r="1.4" />
        </Svg>
      );
    case 'data':
    case 'calendario':
      return (
        <Svg {...box}>
          <Path {...p} d="M4 5.5h16a1 1 0 0 1 1 1v13a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1v-13a1 1 0 0 1 1-1Z" />
          <Path {...p} d="M3 9.5h18" />
          <Path {...p} d="M8 3.5v4M16 3.5v4" />
        </Svg>
      );
    case 'analises':
    case 'grafico':
      return (
        <Svg {...box}>
          <Path {...p} d="M4 4v16h16" />
          <Path {...p} d="M8 20v-4.5" />
          <Path {...p} d="M13 20V11" />
          <Path {...p} d="M17.5 20V7.5" />
        </Svg>
      );
    case 'sync':
    case 'sincronizar':
      return (
        <Svg {...box}>
          <Path {...p} d="M3 12a9 9 0 0 1 15-6.7L21 8" />
          <Path {...p} d="M21 3v5h-5" />
          <Path {...p} d="M21 12a9 9 0 0 1-15 6.7L3 16" />
          <Path {...p} d="M3 21v-5h5" />
        </Svg>
      );
    case 'chat':
      return <Svg {...box}><Path {...p} d="M21 11.5a8.4 8.4 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.4 8.4 0 0 1-3.8-.9L3 21l1.9-5.7a8.4 8.4 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.4 8.4 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5Z" /></Svg>;
    case 'biometria':
    case 'digital':
      return (
        <Svg {...box}>
          <Path {...p} d="M4.6 11.5a7.4 7.4 0 0 1 14.4-2.3" />
          <Path {...p} d="M6.7 12a5.3 5.3 0 0 1 10.6 0c0 2.6.4 4.4.8 5.6" />
          <Path {...p} d="M9 12a3 3 0 0 1 6 0c0 2.8.5 4.7 1 6" />
          <Path {...p} d="M12 12v1.5c0 3 .8 5 1.4 6.3" />
          <Path {...p} d="M6.4 16c.5 1.4.7 2.8.7 4" />
        </Svg>
      );
    case 'ajuda':
    case 'help':
      return (
        <Svg {...box}>
          <Circle {...p} cx="12" cy="12" r="9" />
          <Path {...p} d="M9.2 9.3a2.9 2.9 0 0 1 5.6 1c0 1.9-2.8 2.4-2.8 3.9" />
          <Circle cx="12" cy="17.2" r="0.9" fill={cor} />
        </Svg>
      );
    case 'scanner':
    case 'camera':
      return (
        <Svg {...box}>
          <Path {...p} d="M4 8.5h2.5l1.3-2h8.4l1.3 2H20a1.5 1.5 0 0 1 1.5 1.5v8A1.5 1.5 0 0 1 20 19.5H4A1.5 1.5 0 0 1 2.5 18v-8A1.5 1.5 0 0 1 4 8.5Z" />
          <Circle {...p} cx="12" cy="13" r="3.3" />
        </Svg>
      );
    case 'enviar':
    case 'send':
      return (
        <Svg {...box}>
          <Path {...p} d="M22 2L11 13" />
          <Path {...p} d="M22 2l-7 20-4-9-9-4 20-7Z" />
        </Svg>
      );
    case 'alerta':
    case 'sino':
      return (
        <Svg {...box}>
          <Path {...p} d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9" />
          <Path {...p} d="M13.7 21a2 2 0 0 1-3.4 0" />
        </Svg>
      );
    case 'aviso':
      return (
        <Svg {...box}>
          <Path {...p} d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z" />
          <Path {...p} d="M12 9v4" />
          <Circle cx="12" cy="17" r="0.9" fill={cor} />
        </Svg>
      );

    default:
      return null;
  }
}
