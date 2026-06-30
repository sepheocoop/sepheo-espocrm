#!/usr/bin/bash

# Run this to set the EspoCRM container's config so that:
# level is DEBUG
# databaseHandler is true
docker compose exec espocrm perl -pi \
    -e "s/('level'\s*=>\s*).*,/\$1 'DEBUG',/;" \
    -e "s/('databaseHandler'\s*=>\s*).*,/\$1 true,/;" \
    data/config-internal.php
