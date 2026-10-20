import json
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[3]


class PaymentAdjustmentContractTest(unittest.TestCase):
    def test_manual_operations_preserve_admin_boundary_and_replay_contract(self):
        admin = json.loads((ROOT / 'openapi/bundled/admin.openapi.json').read_text())
        public = json.loads((ROOT / 'openapi/bundled/public.openapi.json').read_text())
        paths = [path for path in admin['paths'] if path.startswith('/payments/{paymentId}')]
        self.assertEqual(7, len(paths))
        for path in paths:
            self.assertNotIn(path, public['paths'])
            for method, operation in admin['paths'][path].items():
                self.assertEqual([{'adminSession': []}], operation['security'])
                self.assertEqual('private-no-store', operation['x-cache-policy'])
                self.assertEqual('reporting.financial.read' if method == 'get' else 'payment.adjustment.manage', operation['x-required-permission'])
                if method == 'post' and not path.endswith('/preview'):
                    self.assertEqual('required', operation['x-idempotency'])
                    self.assertIn({'$ref': '#/components/parameters/IdempotencyKey'}, operation['parameters'])
        schemas = admin['components']['schemas']
        self.assertNotIn('const', schemas['AdminPayment']['properties']['provider'])
        self.assertEqual({'const': True}, schemas['AdminPaymentRefundCompleteInput']['properties']['provider_refund_confirmed'])
        for name in ['AdminPaymentAdjustmentInput', 'AdminPaymentRefundCompleteInput']:
            self.assertFalse(schemas[name]['additionalProperties'])
            self.assertIn('reason', schemas[name]['required'])
            self.assertGreater(schemas[name]['properties']['reason']['minLength'], 0)
        self.assertIn('canceled', schemas['AdminPaymentAdjustmentStatus']['enum'])
        self.assertIn('manual_review', schemas['AdminPaymentAdjustmentStatus']['enum'])
