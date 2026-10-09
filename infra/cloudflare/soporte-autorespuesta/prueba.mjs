// Prueba del Worker sin red: simula los correos que llegan y comprueba lo que haría.
// Uso: node infra/cloudflare/soporte-autorespuesta/prueba.mjs
import assert from 'node:assert/strict';
import worker, { construirRespuesta, motivoParaNoContestar } from './worker.js';

class EmailMessageFalso {
    constructor(from, to, raw) { Object.assign(this, { from, to, raw }); }
}

function correo(from, cabeceras = {}, { falla = false } = {}) {
    const h = new Map(Object.entries({ subject: 'Necesito ayuda con mi cuenta', 'message-id': '<abc123@gmail.com>', ...cabeceras }).map(([k, v]) => [k.toLowerCase(), v]));
    const m = {
        from, to: 'soporte@bm1390.cloud',
        headers: { get: (n) => h.get(n.toLowerCase()) ?? null },
        reenviados: [], respuestas: [],
        async forward(a) { m.reenviados.push(a); },
        async reply(r) { if (falla) throw new Error('DMARC no válido'); m.respuestas.push(r); },
    };
    return m;
}

function kvFalso() {
    const d = new Map();
    return { d, async get(k) { return d.get(k) ?? null; }, async put(k, v, o) { d.set(k, { v, o }); } };
}

const env = () => ({ DESTINO: 'equipo@gmail.com', AUTORESPUESTAS: kvFalso(), __EmailMessage: EmailMessageFalso });
const log = console.log; console.log = () => {};
let n = 0;
const prueba = async (nombre, fn) => { await fn(); n++; log('✓', nombre); };

await prueba('reenvía y contesta a un correo normal, desde soporte@ y en el mismo hilo', async () => {
    const e = env(); const m = correo('cliente@gmail.com');
    await worker.email(m, e);
    assert.deepEqual(m.reenviados, ['equipo@gmail.com']);
    assert.equal(m.respuestas.length, 1);
    const r = m.respuestas[0];
    assert.equal(r.from, 'soporte@bm1390.cloud');
    assert.equal(r.to, 'cliente@gmail.com');
    assert.match(r.raw, /^Subject: Re: Necesito ayuda con mi cuenta\r\n/m);
    assert.match(r.raw, /^In-Reply-To: <abc123@gmail\.com>\r\n/m);
    assert.match(r.raw, /^Auto-Submitted: auto-replied\r\n/m);
    assert.ok(e.AUTORESPUESTAS.d.get('respondido:cliente@gmail.com').o.expirationTtl === 86400);
});

await prueba('a la misma persona no se le repite antes de 24 h (pero el correo se reenvía)', async () => {
    const e = env();
    await worker.email(correo('cliente@gmail.com'), e);
    const segundo = correo('cliente@gmail.com');
    await worker.email(segundo, e);
    assert.deepEqual(segundo.reenviados, ['equipo@gmail.com']);
    assert.equal(segundo.respuestas.length, 0);
});

await prueba('no contesta a correos automáticos, listas, noreply, rebotes ni al propio dominio', async () => {
    const casos = [
        correo('alguien@gmail.com', { 'auto-submitted': 'auto-replied' }),
        correo('news@tienda.com', { 'list-id': '<ofertas.tienda.com>' }),
        correo('promo@tienda.com', { precedence: 'bulk' }),
        correo('noreply@polar.sh'),
        correo('no-reply@accounts.google.com'),
        correo('MAILER-DAEMON@mx.google.com'),
        correo('yael@bm1390.cloud'),
        correo(''),
    ];
    for (const m of casos) {
        await worker.email(m, env());
        assert.deepEqual(m.reenviados, ['equipo@gmail.com'], 'siempre se reenvía');
        assert.equal(m.respuestas.length, 0, 'no se contesta a ' + m.from);
        assert.ok(motivoParaNoContestar(m));
    }
});

await prueba('si Cloudflare rechaza la respuesta, el correo ya estaba reenviado y no se marca como respondido', async () => {
    const e = env(); const m = correo('sin-dmarc@dominio-raro.com', {}, { falla: true });
    await worker.email(m, e);
    assert.deepEqual(m.reenviados, ['equipo@gmail.com']);
    assert.equal(e.AUTORESPUESTAS.d.size, 0);
});

await prueba('sin KV contesta igual (a cada correo)', async () => {
    const e = { DESTINO: 'equipo@gmail.com', __EmailMessage: EmailMessageFalso };
    const m = correo('otro@outlook.com');
    await worker.email(m, e);
    assert.equal(m.respuestas.length, 1);
});

await prueba('los acentos y la ñ llegan bien (UTF-8 en base64) y el asunto con acentos va codificado', async () => {
    const m = correo('cliente@gmail.com', { subject: 'Facturación electrónica' });
    const raw = construirRespuesta(m);
    assert.match(raw, /^Subject: =\?UTF-8\?B\?.+\?=\r\n/m);
    const partes = raw.split(/--bm-[a-z0-9]+/i).filter((p) => p.includes('Content-Transfer-Encoding: base64'));
    const texto = Buffer.from(partes[0].split('\r\n\r\n')[1].replace(/\s+/g, ''), 'base64').toString('utf8');
    assert.match(texto, /te responderemos a la mayor brevedad posible/);
    assert.match(texto, /escríbenos por WhatsApp al \+1 829-651-2447/);
    const html = Buffer.from(partes[1].split('\r\n\r\n')[1].replace(/\s+/g, ''), 'base64').toString('utf8');
    assert.match(html, /https:\/\/wa\.me\/18296512447/);
});

await prueba('un asunto con saltos de línea no puede inyectar cabeceras', async () => {
    const raw = construirRespuesta(correo('cliente@gmail.com', { subject: 'Hola\r\nBcc: victima@x.com' }));
    assert.doesNotMatch(raw, /^Bcc:/m);
});

console.log = log;
log(`\n${n} pruebas en verde`);
