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
LIVE_FIXTURE = f"""server {{
    server_name luxe-pack.biz;

    client_max_body_size 20m;

    location /.well-known/acme-challenge/ {{
        root /usr/share/nginx/html;
    }}

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

    location ^~ /admin/api/ {{
        return 404 '{{"status":404}}';
    }}

    location / {{
        proxy_pass http://127.0.0.1:3200;
        proxy_http_version 1.1;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }}

    listen [::]:443 ssl ipv6only=on;
    listen 443 ssl;
}}
server {{
    if ($host = luxe-pack.biz) {{
        return 301 https://$host$request_uri;
    }}

    listen 80;
    listen [::]:80;
    server_name luxe-pack.biz;
    return 404;
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

    def test_render_canonicalizes_live_exact_and_prefix_without_webhook(self):
        rendered = preview_fincode_nginx.render_config(
            LIVE_FIXTURE, preview_fincode_nginx.LIVE_SERVER_NAME
        )

        self.assertEqual(
            LIVE_FIXTURE.replace(OLD_UPSTREAM, STABLE_UPSTREAM), rendered
        )
        self.assertEqual(2, rendered.count(f"proxy_pass {STABLE_UPSTREAM};"))
        self.assertNotIn(OLD_UPSTREAM, rendered)
        self.assertNotIn(CURRENT_CONTAINER_UPSTREAM, rendered)
        self.assertNotIn(preview_fincode_nginx.WEBHOOK_PATH, rendered)
        self.assertIn("client_max_body_size 20m;", rendered)
        self.assertIn("proxy_pass http://127.0.0.1:3200;", rendered)
        self.assertEqual(
            rendered,
            preview_fincode_nginx.render_config(
                rendered, preview_fincode_nginx.LIVE_SERVER_NAME
            ),
        )

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
        for fixture, server_name in (
            (FIXTURE, preview_fincode_nginx.PREVIEW_SERVER_NAME),
            (LIVE_FIXTURE, preview_fincode_nginx.LIVE_SERVER_NAME),
        ):
            with self.subTest(server_name=server_name, contract="headers"):
                with self.assertRaisesRegex(
                    preview_fincode_nginx.PreviewNginxError,
                    "preview_proxy_header_contract_invalid",
                ):
                    preview_fincode_nginx.render_config(
                        fixture.replace(
                            "proxy_set_header Host $host;",
                            "proxy_set_header Host $http_host;",
                            1,
                        ),
                        server_name,
                    )

            with self.subTest(server_name=server_name, contract="uri"):
                with self.assertRaisesRegex(
                    preview_fincode_nginx.PreviewNginxError,
                    "preview_proxy_uri_semantics_invalid",
                ):
                    preview_fincode_nginx.render_config(
                        fixture.replace(
                            OLD_UPSTREAM, f"{OLD_UPSTREAM}/api/v2/", 1
                        ),
                        server_name,
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
        for fixture, server_name in (
            (FIXTURE, preview_fincode_nginx.PREVIEW_SERVER_NAME),
            (LIVE_FIXTURE, preview_fincode_nginx.LIVE_SERVER_NAME),
        ):
            for upstream in (OLD_UPSTREAM, CURRENT_CONTAINER_UPSTREAM):
                with self.subTest(server_name=server_name, upstream=upstream):
                    with self.assertRaisesRegex(
                        preview_fincode_nginx.PreviewNginxError,
                        "container_specific_upstream_rejected",
                    ):
                        preview_fincode_nginx.verify_content(
                            fixture.replace(OLD_UPSTREAM, upstream), server_name
                        )

            preview_fincode_nginx.verify_content(
                fixture.replace(OLD_UPSTREAM, STABLE_UPSTREAM), server_name
            )

    def test_render_requires_the_selected_managed_server(self):
        with self.assertRaisesRegex(
            preview_fincode_nginx.PreviewNginxError,
            "managed_tls_server_not_unique",
        ):
            preview_fincode_nginx.render_config(LIVE_FIXTURE)

        with self.assertRaisesRegex(
            preview_fincode_nginx.PreviewNginxError,
            "managed_tls_server_not_unique",
        ):
            preview_fincode_nginx.render_config(
                FIXTURE, preview_fincode_nginx.LIVE_SERVER_NAME
            )

        with self.assertRaisesRegex(
            preview_fincode_nginx.PreviewNginxError,
            "managed_server_name_invalid",
        ):
            preview_fincode_nginx.render_config(FIXTURE, "support.luxe-pack.biz")

    def test_apply_is_atomic_backed_up_byte_identically_and_verifiable(self):
        for filename, fixture, server_name in (
            (
                "test.luxe-pack.biz.conf",
                FIXTURE,
                preview_fincode_nginx.PREVIEW_SERVER_NAME,
            ),
            (
                "luxe-pack.biz.conf",
                LIVE_FIXTURE,
                preview_fincode_nginx.LIVE_SERVER_NAME,
            ),
        ):
            with self.subTest(server_name=server_name):
                with tempfile.TemporaryDirectory() as temporary:
                    root = Path(temporary)
                    config = root / filename
                    backup = root / "before.conf"
                    config.write_text(fixture, encoding="utf-8")
                    config.chmod(0o640)

                    result = preview_fincode_nginx.apply_config(
                        config, backup, server_name
                    )

                    self.assertEqual(
                        {"changed": True, "status": "updated"}, result
                    )
                    self.assertEqual(fixture.encode(), backup.read_bytes())
                    self.assertEqual(0o600, stat.S_IMODE(os.lstat(backup).st_mode))
                    self.assertEqual(0o640, stat.S_IMODE(os.lstat(config).st_mode))
                    self.assertEqual(
                        {"changed": False, "status": "canonical"},
                        preview_fincode_nginx.verify_config(config, server_name),
                    )

    def test_invalid_input_does_not_change_active_config_or_create_backup(self):
        for fixture, server_name in (
            (FIXTURE, preview_fincode_nginx.PREVIEW_SERVER_NAME),
            (LIVE_FIXTURE, preview_fincode_nginx.LIVE_SERVER_NAME),
        ):
            with self.subTest(server_name=server_name):
                with tempfile.TemporaryDirectory() as temporary:
                    root = Path(temporary)
                    config = root / f"{server_name}.conf"
                    backup = root / "before.conf"
                    invalid = fixture.replace(
                        "proxy_set_header Host $host;", "", 1
                    )
                    config.write_text(invalid, encoding="utf-8")

                    with self.assertRaisesRegex(
                        preview_fincode_nginx.PreviewNginxError,
                        "preview_proxy_header_contract_invalid",
                    ):
                        preview_fincode_nginx.apply_config(
                            config, backup, server_name
                        )

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
        for fixture, server_name in (
            (FIXTURE, preview_fincode_nginx.PREVIEW_SERVER_NAME),
            (LIVE_FIXTURE, preview_fincode_nginx.LIVE_SERVER_NAME),
        ):
            with self.subTest(server_name=server_name):
                with tempfile.TemporaryDirectory() as temporary:
                    root = Path(temporary)
                    config = root / f"{server_name}.conf"
                    backup = root / "before.conf"
                    config.write_text(fixture, encoding="utf-8")
                    commands = []

                    def runner(command, **_kwargs):
                        commands.append(tuple(command))
                        return mock.Mock(returncode=1)

                    with self.assertRaisesRegex(
                        preview_fincode_nginx.PreviewNginxError,
                        "nginx_config_test_failed_rolled_back",
                    ):
                        preview_fincode_nginx.activate_config(
                            config, backup, runner, server_name
                        )

                    self.assertEqual(
                        [preview_fincode_nginx.NGINX_TEST_COMMAND], commands
                    )
                    self.assertEqual(fixture.encode(), config.read_bytes())
                    self.assertEqual(fixture.encode(), backup.read_bytes())

    def test_activation_tests_config_before_one_reload(self):
        for fixture, server_name in (
            (FIXTURE, preview_fincode_nginx.PREVIEW_SERVER_NAME),
            (LIVE_FIXTURE, preview_fincode_nginx.LIVE_SERVER_NAME),
        ):
            with self.subTest(server_name=server_name):
                with tempfile.TemporaryDirectory() as temporary:
                    root = Path(temporary)
                    config = root / f"{server_name}.conf"
                    backup = root / "before.conf"
                    config.write_text(fixture, encoding="utf-8")
                    commands = []

                    def runner(command, **_kwargs):
                        commands.append(tuple(command))
                        return mock.Mock(returncode=0)

                    result = preview_fincode_nginx.activate_config(
                        config, backup, runner, server_name
                    )

                    self.assertEqual(
                        [
                            preview_fincode_nginx.NGINX_TEST_COMMAND,
                            preview_fincode_nginx.NGINX_RELOAD_COMMAND,
                        ],
                        commands,
                    )
                    self.assertEqual("passed", result["config_test"])
                    self.assertEqual("completed", result["reload"])
                    preview_fincode_nginx.verify_config(config, server_name)


if __name__ == "__main__":
    unittest.main()
