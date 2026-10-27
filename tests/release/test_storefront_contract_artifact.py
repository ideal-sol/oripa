import copy
import hashlib
import importlib.util
import io
import json
from pathlib import Path
import shutil
import tarfile
import tempfile
import unittest
from unittest import mock


ROOT = Path(__file__).resolve().parents[2]
SCRIPT = ROOT / "scripts" / "release" / "storefront_contract_artifact.py"
SPEC = importlib.util.spec_from_file_location("storefront_contract_artifact", SCRIPT)
artifact = importlib.util.module_from_spec(SPEC)
assert SPEC.loader
SPEC.loader.exec_module(artifact)


def package_archive(
    path: Path,
    name: str,
    version: str,
    runtime_version=None,
) -> None:
    content = json.dumps({"name": name, "version": version}).encode()
    with tarfile.open(path, mode="w:gz") as archive:
        info = tarfile.TarInfo("package/package.json")
        info.size = len(content)
        archive.addfile(info, io.BytesIO(content))
        if name == "@oripa/storefront-client":
            constants = (
                "export const STOREFRONT_CLIENT_VERSION = "
                f'"{runtime_version or version}";\n'
            ).encode()
            constants_info = tarfile.TarInfo("package/dist/constants.js")
            constants_info.size = len(constants)
            archive.addfile(constants_info, io.BytesIO(constants))


