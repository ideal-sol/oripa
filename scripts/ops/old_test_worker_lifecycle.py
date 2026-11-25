"""Re-use existing OLD Test one-off rank and private-first LINE Compose procedures."""

import contextlib
import os
from pathlib import Path
import select
import subprocess

import old_test_host as host
import old_test_simple as simple
from old_test_simple_source import require


def current(plan, target, guard):
    folder, _ = host.approved(plan, guard)
    settings = host.config(plan)
    require(
        plan["workers"].get(target) == "active" and target in ("member-rank", "line-sender"),
        "worker_not_selected",
    )
    return folder, settings, host.container(settings, target)


def env_of(obj):
    return dict(x.split("=", 1) for x in obj["Config"].get("Env", []))


def sender_idle(line):
    state = line["queue"](host.canonical)
    require(
        all(
            state[k] == 0
            for k in (
                "unknown",
                "sending",
                "batch_leases",
                "delivery_leases",
                "generation_mismatch",
                "halted",
            )
        ),
        "sender_inflight_or_unknown_delivery",
    )


@contextlib.contextmanager
def sender_fence(settings, guard):
    # Use the same row as the existing sender. Never hold it while stopping the old process.
    db = host.container(settings, "postgres")
    env = env_of(db)
    command = [
        "/usr/bin/docker",
        "exec",
        "-i",
        db["Id"],
        "psql",
        "-XqtA",
        "-v",
        "ON_ERROR_STOP=1",
        "-U",
        env["POSTGRES_USER"],
        "-d",
        env["POSTGRES_DB"],
    ]
    guard()
    proc = subprocess.Popen(command, stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL)
    try:
        proc.stdin.write(
            b"BEGIN; SET LOCAL lock_timeout='3s'; SET LOCAL idle_in_transaction_session_timeout='90s'; SELECT 'READY' FROM line_messaging_settings WHERE id=1 FOR UPDATE;\n"
        )
        proc.stdin.flush()
        require(
            select.select([proc.stdout], [], [], 5)[0] and proc.stdout.readline().strip() == b"READY",
            "sender_lock_unavailable",
        )
        yield lambda: require(proc.poll() is None, "sender_lock_lost")
    finally:
        try:
            if proc.poll() is None:
                proc.stdin.write(b"ROLLBACK;\n")
                proc.stdin.close()
                require(proc.wait(timeout=8) == 0, "sender_lock_release_failed")
        finally:
            if proc.poll() is None:
                proc.kill()
                proc.wait()


def stop(plan, target, guard):
    _, settings, c = current(plan, target, guard)
    require(c["State"]["Running"], "worker_already_stopped")
    if target == "line-sender":
        sender_idle(host.pinned(settings["line_helper"]))
    guard()
    host.run(["/usr/bin/docker", "stop", "--signal", "SIGINT", "--time", "75", c["Id"]], timeout=90)
    stopped = host.canonical.inspect(c["Id"])
    require(stopped["State"]["Pid"] == 0 and not stopped["State"]["Running"], "worker_not_drained")


def line_config(plan, settings, old, image, folder):
    helper = host.pinned(settings["line_helper"])
    path = folder / "line-worker.json"
    repo = Path(settings["database"]["source_repository"])
    source = plan["sources"]["ideal-sol/oripa"]["sha"]
    blob = host.canonical.source_bytes(repo, source, "docker-compose.v2.yml")
    input_dir = folder / "line-source"
    input_dir.mkdir(mode=0o700, exist_ok=True)
    cfg = input_dir / "docker-compose.v2.yml"
    if cfg.exists():
        require(host.checked_file(cfg, True) == blob, "line_source_changed")
    else:
        cfg.write_bytes(blob)
        os.chmod(cfg, 0o600)
    # The historical helper rejects any ambient Docker override, including the local socket.
    # Its subprocess resolves the stock local socket with this restricted environment.
    saved = os.environ.get("DOCKER_HOST")
    require(saved in (None, "unix:///var/run/docker.sock"), "worker_wrong_docker_host")
    try:
        os.environ.pop("DOCKER_HOST", None)
        cmd = helper["prepare_compose"](host.canonical, old, image, input_dir, path)
    finally:
        if saved is not None:
            os.environ["DOCKER_HOST"] = saved
    env = dict(os.environ, **env_of(old))
    env["PATH"] = "/usr/sbin:/usr/bin:/sbin:/bin"
    return cmd, env, helper


