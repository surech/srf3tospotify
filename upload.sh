#!/usr/bin/bash

echo Build Release...
docker compose exec -T app composer quality
docker compose exec -T app composer release
sha256sum -c build/srf3tospotify-release.tar.gz.sha256

echo Move to Release-Directory...
cd build/release

echo Upload...
lftp -v -c "set ftp:ssl-force true; set ssl:verify-certificate no; open s2s.surech.ch; mirror -R; quit"

echo Update Database...
curl --fail-with-body \
  --request POST \
  --header "Authorization: Bearer QRh0g8zbu3ugVggmsMSkB2EozW5wPzVy" \
  https://s2s.surech.ch/internal/maintenance/migrate