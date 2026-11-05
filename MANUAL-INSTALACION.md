# Manual de instalación y operación de webapp2

Este manual explica cómo instalar, configurar, probar y operar `webapp2` en
Ubuntu para los dos runtimes compatibles:

- **Hermes**
- **OpenClaw**

`webapp2` es una aplicación PHP 8.3 + MariaDB que ofrece dos procesos:

- **Proceso A (`pa/index.php`)**: formulario público para que una persona
  capture un código de pairing y sus datos de contacto.
- **Proceso B (`pb/index.php`)**: API JSON autenticada para Paynani,
  webhooks o sistemas internos.

El runtime se configura en el servidor. El cliente nunca puede elegir Hermes,
OpenClaw, el binario, el usuario SSH ni la sesión.

## 1. Arquitectura y flujo

```text
Usuario -> pa/index.php -> MariaDB -> SSH -> Hermes/OpenClaw pairing approve

Paynani/webhook -> pb/index.php -> MariaDB -> SSH -> sesión principal
                                           -> JSON de confirmación
```

Paynani se utilizó como referencia para identificar cómo entregar mensajes al
agente. `webapp2` no reemplaza Paynani: implementa su propio puente controlado
para el pairing.

### Proceso A

1. El usuario abre `/pa/index.php`.
2. Captura código, nombre, país, teléfono, correo y plataforma.
3. PHP valida CSRF, formato, rate limiting y datos.
4. PHP registra la solicitud en MariaDB.
5. PHP ejecuta por SSH el comando fijo del runtime:

   ```text
   hermes pairing approve <plataforma> <código>
   openclaw pairing approve <plataforma> <código>
   ```

6. La página muestra éxito o error.

### Proceso B

1. Un sistema autorizado envía `POST` JSON a `/pb/index.php`.
2. PHP valida el Bearer token y los datos.
3. PHP registra un `request_id`.
4. PHP envía un prompt controlado a la sesión principal:
   - Hermes: `hermes chat --resume ... -q ...`
   - OpenClaw: `openclaw system event --mode now --expect-final ... --json`
5. La respuesta del agente debe ser JSON.
6. `pb/index.php` responde sólo con éxito cuando el agente confirma
   `approved: true`.

Un evento encolado no se considera aprobación confirmada.

## 2. Requisitos del host Ubuntu

Instalar en el host que alojará la aplicación:

- Ubuntu 22.04 o posterior.
- PHP 8.3 con CLI, FPM o servidor compatible.
- Extensiones PHP: `pdo`, `pdo_mysql`, `mbstring`, `json`, `openssl`.
- MariaDB 10.6 o posterior.
- Cliente OpenSSH (`ssh`), con autenticación por clave.
- Git.
- El runtime instalado y funcional: Hermes u OpenClaw.
- Una sesión principal identificable para el Proceso B.
- Opcional: ngrok u otro túnel HTTPS para publicar la aplicación.

Comprobar versiones y extensiones:

```bash
php -v
php -m | grep -E 'PDO|pdo_mysql|mbstring|json|openssl'
mariadb --version
ssh -V
git --version
```

Instalación típica de dependencias PHP/MariaDB:

```bash
sudo apt update
sudo apt install -y git mariadb-server openssh-client php8.3-cli php8.3-fpm php8.3-mysql php8.3-mbstring
```

No se requiere una contraseña de `sudo` dentro de `.env`. Las credenciales de
MariaDB y la clave SSH son mecanismos separados.

## 3. Descargar el repositorio

```bash
sudo mkdir -p /var/www/webapp2
sudo chown "$USER":"$USER" /var/www/webapp2
git clone https://github.com/etorres-iaamx/webapp2-etorres.git /var/www/webapp2
cd /var/www/webapp2
```

Si el repositorio se clona en otra ruta, no es necesario modificar el código:
la aplicación usa rutas relativas a su propia raíz.

## 4. Crear MariaDB

Ejecutar el esquema con una cuenta administrativa:

