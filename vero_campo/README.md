# VERO Campo — app (React Native + Expo)

App de campo do VERO, no padrão do app VERO: **Expo (frontend)** + **backend PHP `/api/v1`
do VERO** (Opção B — o app é idêntico; muda só a `BASE_URL`).

## Rodar

```bash
npm install
# ajuste o ambiente/URL em src/services/config.js (ATIVO = 'dev' | 'homolog' | 'producao')
npx expo start
```

Abra no **Expo Go** (Android/iOS) ou num emulador. Requer Node 18+.

## Estrutura

```
App.js                     providers (Auth + Sync) + fontes IBM Plex + RootNavigator
src/theme.js               tokens VERO (teal, verde suavizado, status, fontes)
src/navigation/            RootNavigator (Login × Tabs) · TabNavigator (5 abas) · stacks por feature
src/screens/               1 tela por jornada/passo (Login e Home implementadas; demais em construção)
src/components/             ui, AppHeader, CartaoContexto, BussolaCarencia
src/context/               AuthContext (token/permissões) · SyncContext (online + fila)
src/hooks/                 useApi
src/services/              http (Bearer + envelope) · veroApi (endpoints) · config (BASE_URL) · authStorage (SecureStore)
src/offline/               db (SQLite) · fila · idempotencia (client_uuid) · sincronizador (delta + upload)
assets/                    logos e ícone da marca
```

## Backend (Opção B)

Este app consome a API PHP `/api/v1` a ser construída sobre `includes/db.php`,
`permissions.php` e os services (`vero_srv_*`) do VERO web. Endpoints mapeados em
`src/services/veroApi.js`. Nada de regra de negócio é reimplementado no app — ele
captura, consulta e reage; o motor continua no servidor.

## Offline-first

Escrita entra na fila (SQLite) com `client_uuid` (idempotente — reenvio não duplica).
Leitura por delta (`updated_since`) em cache local. Fotos sobem em 2º plano após o
registro pai confirmar. Indicador de pendências sempre visível (decisão D6).

## Decisões

- **D1 (atualizada):** app nativo React Native + Expo (antes PWA) — definido pelo cliente
  a partir do padrão do app VERO.
- **D2 (mantida):** backend PHP `/api/v1` reusando o motor do VERO.
