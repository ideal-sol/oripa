import json
from pathlib import Path
import unittest


ROOT = Path(__file__).resolve().parents[3]


class CardInventoryContractTest(unittest.TestCase):
    def test_inventory_is_admin_only_read_only_and_nullable_until_notion(self):
        admin = json.loads((ROOT / 'openapi/bundled/admin.openapi.json').read_text())
        paths = ['/inventory/cards', '/inventory/cards/{externalId}', '/inventory/unlinked-prizes']
        for path in paths:
            self.assertEqual(set(admin['paths'][path]), {'get'})
            operation = admin['paths'][path]['get']
            self.assertEqual(operation['security'], [{'adminSession': []}])
            self.assertEqual(operation['x-idempotency'], 'safe')
            self.assertIn('catalog.read', operation['description'])
            self.assertIn({'$ref': '#/components/parameters/PageCursor'}, operation['parameters'])
        card = admin['components']['schemas']['AdminInventoryCard']
        for field in ['notion_on_hand', 'notion_available', 'notion_read_at']:
            self.assertEqual(card['properties'][field]['type'], 'null')
            self.assertIn(field, card['required'])
        for surface in ['public', 'webhook']:
            other = json.loads((ROOT / f'openapi/bundled/{surface}.openapi.json').read_text())
            for path in paths:
                self.assertNotIn(path, other['paths'])
            self.assertNotIn('AdminInventoryCard', other['components']['schemas'])
