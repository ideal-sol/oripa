import importlib.util
import os
from pathlib import Path
import stat
import tempfile
import unittest
from unittest import mock


MODULE_PATH = (
    Path(__file__).resolve().parents[2] / "scripts" / "ops" / "preview_fincode_nginx.py"
)
SPEC = importlib.util.spec_from_file_location("preview_fincode_nginx", MODULE_PATH)
preview_fincode_nginx = importlib.util.module_from_spec(SPEC)
assert SPEC.loader is not None
SPEC.loader.exec_module(preview_fincode_nginx)


OLD_UPSTREAM = "http://192.168.61.10:8000"
CURRENT_CONTAINER_UPSTREAM = "http://192.168.61.2:8000"
STABLE_UPSTREAM = "http://127.0.0.1:8611"
WEBHOOK_BLOCK = f"""    location = /webhooks/v2/fincode {{
        limit_except POST {{
            deny all;
        }}
        proxy_pass {OLD_UPSTREAM};
        proxy_http_version 1.1;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }}

"""
FIXTURE = f"""server {{
    listen 443 ssl;
    listen [::]:443 ssl;
    server_name test.luxe-pack.biz;

    location = /api/v2 {{
        proxy_pass {OLD_UPSTREAM};
        proxy_http_version 1.1;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }}

    location ^~ /api/v2/ {{
        proxy_pass {OLD_UPSTREAM};
        proxy_http_version 1.1;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }}

{WEBHOOK_BLOCK}    location ^~ /admin/api/ {{
        return 404 '{{"status":404}}';
    }}

    location / {{
        proxy_pass http://127.0.0.1:3200;
    }}
}}

server {{
    listen 80;
    server_name test.luxe-pack.biz;
    return 301 https://$host$request_uri;
}}
"""


