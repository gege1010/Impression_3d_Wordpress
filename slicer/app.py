#!/usr/bin/env python3
# -*- coding: utf-8 -*-
#
# Service de découpe ("slicer API")
# ---------------------------------
# Petit serveur web qui tourne sur le VPS. WordPress lui envoie un fichier STL
# + quelques paramètres, et il répond (en JSON) avec :
#   - le poids de filament (g)         -> pour la facturation au poids
#   - le temps d'impression (secondes) -> pour la facturation à l'heure
#   - les dimensions de la pièce       -> pour le contrôle "est-ce que ça rentre"
#   - si la pièce rentre dans la machine choisie
#
# Le calcul se fait avec PrusaSlicer (installé dans la même image Docker).
# Le poids inclut les supports si on les active.

import os
import re
import time
import math
import struct
import tempfile
import subprocess
import numpy as np
from flask import Flask, request, jsonify

app = Flask(__name__)

# Taille maximale d'un fichier uploadé (60 Mo). Au-delà, on refuse :
# ça évite qu'un fichier énorme fasse ramer le serveur.
app.config["MAX_CONTENT_LENGTH"] = 60 * 1024 * 1024

# Clé secrète : seul celui qui la connaît (ton WordPress) peut appeler le service.
# Elle est fournie au démarrage du conteneur (variable d'environnement).
API_KEY = os.environ.get("SLICER_API_KEY", "change-me")

# Combien de temps au maximum on autorise une découpe (en secondes).
# Si un modèle est trop lourd à découper, on coupe au bout de ce délai.
SLICE_TIMEOUT = int(os.environ.get("SLICE_TIMEOUT", "180"))

# --- Définition de tes machines -------------------------------------------
# "box"      = volume rectangulaire (les Bambu)
# "cylinder" = volume cylindrique  (la FLSUN delta)
# time_factor = coefficient pour ajuster le temps estimé à la réalité de la
#               machine. On le règlera plus tard en comparant à de vraies
#               impressions (ex. si PrusaSlicer sous-estime de 20%, on met 1.2).
MACHINES = {
    "a1":   {"name": "Bambu Lab A1",  "shape": "box",      "x": 256, "y": 256, "z": 256, "time_factor": 1.0},
    "p1s":  {"name": "Bambu Lab P1S", "shape": "box",      "x": 256, "y": 256, "z": 256, "time_factor": 1.0},
    "v400": {"name": "FLSUN V400",    "shape": "cylinder", "diameter": 300,      "z": 410, "time_factor": 1.0},
}

# --- Géométrie du plateau transmise à PrusaSlicer -------------------------
# IMPORTANT : sans ces réglages, PrusaSlicer utilise sa machine par défaut
# (plateau 200 x 200, hauteur 200) et REFUSE de découper toute pièce plus
# grande, avec "Objects could not fit on the bed". C'était la cause des
# "Devis indisponible" sur les grandes pièces.
#
# PrusaSlicer décrit le plateau par une liste de points "XxY". Un plateau
# rond (delta) est donc un polygone à N points répartis sur le cercle.
CIRCLE_POINTS = 72


def _round_bed(diameter, points=CIRCLE_POINTS):
    """Plateau circulaire -> polygone, posé dans le quadrant positif."""
    r = diameter / 2.0
    return ",".join(
        "%.2fx%.2f" % (r + r * math.cos(2 * math.pi * i / points),
                       r + r * math.sin(2 * math.pi * i / points))
        for i in range(points)
    )


def _square_bed(x, y):
    """Plateau rectangulaire -> les 4 coins."""
    return "0x0,%gx0,%gx%g,0x%g" % (x, x, y, y)


for _key, _m in MACHINES.items():
    if _m["shape"] == "cylinder":
        _m["bed_shape"] = _round_bed(_m["diameter"])
        _m["center"] = "%g,%g" % (_m["diameter"] / 2.0, _m["diameter"] / 2.0)
    else:
        _m["bed_shape"] = _square_bed(_m["x"], _m["y"])
        _m["center"] = "%g,%g" % (_m["x"] / 2.0, _m["y"] / 2.0)


