# webapp2 Pairing Web App

## Manual de instalación y operación

Consulta [MANUAL-INSTALACION.md](MANUAL-INSTALACION.md) para instalar la
aplicación en Ubuntu, configurar Hermes u OpenClaw, preparar MariaDB y SSH,
publicar los endpoints, ejecutar las pruebas y diagnosticar errores.

Aplicación PHP 8.3 para recibir solicitudes de emparejamiento de Telegram o WhatsApp.

## Estado

- Código PHP inicial creado.
- El servidor debe usar este repositorio como raíz privada o publicar sólo `pa/` y `pb/`; `.env` y `schema.sql` nunca deben quedar expuestos por HTTP.
- La aplicación comparte validación, MariaDB, rate limiting y auditoría entre Hermes y OpenClaw.
- El runtime se fija en `.env`; nunca se acepta desde el navegador ni desde la API.
- Los procesos A y B se conectan por SSH en modo no interactivo a `localhost`, con argumentos fijos y `bypass_shell`.
- El comando remoto se cita como una unidad para que prompts, JSON y espacios no
  se rompan al atravesar el shell remoto; no depende de rutas del host.
- Las variables `WEBAPP2_*` del entorno del servicio tienen prioridad sobre `.env`,
  para permitir despliegues en Ubuntu, contenedores o systemd.
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

Para proteger el endpoint se requiere `WEBAPP2_PROCESS_B_TOKEN` en el `.env` y el
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
3. Levantar el servidor PHP local con `php -S 127.0.0.1:8081 -t . router.php` para
   exponer únicamente `pa/index.php` y `pb/index.php`; no usar el docroot sin router.
4. Cambiar el servicio Ngrok para publicar el puerto PHP bajo el dominio del runtime.
5. Probar el formulario y los flujos de éxito, error, expiración y rate limiting.
6. Configurar `WEBAPP2_RUNTIME`, `WEBAPP2_SSH_USER`, host/puerto SSH y la sesión
   principal (`WEBAPP2_HERMES_SESSION` u `WEBAPP2_OPENCLAW_SESSION_KEY`).
7. Agregar `WEBAPP2_PROCESS_B_TOKEN` y probar `pb/index.php` con un código de laboratorio.
   No publicar el token ni usar códigos reales durante pruebas automatizadas.

## Manual operativo resumido

### Requisitos de Ubuntu

Se necesita Ubuntu 22.04 o posterior, PHP 8.3 con `pdo`, `pdo_mysql`,
`mbstring`, `json` y `openssl`, MariaDB 10.6+, Git, OpenSSH y un runtime
instalado y funcional: Hermes u OpenClaw. Para publicar la aplicación se
requiere además un túnel HTTPS como ngrok.

```bash
sudo apt update
sudo apt install -y git mariadb-server openssh-client \
  php8.3-cli php8.3-fpm php8.3-mysql php8.3-mbstring
```

No se guarda ninguna contraseña de `sudo` en `.env`.

### Instalación

```bash
sudo mkdir -p /var/www/webapp2
sudo chown "$USER":"$USER" /var/www/webapp2
git clone https://github.com/etorres-iaamx/webapp2-etorres.git /var/www/webapp2
cd /var/www/webapp2
sudo mariadb < schema.sql
cp .env.example .env
chmod 600 .env
```

Crear un usuario MariaDB con permisos mínimos:

```sql
CREATE USER 'webapp2_pairing'@'127.0.0.1' IDENTIFIED BY 'CONTRASENA_ALEATORIA';
GRANT SELECT, INSERT, UPDATE ON webapp2_pairing.* TO 'webapp2_pairing'@'127.0.0.1';
FLUSH PRIVILEGES;
```

### Variables `.env`

```dotenv
WEBAPP2_DB_HOST=127.0.0.1
WEBAPP2_DB_PORT=3306
WEBAPP2_DB_NAME=webapp2_pairing
WEBAPP2_DB_USER=webapp2_pairing
WEBAPP2_DB_PASSWORD=CONTRASENA_DE_MARIADB
WEBAPP2_PROCESS_B_TOKEN=TOKEN_LARGO_ALEATORIO
WEBAPP2_SSH_USER=USUARIO_UBUNTU_DEL_AGENTE
WEBAPP2_SSH_HOST=127.0.0.1
WEBAPP2_SSH_PORT=22
WEBAPP2_SSH_IDENTITY_FILE=/ruta/segura/a/id_ed25519
WEBAPP2_HERMES_BIN=hermes
WEBAPP2_OPENCLAW_BIN=openclaw
```