class PreviewFincodeNginxTest(unittest.TestCase):
    def test_render_canonicalizes_exact_prefix_and_webhook_routes(self):
        rendered = preview_fincode_nginx.render_config(FIXTURE)

        self.assertEqual(FIXTURE.replace(OLD_UPSTREAM, STABLE_UPSTREAM), rendered)
        self.assertEqual(3, rendered.count(f"proxy_pass {STABLE_UPSTREAM};"))
        self.assertNotIn(OLD_UPSTREAM, rendered)
        self.assertNotIn(CURRENT_CONTAINER_UPSTREAM, rendered)
        self.assertIn("location = /api/v2 {", rendered)
        self.assertIn("location ^~ /api/v2/ {", rendered)
        self.assertIn("location = /webhooks/v2/fincode {", rendered)
        self.assertIn("limit_except POST {", rendered)
        for header in (
            "proxy_set_header Host $host;",
            "proxy_set_header X-Real-IP $remote_addr;",
            "proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;",
            "proxy_set_header X-Forwarded-Proto $scheme;",
        ):
            self.assertEqual(3, rendered.count(header))
        self.assertEqual(rendered, preview_fincode_nginx.render_config(rendered))

    def test_render_adds_missing_exact_post_webhook_route(self):
        rendered = preview_fincode_nginx.render_config(
            FIXTURE.replace(WEBHOOK_BLOCK, "")
        )

        self.assertIn("location = /webhooks/v2/fincode {", rendered)
        self.assertIn(f"proxy_pass {STABLE_UPSTREAM};", rendered)
        self.assertNotIn("location ^~ /webhooks/", rendered)
        self.assertEqual(rendered, preview_fincode_nginx.render_config(rendered))

    def test_render_rejects_broad_or_mismatched_webhook_routes(self):
        broad = FIXTURE.replace(
            "    location ^~ /admin/api/ {",
            "    location ^~ /webhooks/ {\n"
            f"        proxy_pass {OLD_UPSTREAM};\n"
            "    }\n\n"
            "    location ^~ /admin/api/ {",
        )
        with self.assertRaisesRegex(
            preview_fincode_nginx.PreviewNginxError,
            "broad_webhook_location_rejected",
        ):
            preview_fincode_nginx.render_config(broad)

        with self.assertRaisesRegex(
            preview_fincode_nginx.PreviewNginxError,
            "fincode_webhook_location_mismatch",
        ):
            preview_fincode_nginx.render_config(
                FIXTURE.replace("limit_except POST", "limit_except GET")
            )

    def test_render_rejects_header_or_uri_semantics_changes(self):
        with self.assertRaisesRegex(
            preview_fincode_nginx.PreviewNginxError,
            "preview_proxy_header_contract_invalid",
        ):
            preview_fincode_nginx.render_config(
                FIXTURE.replace(
                    "proxy_set_header Host $host;",
                    "proxy_set_header Host $http_host;",
                    1,
                )
            )

        with self.assertRaisesRegex(
            preview_fincode_nginx.PreviewNginxError,
            "preview_proxy_uri_semantics_invalid",
        ):
            preview_fincode_nginx.render_config(
                FIXTURE.replace(OLD_UPSTREAM, f"{OLD_UPSTREAM}/api/v2/", 1)
            )

        webhook_with_path = FIXTURE.replace(
            WEBHOOK_BLOCK,
            WEBHOOK_BLOCK.replace(
                OLD_UPSTREAM, f"{OLD_UPSTREAM}/webhooks/v2/fincode"
            ),
        )
        with self.assertRaisesRegex(
            preview_fincode_nginx.PreviewNginxError,
            "preview_proxy_uri_semantics_invalid",
        ):
            preview_fincode_nginx.render_config(webhook_with_path)

    def test_verify_rejects_container_specific_upstream_literals(self):
        for upstream in (OLD_UPSTREAM, CURRENT_CONTAINER_UPSTREAM):
            with self.subTest(upstream=upstream):
                with self.assertRaisesRegex(
                    preview_fincode_nginx.PreviewNginxError,
                    "container_specific_upstream_rejected",
                ):
                    preview_fincode_nginx.verify_content(
                        FIXTURE.replace(OLD_UPSTREAM, upstream)
                    )

        preview_fincode_nginx.verify_content(
            FIXTURE.replace(OLD_UPSTREAM, STABLE_UPSTREAM)
        )

    def test_render_rejects_non_preview_server(self):
        with self.assertRaisesRegex(
            preview_fincode_nginx.PreviewNginxError,
            "preview_tls_server_not_unique",
        ):
            preview_fincode_nginx.render_config(
                FIXTURE.replace("test.luxe-pack.biz", "luxe-pack.biz")
            )

    def test_apply_is_atomic_backed_up_byte_identically_and_verifiable(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            config = root / "test.luxe-pack.biz.conf"
            backup = root / "before.conf"
            config.write_text(FIXTURE, encoding="utf-8")
            config.chmod(0o640)

            result = preview_fincode_nginx.apply_config(config, backup)

            self.assertEqual({"changed": True, "status": "updated"}, result)
            self.assertEqual(FIXTURE.encode(), backup.read_bytes())
            self.assertEqual(0o600, stat.S_IMODE(os.lstat(backup).st_mode))
            self.assertEqual(0o640, stat.S_IMODE(os.lstat(config).st_mode))
            self.assertEqual(
                {"changed": False, "status": "canonical"},
                preview_fincode_nginx.verify_config(config),
            )

    def test_invalid_input_does_not_change_active_config_or_create_backup(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            config = root / "test.luxe-pack.biz.conf"
            backup = root / "before.conf"
            invalid = FIXTURE.replace("proxy_set_header Host $host;", "", 1)
            config.write_text(invalid, encoding="utf-8")

            with self.assertRaisesRegex(
                preview_fincode_nginx.PreviewNginxError,
                "preview_proxy_header_contract_invalid",
            ):
                preview_fincode_nginx.apply_config(config, backup)

            self.assertEqual(invalid.encode(), config.read_bytes())
            self.assertFalse(backup.exists())

    def test_atomic_write_failure_never_partially_overwrites_active_config(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            config = root / "test.luxe-pack.biz.conf"
            backup = root / "before.conf"
            config.write_text(FIXTURE, encoding="utf-8")

            with mock.patch.object(
                preview_fincode_nginx,
                "atomic_replace",
                side_effect=OSError("simulated write failure"),
            ):
                with self.assertRaises(OSError):
                    preview_fincode_nginx.apply_config(config, backup)

            self.assertEqual(FIXTURE.encode(), config.read_bytes())
            self.assertEqual(FIXTURE.encode(), backup.read_bytes())

    def test_config_test_failure_rolls_back_without_reload(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            config = root / "test.luxe-pack.biz.conf"
            backup = root / "before.conf"
            config.write_text(FIXTURE, encoding="utf-8")
            commands = []

            def runner(command, **_kwargs):
                commands.append(tuple(command))
                return mock.Mock(returncode=1)

            with self.assertRaisesRegex(
                preview_fincode_nginx.PreviewNginxError,
                "nginx_config_test_failed_rolled_back",
            ):
                preview_fincode_nginx.activate_config(config, backup, runner)

            self.assertEqual([preview_fincode_nginx.NGINX_TEST_COMMAND], commands)
            self.assertEqual(FIXTURE.encode(), config.read_bytes())
            self.assertEqual(FIXTURE.encode(), backup.read_bytes())

    def test_activation_tests_config_before_one_reload(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            config = root / "test.luxe-pack.biz.conf"
            backup = root / "before.conf"
            config.write_text(FIXTURE, encoding="utf-8")
            commands = []

            def runner(command, **_kwargs):
                commands.append(tuple(command))
                return mock.Mock(returncode=0)

            result = preview_fincode_nginx.activate_config(config, backup, runner)

            self.assertEqual(
                [
                    preview_fincode_nginx.NGINX_TEST_COMMAND,
                    preview_fincode_nginx.NGINX_RELOAD_COMMAND,
                ],
                commands,
            )
            self.assertEqual("passed", result["config_test"])
            self.assertEqual("completed", result["reload"])
            preview_fincode_nginx.verify_config(config)


if __name__ == "__main__":
    unittest.main()
