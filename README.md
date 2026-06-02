# Impression 3D — WordPress + WooCommerce + Slicer auto-hébergé

Projet : permettre à un client d'uploader un fichier STL sur le site, d'obtenir une
**estimation de prix automatique** pour son impression 3D, de payer en ligne
(WooCommerce), et que la commande arrive côté administration. Le prix repose sur le
**poids de filament** et le **temps d'impression**.

Ce document sert de mémoire du projet : il décrit l'architecture, ce qui est déjà fait,
et ce qu'il reste à faire, pour pouvoir reprendre à tout moment.

---

## Architecture

Deux machines se partagent le travail :

- **WordPress (hébergement mutualisé OVH)** — la vitrine : upload du modèle, interface,
  panier et paiement WooCommerce, suivi des commandes. Ne fait aucun calcul lourd.
- **VPS (Debian 12, 4 vCœurs / 4 Go / 81 Go, Docker)** — le « cerveau de découpe » :
  un service qui reçoit un STL, le passe au slicer (PrusaSlicer), et renvoie le poids
  de filament, le temps d'impression et les dimensions.

WordPress interroge le VPS, applique la tarification, et met **ce prix calculé côté
serveur** dans le panier. Le prix n'est jamais pris depuis le navigateur du client
(c'est la faille du montage gratuit existant : le prix venait du client et était cru
aveuglément, donc falsifiable).

Pourquoi auto-héberger le slicer plutôt que d'acheter la version premium de 3DPrint :
le premium recalcule bien le prix côté serveur (bon point), mais ses fonctions
avancées (temps d'impression, remplissage) passent par une API limitée à 30 appels
gratuits/mois — un visiteur mal intentionné pourrait épuiser le quota. En hébergeant
nous-mêmes, pas de limite et pas de dépendance.

## Machines (profils à intégrer dans le slicer)

| Machine          | Type      | Volume utile                         | Buse  |
|------------------|-----------|--------------------------------------|-------|
| Bambu Lab A1     | FDM       | 256 × 256 × 256 mm (boîte)           | 0,4mm |
| Bambu Lab P1S    | FDM       | 256 × 256 × 256 mm (boîte)           | 0,4mm |
| FLSUN V400       | FDM delta | cylindre Ø300 × 410 mm               | 0,4mm |

Note FLSUN V400 (delta) : pour une pièce carrée, emprise utile ~205 × 205 mm, hauteur
exploitable ~390 mm. Le contrôle « est-ce que la pièce rentre » se fait dans une boîte
pour les Bambu, dans un cylindre pour la delta.

## Tarification

prix = (poids_filament_g × prix_au_gramme_du_matériau)
     + (heures_impression × prix_horaire_de_la_machine)
     + forfait_de_base
Le poids est exact (géométrie × densité). Le temps PrusaSlicer doit être calibré pour
les Bambu (mécanique rapide) : comparer l'estimation à de vraies impressions et ajuster
les vitesses du profil, ou appliquer un coefficient correcteur par machine.

---

## Avancement

- [x] VPS Debian 12, accès root (via console / terminal SSH Plesk).
- [x] Docker installé et fonctionnel sur le VPS.
- [x] Image Docker `slicer` avec PrusaSlicer (paquet Debian `prusa-slicer`).
- [x] Découpe validée : un STL en entrée → poids (g) + temps d'impression en sortie.
- [ ] Profils des 3 machines (vitesses réelles) pour des temps justes.
- [ ] Contrôle « la pièce rentre » (boîte / cylindre selon machine).
- [ ] Service de découpe sur le VPS (reçoit STL + paramètres, renvoie JSON,
      protégé par clé secrète, HTTPS, limite de débit anti-abus, timeout par découpe).
- [ ] Intégration WordPress : prix recalculé côté serveur → panier WooCommerce.
- [ ] Interface front (upload + aperçu 3D + choix machine) — réutiliser 3DPrint Lite
      (GPLv2, garder l'attribution) ou refaire.

## Utilisation du slicer (état actuel)

Voir le dossier `slicer/`.

Construire l'image :

    cd slicer
    docker build -t slicer .

Découper un modèle (exemple avec le cube de test) :

    docker run --rm -v "$PWD":/work slicer \
      --export-gcode /work/cube.stl --output /work/cube.gcode \
      --nozzle-diameter 0.4 --layer-height 0.2 --first-layer-height 0.2 \
      --filament-diameter 1.75 --temperature 210 --bed-temperature 60 \
      --fill-density 20% --perimeters 2 --top-solid-layers 4 \
      --bottom-solid-layers 4 --skirts 1 --filament-density 1.24

Lire le résultat dans le G-code généré :

    grep -iE "filament used \[g\]|estimated printing time" cube.gcode

## Notes de sécurité (pour la suite)

- Le prix est TOUJOURS recalculé côté serveur ; le chiffre venu du navigateur ne sert
  qu'à l'affichage.
- Le service de découpe n'est joignable que par WordPress (clé secrète), en HTTPS.
- La découpe tourne dans Docker (isolation), avec une taille de fichier max et un
  délai limite par découpe.
- Limite de débit (ex. X devis / visiteur / heure) pour éviter l'abus.

## Licence

Si on réutilise 3DPrint Lite pour le front (moteur sous GPLv2), conserver les fichiers
de licence et les mentions d'auteur d'origine (Sergey Burkov / wp3dprinting.com).
