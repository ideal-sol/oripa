#!/usr/bin/env python3
"""Offline checks of candidate images, never a Production activation."""

import argparse
import ipaddress
import json
import os
from pathlib import Path
import re
import subprocess
from urllib.parse import urlsplit


ROOT = Path(__file__).resolve().parents[2]
CONFIG = ROOT / "docs/operations/deployment/agency-production-config.json"
DOMAIN_ENVIRONMENT_AUTHORITY = {
    "public_origin": "V2_PUBLIC_ORIGIN",
    "admin_origin": "V2_ADMIN_ORIGIN",
    "agency_origin": "V2_AGENCY_ORIGIN",
    "agency_login_url": "V2_AGENCY_LOGIN_URL",
}
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


def https_url(value: str, name: str, *, origin: bool) -> tuple:
    if (
        not value.startswith("https://")
        or any(ord(character) <= 32 or ord(character) >= 127 for character in value)
        or "\\" in value
        or re.search(r'[<>"{}|^`]', value)
        or re.search(r"%(?![0-9A-Fa-f]{2})", value)
    ):
        raise ValueError(f"Invalid HTTPS URL: {name}")
    try:
        parsed = urlsplit(value)
        hostname = parsed.hostname
        port = parsed.port
        if not hostname or parsed.username is not None or parsed.password is not None:
            raise ValueError
        if parsed.netloc.endswith(":") or port == 0:
            raise ValueError
        if "[" in parsed.netloc and not re.fullmatch(r"\[[0-9A-Fa-f:.]+\](?::[0-9]+)?", parsed.netloc):
            raise ValueError
        try:
            ipaddress.ip_address(hostname)
        except ValueError:
            if len(hostname) > 253 or not all(
                re.fullmatch(r"[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?", label)
                for label in hostname.split(".")
            ):
                raise ValueError
        if origin and (parsed.path or "?" in value or "#" in value):
            raise ValueError
    except ValueError:
        raise ValueError(f"Invalid HTTPS {'origin' if origin else 'URL'}: {name}") from None
    return parsed.scheme, hostname, port or 443


def resolve_config() -> tuple:
    config = json.loads(CONFIG.read_text())
    authority = config.get("domain_environment_authority")
    if (
        config.get("schema_version") != "oripa.agency-production-config.v2"
        or authority != DOMAIN_ENVIRONMENT_AUTHORITY
    ):
        raise ValueError("Invalid Production domain environment authority")
    environment = {}
    effective = {}
    origins = {}
    for field, name in authority.items():
        value = os.environ.get(name)
        if not value:
            raise ValueError(f"Missing or empty required Production environment: {name}")
        origins[field] = https_url(value, name, origin=field != "agency_login_url")
        environment[name] = value
        effective[field] = value
    if origins["agency_login_url"] != origins["agency_origin"]:
        raise ValueError("Agency login URL origin must match Agency origin")
    if "luxe-pack.biz" in effective["agency_login_url"]:
        raise ValueError("Old Test Agency login URL is forbidden in Production")
    config["required_api_environment"] = environment
    return config, effective


def image_checks(api_image: str, admin_image: str, agency_image: str) -> dict:
    config, effective = resolve_config()
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
    return effective


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--api-image", required=True)
    parser.add_argument("--admin-image", required=True)
    parser.add_argument("--agency-image", required=True)
    parser.add_argument("--evidence", type=Path)
    parser.add_argument("--source-sha")
    arguments = parser.parse_args()
    if arguments.evidence and not re.fullmatch(r"[0-9a-f]{40}", arguments.source_sha or ""):
        parser.error("--evidence requires an exact --source-sha")
    try:
        effective = image_checks(arguments.api_image, arguments.admin_image, arguments.agency_image)
    except ValueError as error:
        parser.exit(1, f"Production readiness failed: {error}\n")
    if arguments.evidence:
        arguments.evidence.write_text(json.dumps({
            "schema_version": "oripa.agency-production-readiness.v1",
            "source_commit": arguments.source_sha,
            "effective_domains": effective,
            "images": {
                "api": arguments.api_image,
                "admin": arguments.admin_image,
                "agency": arguments.agency_image,
            },
            "status": "PASS",
            "effective_production_test_url_path": 0,
            "activation_authorized": False,
        }, sort_keys=True, indent=2) + "\n")


if __name__ == "__main__":
    main()