# --- Vitesses et accélérations par machine --------------------------------
# Sans ces réglages, PrusaSlicer chronomètre TOUTES les machines avec ses
# valeurs génériques (périmètre 60, remplissage 80, déplacement 130 mm/s) :
# le temps estimé — donc le prix — était surévalué d'un facteur 3 à 4.
#
# Les valeurs ci-dessous sont reprises telles quelles des profils publiés par
# les fabricants, pas inventées :
#   - FLSUN V400 : profil PrusaSlicer officiel de FLSUN, section [print:*V400*]
#     de resources/profiles/FLSun.ini dans github.com/Flsun3d/FlsunSlicer
#     (FlsunSlicer est un fork de PrusaSlicer, donc les noms d'options sont
#     déjà les bons).
#   - Bambu A1 / P1S : profils officiels de Bambu Studio
#     (github.com/bambulab/BambuStudio, resources/profiles/BBL), couche
#     0,2 mm, buse 0,4 — noms d'options traduits vers ceux de PrusaSlicer.
#
# Depuis le passage de l'image à Debian trixie (PrusaSlicer 2.9.2), toutes les
# accélérations par type de trajet et les vitesses de surplomb dynamiques du
# profil FLSUN sont reprises. Seule reste sans équivalent travel_speed_first_layer.
# ⚠️ Ne pas revenir à une base bookworm (PrusaSlicer 2.5) sans retirer ces
# options : la 2.5 les refuse et la découpe échouerait.
MACHINE_PROFILES = {
    # Bambu Lab A1 — vitesses communes aux Bambu, accélération plus douce.
    "a1": {
        "external-perimeter-speed": "200", "perimeter-speed": "300",
        "small-perimeter-speed": "50%", "infill-speed": "270",
        "solid-infill-speed": "250", "top-solid-infill-speed": "200",
        "gap-fill-speed": "250", "bridge-speed": "50",
        "support-material-speed": "150", "first-layer-speed": "50",
        "travel-speed": "700", "max-print-speed": "500",
        "default-acceleration": "6000", "perimeter-acceleration": "5000",
        "infill-acceleration": "6000", "first-layer-acceleration": "500",
        "external-perimeter-acceleration": "5000",
        "top-solid-infill-acceleration": "2000",
        "machine-max-acceleration-extruding": "12000,12000",
        "machine-max-acceleration-x": "12000,12000",
        "machine-max-acceleration-y": "12000,12000",
        "machine-max-acceleration-travel": "9000,9000",
        "machine-max-feedrate-x": "500,500", "machine-max-feedrate-y": "500,500",
        "machine-max-jerk-x": "9,9", "machine-max-jerk-y": "9,9",
    },
    # Bambu Lab P1S — mêmes vitesses que l'A1, machine plus nerveuse.
    "p1s": {
        "external-perimeter-speed": "200", "perimeter-speed": "300",
        "small-perimeter-speed": "50%", "infill-speed": "270",
        "solid-infill-speed": "250", "top-solid-infill-speed": "200",
        "gap-fill-speed": "250", "bridge-speed": "50",
        "support-material-speed": "150", "first-layer-speed": "50",
        "travel-speed": "500", "max-print-speed": "500",
        "default-acceleration": "10000", "perimeter-acceleration": "5000",
        "infill-acceleration": "10000", "first-layer-acceleration": "500",
        "external-perimeter-acceleration": "5000",
        "top-solid-infill-acceleration": "2000",
        "travel-acceleration": "10000",
        "machine-max-acceleration-extruding": "20000,20000",
        "machine-max-acceleration-x": "20000,20000",
        "machine-max-acceleration-y": "20000,20000",
        "machine-max-acceleration-travel": "9000,9000",
        "machine-max-feedrate-x": "500,500", "machine-max-feedrate-y": "500,500",
        "machine-max-jerk-x": "9,9", "machine-max-jerk-y": "9,9",
    },
    # FLSUN V400 — delta, nettement plus rapide (remplissage à 550 mm/s).
    "v400": {
        "external-perimeter-speed": "180", "perimeter-speed": "230",
        "small-perimeter-speed": "230", "infill-speed": "550",
        "solid-infill-speed": "350", "top-solid-infill-speed": "250",
        "gap-fill-speed": "100", "bridge-speed": "30",
        "support-material-speed": "400", "first-layer-speed": "100",
        "travel-speed": "600", "max-print-speed": "600",
        "default-acceleration": "10000", "perimeter-acceleration": "5000",
        "infill-acceleration": "10000", "bridge-acceleration": "5000",
        "first-layer-acceleration": "5000",
        "external-perimeter-acceleration": "5000",
        "solid-infill-acceleration": "8000",
        "top-solid-infill-acceleration": "8000",
        "travel-acceleration": "10000",
        # Ralentissements dans les surplombs, selon la part de la trajectoire
        # qui repose dans le vide. Sans effet sur une pièce sans surplomb.
        # None = drapeau sans valeur : lui passer "1" ferait prendre ce 1 pour
        # un nom de fichier et la découpe échouerait ("No such file: 1").
        "enable-dynamic-overhang-speeds": None,
        "overhang-speed-0": "30", "overhang-speed-1": "60",
        "overhang-speed-2": "80", "overhang-speed-3": "120",
        "machine-max-acceleration-extruding": "10000,1250",
        "machine-max-acceleration-x": "30000,1000",
        "machine-max-acceleration-y": "30000,1000",
        "machine-max-feedrate-x": "10000,200", "machine-max-feedrate-y": "10000,200",
        "machine-max-jerk-x": "20000,10", "machine-max-jerk-y": "20000,10",
    },
}

