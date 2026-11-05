# Guía de uso de `pb/index.php`

## 1. Descripción general

`pb/index.php` es el endpoint del **Proceso B** de `webapp2`.

Su función es recibir una solicitud estructurada desde Paynani u otro sistema autorizado, adaptar el mecanismo de entrega usado por Paynani para el runtime configurado (Hermes u OpenClaw), enviar un prompt a la sesión principal y devolver una respuesta JSON indicando si la solicitud fue aprobada o si ocurrió un error.

La revisión de Paynani determina el mecanismo de transporte; no reemplaza a Paynani
ni convierte a `pb/index.php` en un formulario. La aplicación implementa su propio
adaptador, autenticación, persistencia y correlación mediante `request_id`.

Endpoint público:

```text
https://<DOMINIO>.ngrok.io/pb/index.php
```

El endpoint está diseñado para automatizaciones y sistemas internos. No es un formulario para usuarios finales.

## 2. Diferencia entre `pa/index.php` y `pb/index.php`

### `pa/index.php`

Es el formulario de uso público para un usuario que desea emparejar su dispositivo.

El usuario captura:

- Código de pairing.
- Nombre.
- Código de país.
- Teléfono.
- Correo electrónico.
- Plataforma de chat.

URL:

```text
https://<DOMINIO>.ngrok.io/pa/index.php
```

### `pb/index.php`

Es una API interna para que otros sistemas soliciten el pairing automáticamente.

No muestra un formulario HTML y responde exclusivamente con JSON.

URL:

```text
https://<DOMINIO>.ngrok.io/pb/index.php
```

## 3. Flujo del Proceso B

```text
Paynani, webhook o sistema interno
                |
                | POST JSON autenticado
                v
       pb/index.php
                |
                | Prompt controlado
                v
       Sesión principal de Hermes/OpenClaw
                |
                | pairing approve
                v
       Respuesta JSON al sistema solicitante
```

## 4. Autenticación

El endpoint requiere un token mediante el encabezado HTTP:

```http
Authorization: Bearer WEBAPP2_PROCESS_B_TOKEN
```

También acepta, como alternativa compatible:

```http
X-Process-B-Token: WEBAPP2_PROCESS_B_TOKEN
```

El token se configura localmente en:

```text
/path/to/webapp2/.env
```

Variable utilizada:

```env
WEBAPP2_PROCESS_B_TOKEN=token_privado
```

El archivo `.env` debe tener permisos restringidos:

```bash
chmod 600 /path/to/webapp2/.env
```

Nunca se debe publicar el token en GitHub, HTML, logs, correo o mensajes de chat.

## 5. Datos de entrada

El endpoint acepta una petición `POST` con contenido JSON:

```json
{
  "code": "ABC1234",
  "platform": "telegram",
  "name": "Alexis Diaz",
  "country_code": "+52",
  "phone": "5551234567",
  "email": "usuario@example.com"
}
```

### Campos requeridos

| Campo | Descripción |
|---|---|
| `code` | Código de pairing de 6 a 12 caracteres alfanuméricos. |
| `platform` | Debe ser exactamente `telegram` o `whatsapp`. |
| `name` | Nombre del usuario. |
| `country_code` | Código telefónico, por ejemplo `+52`. |
| `phone` | Número telefónico. |
| `email` | Correo electrónico válido. |

## 6. Ejemplo de petición con cURL

No utilizar el token real en documentación pública. El siguiente ejemplo usa un marcador:

```bash
curl -X POST \
  https://<DOMINIO>.ngrok.io/pb/index.php \
  -H "Authorization: Bearer WEBAPP2_PROCESS_B_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "code": "ABC1234",
    "platform": "telegram",
    "name": "Alexis Diaz",
    "country_code": "+52",
    "phone": "5551234567",
    "email": "usuario@example.com"
  }'
```

## 7. Respuesta exitosa

Cuando Hermes aprueba el pairing, la respuesta tiene esta estructura:

```json
{
  "ok": true,
  "approved": true,
  "request_id": "pb-123",
  "error": null
}
```

## 8. Respuestas de error

### Token inválido

HTTP `401`:

```json
{
  "ok": false,
  "approved": false,
  "error": "Unauthorized."
}
```

### Datos inválidos

HTTP `422`:

```json
{
  "ok": false,
  "approved": false,
  "error": "The platform is invalid."
}
```

### Demasiados intentos

HTTP `429`:

```json
{
  "ok": false,
  "approved": false,
  "error": "Too many pairing attempts."
}
```

### Hermes no aprobó el código

HTTP `502`:

