/**
 * Respuesta automática de soporte@bm1390.cloud (Cloudflare Email Worker).
 *
 * El buzón no es un buzón propio: lo recibe Cloudflare Email Routing y lo reenvía al Gmail del equipo.
 * Este Worker se pone en ese mismo camino (regla «soporte@» → Send to a Worker) y, por cada correo:
 *   1. lo REENVÍA al Gmail como hasta ahora (primero, para que nunca se pierda aunque lo demás falle);
 *   2. le CONTESTA al remitente, desde soporte@bm1390.cloud, que le responderemos pronto.
 *
 * Sin dependencias: el MIME se arma aquí, así se puede pegar tal cual en el editor del panel de
 * Cloudflare sin compilar nada.
 *
 * Reglas para no molestar ni crear bucles:
 *   · no se contesta a correos automáticos (Auto-Submitted, listas, boletines, rebotes, noreply);
 *   · no se contesta a nadie del propio dominio;
 *   · a la misma persona, como mucho UNA respuesta cada 24 h (Workers KV `AUTORESPUESTAS`).
 *
 * Requisitos de Cloudflare para `message.reply()` (developers.cloudflare.com/email-routing/
 * email-workers/reply-email-workers/): el correo entrante debe traer un DMARC válido, una sola
 * respuesta por correo, al remitente original y desde el dominio que lo recibió. Si Cloudflare la
 * rechaza, solo se anota en los registros: el reenvío ya se hizo.
 *
 * Configuración (Settings del Worker):
 *   · variable `DESTINO`: el Gmail al que se reenvía (debe estar verificado en Email Routing);
 *   · KV `AUTORESPUESTAS` (opcional; sin él se contesta a cada correo).
 */

// ── Lo que dice la respuesta. Cambiar aquí. ─────────────────────────────────────────────────────
const NOMBRE = 'BM Business OS';
const WHATSAPP_VISIBLE = '+1 829-651-2447';
const WHATSAPP_ENLACE = 'https://wa.me/18296512447';
const HORAS_ENTRE_RESPUESTAS = 24;

const TEXTO = [
    'Hola, gracias por escribir a ' + NOMBRE + '.',
    '',
    'Recibimos tu mensaje y te responderemos a la mayor brevedad posible.',
    '',
    'Si es urgente, escríbenos por WhatsApp al ' + WHATSAPP_VISIBLE + ': ' + WHATSAPP_ENLACE,
    '',
    '— Equipo de soporte · ' + NOMBRE,
    '',
    'Este es un mensaje automático. Si respondes a este correo, lo recibiremos igualmente.',
].join('\r\n');

const HTML = `<!doctype html><html lang="es"><body style="margin:0;padding:24px;background:#f5f7fb;font-family:Arial,Helvetica,sans-serif;color:#1e2230">
<div style="max-width:520px;margin:0 auto;background:#ffffff;border:1px solid #e2e6f0;border-radius:12px;padding:28px">
<p style="margin:0 0 14px;font-size:16px">Hola, gracias por escribir a <b>${NOMBRE}</b>.</p>
<p style="margin:0 0 14px;font-size:15px;line-height:1.5">Recibimos tu mensaje y te responderemos <b>a la mayor brevedad posible</b>.</p>
<p style="margin:0 0 20px;font-size:15px;line-height:1.5">Si es urgente, escríbenos por WhatsApp:</p>
<p style="margin:0 0 24px"><a href="${WHATSAPP_ENLACE}" style="display:inline-block;background:#059669;color:#ffffff;text-decoration:none;font-weight:bold;padding:12px 20px;border-radius:8px">WhatsApp ${WHATSAPP_VISIBLE}</a></p>
<p style="margin:0;font-size:14px;color:#4b5563">— Equipo de soporte · ${NOMBRE}</p>
</div>
<p style="max-width:520px;margin:12px auto 0;font-size:12px;color:#6b7280;text-align:center">Este es un mensaje automático. Si respondes a este correo, lo recibiremos igualmente.</p>
</body></html>`;

// ── El Worker ─────────────────────────────────────────────────────────────────────────────────
export default {
    async email(message, env, ctx) {
        // 1. Reenviar SIEMPRE y primero: el correo llega al equipo pase lo que pase después.
        if (env.DESTINO) {
            await message.forward(env.DESTINO);
        } else {
            console.log('Sin DESTINO configurado: el correo no se reenvía.');
        }

        // 2. ¿Toca contestar?
        const motivo = motivoParaNoContestar(message);
        if (motivo) {
            console.log('Sin respuesta automática para', message.from, '·', motivo);
            return;
        }

        const remitente = String(message.from).toLowerCase();
        const clave = 'respondido:' + remitente;

        if (env.AUTORESPUESTAS && (await env.AUTORESPUESTAS.get(clave))) {
            console.log('Ya se le contestó en las últimas', HORAS_ENTRE_RESPUESTAS, 'h:', remitente);
            return;
        }

        // 3. Contestar. Un fallo (p. ej. remitente sin DMARC válido) no afecta al reenvío.
        try {
            const EmailMessage = env.__EmailMessage ?? (await import('cloudflare:email')).EmailMessage;
            const cruda = construirRespuesta(message);
            await message.reply(new EmailMessage(message.to, message.from, cruda));

            if (env.AUTORESPUESTAS) {
                await env.AUTORESPUESTAS.put(clave, new Date().toISOString(), { expirationTtl: HORAS_ENTRE_RESPUESTAS * 3600 });
            }
            console.log('Respuesta automática enviada a', remitente);
        } catch (e) {
            console.log('No se pudo enviar la respuesta automática a', remitente, '·', e && e.message ? e.message : e);
        }
    },
};

