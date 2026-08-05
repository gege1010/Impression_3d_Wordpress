# Impression 3D — WordPress + WooCommerce + Slicer auto-hébergé

Projet : permettre à un client d'uploader un fichier STL sur le site, d'obtenir une
**estimation de prix automatique** pour son impression 3D, de payer en ligne
(WooCommerce), et que la commande arrive côté administration. Le prix repose sur le
**poids de filament** et le **temps d'impression** (supports inclus si activés).

Ce document sert de mémoire du projet : architecture, ce qui est fait, ce qu'il reste.

---

## Architecture

```
┌──────────────────────────────────────────────────────────┐
│  VPS Debian 12 (OVH, Plesk)                             │
│                                                          │
│  ┌─────────────────┐   HTTP 127.0.0.1:8099   ┌────────┐ │
│  │  WordPress       │ ◄─────────────────────► │ Docker │ │
│  │  + WooCommerce   │   (interne, pas de      │ Slicer │ │
│  │  + 3DPrint Lite  │    domaine/HTTPS)       │  API   │ │
│  │  + Pont (plugin) │                         └────────┘ │
│  └─────────────────┘                                     │
└──────────────────────────────────────────────────────────┘
        ▲
        │ HTTPS (Cloudflare)
        ▼
    Client web
```

- **WordPress + Slicer sur le MÊME VPS** — appel direct en `127.0.0.1:8099`, ni
  domaine ni HTTPS nécessaires entre les deux.
- **VPS** : Debian 12, 4 vCœurs, 4 Go RAM, 80 Go disque, Docker v29.5.2, Plesk.
- **Cloudflare** en façade, **WP Rocket** pour le cache, **Wordfence** pour la sécurité.
- **Thème** : Styler (Nine Theme), **page builder** : Elementor (gratuit).

WordPress interroge le VPS, applique la tarification, et met **ce prix calculé côté
serveur** dans le panier. Le prix n'est jamais pris depuis le navigateur du client.

Pourquoi auto-héberger : pas de limite d'appels (contrairement à l'API du premium,
limitée à 30 devis/mois) et aucune dépendance externe.

---

## Structure du dépôt

```
/
├── README.md
├── slicer/
│   ├── Dockerfile          (gunicorn + numpy)
│   ├── app.py              (296 lignes — /health, /slice, /analyze)
│   └── cube.stl            (cube test 20 mm ASCII)
└── wordpress/
    └── impression-3d-bridge/
        └── impression-3d-bridge.php   (v0.7.1 — ~830 lignes)
```

