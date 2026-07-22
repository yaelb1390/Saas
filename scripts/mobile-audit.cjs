// Audita la app a ancho de móvil: inicia sesión, visita las pantallas clave a 390px y reporta
// qué elementos se salen del ancho (la causa de que aparezca scroll horizontal / se rompa).
// Uso: node scripts/mobile-audit.cjs
const puppeteer = require('puppeteer-core');

const CHROME = 'C:/Program Files/Google/Chrome/Application/chrome.exe';
const BASE = process.env.BASE || 'http://localhost:8000';
const EMAIL = process.env.EMAIL || 'owner@bmbusiness.os';
const PASS = process.env.PASS || 'password';
const WIDTH = 390, HEIGHT = 844; // iPhone 12/13/14

const PAGES = [
    ['login', '/login', false],
    ['dashboard', '/dashboard', true],
    ['pos', '/panel/pos', true],
    ['stock-entry', '/panel/inventario/entradas', true],
    ['products', '/panel/inventario', true],
    ['purchases', '/panel/compras', true],
    ['sales', '/panel/ventas', true],
    ['invoices', '/panel/facturas', true],
    ['reports', '/panel/reportes', true],
];

(async () => {
    const browser = await puppeteer.launch({
        executablePath: CHROME,
        headless: 'new',
        args: ['--no-sandbox', '--disable-dev-shm-usage'],
    });
    const page = await browser.newPage();
    await page.setViewport({ width: WIDTH, height: HEIGHT, isMobile: true, deviceScaleFactor: 2 });

    // Login.
    await page.goto(BASE + '/login', { waitUntil: 'networkidle2' });
    await page.type('input[name="email"]', EMAIL);
    await page.type('input[name="password"]', PASS);
    await Promise.all([
        page.click('button[type="submit"]'),
        page.waitForNavigation({ waitUntil: 'networkidle2' }).catch(() => {}),
    ]);

    for (const [name, path, needsAuth] of PAGES) {
        try {
            await page.goto(BASE + path, { waitUntil: 'networkidle2' });
            const report = await page.evaluate((vw) => {
                const docW = document.documentElement.scrollWidth;
                const overflow = docW > window.innerWidth + 1;
                // Elementos cuyo borde derecho se sale del viewport.
                const culpables = [];
                document.querySelectorAll('*').forEach((el) => {
                    const r = el.getBoundingClientRect();
                    if (r.width > 0 && r.right > vw + 1 && r.left >= 0) {
                        const cls = (el.className && el.className.toString) ? el.className.toString().slice(0, 60) : '';
                        culpables.push(`${el.tagName.toLowerCase()}.${cls.replace(/\s+/g, '.')} right÷${Math.round(r.right)} w${Math.round(r.width)}`);
                    }
                });
                // Dedup y top 6 por ancho.
                const uniq = [...new Set(culpables)].slice(0, 6);
                return { docW, vw: window.innerWidth, overflow, uniq };
            }, WIDTH);

            const flag = report.overflow ? 'DESBORDA' : 'ok';
            console.log(`\n[${flag}] ${name}  (docW=${report.docW} vw=${report.vw})`);
            if (report.overflow) report.uniq.forEach((c) => console.log('   → ' + c));
            await page.screenshot({ path: `scripts/shot-${name}.png` });
        } catch (e) {
            console.log(`\n[ERROR] ${name}: ${e.message.split('\n')[0]}`);
        }
    }

    await browser.close();
})();
