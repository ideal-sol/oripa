import copy
import json
import runpy
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[3]
GATE = runpy.run_path(str(ROOT / 'scripts/ci/openapi_contract_gate.py'))


class PaidyAlpha51ReleaseTest(unittest.TestCase):
    def setUp(self):
        self.old = json.loads(subprocess.check_output(['git', 'show',
            '14f7819c72c5908aa6e5778303b87dc4b1202493:openapi/bundled/public.openapi.json'], cwd=ROOT))
        self.new = json.loads(subprocess.check_output(['git', 'show', '705d03caab389c0f9600b3e85420532aa6d46439:openapi/bundled/public.openapi.json'], cwd=ROOT))

    def test_exact_published_transition_and_only_two_required_additions(self):
        self.assertTrue(GATE['is_authorized_paidy_alpha51_release']('public', self.old, self.new))
        self.assertEqual(GATE['breaking_changes'](self.old, self.new), [
            'components.schemas.PaymentPaidyCheckoutAction.payload.buyer_data: required field added: account_registration_date',
            'components.schemas.PaymentPaidyCheckoutAction.payload.buyer_data: required field added: previous_payment_methods',
        ])

    def test_release_authority_rejects_unapproved_mutations(self):
        for mutation in ['version', 'optional', 'type', 'open', 'extra', 'unrelated', 'security']:
            with self.subTest(mutation=mutation):
                changed = copy.deepcopy(self.new)
                buyer = changed['components']['schemas']['PaymentPaidyCheckoutAction']['properties']['payload']['properties']['buyer_data']
                if mutation == 'version': changed['info']['version'] = '2.0.0-alpha.48'
                elif mutation == 'optional': buyer['required'].remove('account_registration_date')
                elif mutation == 'type': buyer['properties']['previous_payment_methods']['properties']['credit_card_used']['type'] = 'string'
                elif mutation == 'open': buyer['additionalProperties'] = True
                elif mutation == 'extra': buyer['properties']['extra'] = {'type': 'string'}
                elif mutation == 'unrelated': changed['paths'].pop('/me/account-closure')
                else: changed['paths']['/payments/{payment_id}/paidy-authorizations']['post']['security'] = []
                self.assertFalse(GATE['is_authorized_paidy_alpha51_release']('public', self.old, changed))
        self.assertFalse(GATE['is_authorized_paidy_alpha51_release']('admin', self.old, self.new))
        self.assertFalse(GATE['is_authorized_paidy_alpha51_release']('public', self.new, self.old))

    def test_admin_webhook_only_version_changes_from_approved_main(self):
        for surface in ['admin', 'webhook']:
            old = json.loads(subprocess.check_output(['git', 'show',
                f'8eadec09a187e3f220dc09d36e9a14b98511931b:openapi/bundled/{surface}.openapi.json'], cwd=ROOT))
            # Pin this historical release assertion to its published source, as
            # the Public transition above does. Later additive contracts are
            # validated against their own PR base by the contract gate.
            new = json.loads(subprocess.check_output(['git', 'show',
                f'705d03caab389c0f9600b3e85420532aa6d46439:openapi/bundled/{surface}.openapi.json'], cwd=ROOT))
            self.assertEqual(new['info']['version'], '2.0.0-alpha.47')
            new['info']['version'] = old['info']['version']
            self.assertEqual(old, new)