/** Devuelve por qué NO se contesta, o null si sí. Evita bucles con otros contestadores y el spam a listas. */
export function motivoParaNoContestar(message) {
    const h = (nombre) => (message.headers.get(nombre) || '').toLowerCase().trim();
    const de = String(message.from || '').toLowerCase();
    const dominioPropio = String(message.to || '').toLowerCase().split('@')[1] || '';

    if (!de.includes('@')) return 'remitente vacío (rebote)';
    if (h('auto-submitted') && h('auto-submitted') !== 'no') return 'mensaje automático (Auto-Submitted)';
    if (['bulk', 'list', 'junk', 'auto_reply'].includes(h('precedence'))) return 'envío masivo (Precedence)';
    if (h('list-id') || h('list-unsubscribe')) return 'lista o boletín';
    if (h('x-autoreply') || h('x-autorespond')) return 'otro contestador automático';
    if (/^(mailer-daemon|postmaster)@/.test(de) || /no-?reply|do-?not-?reply/.test(de)) return 'dirección que no recibe respuestas';
    if (dominioPropio && de.endsWith('@' + dominioPropio)) return 'remitente del propio dominio';

    return null;
}

/** El correo de respuesta en MIME (texto + HTML, UTF-8), como respuesta en el mismo hilo. */
export function construirRespuesta(message) {
    const asuntoOriginal = limpiarCabecera(message.headers.get('subject') || '');
    const asunto = /^re:/i.test(asuntoOriginal) ? asuntoOriginal : 'Re: ' + (asuntoOriginal || 'Tu mensaje a soporte');
    const idOriginal = limpiarCabecera(message.headers.get('message-id') || '');
    const dominio = String(message.to).split('@')[1] || 'localhost';
    const frontera = 'bm-' + aleatorio();

    const cabeceras = [
        'From: ' + codificarPalabra('Soporte ' + NOMBRE) + ' <' + message.to + '>',
        'To: <' + message.from + '>',
        'Subject: ' + codificarPalabra(asunto),
        'Date: ' + new Date().toUTCString().replace('GMT', '+0000'),
        'Message-ID: <' + aleatorio() + '@' + dominio + '>',
        ...(idOriginal ? ['In-Reply-To: ' + idOriginal, 'References: ' + idOriginal] : []),
        // Marca estándar (RFC 3834): otros contestadores no responden a esta respuesta.
        'Auto-Submitted: auto-replied',
        'X-Auto-Response-Suppress: All',
        'MIME-Version: 1.0',
        'Content-Type: multipart/alternative; boundary="' + frontera + '"',
    ];

    const parte = (tipo, contenido) => [
        '--' + frontera,
        'Content-Type: ' + tipo + '; charset=UTF-8',
        'Content-Transfer-Encoding: base64',
        '',
        base64Utf8(contenido),
    ].join('\r\n');

    return [
        cabeceras.join('\r\n'),
        '',
        parte('text/plain', TEXTO),
        parte('text/html', HTML),
        '--' + frontera + '--',
        '',
    ].join('\r\n');
}

/** Quita saltos de línea de un valor de cabecera (evita inyectar cabeceras). */
function limpiarCabecera(valor) {
    return String(valor).replace(/[\r\n]+/g, ' ').trim();
}

/** Codifica una cabecera con acentos (RFC 2047); si ya es ASCII o ya viene codificada, la deja igual. */
function codificarPalabra(texto) {
    if (/^[\x20-\x7e]*$/.test(texto)) return texto;
    return '=?UTF-8?B?' + base64Utf8(texto, false) + '?=';
}

function base64Utf8(texto, cortar = true) {
    const bytes = new TextEncoder().encode(texto);
    let binario = '';
    for (let i = 0; i < bytes.length; i += 0x8000) {
        binario += String.fromCharCode.apply(null, bytes.subarray(i, i + 0x8000));
    }
    const b64 = btoa(binario);
    return cortar ? b64.replace(/.{1,76}/g, (l) => l + '\r\n').trimEnd() : b64;
}

function aleatorio() {
    return (crypto.randomUUID ? crypto.randomUUID() : Date.now().toString(36) + Math.random().toString(36).slice(2)).replace(/-/g, '');
}
