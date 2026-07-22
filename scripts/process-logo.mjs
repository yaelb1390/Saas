// Procesa el logo de BM Business OS:
//  1) Quita el fondo blanco (lo hace transparente) sin tocar el contenido.
//  2) Genera el lockup completo recortado -> public/images/bm-logo.png  (para el login, sobre blanco).
//  3) Detecta y recorta solo el símbolo (parte superior) -> public/images/bm-mark.png  (para la barra
//     lateral y el favicon, sobre fondo oscuro).
//
// Uso:  node scripts/process-logo.mjs [ruta-origen]
// Origen por defecto: public/images/bm-logo-src.png (PNG).

import fs from 'node:fs';
import path from 'node:path';
import { PNG } from 'pngjs';

const SRC = process.argv[2] || 'public/images/bm-logo-src.png';
const OUT_FULL = 'public/images/bm-logo.png';
const OUT_MARK = 'public/images/bm-mark.png';
const WHITE = 242; // umbral: r,g,b por encima => se considera fondo blanco

if (!fs.existsSync(SRC)) {
    console.error(`No se encontró el archivo de origen: ${SRC}`);
    console.error('Guarda tu logo (PNG) en public/images/bm-logo-src.png y vuelve a ejecutar.');
    process.exit(1);
}

const png = PNG.sync.read(fs.readFileSync(SRC));
const { width, height, data } = png;

// 1) Fondo blanco -> transparente (con suavizado en los bordes casi-blancos).
for (let i = 0; i < data.length; i += 4) {
    const r = data[i], g = data[i + 1], b = data[i + 2];
    if (r >= WHITE && g >= WHITE && b >= WHITE) {
        data[i + 3] = 0;
    } else {
        const lightness = Math.min(r, g, b);
        if (lightness > 225) {
            // borde casi-blanco: reduce alfa proporcionalmente para un recorte limpio
            data[i + 3] = Math.round(data[i + 3] * (1 - (lightness - 225) / (255 - 225)) * 0.9);
        }
    }
}

const alphaAt = (x, y) => data[(y * width + x) * 4 + 3];

// Cobertura opaca por fila y por columna
const rowCov = new Array(height).fill(0);
for (let y = 0; y < height; y++) {
    let c = 0;
    for (let x = 0; x < width; x++) if (alphaAt(x, y) > 20) c++;
    rowCov[y] = c;
}

function bbox(y0, y1) {
    let minX = width, minY = height, maxX = -1, maxY = -1;
    for (let y = y0; y < y1; y++) {
        for (let x = 0; x < width; x++) {
            if (alphaAt(x, y) > 20) {
                if (x < minX) minX = x;
                if (x > maxX) maxX = x;
                if (y < minY) minY = y;
                if (y > maxY) maxY = y;
            }
        }
    }
    return { minX, minY, maxX, maxY };
}

function crop({ minX, minY, maxX, maxY }, pad = 6) {
    minX = Math.max(0, minX - pad); minY = Math.max(0, minY - pad);
    maxX = Math.min(width - 1, maxX + pad); maxY = Math.min(height - 1, maxY + pad);
    const w = maxX - minX + 1, h = maxY - minY + 1;
    const out = new PNG({ width: w, height: h });
    for (let y = 0; y < h; y++) {
        for (let x = 0; x < w; x++) {
            const s = ((minY + y) * width + (minX + x)) * 4;
            const d = (y * w + x) * 4;
            out.data[d] = data[s];
            out.data[d + 1] = data[s + 1];
            out.data[d + 2] = data[s + 2];
            out.data[d + 3] = data[s + 3];
        }
    }
    return out;
}

// 2) Lockup completo
const full = crop(bbox(0, height));
fs.mkdirSync(path.dirname(OUT_FULL), { recursive: true });
fs.writeFileSync(OUT_FULL, PNG.sync.write(full));

// 3) Símbolo: primera fila con contenido -> buscar el mayor "hueco" (banda casi vacía) que
//    separa el símbolo del texto; recortar por encima de ese hueco.
const thresh = Math.max(2, width * 0.004);
let top = 0;
while (top < height && rowCov[top] < thresh) top++;
let bottom = height - 1;
while (bottom > top && rowCov[bottom] < thresh) bottom--;

let gapStart = -1, gapLen = 0, bestStart = -1, bestLen = 0;
for (let y = top; y <= bottom; y++) {
    if (rowCov[y] < thresh) {
        if (gapStart === -1) gapStart = y;
        gapLen++;
        if (gapLen > bestLen) { bestLen = gapLen; bestStart = gapStart; }
    } else {
        gapStart = -1; gapLen = 0;
    }
}

const symbolBottom = bestStart > top ? bestStart : Math.round(top + (bottom - top) * 0.6);
const mark = crop(bbox(top, symbolBottom), 4);
fs.writeFileSync(OUT_MARK, PNG.sync.write(mark));

console.log(`OK -> ${OUT_FULL} (${full.width}x${full.height}) y ${OUT_MARK} (${mark.width}x${mark.height})`);
