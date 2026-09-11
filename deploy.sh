#!/bin/bash
# Deploy script (YENI MODULER PROJE) - Cron ile her dakika calisir.
# Yeni commit varsa repo'yu remote main ile zorla senkronize eder ve
# Laravel cache'lerini temizler.
#
# ============================================================================
#  !!! BU PROJEDE MIGRATION YOKTUR !!!
#  Bu uygulama mevcut (eski) MySQL veritabanini PAYLASIR. Semayi eski monolit
#  yonetir. Burada 'php artisan migrate' KESINLIKLE CALISTIRILMAZ; calistirilirsa
#  paylasilan canli veritabani bozulur. Yeni tablolara Eloquent ile map'lenir
#  (protected $table), migrate ile DEGIL.
# ============================================================================

PROJECT_DIR="/var/www/www-root/data/www/randevumcepteyeni"
HASH_FILE="$PROJECT_DIR/storage/.deploy-last-hash"
LOG_DIR="$PROJECT_DIR/storage/logs"
LOG_FILE="$LOG_DIR/deploy.log"

# PHP 8.3 (varsayilan CLI). Farkli yoldaysa burayi guncelle.
PHP="php8.3"
command -v "$PHP" >/dev/null 2>&1 || PHP="php"

mkdir -p "$LOG_DIR"

log(){
    echo "$(date '+%Y-%m-%d %H:%M:%S') $1" >> "$LOG_FILE"
}

cd "$PROJECT_DIR" || { log "HATA: Proje dizinine gidilemedi"; exit 1; }

# 1) Uzak main'i cek
git fetch origin main >/dev/null 2>&1

# 2) Yerel ve uzak hash karsilastir
LOCAL=$(git rev-parse HEAD 2>/dev/null)
REMOTE=$(git rev-parse origin/main 2>/dev/null)
LAST=$(cat "$HASH_FILE" 2>/dev/null)

NEEDS_DEPLOY=0
[ "$LOCAL" != "$REMOTE" ] && NEEDS_DEPLOY=1
[ "$REMOTE" != "$LAST" ] && NEEDS_DEPLOY=1

if [ "$NEEDS_DEPLOY" -eq 0 ]; then
    exit 0
fi

log "=== Deploy basladi (LOCAL=$LOCAL REMOTE=$REMOTE LAST=$LAST) ==="

# 3) Zorla remote main'e esitle
RESET_OUT=$(git reset --hard origin/main 2>&1)
log "git reset --hard origin/main: $RESET_OUT"

# 3b) composer bagimliliklarinda degisiklik olduysa kur (opsiyonel; composer.lock
#     degisince). vendor repoya girmez, sunucuda kurulur.
if git diff --name-only "$LAST" "$REMOTE" 2>/dev/null | grep -q "composer.lock"; then
    if command -v composer >/dev/null 2>&1; then
        COMPOSER_OUT=$($PHP "$(command -v composer)" install --no-dev --optimize-autoloader --no-interaction 2>&1)
        log "composer install: $COMPOSER_OUT"
    fi
fi

# 4) Laravel cache temizle. DIKKAT: migrate YOK (bkz. bastaki uyari).
VIEW_OUT=$($PHP artisan view:clear 2>&1);     log "view:clear: $VIEW_OUT"
ROUTE_OUT=$($PHP artisan route:clear 2>&1);   log "route:clear: $ROUTE_OUT"
CONFIG_OUT=$($PHP artisan config:clear 2>&1); log "config:clear: $CONFIG_OUT"
CACHE_OUT=$($PHP artisan cache:clear 2>&1);   log "cache:clear: $CACHE_OUT"

# 4b) OPcache sifirla — php-fpm SAPI opcache'ini CLI artisan temizlemez, bu yuzden
#     .php degisiklikleri reload olmadan canli olmaz. FPM'i nazikce yeniden yukle.
if command -v systemctl >/dev/null 2>&1; then
    RELOAD_OUT=$(systemctl reload php8.3-fpm 2>&1)
    log "php8.3-fpm reload: ${RELOAD_OUT:-ok}"
fi

# 5) Son commit'i kaydet (bir sonraki cron tetiklenmesin)
echo "$REMOTE" > "$HASH_FILE"

LAST_COMMIT=$(git log -1 --oneline)
log "HEAD: $LAST_COMMIT"
log "=== Deploy tamamlandi ==="
