# Impression 3D — WordPress + WooCommerce + Slicer auto-hébergé

Projet : permettre à un client d'uploader un fichier STL sur le site, d'obtenir une
**estimation de prix automatique** pour son impression 3D, de payer en ligne
(WooCommerce), et que la commande arrive côté administration. Le prix repose sur le
**poids de filament** et le **temps d'impression** (supports inclus si activés).

Ce document sert de mémoire du projet : architecture, ce qui est fait, ce qu'il reste.

---

## Architecture

- **WordPress (hébergement mutualisé OVH)** — la vitrine : upload du modèle, interface,
  panier et paiement WooCommerce, suivi des commandes. Ne fait aucun calcul lourd.
- **VPS (Debian 12, 4 vCœurs / 4 Go / 81 Go, Docker)** — le "cerveau de découpe" :
  un service web qui reçoit un STL + paramètres et renvoie poids, temps, dimensions,
  et si la pièce rentre dans la machine.

WordPress interroge le VPS, applique la tarification, et met **ce prix calculé côté
serveur** dans le panier. Le prix n'est jamais pris depuis le navigateur du client.

Pourquoi auto-héberger : pas de limite d'appels (contrairement à l'API du premium,
limitée à 30 devis/mois) et aucune dépendance externe.

## Machines

| Machine          | Type      | Volume utile               | Buse  |
|------------------|-----------|----------------------------|-------|
| Bambu Lab A1     | FDM       | 256 × 256 × 256 mm (boîte) | 0,4mm |
| Bambu Lab P1S    | FDM       | 256 × 256 × 256 mm (boîte) | 0,4mm |
| FLSUN V400       | FDM delta | cylindre Ø300 × 410 mm     | 0,4mm |

Contrôle "ça rentre" : boîte pour les Bambu, cylindre pour la FLSUN (delta).

## Tarification

    prix = (poids_g × prix_au_gramme_du_materiau)
         + (heures × prix_horaire_de_la_machine)
         + forfait_de_base

Poids exact (géométrie × densité). Temps PrusaSlicer à calibrer pour les Bambu via le
`time_factor` de chaque machine (dans app.py) : comparer à de vraies impressions.

---

## Avancement

- [x] VPS Debian 12, accès root (terminal SSH via Plesk).
- [x] Docker installé et fonctionnel.
- [x] Image Docker du slicer (PrusaSlicer, paquet Debian `prusa-slicer`).
- [x] Découpe validée : STL -> poids + temps.
- [x] **Service de découpe (slicer API)** opérationnel sur le VPS :
      reçoit STL + paramètres, renvoie JSON (poids, temps, dimensions, fits).
      Protégé par clé secrète, limite anti-abus, délai max par découpe.
- [x] Contrôle "la pièce rentre" (boîte / cylindre) intégré au service.
- [x] Options remplissage (infill) et supports prises en charge par le service.
- [ ] Exposer le service en HTTPS (adresse + certificat) pour que WordPress l'atteigne.
- [ ] Redémarrage automatique du conteneur au reboot du VPS (--restart).
- [ ] Profils fins par machine (vitesses) + calibration du temps (time_factor).
- [ ] Pont WordPress : prix recalculé côté serveur -> panier WooCommerce.
- [ ] Interface front : réutiliser 3DPrint Lite 2.1.4 (upload + viewer + choix machine).

## Le service de découpe (slicer API)

Fichiers : `Dockerfile` + `app.py`.

Construire et lancer (la CLE doit rester secrète ; WordPress l'utilisera) :

    cd ~/slicer
    docker build -t slicer-api .
    docker run -d --name slicer-api -p 127.0.0.1:8099:8099 \
      -e SLICER_API_KEY=ta-cle-secrete slicer-api

Points d'entrée :
- `GET  /health` — vérifie que le service répond.
- `POST /slice`  — en-tête `X-API-Key: ta-cle-secrete`, et en formulaire :
  `file` (le .stl), `printer` (a1 / p1s / v400), `infill` (ex. 20),
  `material_density` (ex. 1.24), `supports` (0/1), `scale` (ex. 1).

Exemple de test :

    curl -s -H "X-API-Key: ta-cle-secrete" \
      -F "file=@cube.stl" -F "printer=a1" -F "infill=20" -F "material_density=1.24" \
      http://127.0.0.1:8099/slice

Réponse (JSON) : ok, printer, weight_g, print_time_seconds, print_time_hours,
dimensions_mm, volume_cm3, fits, infill_percent, supports.

## Notes de sécurité

- Prix TOUJOURS recalculé côté serveur ; le chiffre du navigateur ne sert qu'à l'affichage.
- Service joignable uniquement par WordPress (clé secrète), bientôt en HTTPS.
- Découpe isolée dans Docker, taille de fichier max (60 Mo) et délai limite (180 s).
- Limite de débit anti-abus par adresse IP.

## Notes 3DPrint Lite 2.1.4 (analyse)

- Version récente (testée jusqu'à WordPress 7.0), propre sous PHP 8.3.
- Le prix y est encore calculé côté navigateur (faille) -> on le remplace par notre service.
- Pas d'intégration WooCommerce dans la version Lite (réservé au premium) -> on fait le pont.
- Le remplissage (infill) et le contrôle de taille existent déjà côté Lite.
- Viewer 3D en Three.js r101 (2018) : vieux mais fonctionnel ; modernisation optionnelle, plus tard.

## Licence

Notre code : GPLv2-ou-ultérieure (standard WordPress). Si on réutilise 3DPrint Lite,
ne PAS copier son code dans ce dépôt — l'installer séparément. Pour un usage privé/perso,
toute modification reste permise ; garder l'attribution si jamais on distribue.
