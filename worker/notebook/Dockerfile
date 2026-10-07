# Notebook sandbox image (M8, ADR-010). Build: php scripts/notebook-image.php build  (tag educloud-nb:1)
# Runs ONLY the executor, as an unprivileged user; the dispatcher adds --network none, --read-only, limits,
# --cap-drop ALL and no-new-privileges at run time (app/Modules/Notebooks/DockerSandbox.php).
# Base pinned by digest (python:3.11-slim as of 2026-10-07); bump deliberately and re-run the isolation suite.
FROM python:3.11-slim@sha256:0dd364ba7e10242f07755449e3a3d0e35f9efd987952737b90def6709ab0c5ce

ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1 \
    PIP_NO_CACHE_DIR=1 \
    PIP_DISABLE_PIP_VERSION_CHECK=1 \
    MPLCONFIGDIR=/tmp \
    HOME=/tmp

COPY requirements.txt /opt/educloud/requirements.txt
RUN pip install --require-hashes --only-binary=:all: -r /opt/educloud/requirements.txt \
    && pip uninstall -y pip setuptools wheel \
    && rm -rf /root/.cache \
    && groupadd --gid 10001 student \
    && useradd --uid 10001 --gid 10001 --no-create-home --home-dir /tmp --shell /usr/sbin/nologin student

COPY nb_exec.py /opt/educloud/nb_exec.py
RUN chmod 0444 /opt/educloud/nb_exec.py /opt/educloud/requirements.txt && chmod 0555 /opt/educloud

USER 10001:10001
WORKDIR /tmp
# PID 1 is a kernel-protected guard: the container kills itself after 150 s even if the dispatcher dies mid-run
# (security gate M8-F1). The dispatcher's own timeout (execution.timeouts.notebook_run, 120 s) normally fires first.
ENTRYPOINT ["timeout", "-s", "KILL", "150", "python", "-I", "/opt/educloud/nb_exec.py"]