def rank_config(plan, settings, old, image, folder):
    require(
        old["Config"]["Cmd"] == settings["rank_command"]
        and old["Config"]["Labels"].get("com.docker.compose.oneoff") == "True"
        and old["HostConfig"]["RestartPolicy"]["Name"] in ("no", ""),
        "rank_definition_changed",
    )
    override = folder / "rank-worker.json"
    original = env_of(old)
    new = host.canonical.decoded(["docker", "image", "inspect", image])[0]
    require(
        not old["HostConfig"]["Privileged"]
        and not old["HostConfig"]["PortBindings"]
        and old["Config"]["Entrypoint"] == new["Config"]["Entrypoint"]
        and set(env_of(new)) <= set(original),
        "rank_process_environment_changed",
    )
    network = list(old["NetworkSettings"]["Networks"])
    require(
        network == [settings["database"]["private_network"]] and len(old["Mounts"]) == 1,
        "rank_private_network_changed",
    )
    mount = old["Mounts"][0]
    require(mount["Type"] == "volume" and not mount["RW"], "rank_readonly_storage_changed")
    env = dict(os.environ)
    references = {}
    for i, (key, value) in enumerate(sorted(original.items())):
        variable = "OLDTEST_RANK_ENV_" + str(i)
        env[variable] = value
        references[key] = "${" + variable + "?required}"
    service = dict(
        image=image,
        environment=references,
        networks=["private"],
        restart="no",
        entrypoint=old["Config"]["Entrypoint"],
        working_dir=old["Config"]["WorkingDir"],
        volumes=[dict(type="volume", source="storage", target=mount["Destination"], read_only=True)],
    )
    simple.persist(
        override,
        {
            "services": {"test-api": service},
            "networks": {"private": {"external": True, "name": network[0]}},
            "volumes": {"storage": {"external": True, "name": mount["Name"]}},
        },
    )
    project = old["Config"]["Labels"]["com.docker.compose.project"]
    cmd = [
        "/usr/bin/docker",
        "compose",
        "--env-file",
        "/dev/null",
        "--project-name",
        project,
        "-f",
        str(override),
    ]
    resolved = host.canonical.decoded(cmd + ["config", "--format", "json"], environment=env)
    require(
        set(resolved["services"]) == {"test-api"}
        and resolved["services"]["test-api"]["environment"] == original,
        "rank_effective_environment_changed",
    )
    return cmd, env


def switch_selected(plan, target, image, guard):
    folder, settings, old = current(plan, target, guard)
    require(
        image == simple.asset(plan, "api", host.cached(plan, "api"))
        and old["Image"] == plan["previous"][target],
        "unapproved_worker_image",
    )
    if target == "member-rank":
        cmd, env = rank_config(plan, settings, old, image, folder)
        if old["State"]["Running"]:
            stop(plan, target, guard)
        name = settings["containers"][target]["name"]
        retained = "oldtest-retained-" + plan["session"].lower() + "-rank"
        guard()
        host.run(["/usr/bin/docker", "rename", old["Id"], retained])
        guard()
        host.canonical.run(
            cmd
            + [
                "run",
                "--detach",
                "--no-deps",
                "--pull",
                "never",
                "--name",
                name,
                "test-api",
                *settings["rank_command"],
            ],
            environment=env,
        )
        new = host.container(settings, target)
        require(new["State"]["Running"] and new["Image"] == image, "rank_worker_not_restored")
        return
    cmd, env, line = line_config(plan, settings, old, image, folder)
    sender_idle(line)
    if old["State"]["Running"]:
        stop(plan, target, guard)
    with sender_fence(settings, guard) as locked:
        guard()
        locked()
        try:
            host.canonical.run(
                cmd + ["up", "-d", "--no-deps", "--no-build", "--pull", "never", "line-delivery-worker"],
                environment=env,
            )
            c = host.canonical.inspect(line["WORKER"])
            require(
                c["Image"] == image
                and c["State"]["Running"]
                and set(c["NetworkSettings"]["Networks"]) == {line["PRIVATE"]},
                "line_private_start_failed",
            )
            locked()
            guard()
            host.run(["/usr/bin/docker", "network", "connect", line["EGRESS"], c["Id"]])
            current_worker = host.container(settings, target)
            require(
                current_worker["Id"] == c["Id"] and current_worker["State"]["Running"], "line_worker_changed"
            )
            line["process_boundary"](current_worker)
            locked()
        except BaseException:
            c = host.canonical.inspect(line["WORKER"])
            if (
                c["State"]["Running"]
                and c["Image"] == image
                and line["EGRESS"] in c["NetworkSettings"]["Networks"]
            ):
                try:
                    host.run(["/usr/bin/docker", "network", "disconnect", line["EGRESS"], c["Id"]])
                except Exception:
                    host.run(["/usr/bin/docker", "pause", c["Id"]])
            raise


def resume_selected(plan, target, guard):
    _, settings, c = current(plan, target, guard)
    require(target in plan["migration"]["writers"], "writer_outside_plan")
    if c["State"]["Running"]:
        return
    require(c["Image"] == plan["previous"][target], "unexpected_stopped_worker_image")
    if target == "line-sender":
        with sender_fence(settings, guard) as locked:
            locked()
            guard()
            host.run(["/usr/bin/docker", "start", c["Id"]])
    else:
        guard()
        host.run(["/usr/bin/docker", "start", c["Id"]])
