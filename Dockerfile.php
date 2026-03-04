# ==================================================
# IMAGE DE BASE : PHP 8.4 avec Apache integre
# ==================================================
# On part de l'image officielle PHP qui inclut deja Apache.
# C'est notre point de depart, comme un ordinateur vierge avec PHP et Apache pre-installes.
FROM php:8.4-apache

# ==================================================
# INSTALLATION DES PAQUETS SYSTEME
# ==================================================
# Ces paquets sont necessaires pour compiler certaines extensions PHP.
# - libzip-dev   : necessaire pour l'extension PHP "zip"
# - libpq-dev    : necessaire pour l'extension PHP "pdo_pgsql" (PostgreSQL)
# - libicu-dev   : necessaire pour l'extension PHP "intl" (Symfony)
# - libonig-dev  : necessaire pour l'extension PHP "mbstring"
# - zip, unzip   : outils de compression (utilises par Composer)
# - curl         : outil pour telecharger des fichiers (utilise pour installer Composer)
# - git          : systeme de versionnement (utilise par Composer)
RUN apt-get update && apt-get install -y --no-install-recommends \
    libzip-dev libpq-dev libicu-dev libonig-dev \
    zip unzip curl git \
  && apt-get clean \
  && rm -rf /var/lib/apt/lists/*

# ==================================================
# INSTALLATION DES EXTENSIONS PHP
# ==================================================
# Extensions courantes :
# - pdo, pdo_mysql, pdo_pgsql : connecteurs base de donnees
# - mysqli       : connecteur MySQL classique (PhpMyAdmin, anciens projets)
# - zip          : manipulation d'archives ZIP
# Extensions Symfony :
# - intl         : internationalisation (traductions, formats de dates...)
# - mbstring     : manipulation de chaines multi-octets (UTF-8)
# - opcache      : cache de bytecode PHP (performances)
RUN docker-php-ext-install \
    pdo pdo_mysql pdo_pgsql mysqli zip \
    intl mbstring opcache

# ==================================================
# INSTALLATION DE XDEBUG + APCu
# ==================================================
# - Xdebug : debugger PHP pas-a-pas dans votre IDE
# - APCu   : cache utilisateur en memoire (recommande par Symfony)
RUN pecl install xdebug apcu \
  && docker-php-ext-enable xdebug apcu

# ==================================================
# INSTALLATION DE COMPOSER (gestionnaire de dependances PHP)
# ==================================================
# Composer est l'equivalent de npm pour JavaScript.
# Il permet d'installer des librairies PHP (ex: phpdotenv, symfony, laravel...).
RUN curl -sS https://getcomposer.org/installer | \
    php -- --install-dir=/usr/local/bin --filename=composer

# ==================================================
# INSTALLATION DE SYMFONY CLI
# ==================================================
# Outil en ligne de commande officiel de Symfony.
# Permet de creer des projets, lancer le serveur de dev, verifier les prerequis...
RUN curl -1sLf 'https://dl.cloudsmith.io/public/symfony/stable/setup.deb.sh' | bash \
  && apt-get install -y symfony-cli \
  && apt-get clean \
  && rm -rf /var/lib/apt/lists/*

# ==================================================
# ACTIVATION DES MODULES APACHE
# ==================================================
# - rewrite     : permet les URL propres (ex: /article/42 au lieu de /index.php?id=42)
# - proxy       : permet a Apache de rediriger des requetes vers d'autres serveurs
# - proxy_http  : support du protocole HTTP pour le proxy (utilise pour PhpMyAdmin)
# - headers     : permet de manipuler les en-tetes HTTP
RUN a2enmod rewrite proxy proxy_http headers

# ==================================================
# COPIE DE LA CONFIGURATION APACHE
# ==================================================
# On copie nos fichiers de configuration VirtualHost dans le conteneur.
COPY ./apache-config /etc/apache2/sites-enabled

# ==================================================
# REPERTOIRE DE TRAVAIL
# ==================================================
# C'est le dossier par defaut quand on se connecte au conteneur.
WORKDIR /var/www/html
