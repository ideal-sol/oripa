"""Offline F2-B synthetic tests; never reuse an operational container or network."""

import argparse
import json
from pathlib import Path
import subprocess
import time


ROOT = Path(__file__).resolve().parents[2]
PREFIX = "oripa-f2b-20261013-isolated"
LABEL = "oripa.task=BANNERCSV-20261013-isolated"
DATABASE = "oripa_v2_mig084_f2b_synthetic"
API_IMAGE = "mig082-v2-bannercsv-f2a-api:latest"
POSTGRES_IMAGE = "postgres:17-alpine"


def run(arguments, **kwargs):
    return subprocess.run(arguments, check=True, **kwargs)


def output(arguments):
    return subprocess.check_output(arguments).decode().strip()


def inspect(kind, name):
    return json.loads(output(["docker", kind, "inspect", name]))[0]


def php(arguments, **kwargs):
    return run(["docker", "exec", PREFIX + "-api", "php", "-d", "memory_limit=512M", *arguments], **kwargs)


def start():
    if (ROOT / "apps/api/.env").exists() or (ROOT / "apps/api/bootstrap/cache/config.php").exists():
        raise RuntimeError("Refusing environment files or cached configuration")
    for image in [API_IMAGE, POSTGRES_IMAGE]:
        inspect("image", image)
    run(["docker", "network", "create", "--internal", "--label", LABEL, PREFIX])
    run(["docker", "run", "-d", "--pull=never", "--name", PREFIX + "-postgres", "--label", LABEL,
         "--network", PREFIX, "--dns", "127.0.0.1", "--tmpfs", "/var/lib/postgresql/data",
         "-e", "POSTGRES_USER=synthetic", "-e", "POSTGRES_HOST_AUTH_METHOD=trust",
         "-e", "POSTGRES_DB=" + DATABASE, POSTGRES_IMAGE])
    address = inspect("container", PREFIX + "-postgres")["NetworkSettings"]["Networks"][PREFIX]["IPAddress"]
    environment = {
        "PATH": "/usr/local/bin:/usr/bin:/bin", "HOME": "/tmp/empty-home", "APP_ENV": "testing",
        "APP_DEBUG": "false", "APP_KEY": "base64:" + "YWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWE=",
        "APP_TIMEZONE": "Asia/Tokyo", "DB_TIMEZONE": "Asia/Tokyo", "DB_CONNECTION": "pgsql",
        "DB_HOST": address, "DB_PORT": "5432", "DB_DATABASE": DATABASE, "DB_USERNAME": "synthetic",
        "DB_PASSWORD": "", "FILESYSTEM_DISK": "local", "CACHE_STORE": "array", "SESSION_DRIVER": "array",
        "QUEUE_CONNECTION": "sync", "LOG_CHANNEL": "stderr", "AWS_EC2_METADATA_DISABLED": "true",
        "AWS_SHARED_CREDENTIALS_FILE": "/dev/null", "AWS_CONFIG_FILE": "/dev/null",
        "BOTO_CONFIG": "/dev/null", "V2_BANNER_IMPORT_CONCURRENCY_TEST": "1",
        "V2_PUBLIC_ORIGIN": "https://example.test", "V2_ADMIN_ORIGIN": "https://admin.example.test",
    }
    run(["docker", "run", "-d", "--pull=never", "--name", PREFIX + "-api", "--label", LABEL,
         "--network", PREFIX, "--dns", "127.0.0.1", "--read-only", "--cap-drop=ALL",
         "--security-opt=no-new-privileges", "--user", "33:33", "--tmpfs", "/tmp:mode=1777",
         "--tmpfs", "/workspace/apps/api/storage:uid=33,gid=33,mode=0700",
         "--tmpfs", "/workspace/apps/api/bootstrap/cache:uid=33,gid=33,mode=0700",
         "--mount", "type=bind,src=" + str(ROOT) + ",dst=/workspace,readonly",
         "--workdir", "/workspace/apps/api", "--entrypoint", "/usr/bin/env",
         *[argument for key, value in environment.items() for argument in ["-e", key + "=" + value]], API_IMAGE,
         "-i", *[key + "=" + value for key, value in environment.items()], "sleep", "infinity"])
    for attempt in range(30):
        ready = subprocess.run(["docker", "exec", PREFIX + "-postgres", "pg_isready", "-U", "synthetic"],
                               stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        if ready.returncode == 0:
            break
        time.sleep(1)
    else:
        raise RuntimeError("Synthetic PostgreSQL not ready")
    run(["docker", "exec", PREFIX + "-api", "mkdir", "-p", "/tmp/empty-home",
         "storage/framework/cache", "storage/framework/sessions", "storage/framework/views", "storage/logs"])


def preflight():
    network = inspect("network", PREFIX)
    assert network["Internal"] is True and network["EnableIPv6"] is False
    assert network["Labels"].get("oripa.task") == "BANNERCSV-20261013-isolated"
    for suffix in ["-api", "-postgres"]:
        container = inspect("container", PREFIX + suffix)
        assert container["Config"]["Labels"].get("oripa.task") == "BANNERCSV-20261013-isolated"
        assert list(container["NetworkSettings"]["Networks"]) == [PREFIX]
        assert not container["HostConfig"]["PortBindings"]
        assert container["HostConfig"]["Dns"] == ["127.0.0.1"]
    container = inspect("container", PREFIX + "-api")
    assert container["HostConfig"]["ReadonlyRootfs"]
    assert container["HostConfig"]["CapDrop"] == ["ALL"]
    assert container["Config"]["User"] == "33:33"
    assert container["HostConfig"]["Binds"] is None
    mounts = container["HostConfig"]["Mounts"]
    assert len(mounts) == 1 and mounts[0]["Source"] == str(ROOT) and mounts[0]["ReadOnly"]
    php(["-r", r'''
        function guard(bool $valid): void { if (!$valid) throw new RuntimeException('Isolation preflight failed'); }
        foreach (array_slice(file('/proc/net/route'), 1) as $route) {
            $fields = preg_split('/\s+/', trim($route));
            guard($fields[1] !== '00000000');
        }
        guard(!file_exists('/var/run/docker.sock') && !file_exists('.env'));
        foreach (['AWS_ACCESS_KEY_ID','AWS_SECRET_ACCESS_KEY','AWS_SESSION_TOKEN','AWS_PROFILE',
                  'AWS_ROLE_ARN','AWS_WEB_IDENTITY_TOKEN_FILE','AWS_CONTAINER_CREDENTIALS_RELATIVE_URI',
                  'AWS_CONTAINER_CREDENTIALS_FULL_URI','HTTP_PROXY','HTTPS_PROXY','ALL_PROXY',
                  'http_proxy','https_proxy','all_proxy'] as $name) guard(!getenv($name));
        guard(getenv('AWS_EC2_METADATA_DISABLED') === 'true');
        guard(getenv('AWS_SHARED_CREDENTIALS_FILE') === '/dev/null');
        guard(getenv('AWS_CONFIG_FILE') === '/dev/null');
        guard(!file_exists(getenv('HOME').'/.aws'));
        require 'vendor/autoload.php';
        $app = require 'bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        guard($app->environment('testing') && config('filesystems.default') === 'local');
        guard(config('database.connections.pgsql.database') === 'oripa_v2_mig084_f2b_synthetic');
        guard(Illuminate\Support\Facades\DB::selectOne('select current_database() as name')->name === 'oripa_v2_mig084_f2b_synthetic');
        guard(config('v2_banner_import.enabled') === false);
        echo "NETWORK_STORAGE_DB_PREFLIGHT_PASS\n";
    '''])


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("action", choices=["start", "preflight", "migrate", "test"])
    parser.add_argument("--evidence", type=Path)
    parser.add_argument("--name", default="isolated")
    arguments, extra = parser.parse_known_args()
    if arguments.action == "start":
        start()
    elif arguments.action == "preflight":
        preflight()
    elif arguments.action == "migrate":
        php(["artisan", "migrate", "--path=database/migrations-v2", "--force"])
    else:
        if arguments.evidence is None or not arguments.name.replace("-", "").isalnum():
            parser.error("test requires an evidence directory and an alphanumeric run name")
        arguments.evidence.mkdir(parents=True, exist_ok=True)
        command = ["vendor/bin/phpunit", "--configuration", "phpunit.v2.xml", "--colors=never",
                   "--cache-directory=/tmp/phpunit-cache", "--log-junit=/tmp/" + arguments.name + ".xml", *extra]
        with (arguments.evidence / (arguments.name + ".log")).open("w") as log:
            try:
                php(command, stdout=log, stderr=subprocess.STDOUT)
                status = 0
            except subprocess.CalledProcessError as error:
                status = error.returncode
        with (arguments.evidence / (arguments.name + ".xml")).open("wb") as report:
            run(["docker", "exec", PREFIX + "-api", "cat", "/tmp/" + arguments.name + ".xml"], stdout=report)
        print(json.dumps({"run": arguments.name, "status": status}))
        raise SystemExit(status)


if __name__ == "__main__":
    main()