```json
{
  "ok": false,
  "approved": false,
  "request_id": "pb-123",
  "error": "HermesAgent did not approve the pairing."
}
```

### Servicio no configurado o error interno

HTTP `500` o `503`:

```json
{
  "ok": false,
  "approved": false,
  "request_id": null,
  "error": "The pairing service is unavailable."
}
```

## 9. Aplicaciones posibles

### 9.1 Integración con Paynani

Paynani puede recibir un correo con una solicitud de pairing y enviar sus datos a `pb/index.php`.

Flujo:

1. Paynani recibe el correo.
2. Extrae el código y los datos del usuario.
3. Valida que la plataforma sea Telegram o WhatsApp.
4. Envía una petición JSON autenticada.
5. Interpreta `ok` y `approved`.
6. Responde al remitente con el resultado.

### 9.2 Webhooks

Un sistema externo puede llamar al endpoint cuando se cree una solicitud de pairing en un CRM, portal o sistema de atención.

### 9.3 Panel administrativo

Un panel privado puede mostrar solicitudes pendientes y permitir a un administrador aprobarlas mediante un botón.

El panel no debe guardar el token en JavaScript público. La llamada debe realizarse desde el backend del panel.

### 9.4 Integración con otro agente IA

Otro agente puede preparar la solicitud y enviarla a Hermes mediante la API, siempre que utilice el token y los campos correctos.

### 9.5 Procesamiento de una cola de solicitudes

Un proceso programado puede consultar solicitudes pendientes en una base de datos y enviarlas una por una al endpoint.

### 9.6 Automatización de soporte

Un sistema de soporte puede recibir los datos de contacto, iniciar el pairing y devolver el resultado al equipo de atención.

## 10. Sesión principal del runtime

Para Hermes utiliza:

```env
WEBAPP2_HERMES_SESSION=ID_DE_LA_SESION_PRINCIPAL
```

Cuando está configurada, el prompt se envía mediante:

```bash
hermes chat --resume ID_DE_LA_SESION_PRINCIPAL -q "PROMPT"
```

La configuración actual utiliza una sesión principal de Telegram.

Para OpenClaw utiliza:

```env
WEBAPP2_RUNTIME=openclaw
WEBAPP2_OPENCLAW_SESSION_KEY=agent:main:telegram:direct:ID
```

El adaptador invoca `openclaw system event --mode now --expect-final` con la
sesión explícita y salida JSON. La orden básica de Paynani sin
`--expect-final` sólo confirma que el Gateway encoló el evento; no basta por sí
sola para declarar aprobado el pairing.
La sesión principal debe estar configurada explícitamente; no se usa una
ejecución aislada para B.

## 11. Seguridad

El endpoint aplica las siguientes medidas:

- Autenticación mediante Bearer token.
- Validación estricta de plataforma.
- Validación del código de pairing.
- Consultas preparadas para MariaDB.
- Protección contra exceso de intentos.
- Código almacenado mediante hash.
- Comando del runtime construido con argumentos separados.
- Runtime, usuario SSH, binario y sesión definidos sólo en `.env`.
- No acepta comandos arbitrarios enviados por el cliente.
- No permite elegir el binario que se ejecutará.
- No muestra credenciales ni excepciones internas.
- Devuelve respuestas JSON controladas.

## 12. Recomendaciones de uso

- Usar `pa/index.php` para usuarios finales.
- Usar `pb/index.php` únicamente para sistemas internos o automatizaciones.
- Mantener el token fuera del repositorio.
- Usar siempre HTTPS.
- Generar códigos nuevos para cada intento.
- No reutilizar códigos expirados.
- Registrar el `request_id` para auditoría.
- No enviar códigos de pairing en logs.
- No usar `npm audit fix --force` como parte del proceso de pairing.
- Probar primero con un código inválido para verificar la respuesta de error.
- Confirmar después con un código real y pendiente.

## 13. Prueba mínima de autorización

Para verificar que el endpoint rechaza un token incorrecto:

```bash
curl -X POST \
  https://<DOMINIO>.ngrok.io/pb/index.php \
  -H "Authorization: Bearer token-invalido" \
  -H "Content-Type: application/json" \
  -d '{}'
```

El resultado esperado es HTTP `401`.

## 14. Resumen

```text
pa/index.php = formulario para usuarios finales
pb/index.php = API autenticada para automatizaciones
```

`pb/index.php` permite que Paynani, webhooks, paneles administrativos u otros sistemas soliciten al agente principal de Hermes que procese una operación de pairing y devuelva un resultado JSON controlado.
