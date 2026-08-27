import importlib.util
import os
from pathlib import Path
import stat
import tempfile
import unittest


MODULE_PATH = (
    Path(__file__).resolve().parents[2] / "scripts" / "ops" / "preview_fincode_nginx.py"
)
SPEC = importlib.util.spec_from_file_location("preview_fincode_nginx", MODULE_PATH)
preview_fincode_nginx = importlib.util.module_from_spec(SPEC)
assert SPEC.loader is not None
SPEC.loader.exec_module(preview_fincode_nginx)


FIXTURE = """server {
    listen 443 ssl;
    listen [::]:443 ssl;
    server_name test.luxe-pack.biz;

    location ^~ /api/v2/ {
        proxy_pass http://192.0.2.10:8000;
        proxy_http_version 1.1;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }

    location ^~ /admin/api/ {
        return 404 '{"status":404}';
    }

    location / {
        proxy_pass http://127.0.0.1:3200;
    }
}

server {
    listen 80;
    server_name test.luxe-pack.biz;
    return 301 https://$host$request_uri;
}
"""


class PreviewFincodeNginxTest(unittest.TestCase):
    def test_render_adds_only_exact_post_webhook_route(self):
        rendered = preview_fincode_nginx.render_config(FIXTURE)
        self.assertIn("location = /webhooks/v2/fincode {", rendered)
        self.assertIn("limit_except POST {", rendered)
        self.assertIn("proxy_pass http://192.0.2.10:8000;", rendered)
        self.assertNotIn("location ^~ /webhooks/", rendered)
        self.assertEqual(rendered, preview_fincode_nginx.render_config(rendered))

    def test_render_rejects_broad_or_mismatched_webhook_routes(self):
        broad = FIXTURE.replace(
            "    location ^~ /admin/api/ {",
            "    location ^~ /webhooks/ {\n        proxy_pass http://192.0.2.10:8000;\n    }\n\n"
            "    location ^~ /admin/api/ {",
        )
        with self.assertRaisesRegex(
            preview_fincode_nginx.PreviewNginxError,
            "broad_webhook_location_rejected",
        ):
            preview_fincode_nginx.render_config(broad)

        mismatched = preview_fincode_nginx.render_config(FIXTURE).replace(
            "limit_except POST", "limit_except GET"
        )
        with self.assertRaisesRegex(
            preview_fincode_nginx.PreviewNginxError,
            "fincode_webhook_location_mismatch",
        ):
            preview_fincode_nginx.render_config(mismatched)

    def test_render_rejects_non_preview_server(self):
        with self.assertRaisesRegex(
            preview_fincode_nginx.PreviewNginxError,
            "preview_tls_server_not_unique",
        ):
            preview_fincode_nginx.render_config(
                FIXTURE.replace("test.luxe-pack.biz", "luxe-pack.biz")
            )

    def test_apply_is_atomic_backed_up_and_verifiable(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            config = root / "test.luxe-pack.biz.conf"
            backup = root / "before.conf"
            config.write_text(FIXTURE, encoding="utf-8")
            config.chmod(0o640)

            result = preview_fincode_nginx.apply_config(config, backup)

            self.assertEqual({"changed": True, "status": "updated"}, result)
            self.assertEqual(FIXTURE, backup.read_text(encoding="utf-8"))
            self.assertEqual(0o600, stat.S_IMODE(os.lstat(backup).st_mode))
            self.assertEqual(0o640, stat.S_IMODE(os.lstat(config).st_mode))
            self.assertEqual(
                {"changed": False, "status": "canonical"},
                preview_fincode_nginx.verify_config(config),
            )


if __name__ == "__main__":
    unittest.main()
