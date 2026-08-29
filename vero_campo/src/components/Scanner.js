import React, { useRef, useState, useEffect } from 'react';
import { View, Text, Pressable, Modal, StyleSheet } from 'react-native';
import { CameraView, useCameraPermissions } from 'expo-camera';
import { cores, fonte, raio } from '../theme';

// 7.4 — Leitor de QR/código de barras (expo-camera, já instalado).
// Uso: <Scanner visivel={v} titulo="…" aoLer={(codigo)=>…} aoFechar={()=>…} />
// Identifica máquina (plaqueta) e produto (etiqueta) pelo campo `codigo`.
// 19/08 (packing): `continuo` mantém a câmera lendo (posto de bipagem — a
// tela debounce/idempotência cuidam do resto) e `overlay` é um nó da tela
// desenhado por cima da câmera (feedback grande de campo: nome + caixas).

export default function Scanner({ visivel, titulo = 'Aponte para o código', aoLer, aoFechar, continuo = false, overlay = null }) {
  const [permissao, pedirPermissao] = useCameraPermissions();
  const [erro, setErro] = useState(false);
  const lidoRef = useRef(false); // evita disparo múltiplo do mesmo quadro

  useEffect(() => {
    if (visivel) {
      lidoRef.current = false;
      setErro(false);
      if (permissao && !permissao.granted) pedirPermissao();
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [visivel]);

  function lidos({ data }) {
    if (!data) return;
    if (continuo) {
      // modo contínuo: repassa TODO quadro lido — quem descarta leitura
      // idêntica (janela de 400ms) é a tela, que conhece a regra do posto
      aoLer(String(data).trim());
      return;
    }
    if (lidoRef.current) return;
    lidoRef.current = true;
    aoLer(String(data).trim());
  }

  return (
    <Modal visible={visivel} animationType="slide" onRequestClose={aoFechar}>
      <View style={styles.tela}>
        {permissao?.granted ? (
          <CameraView
            style={StyleSheet.absoluteFill}
            barcodeScannerSettings={{
              barcodeTypes: ['qr', 'code128', 'code39', 'ean13', 'ean8', 'upc_a'],
            }}
            onBarcodeScanned={lidos}
          />
        ) : (
          <View style={styles.centro}>
            <Text style={styles.aviso}>
              {permissao ? 'Sem permissão de câmera — libere nas configurações do aparelho.' : 'Abrindo a câmera…'}
            </Text>
          </View>
        )}

        {/* moldura de mira */}
        <View pointerEvents="none" style={styles.miraWrap}>
          <View style={styles.mira} />
          <Text style={styles.miraTxt}>{titulo}</Text>
          {erro && <Text style={styles.miraErro}>Código não encontrado — tente de novo.</Text>}
        </View>

        {/* overlay da tela (feedback do posto, contadores) — por cima da câmera */}
        {!!overlay && (
          <View pointerEvents="box-none" style={styles.overlayWrap}>{overlay}</View>
        )}

        <Pressable style={styles.fechar} onPress={aoFechar}>
          <Text style={styles.fecharTxt}>✕ Fechar</Text>
        </Pressable>
      </View>
    </Modal>
  );
}

// permite à tela sinalizar "não achei" e rearmar a leitura
Scanner.rearmar = null;

const styles = StyleSheet.create({
  tela: { flex: 1, backgroundColor: '#000' },
  centro: { flex: 1, alignItems: 'center', justifyContent: 'center', padding: 30 },
  aviso: { color: '#fff', fontSize: 14, fontFamily: fonte.sansSemi, textAlign: 'center', lineHeight: 21 },
  miraWrap: { ...StyleSheet.absoluteFillObject, alignItems: 'center', justifyContent: 'center', gap: 14 },
  mira: {
    width: 240, height: 240, borderRadius: raio.card,
    borderWidth: 3, borderColor: cores.lime, backgroundColor: 'transparent',
  },
  miraTxt: { color: '#fff', fontSize: 14, fontFamily: fonte.sansBold, textShadowColor: '#000', textShadowRadius: 6 },
  miraErro: { color: '#ffb4a8', fontSize: 13, fontFamily: fonte.sansBold, textShadowColor: '#000', textShadowRadius: 6 },
  overlayWrap: { position: 'absolute', top: 0, left: 0, right: 0, paddingTop: 54, paddingHorizontal: 14, gap: 8 },
  fechar: {
    position: 'absolute', bottom: 40, alignSelf: 'center',
    paddingHorizontal: 26, height: 50, borderRadius: 25,
    backgroundColor: 'rgba(255,255,255,0.92)', alignItems: 'center', justifyContent: 'center',
  },
  fecharTxt: { fontSize: 14, color: cores.ink, fontFamily: fonte.sansBold },
});
