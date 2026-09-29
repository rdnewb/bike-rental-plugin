"""Package only the Bike Rental client plugin; the server is a separate repository."""
from pathlib import Path
import hashlib
import re
import zipfile

root = Path(__file__).resolve().parents[1]
plugin = root / "bike-rental-plugin"
version = re.search(r"\* Version: ([0-9.]+)", (plugin / "bike-rental-plugin.php").read_text()).group(1)
files = sorted(p for p in plugin.rglob("*") if p.is_file())
allowed_root = {"bike-rental-plugin.php", "uninstall.php", "readme.txt"}
for source in files:
    relative = source.relative_to(plugin)
    allowed = (relative.as_posix() in allowed_root or
               (len(relative.parts) == 2 and relative.parts[0] == "src" and source.suffix == ".php") or
               (len(relative.parts) == 3 and relative.parts[:2] in {("assets", "js"), ("assets", "css")} and
                source.suffix == (".js" if relative.parts[1] == "js" else ".css")))
    assert allowed, f"Unexpected production file: {relative}"
    assert not source.is_symlink(), f"Symlink cannot ship: {relative}"
assert not (root / "nt-license-controller").exists(), "Controller source must live in its own repository"
assert all("nt-license-controller" not in p.relative_to(plugin).as_posix() for p in files)
out = root / ".release" / "production" / f"bike-rental-plugin-{version}.zip"
out.parent.mkdir(parents=True, exist_ok=True)
with zipfile.ZipFile(out, "w", zipfile.ZIP_DEFLATED) as archive:
    for source in files:
        archive.write(source, source.relative_to(root).as_posix())
with zipfile.ZipFile(out) as archive:
    assert archive.testzip() is None
    assert {name.split('/')[0] for name in archive.namelist()} == {"bike-rental-plugin"}
    assert len(archive.namelist()) == len(files)
    for source in files:
        assert archive.read(source.relative_to(root).as_posix()) == source.read_bytes()
print(f"Verified {len(files)} client files: {out}")
print("SHA256 " + hashlib.sha256(out.read_bytes()).hexdigest())
