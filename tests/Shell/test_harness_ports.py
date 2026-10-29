import importlib.util
from pathlib import Path
import socket
import unittest
from unittest.mock import patch


spec = importlib.util.spec_from_file_location('harness_ports', Path(__file__).resolve().parents[2] / 'scripts/check-harness-ports.py')
harness = importlib.util.module_from_spec(spec)
spec.loader.exec_module(harness)


class HarnessPortsTest(unittest.TestCase):
    def test_conflicting_port_is_reported_before_start(self):
        with socket.socket() as listener:
            listener.bind(('127.0.0.1', 0))
            listener.listen()
            port = listener.getsockname()[1]
            config = {'services': {'redis': {'ports': [{'published': str(port), 'target': 6379, 'host_ip': '127.0.0.1'}]}}}
            self.assertEqual(harness.check(config, []), [('redis', str(port))])
            running = [{'Service': 'redis', 'State': 'running', 'Publishers': [{'PublishedPort': port, 'TargetPort': 6379, 'Protocol': 'tcp', 'URL': '127.0.0.1'}]}]
            self.assertEqual(harness.check(config, running), [])
            running[0]['Service'] = 'another-service'
            self.assertEqual(harness.check(config, running), [('redis', str(port))])

    def test_unoccupied_port_passes(self):
        with socket.socket() as probe:
            probe.bind(('127.0.0.1', 0))
            port = probe.getsockname()[1]
        config = {'services': {'mysql': {'ports': [{'published': str(port), 'host_ip': '127.0.0.1'}]}}}
        self.assertEqual(harness.check(config, []), [])

    @patch.dict('os.environ', {'DOCKER_HOST': 'ssh://remote.example'}, clear=True)
    @patch.object(harness.subprocess, 'check_output')
    def test_remote_daemon_does_not_probe_local_ports(self, compose):
        self.assertEqual(harness.main(['docker', 'compose', '--', '-d']), 0)
        compose.assert_not_called()

    @patch.dict('os.environ', {'DOCKER_HOST': 'unix:///tmp/docker.sock'}, clear=True)
    @patch.object(harness.subprocess, 'check_output')
    def test_service_specific_start_is_left_to_compose(self, compose):
        self.assertEqual(harness.main(['docker', 'compose', '--', 'redis']), 0)
        compose.assert_not_called()


if __name__ == '__main__':
    unittest.main()
