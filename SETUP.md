# Santoral Bot — Guía de Puesta en Marcha

## Requisitos del servidor

```bash
# PHP 8.1+ con extensiones: gd, curl, mbstring, openssl
php -m | grep -E "gd|curl|mbstring"

# FFmpeg (obligatorio para vídeo)
apt install ffmpeg -y

# Composer
php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');" && php composer-setup.php
```

---

## 1. Clonar e instalar

```bash
cd /home/user/santoral
composer install --no-dev --optimize-autoloader
```

---

## 2. Configurar las APIs

Copia `.env.example` a `.env` y rellena cada clave:

```bash
cp .env.example .env
nano .env
```

### API Keys necesarias

| Servicio | Dónde conseguirla | Coste |
|---|---|---|
| **GEMINI_API_KEY** | aistudio.google.com → API Keys | Gratis hasta límite, luego ~$7/mes |
| **OPENAI_API_KEY** | platform.openai.com → API Keys | ~$1.20/mes (DALL-E 3) |
| **GOOGLE_TTS_API_KEY** | console.cloud.google.com → Text-to-Speech API | Gratis 1M chars/mes |
| **META_ACCESS_TOKEN** | Ver sección Instagram abajo | Gratis |
| **TIKTOK_ACCESS_TOKEN** | Ver sección TikTok abajo | Gratis |

---

## 3. Configurar Instagram (Meta Graph API)

### Paso a paso:

1. **Cuenta Instagram Business**: Convierte tu cuenta de Instagram a "Cuenta de empresa"
   (Configuración → Cuenta → Cambiar a cuenta profesional)

2. **Facebook Page**: Crea una página de Facebook y vincúlala a tu Instagram Business

3. **Meta for Developers**:
   - Ve a [developers.facebook.com](https://developers.facebook.com)
   - "Crear app" → tipo "Business"
   - Añade producto: **Instagram Graph API**

4. **Permisos**: En "Permisos y funciones" solicita:
   - `instagram_basic`
   - `instagram_content_publish`
   - `pages_read_engagement`

5. **Token de acceso**:
   ```
   # En Graph API Explorer (developers.facebook.com/tools/explorer)
   GET /me/accounts → copia el access_token de tu página
   
   # Extender a 60 días (long-lived token)
   GET https://graph.facebook.com/oauth/access_token
     ?grant_type=fb_exchange_token
     &client_id={APP_ID}
     &client_secret={APP_SECRET}
     &fb_exchange_token={SHORT_TOKEN}
   ```

6. **Instagram User ID**:
   ```
   GET /me/accounts → busca tu página → instagram_business_account → id
   ```

7. **URL pública de imágenes**: Instagram necesita descargar la imagen desde una URL accesible.
   Configura nginx para servir `/home/user/santoral/storage/public/` en tu dominio:
   ```nginx
   location /media/ {
       alias /home/user/santoral/storage/public/;
   }
   ```
   Luego en `.env`: `PUBLIC_BASE_URL=https://tudominio.com/media`

---

## 4. Configurar TikTok (Content Posting API)

1. Registrarse en [developers.tiktok.com](https://developers.tiktok.com)
2. "Create App" → nombre y descripción
3. Añadir productos: **Login Kit** + **Content Posting API**
4. En "Content Posting API" → solicitar acceso a **Direct Post**
   ⚠️ La aprobación puede tardar 1-2 semanas
5. Una vez aprobado, implementar el flujo OAuth para obtener `access_token` y `open_id`

**Mientras se aprueba**: el bot publicará con `privacy_level: SELF_ONLY` (solo visible para ti)
para hacer pruebas. El código detecta automáticamente `APP_ENV=production` para publicar al público.

---

## 5. Assets necesarios (descargar manualmente)

### Fuente Cinzel-Bold (elegante para santos)
```bash
# Descargar de Google Fonts
wget -O assets/fonts/Cinzel-Bold.ttf \
  "https://fonts.gstatic.com/s/cinzel/v23/8vIJ7ww63mVu7gtL-a8f.woff2"
# O buscar "Cinzel Bold TTF" en fonts.google.com → descargar → copiar a assets/fonts/
```

### Música de fondo gregoriana (libre de derechos)
```bash
# Opción A: freemusicarchive.org → buscar "gregorian chant" → licencia CC0
# Opción B: pixabay.com/music → buscar "gregorian"
# Guardar como: assets/music/gregorian_bg.mp3
```

---

## 6. Marco decorativo (opcional)
Si quieres un marco PNG transparente sobre las imágenes:
```bash
# Crear un PNG 1080x1080 con marco dorado transparente en el centro
# y guardarlo en: assets/frames/border.png
# Se puede crear con GIMP o Photoshop
```

---

## 7. Configurar el cron job

```bash
# Editar crontab
crontab -e

# Añadir (ejecuta a las 9:00 AM cada día)
0 9 * * * /usr/bin/php /home/user/santoral/run.php >> /home/user/santoral/storage/logs/cron.log 2>&1

# Verificar que está activo
crontab -l
```

---

## 8. Prueba manual

```bash
# Probar con la fecha de hoy
php run.php

# Probar con una fecha específica (sin publicar si quieres)
php run.php --date=12-25   # Navidad

# Ver log en tiempo real
tail -f storage/logs/$(date +%Y-%m-%d).log
```

---

## 9. Estrategia para viralizarse

- **Hora de publicación**: 9:00 AM hora local (máxima actividad de tu audiencia)
- **Hashtags**: el bot usa 15 hashtags virales en cada post de Instagram
- **Constancia**: el algoritmo premia la publicación diaria y regular
- **TikTok**: los primeros 3 segundos del hook son críticos → Gemini los optimiza
- **Interacción**: aunque el bot es automático, responde manualmente a los primeros comentarios los primeros días para aumentar el alcance orgánico
- **Temáticas que funcionan**: santos con historias dramáticas (mártires), santos modernos (Madre Teresa, Padre Pío), santos populares (San Valentín, San Patricio)

---

## Coste estimado mensual

| Servicio | Coste/mes |
|---|---|
| Gemini 1.5 Pro | ~$0.21 |
| DALL-E 3 (1 imagen/día) | ~$1.22 |
| Google TTS Wavenet | ~$0.04 |
| **TOTAL** | **~$1.47/mes** |

Instagram y TikTok APIs: **gratuitas**
