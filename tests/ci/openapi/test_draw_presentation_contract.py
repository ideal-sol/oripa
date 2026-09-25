import json
from pathlib import Path
import unittest


ROOT = Path(__file__).resolve().parents[3]


class DrawPresentationContractTests(unittest.TestCase):
    def setUp(self):
        self.contract = json.loads((ROOT / 'openapi/bundled/public.openapi.json').read_text())
        self.schemas = self.contract['components']['schemas']

    def test_new_draw_full_results_and_historical_optional_fields(self):
        response = self.schemas['DrawResponse']
        results = response['properties']['results']
        self.assertEqual(results['maxItems'], 1000)
        self.assertEqual(results['items'], {'$ref': '#/components/schemas/DrawResult'})
        self.assertNotIn('results', response['required'])
        self.assertNotIn('presentation', response['required'])
        self.assertEqual(response['properties']['high_rank_results']['maxItems'], 20)
        self.assertEqual(response['properties']['presentation']['oneOf'], [
            {'$ref': '#/components/schemas/DrawPresentation'}, {'type': 'null'},
        ])

    def test_single_presentation_reuses_only_public_snapshot_types(self):
        presentation = self.schemas['DrawPresentation']
        self.assertEqual(presentation['type'], 'object')
        self.assertFalse(presentation['additionalProperties'])
        self.assertEqual(presentation['required'], ['rank', 'video_snapshot'])
        self.assertEqual(presentation['properties'], {
            'rank': {'$ref': '#/components/schemas/RankReference'},
            'video_snapshot': {'$ref': '#/components/schemas/PresentationAsset'},
        })
        for path, method in [('/gachas/{gacha_id}/draws', 'post'), ('/draw-requests/{draw_request_id}', 'get')]:
            operation = self.contract['paths'][path][method]
            self.assertEqual(operation['responses']['200']['content']['application/json']['schema'], {
                '$ref': '#/components/schemas/DrawResponse',
            })
