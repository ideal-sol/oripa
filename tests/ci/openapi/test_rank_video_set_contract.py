import json
from pathlib import Path
import re
import unittest


ROOT = Path(__file__).resolve().parents[3]


class RankVideoSetContractTest(unittest.TestCase):
    def test_admin_video_set_preserves_legacy_operation_and_exact_weights(self):
        document = json.loads((ROOT / 'openapi/bundled/admin.openapi.json').read_text())
        schemas = document['components']['schemas']
        path = '/catalog/gachas/{gacha_id}/ranks/{rank_id}/'
        self.assertEqual(document['paths'][path + 'video']['put']['operationId'], 'setAdminGachaRankVideo')
        operation = document['paths'][path + 'videos']['put']
        self.assertEqual(operation['x-idempotency'], 'required')
        self.assertEqual(operation['security'], [{'adminSession': []}])
        items = schemas['AdminGachaRankVideosSet']['properties']['items']
        self.assertEqual((items['minItems'], items['maxItems']), (1, 10))
        weight = schemas['AdminRankVideoWeight']
        self.assertEqual(weight['type'], 'string')
        for valid in ['80.00', '20.00', '0.01', '100']:
            self.assertIsNotNone(re.fullmatch(weight['pattern'], valid))
        for invalid in ['80.001', '-1', '101', '1e2', ' 80']:
            self.assertIsNone(re.fullmatch(weight['pattern'], invalid))
        self.assertIn('videos', schemas['AdminCompositionRank']['properties'])
        self.assertIn('video_asset_id', schemas['AdminCompositionRank']['properties'])

    def test_public_candidates_only_expose_assets_and_keep_single_video(self):
        document = json.loads((ROOT / 'openapi/bundled/public.openapi.json').read_text())
        schemas = document['components']['schemas']
        for name, legacy in [('GachaRankPresentation', 'current_video'), ('LoginGachaRank', 'video')]:
            properties = schemas[name]['properties']
            self.assertIn(legacy, properties)
            self.assertEqual(properties['videos']['items'], {'$ref': '#/components/schemas/PresentationAsset'})
            self.assertNotIn('weight_percent', properties)
        self.assertNotIn('weight_percent', json.dumps(document))
        self.assertNotIn('video_item_id', json.dumps(document))
