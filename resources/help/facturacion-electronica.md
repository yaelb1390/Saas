---
title: Facturación electrónica DGII (e-CF)
module: e_invoicing
permission: ecf.view
keywords: e-cf, ecf, factura electronica, facturacion electronica, e-ncf, certificado digital, firma digital, ley 32-23
related: facturacion, compras-606
route: panel.e-invoicing
---

Aquí se prepara tu empresa para emitir **facturas electrónicas (e-CF)** ante la DGII.

**Importante:** configurar esta sección **no es una autorización de la DGII**. Para emitir e-CF con validez fiscal necesitas, a tu nombre, un certificado digital para procesos tributarios, acceso a la Oficina Virtual de la DGII y las secuencias de e-NCF que ella te autorice.

Por ahora puedes:

- **Registrar tus secuencias de e-NCF** (serie E) por tipo y ambiente. Un número rechazado por la DGII solo se vuelve a usar cuando ella lo permite.
- **Subir tu certificado digital** (archivo .p12 y su contraseña). Se guarda cifrado y nunca se muestra; te avisamos 30 días antes de que venza.

- **Recibir los e-CF de tus proveedores**: en «Comprobantes recibidos» están las direcciones que registras en la DGII. Cada e-CF que llega recibe su acuse y desde ahí lo **aceptas o rechazas** (aprobación comercial).
- **Anular números sin usar** de una secuencia ante la DGII, cuando ya no la vas a usar.
- Revisar el **Diagnóstico**, que te dice qué falta y cómo resolverlo.

## Conectar tu proveedor autorizado (PSFE)

Si trabajas con un **proveedor de servicios de facturación electrónica autorizado por la DGII**, puedes conectarlo en vez de configurar tú la firma:

1. En **Conecta tu proveedor autorizado**, elige tu proveedor.
2. Escribe los datos de tu cuenta con él (por ejemplo, su clave de API).
3. Pulsa **Conectar y probar**. BMIA comprueba la conexión antes de guardar nada; si el proveedor no acepta los datos, te dice por qué y no cambia tu configuración.

Si tu proveedor **firma por ti**, ya no necesitas subir el certificado digital: el paso aparece como hecho. Las secuencias de e-NCF sí las sigues registrando, porque te las autoriza la DGII a ti.

Tus datos de acceso se guardan cifrados y no se vuelven a mostrar. Desde la misma tarjeta puedes **Probar otra vez** o **Desconectar**. Si desconectas y no tienes certificado, la emisión de e-CF se apaga, porque ya no hay con qué firmar.

La conexión directa con la DGII y el certificado propio siguen disponibles igual que antes.

Tu empresa empieza en el ambiente de **pruebas**, con un proveedor de prueba que no envía nada a la DGII. Los documentos fiscales se conservan 10 años, aunque se elimine la cuenta.
