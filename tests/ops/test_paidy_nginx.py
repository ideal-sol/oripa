"""Synthetic local Nginx test; never reads or reloads the host configuration."""
import base64
import hashlib
import http.server
from pathlib import Path
import shutil
import socket
import subprocess
import tempfile
import threading
import time
import unittest
import urllib.error
import urllib.request

ROOT = Path(__file__).resolve().parents[2]
TEMPLATE = ROOT / "docs/operations/deployment/paidy-old-test-locations.conf.example"


class PaidyNginxTest(unittest.TestCase):
    def test_template_stays_closed_and_targets_only_old_test(self):
        value = TEMPLATE.read_text()
        self.assertEqual(value.count("auth_basic off;"), 1)
        self.assertIn("location = /webhooks/v2/paidy/__PAIDY_TOKEN__", value)
        self.assertIn("return 503;", value)
        self.assertIn("limit_except POST { deny all; }", value)
        self.assertIn("proxy_pass http://127.0.0.1:8621;", value)
        self.assertEqual(value.count("access_log off;"), 2)
        self.assertEqual(value.count("error_log /dev/null;"), 2)
        self.assertNotIn("univapay", value)

    @unittest.skipUnless(shutil.which("nginx"), "Nginx runtime probe requires local nginx; static checks always run")
    def test_isolated_nginx_auth_paths_body_failure_and_logs(self):
        received = []

        class Receiver(http.server.BaseHTTPRequestHandler):
            def do_POST(self):
                received.append((self.path, self.rfile.read(int(self.headers['Content-Length']))))
                self.send_response(200)
                self.end_headers()
            def log_message(self, *args):
                pass

        upstream = http.server.HTTPServer(('127.0.0.1', 0), Receiver)
        thread = threading.Thread(target=upstream.serve_forever, daemon=True)
        thread.start()
        token = 'synthetic_only_' + 'a' * 40
        try:
            for staged in (True, False):
                with tempfile.TemporaryDirectory(prefix='paidy-nginx-') as directory:
                    root = Path(directory)
                    root.chmod(0o755)
                    (root / 'passwords').write_text('probe:{SHA}' + base64.b64encode(hashlib.sha1(b'fixture').digest()).decode() + '\n')
                    with socket.socket() as listener:
                        listener.bind(('127.0.0.1', 0))
                        port = listener.getsockname()[1]
                    locations = TEMPLATE.read_text().replace('__PAIDY_TOKEN__', token).replace(':8621;', f':{upstream.server_port};')
                    if not staged:
                        locations = locations.replace('    return 503;\n', '')
                    config = f'''daemon off; master_process off;
pid {root}/pid;
error_log {root}/error.log;
events {{}}
http {{
    access_log {root}/access.log;
    server {{
        listen 127.0.0.1:{port};
        auth_basic "Restricted";
        auth_basic_user_file {root}/passwords;
        {locations}
        location / {{ root {root}; }}
    }}
}}
'''
                    (root / 'nginx.conf').write_text(config)
                    subprocess.run(['nginx', '-t', '-p', directory, '-c', str(root / 'nginx.conf')], check=True, capture_output=True)
                    process = subprocess.Popen(['nginx', '-p', directory, '-c', str(root / 'nginx.conf')], stdout=subprocess.PIPE, stderr=subprocess.PIPE)
                    try:
                        for _ in range(100):
                            try:
                                with socket.create_connection(('127.0.0.1', port), .1):
                                    break
                            except OSError:
                                time.sleep(.02)

                        def request(path, method='GET', expected=401):
                            request = urllib.request.Request(f'http://127.0.0.1:{port}' + path, data=b'unchanged-body' if method == 'POST' else None, method=method)
                            try:
                                with urllib.request.urlopen(request, timeout=3) as response:
                                    status = response.status
                            except urllib.error.HTTPError as error:
                                status = error.code
                            self.assertEqual(status, expected)

                        path = '/webhooks/v2/paidy/' + token
                        request('/')
                        request('/webhooks/v2/univapay/fixture')
                        request(path, 'POST', 503 if staged else 200)
                        request(path, 'GET', 403)
                        request('/webhooks/v2/paidy/wrong-' + token, 'POST', 404)
                        request(path + '/extra', 'POST', 404)
                        request(path.replace('synthetic', '%73ynthetic'), 'POST', 503 if staged else 200)
                        if not staged:
                            self.assertEqual(received[0], (path, b'unchanged-body'))
                            upstream.shutdown()
                            upstream.server_close()
                            request(path, 'POST', 502)
                    finally:
                        process.terminate()
                        stdout, stderr = process.communicate(timeout=5)
                    output = stdout + stderr + (root / 'access.log').read_bytes() + (root / 'error.log').read_bytes()
                    self.assertNotIn(token.encode(), output)
        finally:
            upstream.shutdown()
            upstream.server_close()


if __name__ == '__main__':
    unittest.main()