```bash
sudo mariadb < schema.sql
```

Crear un usuario de aplicación con contraseña aleatoria y permisos mínimos.
Sustituir los marcadores antes de ejecutar:

```sql
CREATE USER 'webapp2_pairing'@'127.0.0.1' IDENTIFIED BY 'CONTRASENA_ALEATORIA';
GRANT SELECT, INSERT, UPDATE ON webapp2_pairing.* TO 'webapp2_pairing'@'127.0.0.1';
FLUSH PRIVILEGES;
```

El esquema crea:

- Base: `webapp2_pairing`
- Tabla: `webapp2_pairing_requests`
- Estados: `received`, `approved`, `failed`
- Runtime registrado: `hermes` u `openclaw`

## 5. Configurar `.env`

```bash
cp .env.example .env
chmod 600 .env
${EDITOR:-vi} .env
```

Configurar como mínimo:

```dotenv
WEBAPP2_DB_HOST=127.0.0.1
WEBAPP2_DB_PORT=3306
WEBAPP2_DB_NAME=webapp2_pairing
WEBAPP2_DB_USER=webapp2_pairing
WEBAPP2_DB_PASSWORD=CONTRASENA_DE_MARIADB
WEBAPP2_PROCESS_B_TOKEN=TOKEN_LARGO_ALEATORIO

WEBAPP2_RUNTIME=openclaw
WEBAPP2_SSH_USER=USUARIO_UBUNTU_DEL_AGENTE
WEBAPP2_SSH_HOST=127.0.0.1
WEBAPP2_SSH_PORT=22
WEBAPP2_SSH_IDENTITY_FILE=/ruta/segura/a/id_ed25519

WEBAPP2_HERMES_BIN=hermes
WEBAPP2_OPENCLAW_BIN=openclaw
WEBAPP2_OPENCLAW_SESSION_KEY=CLAVE_DE_SESION_PRINCIPAL
```

Para Hermes, cambiar la parte del runtime:

```dotenv
WEBAPP2_RUNTIME=hermes
WEBAPP2_HERMES_SESSION=ID_DE_LA_SESION_PRINCIPAL
```

Reglas importantes:

- `WEBAPP2_RUNTIME` sólo puede ser `hermes` u `openclaw`.
- No usar rutas, usuarios ni nombres de otra instalación.
- `WEBAPP2_SSH_HOST=127.0.0.1` significa el mismo host; si el agente está en
  otro servidor, usar su host y puerto SSH autorizados.
- Las variables exportadas por systemd/contenedor tienen prioridad sobre `.env`.
- Nunca subir `.env`, tokens, contraseñas o claves privadas a Git.

Generar valores aleatorios, por ejemplo:

```bash
openssl rand -hex 32
```

## 6. Preparar SSH no interactivo

El usuario que ejecuta PHP debe poder conectarse al host del agente sin pedir
contraseña ni confirmación interactiva.

Crear una clave dedicada si no existe:

```bash
ssh-keygen -t ed25519 -f ~/.ssh/webapp2_ed25519 -N ''
```

Autorizar la clave en el usuario del agente:

```bash
ssh-copy-id -i ~/.ssh/webapp2_ed25519.pub -p PUERTO_SSH USUARIO_UBUNTU@HOST_SSH
```

Probar exactamente con el usuario que usará PHP:

```bash
ssh -o BatchMode=yes -o ConnectTimeout=5 \
  -i /ruta/segura/a/id_ed25519 \
  -p PUERTO_SSH \
  USUARIO_UBUNTU@HOST_SSH \
  'command -v openclaw || command -v hermes'
```

Si el binario no está en el `PATH` del servicio, configurar una ruta absoluta
en `WEBAPP2_OPENCLAW_BIN` o `WEBAPP2_HERMES_BIN`.

Comprobar también el comando de pairing manualmente con un código de prueba
pendiente; no usar códigos reales durante pruebas automatizadas.

## 7. Seleccionar Hermes u OpenClaw

### Hermes

