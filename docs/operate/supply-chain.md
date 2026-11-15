# Supply-chain and release assurance

Knossos treats the runtime image as the release artifact and the larger quality
image as a development environment. Run the reproducible assurance gate with:

```sh
tools/quality-container full
```

The `release` lane of the full profile builds both images and invokes
`tools/supply-chain`. Reports are written to `coverage/supply-chain/`:

- `runtime.cdx.json` and `quality.cdx.json` are CycloneDX SBOMs.
- `runtime-vulnerabilities.json` fails the gate for fixed HIGH or CRITICAL
  findings. The development-only quality image is reported separately because
  it deliberately contains Docker, compilers, linters, and audit tools that are
  not shipped in the runtime.
- `dockerfile-misconfigurations.json` enforces HIGH and CRITICAL container
  configuration findings. `.trivyignore` contains the reviewed, narrow
  exception for the quality stage to access a mounted Docker socket as root.
- `provenance.json` records the immutable image IDs and SHA-256 digests of the
  Dockerfile and dependency lock files.
- `provenance.sigstore.json` is the Cosign bundle produced and verified by the
  gate.

The quality image installs Trivy and Cosign from versioned release artifacts
only after verifying checked-in SHA-256 expectations in the Dockerfile. Local
and pull-request validation uses a freshly generated, encrypted ephemeral
Cosign key to prove the complete signing and verification path without storing
release credentials. Published releases should replace that local key with the
project's protected keyless or hardware-backed identity and retain transparency
log verification. The release workflow does not sign anything. It
runs the full profile against the released tree, checks that the runtime image
reports its version and passes `doctor`, builds a tarball for each of four
platforms with a merged `SHA256SUMS`, and publishes the runtime image as
`ghcr.io/araneadev/knossos:<version>` for amd64 and arm64, with version tags
only and no `latest`. The checksums guard against a corrupted download and come
from the same origin as the tarballs, so they are not a signature. The
per-commit quality image that CI's lanes share is not a release artifact.

## Install, upgrade, and rollback lifecycle

`tools/release-lifecycle` creates a new named data volume, runs installation and
doctor checks, scans a read-only mixed-language fixture, creates an atomic
backup (`pre-upgrade.sqlite`), repeats the scan as an idempotent upgrade/migration check, restores the
backup, and verifies that architecture queries still succeed. It always removes
its temporary containers and volumes.

The fixture is copied into a scratch volume rather than bind-mounted, because a
bind source is resolved by the Docker daemon against the host filesystem. When
the profile runs inside the quality container, or on a CI runner whose
workspace is itself a container mount, that path need not exist on the host.
Set `KNOSSOS_LIFECYCLE_FIXTURE` to exercise a different project tree.

These tests demonstrate the supported data lifecycle; they do not replace
retaining external backups before production upgrades. See
[maintenance](maintenance.md) for backup and integrity operations and
[installation](../get-started/installation.md) for runtime deployment.

## Reviewed release checklist

1. Run the full pinned quality profile from a clean checkout.
2. Review runtime SBOM, vulnerability, configuration, benchmark, mutation, and
   coverage reports.
3. Confirm the runtime image ID matches the provenance subject.
4. Sign the final immutable release digest with the protected release identity
   and verify its transparency-log record.
5. Exercise backup, upgrade, restore, and architecture query verification
   against the intended release image.
