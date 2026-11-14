import json
import sys
from pathlib import Path
required = {'AulaViva interactive shell', 'AulaViva write configuration', 'AulaViva unexpected outbound port'}
seen = set()
for line in Path(sys.argv[1]).read_text().splitlines():
    if not line.strip():
        continue
    incident = json.loads(line)
    event = incident.get('event', {})
    if incident.get('status') == 'open' and event.get('output_fields', {}).get('k8s.ns.name') == 'aulaviva':
        seen.add(event.get('rule'))
missing = required - seen
print('Reglas recibidas:', ', '.join(sorted(seen)))
if missing:
    print('Faltan:', ', '.join(sorted(missing)))
    raise SystemExit(1)