```dotenv
WEBAPP2_RUNTIME=hermes
WEBAPP2_HERMES_BIN=hermes
WEBAPP2_HERMES_SESSION=ID_DE_SESION
```

Proceso A ejecuta `hermes pairing approve`. Proceso B reanuda la sesión con
`hermes chat --resume` y exige el JSON de resultado.

### OpenClaw

```dotenv
WEBAPP2_RUNTIME=openclaw
WEBAPP2_OPENCLAW_BIN=openclaw
WEBAPP2_OPENCLAW_SESSION_KEY=agent:main:telegram:direct:IDENTIFICADOR
```

Proceso A ejecuta `openclaw pairing approve`. Proceso B usa el mecanismo de
eventos de OpenClaw con `--expect-final` y `--json` para esperar la respuesta
final de la sesión principal.

La clave de sesión debe corresponder a la sesión que tiene permiso para
ejecutar el pairing. Un evento aceptado por el Gateway, sin respuesta final,
no basta para devolver `approved: true`.

## 8. Ejecutar localmente de forma segura

Usar el router incluido. No publicar la raíz del repositorio directamente:

```bash
cd /var/www/webapp2
php -S 127.0.0.1:8081 -t . router.php
```

El router sólo permite:

```text
/pa/index.php
/pb/index.php
```

Todo lo demás debe devolver HTTP 404. Verificar:

```bash
curl -i http://127.0.0.1:8081/pa/index.php
curl -i http://127.0.0.1:8081/pb/index.php
curl -i http://127.0.0.1:8081/.env
curl -i http://127.0.0.1:8081/schema.sql
```

Resultados esperados: A `200`, B por GET `405`, archivos privados `404`.

Para producción se recomienda PHP-FPM detrás de Nginx o Apache, con reglas que
bloqueen `.env`, archivos fuente, `schema.sql`, `.git` y cualquier archivo
oculto.

## 9. Hacer persistentes PHP y el túnel

Un proceso lanzado manualmente puede morir al cerrar la terminal o reiniciar
el Gateway. Para una URL pública estable, ejecutar PHP y ngrok bajo systemd,
supervisor o el gestor de servicios de la instalación.

Ejemplo de unidad para PHP; ajustar usuario y ruta:

```ini
[Unit]
Description=webapp2 PHP server
After=network.target mariadb.service

[Service]
Type=simple
User=USUARIO_WEBAPP2
WorkingDirectory=/var/www/webapp2
ExecStart=/usr/bin/php -S 127.0.0.1:8081 -t /var/www/webapp2 /var/www/webapp2/router.php
Restart=always
RestartSec=3

[Install]
WantedBy=multi-user.target
```

Guardar como `/etc/systemd/system/webapp2.service`, después:

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now webapp2
sudo systemctl status webapp2
```

Configurar ngrok con el mismo criterio de persistencia y hacer que apunte a

```text
http://127.0.0.1:8081
```

No iniciar un segundo túnel si ya existe un endpoint gratuito activo. Verificar
el endpoint desde una red externa antes de compartir la URL.

## 10. Pruebas de instalación

### Sintaxis y dependencias

```bash
php -l config.php
php -l runtime.php
php -l pa/index.php
php -l pb/index.php
php -r 'new PDO("mysql:host=127.0.0.1;dbname=webapp2_pairing", "webapp2_pairing", "CONTRASENA_DE_MARIADB"); echo "DB OK\n";'
```

### Proceso A

1. Abrir `/pa/index.php`.
2. Confirmar HTTP 200 y presencia del formulario.
3. Enviar un código de laboratorio pendiente.
4. Confirmar que MariaDB registra `approved` o `failed`.
5. Confirmar que no aparecen contraseñas, tokens ni código completo en logs.

Un reenvío del mismo código ya aprobado debe mostrar éxito idempotente; no debe
reportarse como un fallo falso.

### Proceso B

Petición de autorización inválida:

```bash
curl -i -X POST http://127.0.0.1:8081/pb/index.php \
  -H 'Authorization: Bearer token-invalido' \
  -H 'Content-Type: application/json' \
  -d '{}'
