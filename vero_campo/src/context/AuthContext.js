import React, { createContext, useContext, useEffect, useState, useCallback } from 'react';
import veroApi from '../services/veroApi';
import { registrarSessaoInvalida } from '../services/http';
import { registrarPush } from '../services/push';
import { MODO_DEMO } from '../services/config';
import { salvarToken, apagarToken, salvarUsuario, lerUsuario, lerToken } from '../services/authStorage';
import {
  normalizarCodigo, validarCodigo, salvarCodigo, lerCodigo, temAmbiente, apagarCodigo, lerBaseUrl,
} from '../services/ambiente';
import { garantirDonoDosDados, zerarBancoLocal } from '../offline/db';
import biometria from '../services/biometria';

const AuthContext = createContext(null);

export function AuthProvider({ children }) {
  const [carregando, setCarregando] = useState(true);
  const [usuario, setUsuario] = useState(null); // { id, nome, perfil, permissoes:[] }
  // multi-cliente: cada empresa vive em https://<codigo>.example.com/api/v1.
  // codigoEmpresa é o código REAL salvo (para exibição); ambienteDefinido é o
  // que a navegação observa — com EXPO_PUBLIC_API_URL (override de dev) o app
  // se comporta como se o ambiente já existisse mesmo sem código salvo.
  const [codigoEmpresa, setCodigoEmpresa] = useState(null);
  const [ambienteDefinido, setAmbienteDefinido] = useState(false);

  // token morto no servidor (expirado/revogado) → derruba a sessão local
  useEffect(() => {
    registrarSessaoInvalida(async () => {
      await apagarToken();
      setUsuario(null);
    });
  }, []);

  // ao abrir o app: carrega o ambiente (código da empresa) e, se ele existir,
  // tenta restaurar a sessão do dispositivo lembrado (token sem base URL não
  // tem para onde ir — sem ambiente, nem lê o token)
  useEffect(() => {
    (async () => {
      try {
        const [codigo, definido] = await Promise.all([lerCodigo(), temAmbiente()]);
        // override de dev sem código salvo → pseudo-valor só para exibição
        setCodigoEmpresa(codigo || (definido ? 'dev' : null));
        setAmbienteDefinido(definido);
        if (!definido) return;
        const token = await lerToken();
        if (token) {
          const u = await lerUsuario();
          setUsuario(u);
          // renovação deslizante: cada abertura estende os 30 dias do token;
          // offline falha em silêncio, token morto derruba via interceptor
          veroApi.refresh().catch(() => {});
          registrarPush(); // 7.5: só produz efeito em build EAS (fail-safe)
        }
      } finally {
        setCarregando(false);
      }
    })();
  }, []);

  // primeira abertura (ou depois de "Trocar empresa"): o operador digita o
  // código; validação é 100% local — NUNCA vai à rede com código inválido
  const definirEmpresa = useCallback(async (textoDigitado) => {
    const codigo = normalizarCodigo(textoDigitado);
    if (!validarCodigo(codigo)) {
      throw new Error('Código inválido. Use só letras e números, começando por letra.');
    }
    await salvarCodigo(codigo); // troca de código apaga o token sozinho
    // dados locais de OUTRO servidor (empresa anterior ou era servidor01) são
    // expurgados AGORA — antes de qualquer tela ler o cache antigo
    try { await garantirDonoDosDados(await lerBaseUrl()); } catch (_) {}
    setCodigoEmpresa(codigo);
    setAmbienteDefinido(true);
    return codigo;
  }, []);

  // "Trocar empresa" (Ajustes): apaga código E token → RootNavigator volta
  // para a tela de código. O logout comum (sair) NÃO passa por aqui: mantém
  // o código para o operador não redigitar a cada sessão.
  const trocarEmpresa = useCallback(async () => {
    await apagarCodigo(); // apaga código E token
    // banco local e biometria pertencem ao servidor antigo — nada sobrevive
    // à troca (cache misturado entre clientes foi bug real em 13/08)
    try { await zerarBancoLocal(); } catch (_) {}
    try { await biometria.desativar(); } catch (_) {}
    setUsuario(null);
    setCodigoEmpresa(null);
    setAmbienteDefinido(false);
  }, []);

  const entrar = useCallback(async (email, senha, device) => {
    if (MODO_DEMO) {
      // sem backend: monta um usuário local com todas as permissões
      const nome = (email.split('@')[0] || 'demo').replace(/[._]/g, ' ');
      const u = { id: 0, nome, perfil: 'demo', permissoes: ['*'] };
      await salvarToken('demo');
      await salvarUsuario(u);
      setUsuario(u);
      return u;
    }
    const resp = await veroApi.login(email, senha, device);
    const { token, usuario: u } = resp.data;
    await salvarToken(token);
    await salvarUsuario(u);
    setUsuario(u);
    registrarPush(); // 7.5: registra o aparelho p/ push (inerte no Expo Go)
    return u;
  }, []);

  const sair = useCallback(async () => {
    if (!MODO_DEMO) {
      try { await veroApi.logout(); } catch (_) {}
    }
    await apagarToken();
    setUsuario(null);
  }, []);

  // esconde UI por slug de permissão (o servidor nega de qualquer forma)
  const pode = useCallback(
    (slug) => {
      if (!usuario?.permissoes) return false;
      const p = usuario.permissoes;
      if (p.includes('*') || p.includes(slug)) return true;
      // wildcard {modulo}.* e *.acao
      const [base, micro, acao] = slug.split('.');
      return (
        p.includes(`${base}.*`) ||
        p.includes(`${base}.${micro}.*`) ||
        (acao && p.includes(`*.${acao}`))
      );
    },
    [usuario]
  );

  const value = {
    carregando, usuario, logado: !!usuario, entrar, sair, pode,
    codigoEmpresa, ambienteDefinido, definirEmpresa, trocarEmpresa,
  };
  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth() {
  const ctx = useContext(AuthContext);
  if (!ctx) throw new Error('useAuth deve estar dentro de AuthProvider');
  return ctx;
}

export default AuthContext;
