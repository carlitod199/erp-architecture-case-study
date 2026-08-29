import uuid from 'react-native-uuid';

// Todo registro criado offline nasce com um client_uuid.
// O endpoint de escrita é idempotente por esse UUID: reenviar o mesmo
// devolve o registro já criado, nunca duplica (decisão D5).
export function novoClientUuid() {
  return String(uuid.v4());
}

export default { novoClientUuid };
