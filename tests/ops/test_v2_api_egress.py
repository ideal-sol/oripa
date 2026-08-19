import importlib.util
from pathlib import Path
import unittest


MODULE_PATH = Path(__file__).resolve().parents[2] / "scripts" / "ops" / "v2_api_egress.py"
SPEC = importlib.util.spec_from_file_location("v2_api_egress", MODULE_PATH)
v2_api_egress = importlib.util.module_from_spec(SPEC)
assert SPEC.loader is not None
SPEC.loader.exec_module(v2_api_egress)


class V2ApiEgressTest(unittest.TestCase):
    def private_network(self):
        return {
            "Driver": "bridge",
            "Internal": True,
            "IPAM": {"Config": [{"Subnet": "192.168.61.0/24"}]},
        }

    def egress_network(self):
        return {
            "Driver": "bridge",
            "Internal": False,
            "IPAM": {"Config": [{"Subnet": "192.168.62.0/28"}]},
        }

    def test_non_overlapping_api_egress_boundary_passes(self):
        v2_api_egress.validate_network_boundary(
            self.private_network(), self.egress_network(), "192.168.62.0/28"
        )

    def test_internal_egress_network_fails(self):
        egress = self.egress_network()
        egress["Internal"] = True
        with self.assertRaisesRegex(v2_api_egress.EgressFailure, "egress network"):
            v2_api_egress.validate_network_boundary(
                self.private_network(), egress, "192.168.62.0/28"
            )

    def test_overlapping_egress_subnet_fails(self):
        egress = self.egress_network()
        egress["IPAM"]["Config"][0]["Subnet"] = "192.168.61.0/28"
        with self.assertRaisesRegex(v2_api_egress.EgressFailure, "overlaps"):
            v2_api_egress.validate_network_boundary(
                self.private_network(), egress, "192.168.61.0/28"
            )

    def test_only_api_may_join_egress(self):
        private_name = "preview_v2_private"
        egress_name = "preview_v2_api_egress"
        memberships = {
            "api": {private_name, egress_name},
            "admin": {private_name},
            "postgres": {private_name},
            "redis": {private_name},
        }
        v2_api_egress.validate_service_memberships(
            memberships, private_name, egress_name, attached=True
        )
        memberships["redis"].add(egress_name)
        with self.assertRaisesRegex(v2_api_egress.EgressFailure, "redis"):
            v2_api_egress.validate_service_memberships(
                memberships, private_name, egress_name, attached=True
            )

    def test_create_phase_must_remain_private_only(self):
        private_name = "preview_v2_private"
        egress_name = "preview_v2_api_egress"
        memberships = {service: {private_name} for service in v2_api_egress.SERVICES}
        v2_api_egress.validate_service_memberships(
            memberships, private_name, egress_name, attached=False
        )


if __name__ == "__main__":
    unittest.main()