# Débit maximum de matière, en mm³/s. C'est le vrai plafond physique : une
# buse ne peut pas fondre plus vite que ça, quelle que soit la vitesse
# demandée. 0 = pas de plafond.
#   - Bambu publie 21 mm³/s pour son PLA (profil filament officiel) : repris tel quel.
#   - FLSUN laisse le champ à 0, donc aucun plafond.
#
# ⚠️ SEULE VALEUR DE CE FICHIER QUI NE VIENT PAS D'UNE SOURCE OFFICIELLE : les
# 25 mm³/s de la V400. Le profil FLSUN ne plafonne rien tout en demandant
# 550 mm/s de remplissage, ce qui représente ~49 mm³/s en couche de 0,2 mm —
# aucune buse de 0,4 ne fond aussi vite. Suivre FLSUN à la lettre sous-estime
# donc le temps, et fait SOUS-FACTURER. 25 mm³/s correspond à une buse haute
# fusion 0,4, ce que la V400 embarque. Effet mesuré sur un cube de 100 mm :
# 1 h 41 sans plafond, 2 h 10 à 25 mm³/s (+29 %).
# À remplacer dès qu'une impression réelle chronométrée permet de trancher.
MAX_VOLUMETRIC = {"a1": "21", "p1s": "21", "v400": "25"}

# ⚠️ Mesuré, contre-intuitif : PrusaSlicer 2.5 n'applique les limites machine
# au calcul du temps QUE pour les saveurs Marlin. En saveur "reprap" (le
# défaut), il ignore les accélérations qu'on lui passe et retombe sur sa
# valeur interne de 1500 mm/s². Même configuration, même pièce : 3 h 31 en
# reprap contre 2 h 30 en marlin2. Le G-code ne sert qu'à lire le poids et le
# temps — il n'est jamais envoyé à une imprimante — donc la saveur est sans
# conséquence par ailleurs. FLSUN utilise "klipper", qui n'existe pas encore
# dans la 2.5 : marlin2 est l'équivalent le plus proche qui honore les limites.
GCODE_FLAVOR = "marlin2"


def profile_args(printer_key):
    """Traduit le profil d'une machine en options de ligne de commande."""
    args = ["--gcode-flavor", GCODE_FLAVOR]
    for option, value in MACHINE_PROFILES[printer_key].items():
        # Valeur None = option booléenne, qui s'active sans argument.
        args += ["--" + option] if value is None else ["--" + option, value]
    args += ["--filament-max-volumetric-speed", MAX_VOLUMETRIC[printer_key]]
    return args

# --- Petit garde-fou anti-abus --------------------------------------------
# On limite le nombre de demandes par adresse IP sur une fenêtre de temps,
# pour qu'un plaisantin qui envoie 30 fichiers ne sature pas le serveur.
RATE_MAX = int(os.environ.get("RATE_MAX", "20"))      # nb de requêtes...
RATE_WINDOW = int(os.environ.get("RATE_WINDOW", "60"))  # ...par X secondes
_hits = {}  # mémoire simple : { ip: [timestamps...] }

def rate_limited(ip):
    now = time.time()
    recent = [t for t in _hits.get(ip, []) if now - t < RATE_WINDOW]
    recent.append(now)
    _hits[ip] = recent
    return len(recent) > RATE_MAX


