"""No confundir WARN de baseline con severidad Medium. Falla cerrado."""
import json
import sys
from pathlib import Path
report = json.loads(Path(sys.argv[1]).read_text(encoding="utf-8"))
sites = report.get("site")
if not isinstance(sites, list) or not sites:
    raise SystemExit("ERROR: ZAP no informa sitios analizados")
blocked = []
for site in sites:
    alerts = site.get("alerts")
    if not isinstance(alerts, list):
        raise SystemExit("ERROR: informe ZAP incompleto")
    for alert in alerts:
        risk = int(alert["riskcode"])
        if risk >= 2:
            blocked.append(alert)
for alert in blocked:
    print(f'BLOQUEO {alert.get("pluginid")}: {alert.get("name")} ({alert["riskcode"]})')
print(f"Sitios: {len(sites)}. Hallazgos Medium+: {len(blocked)}")
raise SystemExit(1 if blocked else 0)
