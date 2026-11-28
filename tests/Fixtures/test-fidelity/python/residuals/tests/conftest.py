"""Runs the start-up hook by name, as an application's plugin entry point does, so no test file imports it."""

import importlib

importlib.import_module("startup").enable()
