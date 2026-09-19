// Corrige la configuración de la función PHP que genera `vercel build` para el bootstrap nuevo de
// Vercel. Ver docs/DEPLOY_VERCEL_PREBUILT.md.
//
// Uso: node patch-config.js <ruta al .vc-config.json> [--debug]
// Parte SIEMPRE de la config original que guardó build.sh (/data/vc-config.orig.json).
const fs = require("fs");

const [destino, ...banderas] = process.argv.slice(2);
const debug = banderas.includes("--debug");
const c = JSON.parse(fs.readFileSync("/data/vc-config.orig.json", "utf8"));

// 1. Modo Lambda. Sin `launcherType` y `awsLambdaHandler`, el bootstrap nuevo trata el handler como un
//    módulo con export por defecto ("The default export must be a function or server").
c.handler = "launcher.js";
c.launcherType = "Nodejs";
c.awsLambdaHandler = "launcher.launcher";

// 2 y 3. Launcher "puente". El original (vercel-php) se conserva como launcher-orig.js.
//  - LAMBDA_TASK_ROOT ya no existe en el bootstrap nuevo y el launcher calcula /user y /php con
//    `LAMBDA_TASK_ROOT || "/"` (→ "spawn php ENOENT"). Es una variable RESERVADA: declararla en
//    `environment` hace fallar el deploy, así que se define en código.
//  - El evento llega estilo API Gateway v1: el cuerpo es un ARRAY de bytes (o null en GET) y el
//    launcher hace `req.write(body)`, que solo admite string/Buffer.
const puente = `process.env.LAMBDA_TASK_ROOT = process.env.LAMBDA_TASK_ROOT || "/var/task";
const orig = require("./launcher-orig.js");

function adaptar(event) {
  const e = { ...event };
  if (Array.isArray(e.body)) e.body = Buffer.from(e.body);
  else if (typeof e.body === "string") e.body = Buffer.from(e.body, e.isBase64Encoded ? "base64" : "utf8");
  else e.body = undefined;
  const h = e.headers || {};
  e.host = e.host || h["x-forwarded-host"] || h.host;
  return e;
}

exports.launcher = async function (event, context) {
  const e = adaptar(event);
${debug ? '  console.log("ADAPT method=" + e.httpMethod + " path=" + e.path + " body=" + (e.body ? "Buffer(" + e.body.length + ")" : "none"));\n' : ""}  return orig.launcher(e, context);
};
`;

c.filePathMap["launcher-orig.js"] = c.filePathMap["launcher.js"];
fs.mkdirSync(".vercel/wrapper", { recursive: true });
fs.writeFileSync(".vercel/wrapper/launcher.js", puente);
c.filePathMap["launcher.js"] = ".vercel/wrapper/launcher.js";

fs.writeFileSync(destino, JSON.stringify(c, null, 2));

const { filePathMap, ...resto } = c;
console.log("config de la función: " + JSON.stringify(resto));
