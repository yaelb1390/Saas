# Base de conocimiento (RAG)

Los textos que se cargan en **Administración › IA › Base de conocimiento**, para que el asistente
pueda responder sobre ellos.

## Por qué están aquí y no solo en la base de datos

Una vez indexado, el texto queda troceado en fragmentos con sus vectores y **deja de ser cómodo de
leer o de corregir**. Guardar aquí el original permite ver qué cambió y cuándo, revisar una frase
antes de que un cliente la lea, y volver a indexar desde una versión conocida si alguien la borra.

Es lo mismo que se hace con las migraciones: el estado vive en la base de datos, la fuente vive aquí.

## Cómo se cargan

Se pegan **tal cual** en el formulario «+ Documento» de esa pantalla. Son ficheros de texto plano a
propósito: lo que se pegue es literalmente lo que va a leer el modelo, así que un `#` o un `**` de
Markdown acabaría dentro de la respuesta a un cliente.

Indexar **cuesta dinero**: se genera una llamada de embeddings por cada fragmento, contra la clave
del proveedor configurado. Conviene revisar el texto antes, no después.

Y hay que hacerlo desde la propia pantalla, no desde fuera: la clave del proveedor está cifrada con
el `APP_KEY` del entorno, así que solo ese entorno puede descifrarla y llamar al modelo.

## Al cambiar de proveedor de IA

Cada fragmento recuerda con qué proveedor y con qué tamaño de vector se indexó, y solo se compara lo
que casa con el proveedor de ahora. Si se cambia de proveedor, **hay que volver a indexar**: los
documentos viejos siguen en la lista pero el asistente deja de encontrarlos.

## Qué hay

| Fichero | De qué trata |
|---|---|
| `bm-business-os.txt` | Qué es la plataforma, para quién, los planes con sus precios y límites, los módulos, DGII/NCF, y cómo funciona el bot de WhatsApp. |

## Lo que NO debe entrar aquí

- **Teléfonos y correos que no estén confirmados.** Los valores por omisión de
  `config/platform.php` son marcadores (`18090000000`). Indexar eso le daría a un cliente un número
  que no existe.
- Plazos, horarios, garantías o políticas de reembolso que no haya decidido el negocio. El asistente
  responde solo con lo que está escrito aquí: lo que se escriba, lo va a decir.
- Cualquier dato personal de un cliente concreto. Esto es texto de empresa, no fichas.