def parse_time_to_seconds(text):
    """Convertit 'Xd Yh Zm Ws' (le format de PrusaSlicer) en secondes."""
    units = {"d": 86400, "h": 3600, "m": 60, "s": 1}
    total = 0
    for value, unit in re.findall(r"(\d+)\s*([dhms])", text):
        total += int(value) * units[unit]
    return total


def model_dimensions(stl_path, scale):
    """Demande à PrusaSlicer les dimensions et le volume bruts du modèle,
    puis applique l'échelle choisie par le client."""
    out = subprocess.run(
        ["prusa-slicer", "--info", stl_path],
        capture_output=True, text=True, timeout=60
    ).stdout

    def grab(key):
        m = re.search(rf"{key}\s*=\s*([\d.]+)", out)
        return float(m.group(1)) if m else 0.0

    sx, sy, sz = grab("size_x"), grab("size_y"), grab("size_z")
    volume_mm3 = grab("volume")
    # On applique l'échelle (1 = taille d'origine ; 2 = deux fois plus grand, etc.)
    return (sx * scale, sy * scale, sz * scale, volume_mm3 * (scale ** 3))


def fits_in_machine(machine, sx, sy, sz):
    """Vérifie si la pièce rentre dans la machine.
    - boîte    : on essaie les deux orientations à plat.
    - cylindre : l'empreinte au sol doit tenir dans le cercle (diagonale <= diamètre).

    C'est le SEUL contrôle de taille qui fait foi. On ne peut pas s'appuyer sur
    PrusaSlicer : dès qu'on lui passe un --center explicite il ne vérifie plus
    rien (mesuré : il accepte 310 mm de large sur un plateau de 300, et 420 mm
    de haut pour une limite à 410), et sans --center il ne teste que la boîte
    englobante du plateau, jamais le cercle."""
    if machine["shape"] == "box":
        footprint_ok = (sx <= machine["x"] and sy <= machine["y"]) or \
                       (sx <= machine["y"] and sy <= machine["x"])
        return footprint_ok and sz <= machine["z"]
    else:  # cylinder
        diagonal = math.sqrt(sx * sx + sy * sy)
        return diagonal <= machine["diameter"] and sz <= machine["z"]


@app.route("/health")
def health():
    """Petite page pour vérifier que le service répond.
    On y expose l'enveloppe de chaque machine : ça permet de contrôler d'un
    coup d'oeil, depuis l'extérieur, que le service tourne bien avec les bonnes
    dimensions (et pas la machine par défaut de PrusaSlicer)."""
    envelopes = {}
    for key, m in MACHINES.items():
        if m["shape"] == "cylinder":
            envelopes[key] = {"shape": "cylinder", "diameter_mm": m["diameter"], "height_mm": m["z"]}
        else:
            envelopes[key] = {"shape": "box", "x_mm": m["x"], "y_mm": m["y"], "height_mm": m["z"]}
    return jsonify(ok=True, service="slicer",
                   machines=list(MACHINES.keys()), build_volumes=envelopes)