```

Esperar HTTP `401`.

Petición válida de laboratorio:

```bash
curl -i -X POST http://127.0.0.1:8081/pb/index.php \
  -H 'Authorization: Bearer TOKEN_CONFIGURADO' \
  -H 'Content-Type: application/json' \
  -d '{
    "code":"CODIGO_DE_PRUEBA",
    "platform":"telegram",
    "name":"Prueba webapp2",
    "country_code":"+52",
    "phone":"5551234567",
    "email":"pruebas@example.invalid"
  }'
```

Respuestas posibles:

- `200`: aprobación confirmada por el agente.
- `401`: token incorrecto.
- `405`: método distinto de POST.
- `422`: datos inválidos.
- `429`: límite de intentos.
- `502`: el agente respondió, pero no confirmó la aprobación.
- `503`: falta configuración del Proceso B.
- `500`: error interno no expuesto al cliente.

## 11. Diagnóstico rápido

### “El servicio no está disponible” en Proceso A

Revisar, en este orden:

```bash
test -r .env && echo '.env legible'
php -m | grep -E 'PDO|pdo_mysql'
sudo systemctl status mariadb
ssh -o BatchMode=yes -p "$WEBAPP2_SSH_PORT" "$WEBAPP2_SSH_USER@$WEBAPP2_SSH_HOST" true
```

La aplicación no usa una contraseña de sudo. El error suele ser MariaDB,
`.env`, SSH, puerto incorrecto, clave no autorizada o runtime ausente.

### `ERR_NGROK_3200`

Significa que el endpoint ngrok está fuera de línea. Comprobar que PHP y ngrok
estén activos y que ngrok apunte al puerto correcto. Si desaparece después de
reinicios, convertir ambos procesos en servicios persistentes.

### Proceso B devuelve `502`

Revisar la sesión principal, el binario configurado y la salida del runtime.
Un evento encolado sin respuesta final no es una aprobación.

### WhatsApp no responde

Separar el diagnóstico de webapp2 del canal:

1. Confirmar que A/B funcionan localmente.
2. Confirmar que el canal WhatsApp está conectado.
3. Revisar autenticación 401, `allowFrom` y estado del Gateway.
4. Volver a enlazar WhatsApp si la sesión expiró.

El `.env` de webapp2 no sustituye la autenticación de WhatsApp.

## 12. Seguridad y operación

- Mantener `.env` con permisos `600`.
- No enviar tokens, contraseñas ni claves privadas por chat.
- No registrar códigos completos en logs.
- No permitir que el cliente elija comandos o binarios.
- Usar HTTPS para cualquier URL pública.
- Limitar MariaDB al usuario y base necesarios.
- Usar una clave SSH dedicada y con permisos mínimos.
- Revisar periódicamente los estados `failed` y `approved`.
- Mantener PHP, MariaDB, Hermes/OpenClaw y Ubuntu actualizados.
- No exponer el docroot sin el router o reglas equivalentes.
- Probar con códigos de laboratorio antes de usar códigos reales.

## 13. Lista final de aceptación

- [ ] `php -l` pasa para los cuatro PHP principales.
- [ ] MariaDB responde y existe `webapp2_pairing_requests`.
- [ ] `.env` existe, es legible por el servicio y tiene permisos `600`.
- [ ] SSH funciona en modo `BatchMode=yes`.
- [ ] El binario del runtime funciona con el usuario configurado.
- [ ] La sesión principal de Hermes/OpenClaw está configurada.
- [ ] A devuelve HTTP 200 y completa una prueba de laboratorio.
- [ ] B devuelve 401 con token inválido.
- [ ] B devuelve un JSON confirmado con una prueba válida.
- [ ] Archivos privados devuelven 404.
- [ ] PHP y ngrok sobreviven a un reinicio.
- [ ] La URL pública responde desde una red externa.
