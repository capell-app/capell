#!/usr/bin/env python3
"""Detect local Compose port conflicts before an all-services harness start."""

import errno
import json
import os
import socket
import subprocess
import sys


def check(config, running):
    owned = {
        (item.get('Service'), str(port.get('PublishedPort')), port.get('TargetPort'), port.get('Protocol'), port.get('URL'))
        for item in running
        if item.get('State') == 'running'
        for port in item.get('Publishers') or []
    }
    conflicts = []
    for service, spec in config.get('services', {}).items():
        for port in spec.get('ports', []):
            published = port.get('published')
            protocol = port.get('protocol', 'tcp')
            address = port.get('host_ip', '0.0.0.0')
            if not published or protocol != 'tcp' or ':' in address:
                continue
            target = port.get('target')
            if any((service, str(published), target, protocol, ip) in owned for ip in (address, '0.0.0.0', '::')):
                continue
            with socket.socket(socket.AF_INET, socket.SOCK_STREAM) as probe:
                try:
                    probe.bind((address, int(published)))
                except OSError as error:
                    if error.errno == errno.EADDRINUSE:
                        conflicts.append((service, published))
                    else:
                        raise
    return conflicts


def main(args):
    if '--' not in args:
        return 2
    separator = args.index('--')
    compose, up_args = args[:separator], args[separator + 1:]
    if not compose or compose[0] == 'docker-compose':
        return 0  # Legacy Compose does not provide the JSON inspection contract.
    # Service-specific starts and uncommon Compose options retain Compose's
    # own validation; probing every configured port would give false positives.
    if any(arg not in ('-d', '--detach', '--build', '--quiet-pull') for arg in up_args):
        return 0
    host = os.environ.get('DOCKER_HOST', '')
    if not host:
        context = subprocess.run(['docker', 'context', 'inspect', '--format', '{{.Endpoints.docker.Host}}'], capture_output=True, text=True)
        host = context.stdout.strip() if context.returncode == 0 else ''
    if not host.startswith(('unix://', 'npipe://')):
        return 0
    try:
        config = json.loads(subprocess.check_output([*compose, 'config', '--format', 'json'], text=True))
        output = subprocess.check_output([*compose, 'ps', '--format', 'json'], text=True)
        running = [json.loads(line) for line in output.splitlines() if line.strip()]
        conflicts = check(config, running)
    except (OSError, ValueError, subprocess.CalledProcessError) as error:
        print(f'Harness port preflight could not inspect Compose: {error}', file=sys.stderr)
        return 1
    if conflicts:
        for service, port in conflicts:
            print(f'Harness port {port} for {service} is already in use; choose an available port in this checkout’s .env.', file=sys.stderr)
        return 1
    return 0


if __name__ == '__main__':
    raise SystemExit(main(sys.argv[1:]))
