#!/bin/sh
S=/private/tmp/claude-501/-Users-enrinjr-Documents-GitHub-Dorsia/34238fb1-e491-45f1-abda-f351d9259c40/scratchpad/eval-inventory-r2
cd $S/wt
python3 - "$@" <<'PY'
import sys, subprocess, shutil
sys.path.insert(0, '/private/tmp/claude-501/-Users-enrinjr-Documents-GitHub-Dorsia/34238fb1-e491-45f1-abda-f351d9259c40/scratchpad/eval-inventory-r2/harness')
from mutants import M
only = sys.argv[1:]
out = open('/private/tmp/claude-501/-Users-enrinjr-Documents-GitHub-Dorsia/34238fb1-e491-45f1-abda-f351d9259c40/scratchpad/eval-inventory-r2/mut-results.txt','a')
for mid, f, old, new in M:
    if only and mid not in only: continue
    src = open(f).read()
    if src.count(old) != 1:
        out.write(f'{mid}: BADPATTERN count={src.count(old)}\n'); out.flush(); continue
    open(f,'w').write(src.replace(old, new))
    try:
        r = subprocess.run(['/private/tmp/claude-501/-Users-enrinjr-Documents-GitHub-Dorsia/34238fb1-e491-45f1-abda-f351d9259c40/scratchpad/eval-inventory-r2/run.sh','vendor/bin/pest','tests/Modules/Inventory','tests/Modules/Reservations','tests/Arch/Contracts/InventoryContractTest.php','--stop-on-failure'], capture_output=True, text=True, timeout=600)
        txt = r.stdout + r.stderr
        status = 'KILLED' if r.returncode != 0 else 'SURVIVED'
        import re
        fl = re.findall(r'FAILED\W+\S*\s*(.*)', txt)
        failed = [l for l in txt.splitlines() if '⨯' in l][:2]
        out.write(f'{mid}: {status} {failed}\n'); out.flush()
    finally:
        open(f,'w').write(src)
PY
