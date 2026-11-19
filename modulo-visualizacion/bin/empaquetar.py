"""Construye la entrega sin credenciales. Intermedios exclusivamente en LOCALAPPDATA."""
from pathlib import Path
import hashlib
import os
import re
import shutil
import subprocess
import tempfile
import zipfile

ROOT = Path(__file__).resolve().parents[2]
NAME = "io2-completo-configurable-20261118"


def local_temp():
    base = Path(os.environ["LOCALAPPDATA"]) / "Codex" / "Temp"
    for p in (base, *base.parents):
        if re.search(r"onedrive|dropbox|google drive|icloud", str(p), re.I):
            raise RuntimeError("La ruta temporal debe estar fuera de la nube")
        if p.exists() and (p.is_symlink() or getattr(p, "is_junction", lambda: False)()):
            raise RuntimeError("La ruta temporal está redirigida")
    base.mkdir(parents=True, exist_ok=True)
    return base


def run(args):
    env = os.environ.copy()
    env.update(IO_TEST_DSN="mysql:host=127.0.0.1;port=3307;charset=utf8mb4", IO_TEST_USER="root", IO_TEST_PASS="")
    result = subprocess.run(args, cwd=ROOT, env=env, capture_output=True, text=True, encoding="utf-8", errors="replace")
    if result.returncode:
        raise RuntimeError(f"Falló {args[-1]}:\n{result.stdout[-3000:]}\n{result.stderr[-1000:]}")
    return result.stdout.strip()


