// Comprueba si la tarjeta de acceso cabe entera en pantallas reales, en vez de fiarse de la vista.
// Uso: node scripts/medir-login.cjs
const puppeteer = require('puppeteer-core');

const CHROME = 'C:/Program Files/Google/Chrome/Application/chrome.exe';
const BASE = process.env.BASE || 'http://localhost:8000';

const PANTALLAS = [
  ['Galaxy S8/S20 (360x740)', 360, 740],
  ['iPhone SE (375x667)', 375, 667],
  ['iPhone 12/13 (390x844)', 390, 844],
  ['Pixel 7 (412x915)', 412, 915],
  ['Movil horizontal (740x360)', 740, 360],
  ['Portatil bajo (1366x600)', 1366, 600],
  ['Escritorio (1920x1080)', 1920, 1080],
];

(async () => {
  const browser = await puppeteer.launch({ executablePath: CHROME, headless: 'new' });

  for (const [nombre, w, h] of PANTALLAS) {
    const page = await browser.newPage();
    await page.setViewport({ width: w, height: h, deviceScaleFactor: 1 });

    for (const ruta of ['/login', '/registro']) {
      // `domcontentloaded` y no `networkidle0`: el service worker mantiene conexiones abiertas y
      // la red nunca queda del todo en reposo.
      await page.goto(`${BASE}${ruta}`, { waitUntil: 'domcontentloaded' });
      await page.evaluate(() => document.fonts.ready);

      const m = await page.evaluate(() => {
        const card = document.querySelector('.bmos-auth-card');
        const r = card.getBoundingClientRect();
        return {
          alto: Math.round(r.height),
          arriba: Math.round(r.top),
          abajo: Math.round(r.bottom),
          ventana: window.innerHeight,
          desbordaX: document.documentElement.scrollWidth > window.innerWidth,
        };
      });

      const cabe = m.arriba >= 0 && m.abajo <= m.ventana;
      const marca = cabe ? 'CABE  ' : 'scroll';
      const x = m.desbordaX ? '  DESBORDA-X' : '';

      console.log(
        `${marca} ${nombre.padEnd(28)} ${ruta.padEnd(10)} tarjeta=${String(m.alto).padStart(4)}px ventana=${m.ventana}px${x}`,
      );
    }

    await page.close();
  }

  await browser.close();
})();
