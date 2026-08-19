#!/usr/bin/env python3
"""Attach V2 Preview API egress after private-only container startup."""

from __future__ import annotations

import argparse
import ipaddress
import json
import re
import subprocess
from typing import Any


PROJECT_PATTERN = re.compile(r"[a-z0-9][a-z0-9-]{2,62}")
DEFAULT_SUBNET = "192.168.62.0/28"
SERVICES = ("api", "admin", "postgres", "redis")


class EgressFailure(RuntimeError):
    pass


def run(arguments: list[str], *, allow_failure: bool = False) -> subprocess.CompletedProcess[str]:
    result = subprocess.run(
        arguments,
        check=False,
        stdout=subprocess.PIPE,
        stderr=subprocess.DEVNULL,
        text=True,
    )
    if result.returncode and not allow_failure:
        raise EgressFailure("Docker egress operation failed")
    return result


def inspect_object(kind: str, name: str, *, allow_missing: bool = False) -> dict[str, Any] | None:
    result = run(["docker", kind, "inspect", name], allow_failure=allow_missing)
    if result.returncode:
        return None
    try:
        decoded = json.loads(result.stdout)
    except json.JSONDecodeError as error:
        raise EgressFailure("Docker inspect response is invalid") from error
    if not isinstance(decoded, list) or len(decoded) != 1 or not isinstance(decoded[0], dict):
        raise EgressFailure("Docker inspect response is invalid")
    return decoded[0]


def network_subnets(network: dict[str, Any]) -> list[ipaddress.IPv4Network]:
    ipam = network.get("IPAM") if isinstance(network.get("IPAM"), dict) else {}
    configs = ipam.get("Config") if isinstance(ipam.get("Config"), list) else []
    subnets = []
    for config in configs:
        if not isinstance(config, dict) or not isinstance(config.get("Subnet"), str):
            continue
        try:
            subnet = ipaddress.ip_network(config["Subnet"])
        except ValueError as error:
            raise EgressFailure("Docker network subnet is invalid") from error
        if not isinstance(subnet, ipaddress.IPv4Network):
            raise EgressFailure("Docker network subnet is invalid")
        subnets.append(subnet)
    return subnets


def validate_network_boundary(
    private_network: dict[str, Any],
    egress_network: dict[str, Any],
    expected_subnet: str,
) -> None:
    try:
        desired = ipaddress.ip_network(expected_subnet)
    except ValueError as error:
        raise EgressFailure("Requested egress subnet is invalid") from error
    if not isinstance(desired, ipaddress.IPv4Network) or desired.prefixlen < 28:
        raise EgressFailure("Requested egress subnet is invalid")
    if private_network.get("Internal") is not True:
        raise EgressFailure("V2 private network must remain internal")
    if egress_network.get("Internal") is True or egress_network.get("Driver") != "bridge":
        raise EgressFailure("V2 API egress network is invalid")
    egress_subnets = network_subnets(egress_network)
    if egress_subnets != [desired]:
        raise EgressFailure("V2 API egress subnet does not match")
    if any(desired.overlaps(subnet) for subnet in network_subnets(private_network)):
        raise EgressFailure("V2 API egress subnet overlaps the private network")


def container_network_names(container: dict[str, Any]) -> set[str]:
    settings = (
        container.get("NetworkSettings")
        if isinstance(container.get("NetworkSettings"), dict)
        else {}
    )
    networks = settings.get("Networks") if isinstance(settings.get("Networks"), dict) else {}
    return set(networks)


def validate_service_memberships(
    memberships: dict[str, set[str]],
    private_name: str,
    egress_name: str,
    *,
    attached: bool,
) -> None:
    expected_api = {private_name, egress_name} if attached else {private_name}
    if memberships.get("api") != expected_api:
        raise EgressFailure("V2 API network membership is invalid")
    for service in ("admin", "postgres", "redis"):
        if memberships.get(service) != {private_name}:
            raise EgressFailure(f"V2 {service} network membership is invalid")