def main():
    # El datadir debe ser local de prueba; nunca se usa config.php para las suites.
    check = run(["mysql", "--host=127.0.0.1", "--port=3307", "--user=root", "--batch", "--skip-column-names", "-e", "SELECT @@port;"])
    if check != "3307":
        raise RuntimeError("Se requiere la instancia de pruebas local 3307")
    for php in (ROOT / "modulo-visualizacion").rglob("*.php"):
        if php.name != "config.php":
            run(["php", "-l", str(php)])
    for js in ["modulo-visualizacion/backoffice/contenido-editor.js", "tema/io-theme.js", "portada/portada.js", "modulo-visualizacion/public/io-player.js"]:
        run(["node", "--check", js])
    report = ["Verificación local de la entrega — 2026-11-18", run(["php", "-r", "echo 'PHP ' . PHP_VERSION;"]) ]
    for test in ["prueba-flujo", "prueba-tienda", "prueba-condiciones", "prueba-promociones", "prueba-importacion", "prueba-importacion-bd", "prueba-descuentos", "prueba-membresias", "prueba-facturacion", "prueba-pedidos", "prueba-confirmacion", "prueba-contenidos", "prueba-carga-contenidos", "prueba-transmisiones", "prueba-miniaturas", "prueba-youtube"]:
        output = run(["php", f"modulo-visualizacion/tests/{test}.php"])
        summary = next((line for line in reversed(output.splitlines()) if line.startswith("Resultado:")), "Finalizó correctamente")
        report.append(test + ": " + summary)
        print(report[-1])
    output = run(["node", "modulo-visualizacion/tests/prueba-retorno.js"])
    summary = next((line for line in reversed(output.splitlines()) if line.startswith("Resultado:")), "Finalizó correctamente")
    report.append("prueba-retorno: " + summary)
    print(report[-1])
    from PIL import Image
    for original in (ROOT / "portada/img").glob("*.png"):
        final = original.with_suffix(".webp")
        if not final.is_file():
            raise RuntimeError("Falta WebP de " + original.name)
        with Image.open(final) as img:
            if img.width > 2000:
                raise RuntimeError("WebP excede 2000 px: " + final.name)
    report += ["Sintaxis PHP y JavaScript: correcta.", "Imágenes WebP verificadas.",
        "Stripe real, integración con sesión del cliente y producción: pendientes de configuración y aceptación del instalador.",
        "La demo de navegador se prueba desde el repositorio, no desde este ZIP."]
    with tempfile.TemporaryDirectory(prefix="io-entrega-", dir=local_temp()) as temp:
        stage = Path(temp) / NAME
        stage.mkdir(parents=True, exist_ok=True)
        template = run(["php", "-r", "require 'modulo-visualizacion/lib/CargaContenidos.php'; echo IOCargaContenidos::plantilla();"])
        (stage / "plantilla-contenidos.csv").write_text(template + "\n", encoding="utf-8")
        for source, target in [("tema", "tema"), ("portada", "portada"), ("modulo-visualizacion", "io")]:
            for file in (ROOT / source).rglob("*"):
                if not file.is_file():
                    continue
                relative = file.relative_to(ROOT / source)
                if file.name in {"config.php", "demo-banner.html", "iniciar-demo.ps1", "empaquetar.py"} or "tests" in relative.parts:
                    continue
                if relative.parts[:2] == ("subidas", "miniaturas"):
                    continue  # imágenes subidas en local: el servidor crea la carpeta al subir la primera
                if file.suffix.lower() not in {".php", ".sql", ".txt", ".html", ".css", ".js", ".webp"} and file.name != ".htaccess":
                    continue
                dest = stage / target / relative
                dest.parent.mkdir(parents=True, exist_ok=True)
                shutil.copy2(file, dest)
        for doc in ["GUIA-DESPLIEGUE.md", "ENTREGA-CLIENTE.md", "MEMBRESIAS.md", "DESCUENTOS.md", "FACTURACION.md", "PEDIDOS.md"]:
            shutil.copy2(ROOT / doc, stage / doc)
        (stage / "VERIFICACION.txt").write_text("\n".join(report) + "\n", encoding="utf-8")
        files = sorted(f for f in stage.rglob("*") if f.is_file())
        for file in files:
            if file.suffix.lower() in {".php", ".js", ".html", ".md", ".txt"}:
                text = file.read_text(encoding="utf-8-sig")
                if re.search(r"(?:sk_(?:test|live)_|whsec_)[A-Za-z0-9]{12,}", text):
                    raise RuntimeError("Posible secreto en " + str(file.relative_to(stage)))
                if file.name == "config.example.php":
                    for value in re.findall(r"['\"]clave['\"]\s*=>\s*['\"]([^'\"]+)['\"]", text):
                        if value != "CLAVE_BD":
                            raise RuntimeError("El ejemplo contiene una clave de base de datos")
        manifest = "\n".join(hashlib.sha256(f.read_bytes()).hexdigest() + "  " + f.relative_to(stage).as_posix() for f in files) + "\n"
        (stage / "MANIFIESTO-SHA256.txt").write_text(manifest, encoding="utf-8")
        archive = Path(temp) / (NAME + ".zip")
        with zipfile.ZipFile(archive, "w", zipfile.ZIP_DEFLATED) as z:
            for f in sorted(stage.rglob("*")):
                if f.is_file():
                    z.write(f, f.relative_to(stage).as_posix())
        with zipfile.ZipFile(archive) as z:
            if z.testzip() is not None or "io/.htaccess" not in z.namelist() or "io/subidas/.htaccess" not in z.namelist():
                raise RuntimeError("Falló la verificación del ZIP")
            if any(n.endswith("/config.php") or "/tests/" in n for n in z.namelist()):
                raise RuntimeError("El ZIP contiene archivos internos de desarrollo")
        out = ROOT / "dist"
        out.mkdir(exist_ok=True)
        final_zip = out / archive.name
        shutil.copy2(archive, final_zip)
        shutil.copy2(stage / "plantilla-contenidos.csv", out / "plantilla-contenidos.csv")
        (out / (archive.name + ".sha256")).write_text(hashlib.sha256(final_zip.read_bytes()).hexdigest() + "  " + archive.name + "\n", encoding="ascii")
        print(f"Entrega: {final_zip} ({len(files) + 1} archivos; {final_zip.stat().st_size} bytes)")


if __name__ == "__main__":
    main()