class StorefrontContractArtifactTest(unittest.TestCase):
    def governance(self):
        value = artifact.load_json(ROOT / artifact.GOVERNANCE_PATH)
        # Historical release tests operate on the immutable alpha.46 ledger.
        # The live pending candidate is validated separately below.
        value['candidate'] = None
        return value

    def test_pay1_candidate_preserves_all_immutable_releases(self):
        value = artifact.governance(ROOT)
        self.assertEqual(value['immutable_history'], self.governance()['immutable_history'])
        self.assertEqual(value['latest_immutable']['bundle_version'], '2.0.0-alpha.46')
        candidate = artifact.pending_candidate(ROOT)
        self.assertEqual(candidate['bundle_version'], '2.0.0-alpha.47')
        self.assertEqual(candidate['predecessor_bundle_version'], '2.0.0-alpha.46')
        self.assertEqual(candidate['public_api_operation_count'], 87)
        self.assertEqual(candidate['release_state'], 'pending')
        self.assertEqual(candidate['release_mode'], 'contract-additive')
        self.assertFalse(candidate['breaking_change'])
        self.assertNotIn('publication', candidate)
        self.assertEqual(artifact.validate_source(ROOT)['bundle_version'], candidate['bundle_version'])

    def test_m4_candidate_is_unpublished_additive_and_preserves_released_alpha45(self):
        # Historical pending46 coverage is isolated; live ROOT is released46.
        value = copy.deepcopy(self.governance())
        self.assertIsNone(value['candidate'])
        released = value['immutable_history'].pop()
        candidate = copy.deepcopy(artifact.release_source({
            **value, 'latest_immutable': released, 'candidate': None,
        }))
        value['latest_immutable'] = copy.deepcopy(value['immutable_history'][-1])
        candidate.update(release_state='pending', breaking_change=False,
                         predecessor_bundle_version='2.0.0-alpha.45')
        for name, details in candidate['packages'].items():
            details['disposition'] = 'reference' if name == '@oripa/site-schema' else 'publish'
            if details['disposition'] == 'publish':
                details.pop('sha256')
        value['candidate'] = candidate
        artifact.validate_governance(value)
        self.assertEqual(candidate['bundle_version'], '2.0.0-alpha.46')
        self.assertEqual(candidate['predecessor_bundle_version'], '2.0.0-alpha.45')
        self.assertEqual(candidate['release_state'], 'pending')
        self.assertEqual(candidate['release_mode'], 'contract-additive')
        self.assertFalse(candidate['breaking_change'])
        self.assertEqual(candidate['contract_versions']['public'], '2.0.0-alpha.42')
        self.assertNotIn('publication', candidate)
        self.assertEqual(value['latest_immutable']['bundle_version'], '2.0.0-alpha.45')
        self.assertEqual(value['immutable_history'], self.governance()['immutable_history'][:-1])
        with mock.patch.object(artifact, 'governance', return_value=value):
            self.assertEqual(artifact.pending_candidate(ROOT), candidate)

    def next_candidate_governance(self):
        value = copy.deepcopy(self.governance())
        value['candidate'] = None
        while value['immutable_history'][-1]['bundle_version'] != '2.0.0-alpha.36':
            value['immutable_history'].pop()
        value['latest_immutable'] = copy.deepcopy(value['immutable_history'][-1])
        released = value['immutable_history'].pop()
        candidate = copy.deepcopy(artifact.release_source(value))
        value['latest_immutable'] = copy.deepcopy(value['immutable_history'][-1])
        candidate.update(release_state='pending', breaking_change=released['breaking_change'],
                         predecessor_bundle_version=value['latest_immutable']['bundle_version'])
        for name, details in candidate['packages'].items():
            details['disposition'] = 'reference' if name == '@oripa/site-schema' else 'publish'
            if details['disposition'] == 'publish':
                details.pop('sha256')
        value['candidate'] = candidate
        return value

    def schema_candidate_governance(self):
        value = self.next_candidate_governance()
        candidate = value["candidate"]
        candidate["release_mode"] = "contract-additive"
        candidate["contract_versions"] = {
            "public": "2.0.0-alpha.32",
            "admin": "2.0.0-alpha.31",
            "webhook": "2.0.0-alpha.31",
        }
        candidate["public_openapi_sha256"] = "0" * 64
        candidate["public_api_operation_count"] = value["latest_immutable"][
            "public_openapi"
        ]["operation_count"]
        candidate["packages"]["@oripa/storefront-testkit"][
            "public_api_operation_count"
        ] = candidate["public_api_operation_count"]
        candidate["packages"]["@oripa/storefront-client"][
            "minimum_public_api_contract"
        ] = "2.0.0-alpha.32"
        return value

    def breaking_candidate_governance(self):
        value = self.next_candidate_governance()
        value["candidate"]["release_mode"] = "contract-breaking"
        value["candidate"]["breaking_change"] = True
        public_version = value['candidate']['contract_versions']['public']
        value['candidate']['contract_versions'] = dict.fromkeys(['public', 'admin', 'webhook'], public_version)
        return artifact.validate_governance(value)

    def settled_governance(self, value, manifest, output: Path):
        candidate = copy.deepcopy(value["candidate"])
        rows = {row["name"]: row for row in manifest["packages"]}
        packages = {}
        for name, details in candidate["packages"].items():
            record = copy.deepcopy(details)
            disposition = record.pop("disposition")
            if disposition == "publish":
                record["sha256"] = rows[name]["sha256"]
            packages[name] = record
        released = {
            "bundle_version": candidate["bundle_version"],
            "manifest_sha256": artifact.sha256_file(output / "artifact-manifest.json"),
            "source_commit": manifest["source_commit"],
            "handoff_status": "released",
            "release_mode": candidate["release_mode"],
            "platform_version": candidate["platform_version"],
            "application_versions": candidate["application_versions"],
            "contract_versions": candidate["contract_versions"],
            "public_openapi": {
                "version": candidate["contract_versions"]["public"],
                "sha256": candidate["public_openapi_sha256"],
                "operation_count": candidate["public_api_operation_count"],
            },
            "packages": packages,
            "publication": {
                "workflow_run_id": 1,
                "workflow_run_attempt": 1,
                "artifact_id": 1,
                "artifact_name": (
                    f"oripa-storefront-contract-{candidate['bundle_version']}"
                ),
                "github_digest": "sha256:" + "1" * 64,
                "sha256sums_sha256": "2" * 64,
            },
        }
        if "breaking_change" in candidate:
            released["breaking_change"] = candidate["breaking_change"]
        value["immutable_history"].append(released)
        value["latest_immutable"] = copy.deepcopy(released)
        value["candidate"] = None
        return artifact.validate_governance(value)

    def test_git_utc_timestamp_is_supported(self):
        parsed = artifact.parse_git_time("2026-08-24T13:08:57Z")
        self.assertEqual(parsed.isoformat(), "2026-08-24T13:08:57+00:00")

    def test_alpha_34_history_is_preserved_and_alpha_37_is_released(self):
        value = copy.deepcopy(artifact.validate_governance(self.governance()))
        self.assertIsNone(value['candidate'])
        self.assertEqual(value['latest_immutable']['bundle_version'], '2.0.0-alpha.46')
        value['immutable_history'].pop()
        value['latest_immutable'] = copy.deepcopy(value['immutable_history'][-1])
        self.assertEqual(value['latest_immutable']['bundle_version'], '2.0.0-alpha.45')
        self.assertEqual(value['immutable_history'][-1], value['latest_immutable'])
        value['immutable_history'].pop()
        value['latest_immutable'] = copy.deepcopy(value['immutable_history'][-1])
        self.assertEqual(value['latest_immutable']['bundle_version'], '2.0.0-alpha.44')
        self.assertEqual(value['immutable_history'][-1], value['latest_immutable'])
        value['immutable_history'].pop()
        value['latest_immutable'] = copy.deepcopy(value['immutable_history'][-1])
        self.assertIsNone(value['candidate'])
        released = value['immutable_history'][-1]
        self.assertEqual(released, value['latest_immutable'])
        self.assertEqual(released['bundle_version'], '2.0.0-alpha.43')
        self.assertEqual(released['handoff_status'], 'released')
        self.assertEqual(released['release_mode'], 'contract-additive')
        self.assertFalse(released['breaking_change'])
        self.assertEqual(released['contract_versions']['public'], '2.0.0-alpha.39')
        self.assertEqual(released['public_openapi']['operation_count'], 78)
        self.assertEqual(released['source_commit'], '0ce41ab473fd5a4fb44773041ae097ffb40b14ce')
        self.assertEqual(released['manifest_sha256'], '1951edf44ef275e3c9bf85ac0ce1417a27bc64e982a607d0a72e49186eb09e74')
        self.assertEqual(released['publication']['artifact_id'], 11349442812)
        self.assertEqual(released['packages']['@oripa/storefront-client']['version'], '2.0.0-alpha.43')
        self.assertEqual(released['packages']['@oripa/storefront-testkit']['version'], '2.0.0-alpha.43')
        value['immutable_history'].pop()
        value['latest_immutable'] = copy.deepcopy(value['immutable_history'][-1])
        self.assertIsNone(value['candidate'])
        released = value['immutable_history'][-1]
        self.assertEqual(released, value['latest_immutable'])
        self.assertEqual(released['bundle_version'], '2.0.0-alpha.42')
        self.assertEqual(released['public_openapi']['operation_count'], 78)
        self.assertEqual(released['source_commit'], '2918db8d960380084fa02009a9de15891e1a9d04')
        self.assertEqual(released['manifest_sha256'], 'dc3ebabd16a52f21b226d55cafeba6ed548f5d15b9f0e9e67557cc341b76b346')
        self.assertEqual(released['publication']['artifact_id'], 11210051800)
        # Exercise the existing predecessor assertions against their historical ledger state.
        value['immutable_history'].pop()
        value['latest_immutable'] = copy.deepcopy(value['immutable_history'][-1])
        latest = next(release for release in value['immutable_history'] if release['bundle_version'] == '2.0.0-alpha.34')
        alpha_32 = next(release for release in value['immutable_history'] if release['bundle_version'] == '2.0.0-alpha.32')
        canonical = lambda item: hashlib.sha256(
            json.dumps(item, sort_keys=True, separators=(",", ":")).encode()
        ).hexdigest()

        self.assertEqual(latest["bundle_version"], "2.0.0-alpha.34")
        self.assertEqual(value["immutable_history"][-1], value["latest_immutable"])
        self.assertEqual(latest["handoff_status"], "released")
        self.assertIsNone(value['candidate'])
        self.assertEqual(value['immutable_history'][-1]['bundle_version'], '2.0.0-alpha.41')
        self.assertEqual(value['immutable_history'][-1]['source_commit'], 'e2b30805704ed5b9c3fd54492fb86f8b8549bde5')
        self.assertEqual(value['immutable_history'][-1]['manifest_sha256'], 'b3c7d2a28c0c8332eeea90ac43876245baf4a1e4ce6b7d4f646124212d99f7ee')
        value = copy.deepcopy(value)
        value['latest_immutable'] = value['immutable_history'][-2]
        self.assertEqual(value['latest_immutable']['bundle_version'], '2.0.0-alpha.40')
        self.assertEqual(value['latest_immutable']['release_mode'], 'contract-additive')
        self.assertFalse(value['latest_immutable']['breaking_change'])
        self.assertEqual(value['latest_immutable']['source_commit'], 'dadf79f3b0b2409a57e41b10a83c7b6570ea3507')
        self.assertEqual(value['latest_immutable']['manifest_sha256'], '5fba7399e21cff0226e9ae43a9a3fa73dc7c61a0716ce00787b0f4b931778a59')
        alpha_37 = next(item for item in value['immutable_history'] if item['bundle_version'] == '2.0.0-alpha.37')
        self.assertEqual(alpha_37["bundle_version"], "2.0.0-alpha.37")
        self.assertEqual(alpha_37["release_mode"], "contract-breaking")
        self.assertTrue(alpha_37["breaking_change"])
        self.assertEqual(alpha_37['source_commit'], 'b63c37edac755f782f09ca5b18255fb71cee300b')
        self.assertEqual(alpha_37['contract_versions'], dict.fromkeys(['public', 'admin', 'webhook'], '2.0.0-alpha.33'))
        self.assertEqual(alpha_37['public_openapi']['operation_count'], 76)
        self.assertEqual(latest["source_commit"], "576c35137946e5effcda63d6bf750d5ecc41150f")
        self.assertEqual(latest["manifest_sha256"], "42f4bee68b787dac16d07accee1c6154c7cea392c521c41b14461d6b56221464")
        self.assertEqual(latest["release_mode"], "contract-breaking")
        self.assertTrue(latest["breaking_change"])
        self.assertEqual(latest["public_openapi"]["sha256"], "27d0cdcee9194989058573d7e198066fa4af62017a0f301117ea4af034e733f0")
        self.assertEqual(latest["public_openapi"]["operation_count"], 75)
        self.assertEqual(latest["packages"]["@oripa/storefront-client"]["sha256"], "3363ebf849e3c7165b89ea9f037c681ab889d16539ce290383cad41d31c134c6")
        self.assertEqual(latest["packages"]["@oripa/storefront-testkit"]["sha256"], "07916ff69e2e6882aa0e62ee676a65652382413f14f65459ba4e773a41f8a440")
        self.assertEqual(canonical(value["immutable_history"][:value['immutable_history'].index(alpha_32)]), "5e286877a462d29e643b2fc4e2a0040221e42be9687e31f378e857b28a51026c")
        self.assertEqual(canonical(alpha_32), "fdeee7026dccefe4d212516e2c692eefcf99940545623aa3227bda202768a0ae")
        self.assertEqual(alpha_32["handoff_status"], "retired")
        self.assertEqual(alpha_32["manifest_sha256"], "263955a5521a863635bf6ad23d604e52b1319e84052178288bad7b7c308de564")
        self.assertEqual(
            latest["packages"]["@oripa/site-schema"]["version"],
            "2.0.0-alpha.23",
        )
        self.assertEqual(
            latest["publication"],
            {
                "workflow_run_id": 33395772059,
                "workflow_run_attempt": 1,
                "artifact_id": 9759273312,
                "artifact_name": "oripa-storefront-contract-2.0.0-alpha.34",
                "github_digest": "sha256:c6927b367f9d1ad1a5602792873da481405dc8c3d9c1ba12bbca1954c4e4c8fb",
                "sha256sums_sha256": "555ae3637e71a57bff447aa084d21e649b598c878f64766b9f044d1e59f75355",
            },
        )

    def test_historical_alpha_36_target_preserves_additive_metadata(self):
        value = self.next_candidate_governance()
        target = artifact.verification_target(value)

        self.assertFalse(target["breaking_change"])

    def test_released_alpha_43_has_exact_publication_evidence_and_no_republish_candidate(self):
        value = artifact.validate_governance(self.governance())
        self.assertIsNone(value['candidate'])
        self.assertEqual(value['latest_immutable']['bundle_version'], '2.0.0-alpha.46')
        value['immutable_history'].pop()
        value['latest_immutable'] = copy.deepcopy(value['immutable_history'][-1])
        self.assertEqual(value['latest_immutable']['bundle_version'], '2.0.0-alpha.45')
        value['immutable_history'].pop()
        value['latest_immutable'] = copy.deepcopy(value['immutable_history'][-1])
        released = value['latest_immutable']
        self.assertEqual(released, value['immutable_history'][-1])
        self.assertEqual(released['bundle_version'], '2.0.0-alpha.44')
        self.assertEqual(released['source_commit'], '8f4c79d2964186f704f14f4ddc2af228bf1047c9')
        self.assertEqual(released['manifest_sha256'], '1b12edfd6ddf54fca221a45cd3be4181861f5b49b3b8eba2f5c5aba45b42c692')
        self.assertEqual(released['handoff_status'], 'released')
        self.assertEqual(released['release_mode'], 'contract-breaking')
        self.assertTrue(released['breaking_change'])
        self.assertEqual(released['contract_versions'], dict.fromkeys(['public', 'admin', 'webhook'], '2.0.0-alpha.40'))
        self.assertEqual(released['public_openapi']['operation_count'], 78)
        self.assertEqual(released['publication'], {
            'workflow_run_id': 38247721027,
            'workflow_run_attempt': 1,
            'artifact_id': 11745799489,
            'artifact_name': 'oripa-storefront-contract-2.0.0-alpha.44',
            'github_digest': 'sha256:63388a1fcf0b6d7105834d074d947123fe865036de55541193c7bb8dd08715ac',
            'sha256sums_sha256': '587c17804f4c3a45acfcbf58cf280a68a390760a716d5ab0704449b44de8ad77',
        })
        self.assertEqual(released['public_openapi']['sha256'], '0041ff6a99f4bfb9c162a434d14c8924aa6763e8c81e7a3933e079e189366d1c')
        self.assertEqual(released['packages']['@oripa/storefront-client']['version'], '2.0.0-alpha.44')
        self.assertEqual(released['packages']['@oripa/storefront-client']['sha256'], '49d8026b1bc21f641b3c36f53f88cb0b620d7e45dd80ad9e7d95e5124001ce80')
        self.assertEqual(released['packages']['@oripa/storefront-testkit']['version'], '2.0.0-alpha.44')
        self.assertEqual(released['packages']['@oripa/storefront-testkit']['sha256'], '0b7865beacc4a37202549b0b30d2068f4db1ea84c28bd9628e8be5094d15d99e')
        target = artifact.verification_target(value)
        self.assertEqual(target['source_commit'], released['source_commit'])
        self.assertTrue(target['breaking_change'])
        with mock.patch.object(artifact, 'governance', return_value=value):
            with self.assertRaisesRegex(artifact.ArtifactError, 'no pending Storefront artifact candidate'):
                artifact.pending_candidate(ROOT)
        released = value['immutable_history'][-2]
        self.assertEqual(released['bundle_version'], '2.0.0-alpha.43')
        self.assertEqual(value['immutable_history'][-3]['bundle_version'], '2.0.0-alpha.42')
        self.assertEqual(released['publication'], {
            'workflow_run_id': 37319224331,
            'workflow_run_attempt': 1,
            'artifact_id': 11349442812,
            'artifact_name': 'oripa-storefront-contract-2.0.0-alpha.43',
            'github_digest': 'sha256:53fb939978eabbf9b636369b15c81369d18305890891715f7bbd864e9b197d14',
            'sha256sums_sha256': 'c7027c25f1a9c8070061ff75767e9483eef7732c9ab2240c7d787b89b6487fac',
        })
        self.assertEqual(released['public_openapi']['sha256'], '37cdeb7a214d42f0f69458d578a81c5c7869132e2a6cbd02ca8d150f1abf6faa')
        self.assertEqual(released['packages']['@oripa/storefront-client']['sha256'], '9f14026a53d24413d860975d5c012988a5381771600b81e8309e5119a10792b0')
        self.assertEqual(released['packages']['@oripa/storefront-testkit']['sha256'], 'd5bb5b0d785437e0f400b369ff97663a6c69d4a329c1b82710017087a92af9fb')
        value['immutable_history'].pop()
        value['latest_immutable'] = released
        target = artifact.verification_target(value)
        self.assertEqual(target['source_commit'], '0ce41ab473fd5a4fb44773041ae097ffb40b14ce')
        self.assertFalse(target['breaking_change'])

    def test_released_alpha_46_has_exact_publication_authority(self):
        value = artifact.validate_governance(self.governance())
        self.assertIsNone(value['candidate'])
        self.assertEqual(len(value['immutable_history']), 26)
        released = value['latest_immutable']
        self.assertEqual(released, value['immutable_history'][-1])
        self.assertEqual(released['bundle_version'], '2.0.0-alpha.46')
        self.assertEqual(released['source_commit'], '1d2b0d8ff8193d55173f5bf6978a41f3dfebf7ac')
        self.assertEqual(released['manifest_sha256'], 'a55f578a94af700e68f0cd0d8eed0e18fb3867ec3be5e0ac0c8639a173cf2b26')
        self.assertEqual(released['handoff_status'], 'released')
        self.assertEqual(released['release_mode'], 'contract-additive')
        self.assertFalse(released['breaking_change'])
        self.assertEqual(released['platform_version'], '2.0.0-alpha.23')
        self.assertEqual(released['application_versions'], {
            'workspace': '2.0.0-alpha.23', 'admin': '2.0.0-alpha.23',
        })
        self.assertEqual(released['contract_versions'], {
            'public': '2.0.0-alpha.42', 'admin': '2.0.0-alpha.42',
            'webhook': '2.0.0-alpha.40',
        })
        self.assertEqual(released['public_openapi'], {
            'version': '2.0.0-alpha.42',
            'sha256': '81ee96478ee3d80539817b54321f9f6d181c2a78a3dae2062527dd81049dcec4',
            'operation_count': 84,
        })
        self.assertEqual(released['publication'], {
            'workflow_run_id': 40162277779,
            'workflow_run_attempt': 1,
            'artifact_id': 12586773046,
            'artifact_name': 'oripa-storefront-contract-2.0.0-alpha.46',
            'github_digest': 'sha256:fb4bc9f64b0810e3efae8aaa85252b8fa86ec8c5fd03fb361459c5a627bca275',
            'sha256sums_sha256': '0377250f61bb15420fb58e0ab4b572289ded5a69407e48d43195fb4128c23372',
        })
        self.assertEqual(released['packages']['@oripa/storefront-client'], {
            'version': '2.0.0-alpha.46',
            'sha256': '08bbfe84dd8e57fb69ba4578cbd1d63e711a0a579996821ecbec6bd5a6133a3f',
            'minimum_public_api_contract': '2.0.0-alpha.42',
            'required_capabilities': [
                'draw.browser-mutation.v2', 'gacha.catalog-display.v2',
                'gacha.presentation.v2', 'gacha.rank-master.v2',
                'identity.sms-phone-ownership.v2', 'payment.fincode.v2',
                'prize.fulfillment-browser-mutation.v2', 'user-draw-history.read.v2',
                'user-point.read.v2', 'user-prize.presentation.v2',
            ],
        })
        self.assertEqual(released['packages']['@oripa/storefront-testkit'], {
            'version': '2.0.0-alpha.46',
            'sha256': 'f4e324b859d1d96d66e78c9c1adf16616f132e4b114b10a2af4e9e5fe5a5de83',
            'storefront_client_version': '2.0.0-alpha.46',
            'site_schema_version': '2.0.0-alpha.23',
            'public_api_operation_count': 84,
        })
        self.assertEqual(released['packages']['@oripa/site-schema'], {
            'version': '2.0.0-alpha.23',
            'sha256': 'b4ca0ddb0ec8a6f4bda6dfec40fb5f3f5098a837160310be64de97cab36740c2',
            'source_bundle_version': '2.0.0-alpha.23',
            'source_tree': '11f6bee77dd463c2f90352537f817404cf3042bd',
        })
        history_digest = hashlib.sha256(json.dumps(
            value['immutable_history'][:-1], sort_keys=True, separators=(',', ':'),
        ).encode()).hexdigest()
        self.assertEqual(history_digest, 'd77ebee99ed87d04063c38562a6ce9e90be653ab0e871cf743d2cd0c2e0577cb')
        self.assertEqual(sum(r['bundle_version'] == '2.0.0-alpha.46'
                             for r in value['immutable_history']), 1)
        target = artifact.verification_target(value)
        self.assertEqual(target['release_state'], 'released')
        self.assertEqual(target['source_commit'], released['source_commit'])
        self.assertEqual(target['manifest_sha256'], released['manifest_sha256'])
        self.assertEqual(target['predecessor_bundle_version'], '2.0.0-alpha.45')
        self.assertEqual(target['public_api_operation_count'], 84)
        self.assertFalse(target['breaking_change'])
        self.assertEqual(artifact.validate_source(ROOT)['release_state'], 'pending')

    def test_released_alpha_46_rejects_build_reissue_and_duplicate_history(self):
        with mock.patch.object(artifact, 'governance', return_value=self.governance()), self.assertRaisesRegex(artifact.ArtifactError, 'no pending Storefront artifact candidate'):
            artifact.pending_candidate(ROOT)
        real_run = artifact.run

        def no_package_build(arguments, **kwargs):
            self.assertNotEqual(arguments[0], 'pnpm', 'released artifact build must stop before package commands')
            return real_run(arguments, **kwargs)

        with tempfile.TemporaryDirectory() as temporary:
            output = Path(temporary) / 'must-not-be-created'
            head = real_run(['git', 'rev-parse', 'HEAD'], cwd=ROOT, capture=True)
            # Supply historical validated Source metadata: the current checkout is pending47.
            with mock.patch.object(artifact, 'governance', return_value=self.governance()), mock.patch.object(artifact, 'validate_source', return_value=artifact.release_source(self.governance())), mock.patch.object(artifact, 'run', side_effect=no_package_build):
                with self.assertRaisesRegex(artifact.ArtifactError, 'no pending Storefront artifact candidate'):
                    artifact.build(ROOT, output, head)
            self.assertFalse(output.exists())

        value = copy.deepcopy(self.governance())
        value['immutable_history'].append(copy.deepcopy(value['latest_immutable']))
        with self.assertRaisesRegex(artifact.ArtifactError, 'immutable history version inventory invalid'):
            artifact.validate_governance(value)
        value = copy.deepcopy(self.governance())
        value['candidate'] = artifact.verification_target(value)
        value['candidate'].update(release_state='pending', predecessor_bundle_version='2.0.0-alpha.46')
        with self.assertRaisesRegex(artifact.ArtifactError, 'immutable existing version reissue prohibited'):
            artifact.validate_governance(value)

    def test_released_alpha_46_rejects_corrupt_publication_and_history(self):
        for artifact_id in (0, '12586773046'):
            value = copy.deepcopy(self.governance())
            for row in (value['latest_immutable'], value['immutable_history'][-1]):
                row['publication']['artifact_id'] = artifact_id
            with self.subTest(artifact_id=artifact_id), self.assertRaisesRegex(
                artifact.ArtifactError, 'immutable publication evidence invalid'
            ):
                artifact.validate_governance(value)
        value = copy.deepcopy(self.governance())
        value['immutable_history'][0]['manifest_sha256'] = 'invalid'
        with self.assertRaisesRegex(artifact.ArtifactError, 'immutable history evidence invalid'):
            artifact.validate_governance(value)

    def test_released_alpha_45_has_exact_publication_evidence(self):
        value = artifact.validate_governance(self.governance())
        self.assertEqual(value['latest_immutable']['bundle_version'], '2.0.0-alpha.46')
        value['immutable_history'].pop()
        value['latest_immutable'] = copy.deepcopy(value['immutable_history'][-1])
        self.assertEqual(len(value['immutable_history']), 25)
        self.assertIsNone(value['candidate'])
        released = value['latest_immutable']
        self.assertEqual(released, value['immutable_history'][-1])
        self.assertEqual(released['bundle_version'], '2.0.0-alpha.45')
        self.assertEqual(value['immutable_history'][-2]['bundle_version'], '2.0.0-alpha.44')
        self.assertEqual(released['handoff_status'], 'released')
        self.assertEqual(released['release_mode'], 'contract-additive')
        self.assertFalse(released['breaking_change'])
        self.assertEqual(released['source_commit'], 'c4efbee32a7846bc94f7375d0d5f71779aa0ac77')
        self.assertEqual(released['manifest_sha256'], '0fd03ceb42cf316fc01de0c9757ee20be3bc1242b25cd52bc20870c50c2ce3a5')
        self.assertEqual(released['contract_versions'], {
            'public': '2.0.0-alpha.41',
            'admin': '2.0.0-alpha.41',
            'webhook': '2.0.0-alpha.40',
        })
        self.assertEqual(released['public_openapi'], {
            'version': '2.0.0-alpha.41',
            'sha256': '9fc089b11f70bc0f3e70d44e41c1ec4b32c7479b6ec24410d0481d45f5b6401f',
            'operation_count': 81,
        })
        self.assertEqual(released['publication'], {
            'workflow_run_id': 38866011951,
            'workflow_run_attempt': 1,
            'artifact_id': 12031381227,
            'artifact_name': 'oripa-storefront-contract-2.0.0-alpha.45',
            'github_digest': 'sha256:19472639f0fa5afe1aa14663d93587f19f28df8f79e0fa784621d2dffc4054d2',
            'sha256sums_sha256': 'e7a0175b9bdddce02ee2fdc052e6474cdfaa042ba461926762194f13af8905f7',
        })
        client = released['packages']['@oripa/storefront-client']
        testkit = released['packages']['@oripa/storefront-testkit']
        self.assertEqual(client['version'], '2.0.0-alpha.45')
        self.assertEqual(client['sha256'], '4a78f26395807fd0eff231249c9f2547d416de4a439309605fee9cd903433c4e')
        self.assertEqual(testkit['version'], '2.0.0-alpha.45')
        self.assertEqual(testkit['sha256'], '8e6577375c864a0681aa475ee39c6c7a0958bc09c754a92cd1252f543b9b2a7a')
        self.assertEqual(released['packages']['@oripa/site-schema'], {
            'version': '2.0.0-alpha.23',
            'sha256': 'b4ca0ddb0ec8a6f4bda6dfec40fb5f3f5098a837160310be64de97cab36740c2',
            'source_bundle_version': '2.0.0-alpha.23',
            'source_tree': '11f6bee77dd463c2f90352537f817404cf3042bd',
        })
        history_digest = hashlib.sha256(json.dumps(
            value['immutable_history'][:-1], sort_keys=True, separators=(',', ':'),
        ).encode()).hexdigest()
        self.assertEqual(history_digest, 'f3176af0d90e7f4eede32f8f79c208206b8ba5f7b9801a1b726c6510adc2af3c')
        target = artifact.verification_target(value)
        self.assertEqual(target['source_commit'], released['source_commit'])
        self.assertEqual(target['predecessor_bundle_version'], '2.0.0-alpha.44')
        self.assertEqual(target['manifest_sha256'], released['manifest_sha256'])
        self.assertFalse(target['breaking_change'])
        source = artifact.validate_source(ROOT)
        self.assertEqual(source['bundle_version'], '2.0.0-alpha.47')
        self.assertEqual(source['release_state'], 'pending')

    def test_released_alpha_45_cannot_be_republished_or_duplicated(self):
        with mock.patch.object(artifact, 'governance', return_value=self.governance()):
            with self.assertRaisesRegex(artifact.ArtifactError, 'no pending Storefront artifact candidate'):
                artifact.pending_candidate(ROOT)
        value = copy.deepcopy(self.governance())
        value['immutable_history'].append(copy.deepcopy(value['latest_immutable']))
        with self.assertRaisesRegex(artifact.ArtifactError, 'immutable history version inventory invalid'):
            artifact.validate_governance(value)
        value = copy.deepcopy(self.governance())
        value['immutable_history'].pop()
        value['latest_immutable'] = copy.deepcopy(value['immutable_history'][-1])
        value['candidate'] = artifact.verification_target(value)
        value['candidate']['release_state'] = 'pending'
        value['candidate']['predecessor_bundle_version'] = '2.0.0-alpha.45'
        with self.assertRaisesRegex(artifact.ArtifactError, 'immutable existing version reissue prohibited'):
            artifact.validate_governance(value)

    def test_released_alpha_37_has_no_republish_candidate(self):
        value = self.governance()
        value["candidate"] = None
        while value['immutable_history'][-1]['bundle_version'] != '2.0.0-alpha.37':
            value['immutable_history'].pop()
        value['latest_immutable'] = copy.deepcopy(value['immutable_history'][-1])
        self.assertTrue(artifact.verification_target(value)['breaking_change'])
        released = next(item for item in value['immutable_history'] if item['bundle_version'] == '2.0.0-alpha.37')
        self.assertEqual(released['manifest_sha256'], '064b178c9781ef706855baaedeaa8aa7836214a36c9f8ecd78b1044551d5c293')
        self.assertEqual(released['publication']['artifact_id'], 10606100530)
        self.assertEqual(released['publication']['github_digest'], 'sha256:a196e21b61cef71329312cc057b4376fd6919c2a877492f6852f09006a0ac611')
        with mock.patch.object(artifact, 'governance', return_value=value):
            with self.assertRaisesRegex(artifact.ArtifactError, 'no pending Storefront artifact candidate'):
                artifact.pending_candidate(ROOT)

    def test_settled_breaking_release_missing_metadata_fails_closed(self):
        value = copy.deepcopy(self.governance())
        while value['immutable_history'][-1]['bundle_version'] != '2.0.0-alpha.34':
            value['immutable_history'].pop()
        value['latest_immutable'] = copy.deepcopy(value['immutable_history'][-1])
        value["latest_immutable"].pop("breaking_change")
        value["immutable_history"][-1].pop("breaking_change")

        with self.assertRaisesRegex(
            artifact.ArtifactError, "immutable release evidence invalid"
        ):
            artifact.validate_governance(value)

    def test_alpha_34_publication_digest_format_tamper_is_rejected(self):
        value = copy.deepcopy(self.governance())
        for release in (value["latest_immutable"], value["immutable_history"][-1]):
            release["publication"]["github_digest"] = "sha256:" + "0" * 63
        with self.assertRaisesRegex(
            artifact.ArtifactError, "immutable publication evidence invalid"
        ):
            artifact.validate_governance(value)

    def test_alpha_34_publication_evidence_cannot_be_removed(self):
        value = copy.deepcopy(self.governance())
        for release in (value["latest_immutable"], value["immutable_history"][-1]):
            release.pop("publication")
        with self.assertRaisesRegex(
            artifact.ArtifactError, "immutable publication evidence missing"
        ):
            artifact.validate_governance(value)

    def test_retired_latest_requires_a_pending_successor(self):
        value = copy.deepcopy(self.governance())
        value["latest_immutable"]["handoff_status"] = "retired"
        value["immutable_history"][-1]["handoff_status"] = "retired"
        value["candidate"] = None
        with self.assertRaisesRegex(
            artifact.ArtifactError, "latest immutable release is not adoptable"
        ):
            artifact.validate_governance(value)

    def test_reconciled_alpha_34_duplicate_is_rejected(self):
        value = copy.deepcopy(self.governance())
        value["immutable_history"].append(copy.deepcopy(value["latest_immutable"]))
        with self.assertRaisesRegex(
            artifact.ArtifactError, "immutable history version inventory invalid"
        ):
            artifact.validate_governance(value)

    def test_existing_alpha_28_bundle_reissue_is_rejected(self):
        value = self.next_candidate_governance()
        value["candidate"]["bundle_version"] = "2.0.0-alpha.28"
        with self.assertRaisesRegex(
            artifact.ArtifactError, "immutable existing version reissue prohibited"
        ):
            artifact.validate_governance(value)

    def test_latest_alpha_34_evidence_must_match_immutable_history(self):
        value = copy.deepcopy(self.governance())
        value["latest_immutable"]["source_commit"] = "0" * 40
        with self.assertRaisesRegex(artifact.ArtifactError, "latest immutable release mismatch"):
            artifact.validate_governance(value)

    def test_arbitrary_published_package_mismatch_is_rejected(self):
        value = self.next_candidate_governance()
        value["candidate"]["packages"]["@oripa/storefront-client"]["version"] = (
            "2.0.0-alpha.99"
        )
        with self.assertRaisesRegex(
            artifact.ArtifactError, "published package version must equal bundle version"
        ):
            artifact.validate_governance(value)

    def test_referenced_schema_digest_mismatch_is_rejected(self):
        value = self.next_candidate_governance()
        value["candidate"]["packages"]["@oripa/site-schema"]["sha256"] = "0" * 64
        with self.assertRaisesRegex(
            artifact.ArtifactError, "immutable package reference mismatch"
        ):
            artifact.validate_governance(value)

    def test_schema_only_additive_contract_keeps_operation_count(self):
        value = artifact.validate_governance(self.schema_candidate_governance())
        self.assertEqual(
            value["candidate"]["public_api_operation_count"],
            value["latest_immutable"]["public_openapi"]["operation_count"],
        )

    def test_contract_candidate_cannot_reduce_operation_count(self):
        value = self.schema_candidate_governance()
        value["candidate"]["public_api_operation_count"] -= 1
        value["candidate"]["packages"]["@oripa/storefront-testkit"][
            "public_api_operation_count"
        ] -= 1
        with self.assertRaisesRegex(
            artifact.ArtifactError, "additive contract candidate mismatch"
        ):
            artifact.validate_governance(value)

    def test_canonical_breaking_contract_candidate_is_explicit(self):
        value = self.schema_candidate_governance()
        value['candidate']['contract_versions'] = dict.fromkeys(['public', 'admin', 'webhook'], '2.0.0-alpha.32')
        value["candidate"]["release_mode"] = "contract-breaking"
        value["candidate"]["breaking_change"] = True
        value["candidate"]["public_api_operation_count"] = 1
        value["candidate"]["packages"]["@oripa/storefront-testkit"][
            "public_api_operation_count"
        ] = 1

        result = artifact.validate_governance(value)

        self.assertTrue(result["candidate"]["breaking_change"])

    def test_contract_breaking_candidate_requires_explicit_truthful_marker(self):
        value = self.schema_candidate_governance()
        value["candidate"]["release_mode"] = "contract-breaking"

        with self.assertRaisesRegex(
            artifact.ArtifactError, "contract-breaking candidate authority mismatch"
        ):
            artifact.validate_governance(value)

    def test_candidate_preserves_independent_platform_contract_and_schema(self):
        result = artifact.validate_governance(self.next_candidate_governance())["candidate"]
        self.assertEqual(result["bundle_version"], "2.0.0-alpha.36")
        self.assertEqual(result["platform_version"], "2.0.0-alpha.23")
        self.assertEqual(result["contract_versions"]["public"], "2.0.0-alpha.32")
        self.assertEqual(result["packages"]["@oripa/site-schema"]["version"], "2.0.0-alpha.23")
        self.assertEqual(result["packages"]["@oripa/storefront-client"]["version"], "2.0.0-alpha.36")
        self.assertEqual(result["packages"]["@oripa/storefront-testkit"]["version"], "2.0.0-alpha.36")

    def test_source_client_runtime_version_matches_package_metadata(self):
        result = artifact.validate_source(ROOT)
        self.assertEqual(
            result["packages"]["@oripa/storefront-client"],
            "2.0.0-alpha.47",
        )
        with mock.patch.object(
            artifact,
            "read_source_client_runtime_version",
            return_value="2.0.0-alpha.32",
        ):
            with self.assertRaisesRegex(
                artifact.ArtifactError, "client source runtime version mismatch"
            ):
                artifact.validate_source(ROOT)

    def valid_output(self, output: Path, governance=None, source_commit=None) -> dict:
        governance = governance or self.next_candidate_governance()
        candidate = governance["candidate"]
        assets = {}
        for name in ("@oripa/storefront-client", "@oripa/storefront-testkit"):
            filename = name.removeprefix("@oripa/").replace("/", "-")
            path = output / f"oripa-{filename}-{candidate['packages'][name]['version']}.tgz"
            package_archive(path, name, candidate["packages"][name]["version"])
            assets[name] = {
                "file": path.name,
                "sha256": artifact.sha256_file(path),
                "browser_compatible": True,
            }
        public_path = output / artifact.PUBLIC_OPENAPI_PATH.name
        public = artifact.load_json(ROOT / artifact.PUBLIC_OPENAPI_PATH)
        public['info']['version'] = candidate['contract_versions']['public']
        artifact.write_json(public_path, public)
        candidate['public_openapi_sha256'] = artifact.sha256_file(public_path)
        with mock.patch.object(artifact, "governance", return_value=governance), mock.patch.object(artifact, 'PUBLIC_OPENAPI_PATH', public_path):
            manifest = artifact.create_manifest(
                ROOT,
                source_commit or "1" * 40,
                "2026-08-20T00:00:00Z",
                assets,
            )
        artifact.write_json(output / "artifact-manifest.json", manifest)
        artifact.write_checksums(output)
        return governance

    def test_alpha_36_settled_ledger_rejects_republication(self):
        value = self.governance()
        value['candidate'] = None
        while value['immutable_history'][-1]['bundle_version'] != '2.0.0-alpha.36':
            value['immutable_history'].pop()
        value['latest_immutable'] = value['immutable_history'][-1]
        with mock.patch.object(artifact, 'governance', return_value=value):
            with self.assertRaisesRegex(artifact.ArtifactError, 'no pending Storefront artifact candidate'):
                artifact.pending_candidate(ROOT)
        released = value['latest_immutable']
        self.assertEqual(released['source_commit'], 'aa5049f7efa63e9cff67b10d93e768b4006b0c09')
        self.assertEqual(released['manifest_sha256'], '101ec49daf4e30bcd2fecd10e3314d96604bd86ea0f6029de4b5fc2bf0a39292')
        self.assertEqual(released['publication']['artifact_id'], 10041438541)
        self.assertEqual(released['publication']['github_digest'], 'sha256:5e6dba27ce3745bbbec27d662f2e271dafec63eded0c0f5d11788b69c7298e2e')

    def test_public_only_additive_contract_keeps_other_versions_without_regression(self):
        value = self.next_candidate_governance()
        self.assertEqual(value['candidate']['contract_versions'], {'public': '2.0.0-alpha.32', 'admin': '2.0.0-alpha.31', 'webhook': '2.0.0-alpha.31'})
        artifact.validate_governance(value)
        for surface in ['public', 'admin', 'webhook']:
            for version in ['2.0.0-alpha.30', '2.0.0-alpha.99']:
                invalid = copy.deepcopy(value)
                invalid['candidate']['contract_versions'][surface] = version
                with self.assertRaises(artifact.ArtifactError):
                    artifact.validate_governance(invalid)

    def test_nonbreaking_candidate_and_settled_manifest_verification(self):
        with tempfile.TemporaryDirectory() as temporary:
            output = Path(temporary)
            governance = self.valid_output(output)
            with mock.patch.object(artifact, "governance", return_value=governance):
                manifest = artifact.verify_manifest(ROOT, output)
            dispositions = {
                row["name"]: row["disposition"] for row in manifest["packages"]
            }
            self.assertEqual(
                dispositions,
                {
                    "@oripa/storefront-client": "published",
                    "@oripa/site-schema": "referenced",
                    "@oripa/storefront-testkit": "published",
                },
            )
            settled = self.settled_governance(governance, manifest, output)
            self.assertFalse(artifact.verification_target(settled)["breaking_change"])
            with mock.patch.object(artifact, "governance", return_value=settled):
                artifact.verify_manifest(ROOT, output)

    def test_breaking_candidate_and_settled_manifest_verification(self):
        with tempfile.TemporaryDirectory() as temporary:
            output = Path(temporary)
            governance = self.breaking_candidate_governance()
            source_commit = governance["latest_immutable"]["source_commit"]
            governance = self.valid_output(output, governance, source_commit)
            with mock.patch.object(artifact, "governance", return_value=governance):
                manifest = artifact.verify_manifest(ROOT, output)
            self.assertTrue(manifest["public_openapi"]["breaking_change"])
            settled = self.settled_governance(governance, manifest, output)
            self.assertTrue(artifact.verification_target(settled)["breaking_change"])
            with mock.patch.object(artifact, "governance", return_value=settled):
                artifact.verify_manifest(ROOT, output)

    def test_client_tarball_runtime_mismatch_is_rejected(self):
        with tempfile.TemporaryDirectory() as temporary:
            output = Path(temporary)
            governance = self.valid_output(output)
            client_path = next(output.glob("*storefront-client*.tgz"))
            package_archive(
                client_path,
                "@oripa/storefront-client",
                "2.0.0-alpha.36",
                runtime_version="2.0.0-alpha.34",
            )
            manifest = artifact.load_json(output / "artifact-manifest.json")
            client_row = next(
                row
                for row in manifest["packages"]
                if row["name"] == "@oripa/storefront-client"
            )
            client_row["sha256"] = artifact.sha256_file(client_path)
            artifact.write_json(output / "artifact-manifest.json", manifest)
            artifact.write_checksums(output)
            with mock.patch.object(artifact, "governance", return_value=governance):
                with self.assertRaisesRegex(
                    artifact.ArtifactError, "tarball runtime version mismatch"
                ):
                    artifact.verify_manifest(ROOT, output)

    def test_referenced_alpha_23_schema_must_not_be_reissued(self):
        with tempfile.TemporaryDirectory() as temporary:
            output = Path(temporary)
            governance = self.valid_output(output)
            package_archive(
                output / "oripa-site-schema-2.0.0-alpha.23.tgz",
                "@oripa/site-schema",
                "2.0.0-alpha.23",
            )
            with mock.patch.object(artifact, "governance", return_value=governance):
                with self.assertRaisesRegex(artifact.ArtifactError, "file inventory mismatch"):
                    artifact.verify_manifest(ROOT, output)

    def test_dedicated_workflow_invokes_validator_build_verify_and_readback(self):
        workflow = (
            ROOT / ".github/workflows/storefront-contract-artifact-publish.yml"
        ).read_text()
        self.assertIn("publication_mode:", workflow)
        self.assertIn("default: contract-only", workflow)
        self.assertEqual(workflow.count("actions/checkout@"), 3)
        self.assertIn('test "$(git rev-parse HEAD)"', workflow)
        self.assertIn("pnpm storefront:check", workflow)
        self.assertIn("pnpm site-schema:build", workflow)
        self.assertIn("pnpm testkit:check", workflow)
        self.assertLess(
            workflow.index("pnpm site-schema:build"),
            workflow.index("pnpm testkit:check"),
        )
        self.assertIn("storefront_contract_artifact.py validate-source", workflow)
        self.assertIn("storefront_contract_artifact.py build", workflow)
        self.assertIn("storefront_contract_artifact.py verify", workflow)
        self.assertIn("storefront_contract_publication.py readback", workflow)
        self.assertIn("overwrite: false", workflow)
        self.assertNotIn("docker build", workflow)
        self.assertNotIn("docker push", workflow)
        self.assertNotIn("apps/admin/Dockerfile", workflow)
        self.assertNotIn("package version mismatch", workflow)


if __name__ == "__main__":
    unittest.main()
