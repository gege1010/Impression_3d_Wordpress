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

Contrôle « ça rentre » : boîte pour les Bambu, cylindre inscrit (carré ~205 mm, h ~390 mm) pour la FLSUN.

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
- ✅ Contrôle « la pièce rentre » (boîte / cylindre) intégré.
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
- ✅ **Vérification « ça rentre »** aussi côté client (volumes de construction en dur) pour l'affichage en temps réel (v0.7.1).
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

- 🔶 Plugin v0.7.1 : livré, à tester côté client (extrapolation + debounce + abort).
- 🔶 Tester un vrai paiement (tunnel WooCommerce de bout en bout).
- 🔶 Saisir les vraies valeurs de calibration temps après impressions de test.

### Roadmap ⬜

- ⬜ Refonte esthétique du formulaire (panneau latéral à droite du viewer 3D, cartes modernes).
- ⬜ Profils de vitesse propres à chaque imprimante (optionnel, pour affiner les estimations).
- ⬜ Fusionner les deux plugins en un seul (finition, plus tard).
- ⬜ Traduction FR de 3DPrint Lite via Loco Translate (optionnel).

---

## Versions

- **Pont WordPress** (`impression-3d-bridge`) : **v0.7.1** — build : `extrapolation échelle + debounce 2s + abort page`.
- **Service slicer** (`app.py`) : endpoints `/health`, `/slice`, `/analyze`. Stack : Flask + gunicorn (2 workers, timeout 200s) + numpy.

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
| `/health` | GET | Vérifie que le service répond (pas de clé requise) |
| `/slice` | POST | Découpe STL → poids, temps, dimensions, fits |
| `/analyze` | POST | Analyse géométrique des surplombs → supports recommandés (oui/non/fraction) |

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

```bash
cd ~/slicer
curl -fL -o app.py https://raw.githubusercontent.com/gege1010/Impression_3d_Wordpress/main/slicer/app.py
curl -fL -o Dockerfile https://raw.githubusercontent.com/gege1010/Impression_3d_Wordpress/main/slicer/Dockerfile
docker build -t slicer-api .
docker rm -f slicer-api
docker run -d --name slicer-api --restart unless-stopped \
  -p 127.0.0.1:8099:8099 \
  -e SLICER_API_KEY=<CLE> slicer-api
curl -s http://127.0.0.1:8099/health
```

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