@app.route("/slice", methods=["POST"])
def slice_model():
    # 1) Vérifier la clé secrète
    if request.headers.get("X-API-Key") != API_KEY:
        return jsonify(ok=False, error="cle invalide"), 401

    # 2) Anti-abus
    if rate_limited(request.remote_addr or "?"):
        return jsonify(ok=False, error="trop de demandes, reessaie dans un instant"), 429

    # 3) Récupérer le fichier
    if "file" not in request.files:
        return jsonify(ok=False, error="aucun fichier"), 400
    upload = request.files["file"]

    # 4) Récupérer les paramètres (avec des valeurs par défaut raisonnables)
    printer = request.form.get("printer", "a1").lower()
    if printer not in MACHINES:
        return jsonify(ok=False, error="machine inconnue"), 400
    machine = MACHINES[printer]

    infill = request.form.get("infill", "20")             # remplissage en %
    density = request.form.get("material_density", "1.24")  # densité du matériau (PLA ~1.24)
    layer_height = request.form.get("layer_height", "0.2")
    nozzle = request.form.get("nozzle", "0.4")
    supports = request.form.get("supports", "0") in ("1", "true", "on", "yes")
    try:
        scale = float(request.form.get("scale", "1"))
    except ValueError:
        scale = 1.0

    # 5) Travailler dans un dossier temporaire jetable
    with tempfile.TemporaryDirectory() as tmp:
        stl_path = os.path.join(tmp, "model.stl")
        gcode_path = os.path.join(tmp, "model.gcode")
        upload.save(stl_path)

        # 6) Dimensions + contrôle "ça rentre"
        try:
            sx, sy, sz, volume_mm3 = model_dimensions(stl_path, scale)
        except Exception:
            return jsonify(ok=False, error="fichier illisible (STL invalide ?)"), 400
        fits = fits_in_machine(machine, sx, sy, sz)

        # 6 bis) Hors enveloppe : on ne découpe pas du tout.
        # Inutile (et lent) de faire travailler PrusaSlicer sur une pièce qui
        # ne rentrera pas ; on répond tout de suite avec fits=false, et le site
        # affiche "la pièce ne rentre pas" au lieu d'un devis fantaisiste.
        if not fits:
            return jsonify(
                ok=True,
                printer=machine["name"],
                weight_g=0.0,
                print_time_seconds=0,
                print_time_hours=0.0,
                dimensions_mm=[round(sx, 1), round(sy, 1), round(sz, 1)],
                volume_cm3=round(volume_mm3 / 1000.0, 2),
                fits=False,
                infill_percent=infill,
                supports=supports,
            )

        # 7) Construire la commande de découpe
        cmd = [
            "prusa-slicer", "--export-gcode", stl_path, "--output", gcode_path,
            # Géométrie de la machine choisie. Sans ça, PrusaSlicer découpe sur
            # un plateau 200x200x200 et refuse tout ce qui dépasse.
            "--bed-shape", machine["bed_shape"],
            "--max-print-height", str(machine["z"]),
            # On pose la pièce au centre du plateau : elle est déjà validée par
            # fits_in_machine(), donc PrusaSlicer n'a plus qu'à la découper.
            "--center", machine["center"],
            "--nozzle-diameter", nozzle,
            "--layer-height", layer_height, "--first-layer-height", layer_height,
            "--filament-diameter", "1.75",
            "--temperature", "210", "--bed-temperature", "60",
            "--fill-density", f"{infill}%",
            "--perimeters", "2", "--top-solid-layers", "4", "--bottom-solid-layers", "4",
            "--skirts", "1",
            "--filament-density", density,
        ]
        # Vitesses, accélérations et limites propres à la machine choisie.
        cmd += profile_args(printer)
        if supports:
            cmd += ["--support-material"]
        if scale != 1.0:
            cmd += ["--scale", str(scale)]

        # 8) Lancer la découpe (avec un délai limite)
        try:
            proc = subprocess.run(cmd, capture_output=True, text=True, timeout=SLICE_TIMEOUT)
        except subprocess.TimeoutExpired:
            return jsonify(ok=False, error="decoupe trop longue (modele trop complexe)"), 504
        if not os.path.exists(gcode_path):
            return jsonify(ok=False, error="echec de la decoupe", detail=proc.stderr[-400:]), 500

        # 9) Lire le poids et le temps dans le G-code produit
        gcode = open(gcode_path, "r", errors="ignore").read()
        w = re.search(r"filament used \[g\]\s*=\s*([\d.]+)", gcode)
        t = re.search(r"estimated printing time \(normal mode\)\s*=\s*(.+)", gcode)
        weight_g = float(w.group(1)) if w else 0.0
        time_raw = parse_time_to_seconds(t.group(1)) if t else 0
        time_corrected = round(time_raw * machine["time_factor"])

    # 10) Répondre en JSON
    return jsonify(
        ok=True,
        printer=machine["name"],
        weight_g=round(weight_g, 2),
        print_time_seconds=time_corrected,
        print_time_hours=round(time_corrected / 3600.0, 3),
        dimensions_mm=[round(sx, 1), round(sy, 1), round(sz, 1)],
        volume_cm3=round(volume_mm3 / 1000.0, 2),
        fits=fits,
        infill_percent=infill,
        supports=supports,
    )