**Aucun secret** dans le dépôt (la clé API n'est que sur le VPS et dans les réglages WP).
**Ne jamais committer 3DPrint Lite** ici (ambiguïté de licence GPLv2/AGPLv3).

---

## Machines

| Machine          | Type      | Clé slicer | Volume utile                | Buse  |
|------------------|-----------|------------|-----------------------------|-------|
| Bambu Lab A1     | FDM       | `a1`       | 256 × 256 × 256 mm (boîte) | 0,4mm |
| Bambu Lab P1S    | FDM       | `p1s`      | 256 × 256 × 256 mm (boîte) | 0,4mm |
| FLSUN V400       | FDM delta | `v400`     | cylindre Ø300 × 410 mm      | 0,4mm |

Contrôle « ça rentre » : pour les Bambu, l'empreinte tient à plat dans le carré, dans un sens
ou dans l'autre. Pour la FLSUN, c'est la **diagonale de l'empreinte** qui doit tenir dans le
cercle — pas le plus grand côté : une pièce de 280 × 80 mm passe sur un plateau de Ø300
(diagonale 291). La hauteur reste la hauteur, on ne couche jamais la pièce.

Ce contrôle est fait par `fits_in_machine()` dans `slicer/app.py`, et **c'est le seul qui fait
foi** — voir « Le service de découpe » plus bas pour la raison. Le navigateur applique la même
règle (`checkFits()` dans le plugin), uniquement pour l'affichage en temps réel : les deux
doivent rester identiques.

---

## Tarification

```
prix = (poids_g × prix_au_gramme_du_materiau)
     + (heures × coefficient_temps × prix_horaire_de_la_machine)
     + forfait_de_base
```

Poids exact (géométrie × densité). Temps PrusaSlicer, calibré par machine via le
`coefficient_temps` (temps réel ÷ temps estimé, réglable dans l'admin WP).

### Extrapolation de l'échelle (v0.7.1)

Le slicer n'est appelé qu'**une seule fois à l'échelle 1×** par combinaison machine/matériau/remplissage.
Le curseur d'échelle recalcule instantanément côté client :

```
coût_matière(S) = coût_matière(1) × S³
coût_temps(S)   = coût_temps(1)   × S³
forfait         = inchangé
dimensions(S)   = dimensions(1)   × S
```

Le prix final au panier est toujours recalculé côté serveur (slicer réel avec la vraie échelle).

---

## Avancement

### Infrastructure ✅

- ✅ VPS Debian 12, accès root (terminal SSH via Plesk).
- ✅ Docker installé et fonctionnel (v29.5.2).
- ✅ Redémarrage auto du conteneur au reboot (`--restart unless-stopped`).
- ✅ Sauvegarde offsite : rclone → OneDrive (cron quotidien à 03:00).
- ✅ Cloudflare en façade, WP Rocket, Wordfence.

### Service de découpe (slicer API) ✅

- ✅ Image Docker du slicer (PrusaSlicer CLI, Flask, gunicorn 2 workers, numpy).
- ✅ Découpe validée : STL → poids + temps + dimensions + fits.
- ✅ Contrôle « la pièce rentre » (boîte / cylindre) intégré — seule autorité en matière de taille.
- ✅ **Géométrie réelle de chaque machine transmise à PrusaSlicer** (plateau + hauteur max) : sans
  ça il découpait sur sa machine par défaut 200 × 200 × 200 et refusait tout ce qui dépassait.
- ✅ Options remplissage (infill) et supports prises en charge.
- ✅ Endpoint `/analyze` : détection géométrique des surplombs (normales des facettes).
- ✅ Protégé par clé secrète (`X-API-Key`), limite anti-abus, délai max par découpe.
- ✅ Appel direct en `127.0.0.1:8099` (même VPS).

### Plugin pont WordPress (impression-3d-bridge) ✅

- ✅ **Parcours client complet** : upload STL → devis → panier WooCommerce au bon prix.
- ✅ Prix **toujours calculé côté serveur** (infalsifiable).
- ✅ Détection automatique des supports avec **verrouillage à 2 niveaux** :
  - Surplomb important (>6%) → supports requis, case grisée non-décochable.
  - Surplomb léger (2-6%) → supports conseillés, case cochée mais décochable.
  - Pas de surplomb (<2%) → case libre, message vert.
- ✅ Prix détaillé en temps réel sur le formulaire (matière, temps, forfait, supports, total).
- ✅ **Extrapolation mathématique de l'échelle** : une seule découpe à l'échelle 1×, recalcul instantané en JS (v0.7.1).
- ✅ **Debounce 2 secondes** : changement de machine/matériau/remplissage → attente de 2s avant découpe (v0.7.1).
- ✅ **Annulation des requêtes** à la fermeture de page (`AbortController` + `beforeunload`) (v0.7.1).
- ✅ **Vérification « ça rentre »** aussi côté client (volumes de construction en dur) pour l'affichage en temps réel (v0.7.1), **alignée sur la règle du serveur** depuis la v0.7.2 — à l'échelle 1, c'est le verdict du serveur qui fait foi.
- ✅ Coefficient de calibration du temps par machine (réglable dans l'admin).
- ✅ Conservation du STL par commande (`wp-content/uploads/i3db-orders/<n°>/`) + bouton téléchargement admin.
- ✅ Produit WooCommerce caché créé automatiquement, prix appliqué dynamiquement.
- ✅ Métadonnées dans le panier et la commande (machine, matériau, remplissage, poids, fichier).
- ✅ Compatibilité HPOS WooCommerce.
- ✅ Compatibilité checkout classique + checkout en blocs (Store API).
- ✅ Bouton « Ajouter au panier », champs e-mail/commentaire masqués.

### Interface front ✅

- ✅ 3DPrint Lite 2.1.4 (upload + viewer 3D + choix machine/matériau/remplissage).
- ✅ Shortcode `[3dprint-lite]` dans un widget Elementor « Shortcode / Code court » (PAS « HTML »).

### À tester / vérifier 🔶

- 🔶 Plugin v0.7.2 : déployé, à tester côté client (extrapolation + debounce + abort, et grandes pièces sur les trois machines).
- 🔶 Tester un vrai paiement (tunnel WooCommerce de bout en bout).
- 🔶 Saisir les vraies valeurs de calibration temps après impressions de test.

### Roadmap ⬜

- ⬜ **Profils de vitesse propres à chaque imprimante** — plus optionnel : c'est le dernier vrai
  écart de précision sur les prix. La commande de découpe ne transmet aujourd'hui **aucune vitesse
  ni accélération**, donc les trois machines sont chronométrées avec les défauts génériques de
  PrusaSlicer (périmètre 60, remplissage 80, déplacement 130 mm/s, accél. 1500/1250) — très loin
  d'une delta V400. Même chantier que la calibration des coefficients de temps ci-dessus : les
  deux demandent de vraies impressions, pas des valeurs constructeur.
- ⬜ Refonte esthétique du formulaire (panneau latéral à droite du viewer 3D, cartes modernes).
- ⬜ Fusionner les deux plugins en un seul (finition, plus tard).
- ⬜ Traduction FR de 3DPrint Lite via Loco Translate (optionnel).

---

## Versions

- **Pont WordPress** (`impression-3d-bridge`) : **v0.7.2** — build : `enveloppes machine réelles (V400 Ø300x410, Bambu 256) alignées sur le slicer`.
- **Service slicer** (`app.py`) : endpoints `/health`, `/slice`, `/analyze`. Stack : Flask + gunicorn (2 workers, timeout 200s) + numpy.
- **Image du slicer** : base `debian:trixie-slim` (Debian 13) → **PrusaSlicer 2.9.2**, Python 3.13, numpy 2.x. Migrée depuis `bookworm-slim` (PrusaSlicer 2.5.0, figée en 2022) en 08/2026 : le service fait analyser des STL déposés par n'importe quel visiteur, il ne doit pas rester sur une version qui ne reçoit plus de correctifs.

---

## Le service de découpe (slicer API)

Fichiers : `slicer/Dockerfile` + `slicer/app.py`.

Construire et lancer (la CLÉ doit rester secrète ; WordPress l'utilisera) :

```bash
cd ~/slicer
docker build -t slicer-api .
docker rm -f slicer-api
docker run -d --name slicer-api --restart unless-stopped \
  -p 127.0.0.1:8099:8099 \
  -e SLICER_API_KEY=ta-cle-secrete slicer-api
```

Points d'entrée :

| Endpoint | Méthode | Description |
|----------|---------|-------------|
| `/health` | GET | Vérifie que le service répond (pas de clé requise) et expose l'enveloppe de chaque machine (`build_volumes`) |
| `/slice` | POST | Découpe STL → poids, temps, dimensions, fits |
| `/analyze` | POST | Analyse géométrique des surplombs → supports recommandés (oui/non/fraction) |

### ⚠️ Il n'y a aucun profil `.ini` — la géométrie machine passe en ligne de commande

Ne cherchez pas un profil PrusaSlicer à corriger, il n'en existe pas : `app.py` construit toute
la commande en options `--`, et transmet la géométrie de la machine choisie via `--bed-shape`,
`--max-print-height` et `--center`, à partir du dictionnaire `MACHINES`.

**C'est indispensable.** Sans ces options, PrusaSlicer utilise sa machine compilée par défaut —
plateau 200 × 200, hauteur 200 — et **refuse de découper** toute pièce plus grande, avec
`Objects could not fit on the bed`. Le site affichait alors « Devis indisponible pour cette
configuration ». Les trois machines étaient touchées, pas seulement la V400 (corrigé en 08/2026).

Pour vérifier ce qui est réellement appliqué, les réglages sont écrits en clair dans le G-code
produit : `grep '^; bed_shape' fichier.gcode`.

**Ne comptez jamais sur PrusaSlicer pour refuser une pièce trop grande** (mesuré) : dès qu'on lui
passe un `--center` explicite il ne valide plus rien — il accepte 310 mm de large sur un plateau
de 300, et 420 mm de haut pour une limite à 410. Et sans `--center`, il ne teste que la **boîte
englobante** du plateau, jamais le cercle. Le seul juge est `fits_in_machine()`.

Une pièce hors enveloppe n'est donc pas découpée du tout : la réponse est `ok=true` avec
`fits=false`, poids et temps à 0 — donc un total réduit au seul forfait. ⚠️ **Tout script de
devis par lot doit tester `fits`**, sinon il enregistre des lignes au prix du forfait au lieu de
signaler « pièce à découper ».

### Appel

En-tête : `X-API-Key: ta-cle-secrete`. Champs formulaire pour `/slice` :
`file` (le .stl), `printer` (a1/p1s/v400), `infill` (ex. 20),
`material_density` (ex. 1.24), `supports` (0/1), `scale` (ex. 1).

Exemple de test :

```bash
curl -s -H "X-API-Key: ta-cle-secrete" \
  -F "file=@cube.stl" -F "printer=a1" -F "infill=20" -F "material_density=1.24" \
  http://127.0.0.1:8099/slice
```

---

## Réglages (admin WP → Réglages → Pont Impression 3D)

| Réglage | Défaut | Notes |
|---------|--------|-------|
| Adresse du slicer | `http://127.0.0.1:8099` | Ne pas changer |
| Clé secrète | *(configurée)* | Doit correspondre au `SLICER_API_KEY` du conteneur |
| Matériaux | `pla\|PLA\|1.24\|0.05` | Format : `cle\|Nom\|densité\|prix_au_gramme` |
| Prix horaire A1/P1S/V400 | 1.5 €/h chacun | À ajuster |
| Coefficient de temps A1/P1S/V400 | 1.0 chacun | temps réel ÷ temps estimé, à calibrer |
| Forfait de base | 0 € | Montant fixe par pièce |

---

## Déploiement

### Mettre à jour le plugin WordPress

1. Télécharger le `.zip` depuis la conversation Claude.
2. WP admin → Extensions → Ajouter → Téléverser → « Remplacer l'actuel ».
3. Vider le cache (WP Rocket + Elementor si applicable).
4. Tester en navigation privée.

### Mettre à jour le slicer (VPS)

⚠️ **Ne jamais taper la clé secrète dans la commande.** Tout ce qu'on tape est journalisé
(historique du shell, transcriptions d'outils), et une clé écrite une fois y reste. La mise à
jour n'en a pas besoin : on récupère celle du conteneur déjà en place, dans une variable, sans
jamais l'afficher.

```bash
cd ~/slicer
curl -fL -o app.py https://raw.githubusercontent.com/gege1010/Impression_3d_Wordpress/main/slicer/app.py
curl -fL -o Dockerfile https://raw.githubusercontent.com/gege1010/Impression_3d_Wordpress/main/slicer/Dockerfile

# Filets de sécurité : on garde de quoi revenir en arrière.
cp app.py app.py.avant-$(date +%Y%m%d)
docker tag slicer-api:latest slicer-api:avant-$(date +%Y%m%d)

docker build -t slicer-api:latest .

# Récupération de la clé en place. Le --format ciblé est délibéré :
# un « docker inspect » nu déverse tout l'environnement en clair.
KEY=$(docker inspect slicer-api --format '{{range .Config.Env}}{{println .}}{{end}}' \
      | grep '^SLICER_API_KEY=' | cut -d= -f2-)
[ -n "$KEY" ] || { echo "ABANDON : clé introuvable, ne pas continuer"; exit 1; }

docker stop slicer-api && docker rm slicer-api
docker run -d --name slicer-api --restart unless-stopped \
  -p 127.0.0.1:8099:8099 \
  -e SLICER_API_KEY="$KEY" slicer-api:latest
unset KEY

curl -s http://127.0.0.1:8099/health
```

Le `/health` doit répondre `ok: true` **et** afficher les bonnes enveloppes dans
`build_volumes` — c'est le contrôle qui prouve que le conteneur tourne bien avec la nouvelle
version, et pas avec la machine par défaut de PrusaSlicer.

Retour arrière si besoin : `docker rm -f slicer-api`, puis le même `docker run` en remplaçant
`slicer-api:latest` par le tag `slicer-api:avant-<date>`.

#### Première installation, ou changement de clé

Là seulement, il faut fournir une nouvelle clé — et toujours **par un fichier**, jamais en
argument :

```bash
# Générer la clé et l'écrire directement dans un fichier (elle ne s'affiche jamais).
umask 077 && openssl rand -hex 32 > ~/slicer/.env.key

docker run -d --name slicer-api --restart unless-stopped \
  -p 127.0.0.1:8099:8099 \
  --env-file <(printf 'SLICER_API_KEY=%s\n' "$(cat ~/slicer/.env.key)") \
  slicer-api:latest
```

Puis coller la même valeur dans **admin WP → Réglages → Pont Impression 3D → Clé secrète**
(`cat ~/slicer/.env.key` pour la lire au moment de la copie). ⚠️ C'est un secret partagé :
tant que les deux côtés ne portent pas la même valeur, les devis renvoient `cle invalide` (401).

### Synchroniser le dépôt GitHub

1. Passer le dépôt en public temporairement.
2. Committer les fichiers modifiés (via « Add file → Upload files » ou édition en ligne).
3. Repasser en privé.

---

## Notes de sécurité

- Prix **toujours recalculé côté serveur** ; le chiffre du navigateur ne sert qu'à l'affichage.
- L'extrapolation JS est une approximation temps réel ; le prix final au panier est un vrai calcul slicer.
- Service joignable uniquement par WordPress (clé secrète), `127.0.0.1` uniquement.
- Découpe isolée dans Docker, taille de fichier max (60 Mo) et délai limite (180 s).
- Limite de débit anti-abus par adresse IP.
- STL archivés par commande, dossier protégé par `.htaccess`.

---

## Notes 3DPrint Lite 2.1.4

- Version récente, propre sous PHP 8.3.
- Le prix y est calculé côté navigateur (faille) → on le remplace par notre service.
- Pas d'intégration WooCommerce dans la version Lite (réservé au premium) → on fait le pont.
- Viewer 3D en Three.js r101 (2018) : vieux mais fonctionnel.
- ~650 chaînes traduisibles (pas de traduction FR fournie → Loco Translate optionnel).
- **Ne jamais committer son code dans ce dépôt** (ambiguïté GPLv2/AGPLv3).

---

## Licence

Notre code : GPLv2-ou-ultérieure (standard WordPress). 3DPrint Lite est une
dépendance externe, installée séparément — ne pas copier son code ici.
