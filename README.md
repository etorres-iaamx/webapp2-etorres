# Malinalli Pairing Web App (`webapp2`)

Aplicación PHP 8.3 para recibir solicitudes de emparejamiento de Telegram o WhatsApp.

## Estado

- Código PHP inicial creado.
- El servidor debe usar este repositorio como raíz privada o publicar sólo `pa/` y `pb/`; `.env` y `schema.sql` nunca deben quedar expuestos por HTTP.
- La aplicación comparte validación, MariaDB, rate limiting y auditoría entre Hermes y OpenClaw.
- El runtime se fija en `.env`; nunca se acepta desde el navegador ni desde la API.
- Los procesos A y B se conectan por SSH en modo no interactivo a `localhost`, con argumentos fijos y `bypass_shell`.
- El cliente sólo aporta datos; nunca aporta un comando, binario, usuario, host o sesión.

## Proceso B: puente PHP hacia el agente principal

El endpoint `pb/index.php` implementa el Proceso B sin modificar la interfaz de
`pa/index.php`. Recibe un `POST` JSON o `application/x-www-form-urlencoded`, valida
los datos, guarda la solicitud en MariaDB y envía un prompt a la sesión principal
del runtime configurado. El adaptador usa `hermes chat --resume` para Hermes y
`openclaw system event --mode now --expect-final --session-key ... --json` para
OpenClaw. El prompt le indica al
agente que ejecute únicamente:

```text
hermes pairing approve <plataforma> <código>
openclaw pairing approve <plataforma> <código>
```

El agente debe devolver únicamente JSON. El endpoint valida esa respuesta y
devuelve un resultado correlacionado con `request_id`:

```json
{"ok":true,"approved":true,"request_id":"pb-123","error":null}
```

Para proteger el endpoint se requiere `PROCESS_B_TOKEN` en el `.env` y el
cliente debe enviar `Authorization: Bearer <token>` o
`X-Process-B-Token: <token>`. El endpoint solo acepta `POST`, aplica el mismo
límite de cinco intentos por IP en quince minutos y utiliza SSH no interactivo
con `bypass_shell` y argumentos separados. La respuesta HTTP `200` indica una
aprobación confirmada; los errores del runtime se devuelven como JSON con HTTP
`502`.

Paynani se revisó como referencia de integración: su adaptador de OpenClaw usa
`openclaw system event --mode now --text` y el de Hermes usa rutas HTTP
autenticadas. `webapp2` reutiliza ese conocimiento en un adaptador PHP propio,
pero para B usa una invocación de sesión que permita esperar la respuesta JSON;
un evento meramente encolado no se reporta como aprobación confirmada.

## Próximos pasos de despliegue

1. Crear la base de datos usando `schema.sql`.
2. Crear `.env` a partir de `.env.example` con una contraseña aleatoria de MariaDB.
3. Levantar el servidor PHP local.
4. Cambiar el servicio Ngrok para publicar el puerto PHP bajo el dominio del runtime.
5. Probar el formulario y los flujos de éxito, error, expiración y rate limiting.
6. Configurar `AGENT_RUNTIME`, `AGENT_SSH_USER`, host/puerto SSH y la sesión
   principal (`HERMES_SESSION` u `OPENCLAW_SESSION_KEY`).
7. Agregar `PROCESS_B_TOKEN` y probar `pb/index.php` con un código de laboratorio.
   No publicar el token ni usar códigos reales durante pruebas automatizadas.
