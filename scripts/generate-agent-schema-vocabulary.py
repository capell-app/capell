#!/usr/bin/env python3
"""Regenerate the bundled schema.org term allowlist from an official JSON-LD release."""
import argparse
import hashlib
import json
from pathlib import Path
from urllib.request import urlopen

parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--source', default='https://schema.org/version/30.0/schemaorg-current-https.jsonld')
arguments = parser.parse_args()
with urlopen(arguments.source, timeout=60) as response:
    raw = response.read()
document = json.loads(raw)
terms = sorted({
    node['@id'].removeprefix('schema:').removeprefix('https://schema.org/').removeprefix('http://schema.org/')
    for node in document['@graph']
    if node.get('@id', '').startswith(('schema:', 'https://schema.org/', 'http://schema.org/'))
})
if len(terms) < 1000 or not {'Product', 'price', 'Event', 'Article'}.issubset(terms):
    raise ValueError('Source is not a complete schema.org vocabulary')
output = Path(__file__).resolve().parents[1] / 'packages/core/resources/agent-schema/schemaorg-terms.json'
output.write_text(json.dumps({'source': arguments.source, 'sha256': hashlib.sha256(raw).hexdigest(), 'terms': terms}, indent=4) + '\n')
print(f'Generated {len(terms)} terms in {output}')
