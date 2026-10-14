import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest


ROOT = Path(__file__).resolve().parents[2]
OVERLAY = ROOT / 'infrastructure/notion/compose.notion.yaml'
DUMMY = {
    'V2_NOTION_ENABLED': 'true',
    'V2_NOTION_TOKEN': 'synthetic-worker-$LITERAL',
    'V2_NOTION_DATA_SOURCE_ID': '11111111-1111-4111-8111-111111111111',
    'V2_NOTION_PROPERTY_EXTERNAL_ID': 'Site ID',
    'V2_NOTION_PROPERTY_ON_HAND': 'Site On Hand',
    'V2_NOTION_PROPERTY_AVAILABLE': 'Site Available',
    'V2_NOTION_PROPERTY_REQUIRED': 'Site Required',
    'V2_NOTION_PROPERTY_ALLOCATION': 'Site Allocation',
    'V2_NOTION_PROPERTY_STORED': 'Site Stored',
    'V2_NOTION_PROPERTY_SHIPPED': 'Site Shipped',
    'V2_NOTION_PROPERTY_SYNCED_AT': 'Site Synced',
    'V2_NOTION_SYNC_INTERVAL_MINUTES': '17',
    'V2_NOTION_REQUESTS_PER_SECOND': '2',
    'V2_NOTION_MAX_RETRIES': '4',
    'V2_NOTION_TIMEOUT_SECONDS': '22',
    'V2_NOTION_LEASE_SECONDS': '150',
    'V2_NOTION_FAILURE_BACKOFF_SECONDS': '360',
}


class NotionComposeTest(unittest.TestCase):
    def render(self, host=None, values=None, missing=False):
        with tempfile.TemporaryDirectory(prefix='notion-compose-synthetic-') as directory:
            root = Path(directory)
            base = root / 'compose.json'
            base.write_text(json.dumps({'services': {'api': {'image': 'synthetic-api:local'}},
                'networks': {'v2_private': {'internal': True}, 'v2_api_egress': {}}}))
            interpolation = root / 'empty.env'
            interpolation.write_text('')
            worker = root / 'worker.env'
            if not missing:
                worker.write_text(''.join(f"{key}='{value}'\n" for key, value in (DUMMY if values is None else values).items()))
                worker.chmod(0o600)
            environment = {'PATH': os.defpath, 'HOME': directory,
                'V2_NOTION_WORKER_IMAGE': 'synthetic-api@sha256:' + 'a' * 64,
                'V2_NOTION_WORKER_ENV_FILE': str(worker), **(host or {})}
            return subprocess.run(['docker', 'compose', '--profile', 'notion', '--project-directory', directory,
                '--env-file', str(interpolation), '-f', str(base), '-f', str(OVERLAY),
                'config', '--format', 'json'], env=environment, capture_output=True, text=True, check=False)

    def assert_worker_values(self, result, expected):
        self.assertEqual(result.returncode, 0, 'Synthetic Compose config must succeed')
        worker = json.loads(result.stdout)['services']['notion-worker']
        serialized = {key: value.replace('$', '$$') for key, value in expected.items()}
        self.assertEqual(worker.get('environment', {}), serialized)

    def test_worker_file_survives_absent_host_values(self):
        self.assert_worker_values(self.render(), DUMMY)

    def test_worker_file_survives_empty_host_values(self):
        self.assert_worker_values(self.render({key: '' for key in DUMMY}), DUMMY)

    def test_worker_file_survives_conflicting_host_values(self):
        host = {key: 'synthetic-host-value' for key in DUMMY}
        result = self.render(host)
        self.assert_worker_values(result, DUMMY)
        self.assertEqual(json.loads(result.stdout)['services']['api']['environment']['V2_NOTION_TOKEN'], host['V2_NOTION_TOKEN'])

    def test_missing_worker_settings_do_not_inherit_host_secrets_or_enablement(self):
        self.assert_worker_values(self.render(DUMMY, values={}), {})

    def test_missing_worker_file_fails_closed(self):
        self.assertNotEqual(self.render(missing=True).returncode, 0)