Generar secretos con `openssl rand -hex 32`. Nunca subir `.env`, tokens,
contraseñas o claves privadas a GitHub.

### Hermes

```dotenv
WEBAPP2_RUNTIME=hermes
WEBAPP2_HERMES_SESSION=ID_DE_LA_SESION_PRINCIPAL
```

El Proceso A ejecuta `hermes pairing approve <plataforma> <codigo>` y el
Proceso B usa `hermes chat --resume <sesion> -q <prompt>`.

### OpenClaw

```dotenv
WEBAPP2_RUNTIME=openclaw
WEBAPP2_OPENCLAW_SESSION_KEY=CLAVE_DE_LA_SESION_PRINCIPAL
```

El Proceso A ejecuta `openclaw pairing approve <plataforma> <codigo>` y el
Proceso B usa `openclaw system event --mode now --expect-final --session-key
<sesion> --text <prompt> --json`. Un evento encolado sin respuesta final no es
una aprobación confirmada.

### SSH del runtime

PHP debe conectarse sin contraseña ni interacción:

```bash
ssh-keygen -t ed25519 -f ~/.ssh/webapp2_ed25519 -N ''
ssh-copy-id -i ~/.ssh/webapp2_ed25519.pub \
  -p PUERTO_SSH USUARIO_UBUNTU@HOST_SSH
ssh -o BatchMode=yes -o ConnectTimeout=5 \
  -i /ruta/segura/a/id_ed25519 -p PUERTO_SSH \
  USUARIO_UBUNTU@HOST_SSH 'command -v openclaw || command -v hermes'
```

Si el SSH no usa el puerto 22, actualizar `WEBAPP2_SSH_PORT`. Si el binario no
está en el `PATH` del servicio, usar una ruta absoluta en la variable del
binario correspondiente.

### Ejecutar y publicar

Usar siempre el router incluido; no publicar la raíz del repositorio:

```bash
php -S 127.0.0.1:8081 -t . router.php
```

Rutas públicas:

```text
/pa/index.php  # Proceso A, formulario
/pb/index.php  # Proceso B, API JSON
```

Verificar que `/pa/index.php` devuelva 200, `/pb/index.php` por GET devuelva
405 y `.env`/`schema.sql` devuelvan 404. Ngrok debe apuntar a
`http://127.0.0.1:8081`.

Para que no se caiga después de cerrar la terminal o reiniciar el Gateway,
ejecutar PHP y ngrok como servicios persistentes de systemd, supervisor o
equivalente. El manual ampliado contiene una unidad systemd de ejemplo.

### Uso y pruebas

El formulario A recibe código, datos de contacto y plataforma. El endpoint B
requiere `POST` JSON y `Authorization: Bearer TOKEN`:

```bash
curl -i -X POST https://DOMINIO/pb/index.php \
  -H 'Authorization: Bearer TOKEN_CONFIGURADO' \
  -H 'Content-Type: application/json' \
  -d '{
    "code":"CODIGO_DE_PRUEBA",
    "platform":"telegram",
    "name":"Prueba",
    "country_code":"+52",
    "phone":"5551234567",
    "email":"pruebas@example.invalid"
  }'
```

Respuestas: `200` aprobación confirmada, `401` token inválido, `405` método
incorrecto, `422` datos inválidos, `429` límite de intentos, `502` el agente
no confirmó y `503` configuración incompleta.

Validar antes de producción:

```bash
php -l config.php
php -l runtime.php
php -l pa/index.php
php -l pb/index.php
git diff --check
```

Usar únicamente códigos de laboratorio en pruebas automatizadas.

### Diagnóstico

- **Servicio no disponible:** revisar `.env`, permisos 600, MariaDB, PDO,
  SSH, puerto, clave y runtime.
- **`ERR_NGROK_3200`:** ngrok está fuera de línea; revisar PHP, ngrok y sus
  servicios persistentes.
- **Proceso B `502`:** revisar sesión principal, binario y respuesta final del
  runtime.
- **WhatsApp sin respuesta:** revisar conexión del canal, error 401,
  `allowFrom` y enlace de la sesión. El `.env` de webapp2 no autentica
  WhatsApp.