# --- Détection des supports (analyse géométrique des surplombs) ------------
# On regarde l'orientation de chaque facette. Une facette qui "regarde vers le
# bas" de façon prononcée ET qui n'est pas posée sur le plateau = un surplomb
# qui aurait besoin de support. Si la surface en surplomb dépasse un certain
# pourcentage de la surface totale, on recommande les supports.
# (Indication approximative, le client peut toujours ajuster la case.)
OVERHANG_NZ = float(os.environ.get("OVERHANG_NZ", "-0.6"))          # normale plus basse que ça = surplomb
BED_EPS = float(os.environ.get("BED_EPS", "0.5"))                   # mm au-dessus du plateau (on ignore la face du dessous)
OVERHANG_FRACTION = float(os.environ.get("OVERHANG_FRACTION", "0.02"))  # > 2% de surface en surplomb -> supports


def _load_stl_triangles(path):
    """Charge un STL (binaire ou ASCII) -> tableau numpy (N, 3, 3) des sommets."""
    with open(path, "rb") as f:
        head = f.read(80)
    is_ascii = head[:5].lower() == b"solid"
    if is_ascii:
        with open(path, "rb") as f:
            if b"facet" not in f.read(1024).lower():
                is_ascii = False  # certains binaires commencent par "solid"
    if not is_ascii:
        with open(path, "rb") as f:
            f.read(80)
            (n,) = struct.unpack("<I", f.read(4))
            data = f.read(50 * n)
        dt = np.dtype([("n", "<3f4"), ("v1", "<3f4"), ("v2", "<3f4"), ("v3", "<3f4"), ("a", "<u2")])
        arr = np.frombuffer(data, dtype=dt, count=n)
        return np.stack([arr["v1"], arr["v2"], arr["v3"]], axis=1)
    # ASCII
    verts = []
    with open(path, "r", errors="ignore") as f:
        for line in f:
            line = line.strip()
            if line.startswith("vertex"):
                p = line.split()
                verts.append((float(p[1]), float(p[2]), float(p[3])))
    verts = np.array(verts, dtype=np.float64)
    n = len(verts) // 3
    return verts[:n * 3].reshape(n, 3, 3)


def analyze_supports(path, scale=1.0):
    tris = _load_stl_triangles(path).astype(np.float64) * scale
    if tris.shape[0] == 0:
        return {"supports_recommended": False, "overhang_area_fraction": 0.0, "triangles": 0}
    v1, v2, v3 = tris[:, 0, :], tris[:, 1, :], tris[:, 2, :]
    cross = np.cross(v2 - v1, v3 - v1)
    length = np.linalg.norm(cross, axis=1)
    areas = 0.5 * length
    total = areas.sum()
    if total <= 0:
        return {"supports_recommended": False, "overhang_area_fraction": 0.0, "triangles": int(tris.shape[0])}
    nz = np.zeros_like(length)
    ok = length > 1e-12
    nz[ok] = cross[ok, 2] / length[ok]            # composante verticale de la normale
    min_z = tris[:, :, 2].min()                    # niveau du plateau
    tri_max_z = tris[:, :, 2].max(axis=1)
    overhang = (nz < OVERHANG_NZ) & (tri_max_z > min_z + BED_EPS)
    frac = float(areas[overhang].sum() / total)
    return {
        "supports_recommended": frac > OVERHANG_FRACTION,
        "overhang_area_fraction": round(frac, 4),
        "triangles": int(tris.shape[0]),
    }


@app.route("/analyze", methods=["POST"])
def analyze_model():
    """Indique si la pièce a probablement besoin de supports (analyse rapide, sans découpe)."""
    if request.headers.get("X-API-Key") != API_KEY:
        return jsonify(ok=False, error="cle invalide"), 401
    if rate_limited(request.remote_addr or "?"):
        return jsonify(ok=False, error="trop de demandes, reessaie dans un instant"), 429
    if "file" not in request.files:
        return jsonify(ok=False, error="aucun fichier"), 400
    try:
        scale = float(request.form.get("scale", "1"))
    except ValueError:
        scale = 1.0
    with tempfile.TemporaryDirectory() as tmp:
        stl_path = os.path.join(tmp, "model.stl")
        request.files["file"].save(stl_path)
        try:
            res = analyze_supports(stl_path, scale)
        except Exception:
            return jsonify(ok=False, error="analyse impossible (STL invalide ?)"), 400
    return jsonify(ok=True, **res)


if __name__ == "__main__":
    # Démarrage simple pour des tests en local (en production c'est gunicorn).
    app.run(host="0.0.0.0", port=8099)
