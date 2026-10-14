"""An argv a Python file builds names the file the process runs."""

from __future__ import annotations

from types import ModuleType


def _launched(contributions: list[dict]) -> list[tuple[str, str]]:
    return sorted(
        (edge["source"], edge["target"])
        for item in contributions
        for edge in item["edges"]
        if edge["kind"] == "launches"
    )


def test_an_argv_naming_an_interpreter_and_a_path_launches_that_path(worker: ModuleType, project, scan_collect) -> None:
    root = project(
        {
            "runner.py": (
                "import subprocess, sys\n"
                "from pathlib import Path\n"
                "ROOT = Path(__file__).parent\n"
                "def start():\n"
                "    subprocess.run([sys.executable, '-I', str(ROOT / 'workers' / 'worker.py')])\n"
                "    subprocess.Popen(['node', '--expose-gc', 'js/worker.js'])\n"
                "    subprocess.run([str(ROOT) + '/bin/tool', '--quiet'])\n"
                "    subprocess.run(['node', '--version'])\n"
                "    labels = ['--flag', 'not/an/argv.txt']\n"
                "    subprocess.run(['python3', 'run.py'])\n"
                "    subprocess.run(['bash', 'deploy'])\n"
            ),
        }
    )
    edge = next(
        edge for item in scan_collect(root, ["runner.py"]) for edge in item["edges"] if edge["kind"] == "launches"
    )
    assert edge["confidence"] == "probable"
    assert edge["attributes"] == {"speculative": True}
    assert _launched(scan_collect(root, ["runner.py"])) == [
        ("py:function:runner.start", "py:launched_file:bin/tool"),
        ("py:function:runner.start", "py:launched_file:js/worker.js"),
        ("py:function:runner.start", "py:launched_file:run.py"),
        ("py:function:runner.start", "py:launched_file:workers/worker.py"),
    ]
