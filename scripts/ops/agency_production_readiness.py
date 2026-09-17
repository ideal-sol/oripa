#!/usr/bin/env python3
"""Offline checks of candidate images, never a Production activation."""

import argparse
import json
from pathlib import Path
import subprocess


ROOT = Path(__file__).resolve().parents[2]
CONFIG = ROOT / "docs/operations/deployment/agency-production-config.json"
API_CHECK = r"""
require 'vendor/autoload.php';
$expected = json_decode(getenv('ORIPA_EXPECTED_CONFIG'), true, 512, JSON_THROW_ON_ERROR);
$agency = require 'config/v2_agency.php';
$identity = require 'config/v2_identity.php';
$templates = require 'config/v2_mail_templates.php';
$environment = $expected['required_api_environment'];
foreach ($environment as $name => $value) {
    if (getenv($name) !== $value) {
        throw new RuntimeException('Required Production environment mismatch: '.$name);
    }
}
if ($agency['login_url'] !== $environment['V2_AGENCY_LOGIN_URL']
    || $identity['origins']['agency'] !== $environment['V2_AGENCY_ORIGIN']
    || $identity['origins']['admin'] !== $environment['V2_ADMIN_ORIGIN']
    || $identity['origins']['user'] !== $environment['V2_PUBLIC_ORIGIN']) {
    throw new RuntimeException('Effective Production origin/login mismatch.');
}
$session = $expected['agency_session'];
foreach (['idle_minutes', 'absolute_minutes', 'cookie', 'same_site'] as $key) {
    if ($identity['sessions']['agency'][$key] !== $session[$key]) {
        throw new RuntimeException('Agency session policy mismatch.');
    }
}
foreach (['secure', 'http_only', 'host_only', 'path'] as $key) {
    if ($identity['cookie_security'][$key] !== $session[$key]) {
        throw new RuntimeException('Agency cookie policy mismatch.');
    }
}
$renderer = new App\Domain\Mail\Services\V2TemplateVariableRenderer(
    new App\Domain\ContentContact\Services\V2ContentHtmlSanitizer()
);
$keys = ['agency_account_created', 'agency_password_changed',
    'agency_login_information_reissued', 'agency_email_changed'];
foreach ($keys as $key) {
    if (! isset($templates['templates'][$key])) {
        throw new RuntimeException('Agency mail template missing.');
    }
    $values = ['agency_login_url' => $agency['login_url']];
    $subject = $renderer->subject('{{agency_login_url}}', $values);
    $body = $renderer->html('<p>Login: {{agency_login_url}}</p>', $values);
    if ($subject !== $environment['V2_AGENCY_LOGIN_URL']
        || ! str_contains($body, $environment['V2_AGENCY_LOGIN_URL'])
        || str_contains($body, 'luxe-pack.biz') || str_contains($body, '{{')) {
        throw new RuntimeException('Agency Production mail render mismatch.');
    }
}
echo json_encode(['effective_production_test_url_path' => 0,
    'mail_template_keys' => $keys, 'database_calls' => 0, 'provider_calls' => 0]).PHP_EOL;
"""
FRONTEND_CHECK = r"""
const fs = require('node:fs');
const path = require('node:path');
const forbidden = ['ad.luxe-pack.biz', 'test.luxe-pack.biz', '127.0.0.1:8621'];
let files = 0;
let agencyPrefixPresent = false;
function inspect(directory) {
    for (const entry of fs.readdirSync(directory, {withFileTypes: true})) {
        const filename = path.join(directory, entry.name);
        if (entry.isDirectory()) inspect(filename);
        else if (entry.isFile()) {
            const content = fs.readFileSync(filename);
            if (forbidden.some(value => content.includes(value))) {
                throw new Error('Old Test value in frontend bundle: ' + filename);
            }
            agencyPrefixPresent ||= content.includes('/agency/api/v2');
            files += 1;
        }
    }
}
inspect('.next/static');
inspect('.next/server');
if (!files || (process.env.ORIPA_COMPONENT === 'agency' && !agencyPrefixPresent)) {
    throw new Error('Missing frontend assets or same-origin Agency API prefix.');
}
console.log(JSON.stringify({component: process.env.ORIPA_COMPONENT,
    files, old_test_values: 0, build_id: fs.readFileSync('.next/BUILD_ID', 'utf8').trim()}));
"""


def image_checks(api_image: str, admin_image: str, agency_image: str) -> None:
    config = json.loads(CONFIG.read_text())
    environment = config["required_api_environment"]
    command = ["docker", "run", "--rm", "--read-only", "--network", "none"]
    for name, value in environment.items():
        command.extend(["--env", f"{name}={value}"])
    command.extend(["--env", "APP_ENV=production", "--env", "ORIPA_EXPECTED_CONFIG=" + json.dumps(config)])
    subprocess.run([*command, "--entrypoint", "php", api_image, "-r", API_CHECK], check=True)
    for component, image in (("admin", admin_image), ("agency", agency_image)):
        subprocess.run([
            "docker", "run", "--rm", "--read-only", "--network", "none",
            "--env", f"ORIPA_COMPONENT={component}", "--entrypoint", "node",
            image, "-e", FRONTEND_CHECK,
        ], check=True)


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--api-image", required=True)
    parser.add_argument("--admin-image", required=True)
    parser.add_argument("--agency-image", required=True)
    arguments = parser.parse_args()
    image_checks(arguments.api_image, arguments.admin_image, arguments.agency_image)


if __name__ == "__main__":
    main()
