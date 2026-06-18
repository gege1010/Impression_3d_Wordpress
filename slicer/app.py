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
    - cylindre : l'empreinte au sol doit tenir dans le cercle (diagonale <= diamètre)."""
    if machine["shape"] == "box":
        footprint_ok = (sx <= machine["x"] and sy <= machine["y"]) or \
                       (sx <= machine["y"] and sy <= machine["x"])
        return footprint_ok and sz <= machine["z"]
    else:  # cylinder
        diagonal = math.sqrt(sx * sx + sy * sy)
        return diagonal <= machine["diameter"] and sz <= machine["z"]


@app.route("/health")
def health():
    """Petite page pour vérifier que le service répond."""
    return jsonify(ok=True, service="slicer", machines=list(MACHINES.keys()))


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

        # 7) Construire la commande de découpe
        cmd = [
            "prusa-slicer", "--export-gcode", stl_path, "--output", gcode_path,
            "--nozzle-diameter", nozzle,
            "--layer-height", layer_height, "--first-layer-height", layer_height,
            "--filament-diameter", "1.75",
            "--temperature", "210", "--bed-temperature", "60",
            "--fill-density", f"{infill}%",
            "--perimeters", "2", "--top-solid-layers", "4", "--bottom-solid-layers", "4",
            "--skirts", "1",
            "--filament-density", density,
        ]
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