def container_name(project: str, service: str) -> str:
    return f"{project}-{service}-1"


def inspect_services(project: str) -> dict[str, dict[str, Any]]:
    containers = {}
    for service in SERVICES:
        name = container_name(project, service)
        container = inspect_object("container", name)
        if container is None:
            raise EgressFailure("V2 service container is missing")
        config = container.get("Config") if isinstance(container.get("Config"), dict) else {}
        labels = config.get("Labels") if isinstance(config.get("Labels"), dict) else {}
        state = container.get("State") if isinstance(container.get("State"), dict) else {}
        if (
            labels.get("com.docker.compose.project") != project
            or labels.get("com.docker.compose.service") != service
            or state.get("Running") is not True
        ):
            raise EgressFailure("V2 service container identity is invalid")
        containers[service] = container
    return containers


def memberships(containers: dict[str, dict[str, Any]]) -> dict[str, set[str]]:
    return {
        service: container_network_names(container)
        for service, container in containers.items()
    }


def ensure_egress_network(project: str, subnet: str) -> tuple[str, dict[str, Any]]:
    name = f"{project}_v2_api_egress"
    network = inspect_object("network", name, allow_missing=True)
    if network is None:
        run(
            [
                "docker",
                "network",
                "create",
                "--driver",
                "bridge",
                "--subnet",
                subnet,
                "--label",
                f"com.docker.compose.project={project}",
                "--label",
                "com.docker.compose.network=v2_api_egress",
                name,
            ]
        )
        network = inspect_object("network", name)
    if network is None:
        raise EgressFailure("V2 API egress network is missing")
    return name, network


def execute(action: str, project: str, subnet: str) -> dict[str, Any]:
    if not PROJECT_PATTERN.fullmatch(project):
        raise EgressFailure("Compose project is invalid")
    private_name = f"{project}_v2_private"
    private_network = inspect_object("network", private_name)
    if private_network is None:
        raise EgressFailure("V2 private network is missing")
    containers = inspect_services(project)
    current = memberships(containers)

    if action == "detach":
        egress_name = f"{project}_v2_api_egress"
        if egress_name in current["api"]:
            run(["docker", "network", "disconnect", egress_name, container_name(project, "api")])
        containers = inspect_services(project)
        validate_service_memberships(
            memberships(containers), private_name, egress_name, attached=False
        )
        return {"action": action, "project": project, "status": "detached"}

    egress_name, egress_network = ensure_egress_network(project, subnet)
    validate_network_boundary(private_network, egress_network, subnet)
    if current["api"] not in ({private_name}, {private_name, egress_name}):
        raise EgressFailure("V2 API pre-attach network membership is invalid")
    for service in ("admin", "postgres", "redis"):
        if current[service] != {private_name}:
            raise EgressFailure(f"V2 {service} pre-attach network membership is invalid")
    if egress_name not in current["api"]:
        run(["docker", "network", "connect", egress_name, container_name(project, "api")])
    containers = inspect_services(project)
    validate_service_memberships(
        memberships(containers), private_name, egress_name, attached=True
    )
    verified_egress = inspect_object("network", egress_name)
    if verified_egress is None:
        raise EgressFailure("V2 API egress network is missing")
    attached_names = {
        value.get("Name")
        for value in (verified_egress.get("Containers") or {}).values()
        if isinstance(value, dict)
    }
    if attached_names != {container_name(project, "api")}:
        raise EgressFailure("V2 API egress network contains a non-API container")
    return {
        "action": action,
        "project": project,
        "status": "attached",
        "subnet": subnet,
    }


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("action", choices=("attach", "detach"))
    parser.add_argument("--project", required=True)
    parser.add_argument("--subnet", default=DEFAULT_SUBNET)
    arguments = parser.parse_args()
    print(json.dumps(execute(arguments.action, arguments.project, arguments.subnet), sort_keys=True))


if __name__ == "__main__":
    try:
        main()
    except EgressFailure as error:
        print(f"v2_api_egress_error:{error}")
        raise SystemExit(1)
