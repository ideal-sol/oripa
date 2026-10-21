import json
import pathlib
import unittest


class MemberRankPaymentAdjustmentContractTest(unittest.TestCase):
    def test_adjustment_history_and_separate_review_contract(self):
        root = pathlib.Path(__file__).resolve().parents[3]
        contract = json.loads((root / "openapi/bundled/admin.openapi.json").read_text())
        schemas = contract["components"]["schemas"]
        history = schemas["AdminMemberRankHistoryItem"]
        self.assertIn("payment_adjustment", history["properties"]["reason"]["enum"])
        self.assertIn("adjustment_id", history["properties"])
        summary = schemas["AdminMemberRankSummaryResponse"]["properties"]["data"]["properties"]
        self.assertIn("achievement_id", summary["reviews"]["items"]["required"])
        self.assertIn("rank", summary["reviews"]["items"]["required"])
        self.assertEqual(summary["payment_adjustment_reviews"]["items"]["$ref"],
                         "#/components/schemas/AdminMemberRankPaymentAdjustmentReview")
        review = schemas["AdminMemberRankPaymentAdjustmentReview"]
        self.assertNotIn("achievement_id", review["properties"])
        self.assertNotIn("rank", review["properties"])
        self.assertFalse(review["additionalProperties"])
        impact = schemas["AdminPaymentPointImpact"]
        self.assertFalse(impact["additionalProperties"])
        self.assertIn("purchase_bonus", impact["properties"])
        self.assertIn("rank_adjustment", impact["properties"])
