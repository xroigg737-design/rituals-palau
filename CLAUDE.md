# Rituals al Palau

App web de votació de concerts per un grup d'amics (Xavi, Anna, Albert, Elia, Montse, Roger) per a la temporada 2026-2027 del Palau de la Música i L'Auditori.

## Arquitectura

- **Single-file app**: Tot (HTML + CSS + JS) viu a `index.html`
- **Backend**: API REST PHP a `https://i-xr.duckdns.org/rituals-palau/api/votes.php`
- **Versionat**: Fitxer `VERSION` a l'arrel. SEMPRE incrementar a cada deploy
- **Versió visible**: Es mostra al footer de l'app (actualitzar el `>vX.XX` al HTML)

## Deploy

**Producció (AWS):**
```bash
rsync -avz --exclude='.git' \
  -e "ssh -i ~/AWS/claus/la-meva-clau-ubuntu.pem" \
  /home/xroig/rituals-palau/ \
  ubuntu@13.63.16.49:/var/www/html/rituals-palau/
```

**GitHub:**
```bash
git add <fitxers> && git commit && git push
```

SEMPRE fer commit + push + deploy a AWS a cada canvi.

## Estructura de dades

- `USERS[]` — 6 participants amb id, nom, inicials i color
- `SECTIONS[]` — Agrupacions temàtiques (beethoven, casals, baroque, romantic, cinema, etc.)
- `C[]` — Array de concerts amb: id, títol (t), data (d), hora (h), preu (pr), grup (g), lloc, cicle (cy)
- Secció Beethoven té propietats especials: `icon` (retrat fons), `sig` (signatura manuscrita)

## Fonts de dades

- Palau de la Música: API no documentada `/ca/programming_data_json?palau_productions=1&...`
- Programa temporada: https://www.palaumusica.cat/ca/llibre-de-la-temporada-2026-27_1624620
- Auditori: No té API accessible (SSL issues, contingut dinàmic)

## Imatges

- `img/beethoven.jpg` — Retrat Stieler (960x1154px), fons secció Beethoven
- `img/beethoven-signature-crop.png` — Signatura manuscrita retallada (només "Beethoven")
- `img/beethoven-signature.png` — Signatura completa "Ludwig Van Beethoven"
- `img/beethoven-signature.svg` — Signatura vector completa

## Regles de treball

- Idioma de comunicació: Català
- No demanar feedback intermedi, executar directament
- Mostrar "*** Xavi ja està, al ataque !!! ***" al acabar cada tasca
- Sempre especificar si els canvis són en local o producció
