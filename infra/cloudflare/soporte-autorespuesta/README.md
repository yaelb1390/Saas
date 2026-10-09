# Respuesta automática de soporte@bm1390.cloud

Cada correo que llega a **soporte@bm1390.cloud**:

1. se **reenvía** al Gmail del equipo, igual que hasta ahora;
2. recibe al momento una **respuesta automática desde soporte@bm1390.cloud**: «Recibimos tu mensaje y te
   responderemos a la mayor brevedad posible», con el WhatsApp para urgencias.

Para no molestar ni crear bucles, a la misma persona se le contesta **como mucho una vez cada 24 horas**.
Tampoco se contesta a correos automáticos (rebotes, listas, boletines, `noreply`, otros contestadores) ni a
remitentes de `bm1390.cloud`. El texto se cambia en las constantes del principio de `worker.js`.

## Puesta en marcha (desde el panel de Cloudflare, sin consola)

1. **Storage & Databases → KV → Create**, con el nombre `AUTORESPUESTAS`.
2. **Workers & Pages → Create → Worker**, con el nombre `soporte-autorespuesta`.
   - **Edit code**: borrar lo que trae, pegar todo `worker.js` y pulsar **Deploy**.
3. En el Worker, abrir **Settings**:
   - **Variables and Secrets → Add**: `DESTINO` = el Gmail al que llega hoy soporte@ (el destino
     verificado en Email Routing).
   - **Bindings → Add → KV namespace**: nombre de variable `AUTORESPUESTAS`, namespace `AUTORESPUESTAS`.
4. **Email Routing** (Compute → Email Service → Email Routing → bm1390.cloud) → pestaña **Routing rules**:
   - editar la regla de `soporte@bm1390.cloud`;
   - cambiar la acción a **Send to a Worker** → `soporte-autorespuesta` → Save.

**Probarlo:** escribir desde otro correo (por ejemplo, un Gmail personal) a soporte@bm1390.cloud.

- Debe llegar la respuesta automática y el correo debe seguir apareciendo en el Gmail del equipo.
- En el Worker, **Logs** muestra cada envío y por qué se saltó una respuesta.

**Volver atrás:** en la misma regla, poner de nuevo la acción **Send to an email** → el Gmail. Queda como
estaba al instante.

## Despliegue por consola (alternativa)

```
cd infra/cloudflare/soporte-autorespuesta
npx wrangler login
npx wrangler kv namespace create AUTORESPUESTAS   # copiar el id en wrangler.toml
# poner el Gmail destino en DESTINO de wrangler.toml
npx wrangler deploy
```

Después, el paso 4 de arriba (la regla de Email Routing).

## Límites de Cloudflare

- Cloudflare solo deja responder si el correo entrante trae un **DMARC válido**. Los grandes proveedores
  (Gmail, Outlook, Yahoo…) lo traen. Si algún remitente no lo trae, su correo se reenvía igual, pero no
  recibe la respuesta automática: queda anotado en Logs.
- Se responde una sola vez por correo y siempre al remitente original.

## Pruebas

`node infra/cloudflare/soporte-autorespuesta/prueba.mjs` simula correos sin conectarse a nada:

- siempre reenvía;
- contesta a un correo normal y una sola vez en 24 h;
- no contesta a correos automáticos, listas, `noreply` ni al propio dominio;
- el MIME lleva bien los acentos.
