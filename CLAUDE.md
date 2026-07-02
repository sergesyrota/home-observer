# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

A small collection of standalone PHP scripts that poll home automation hardware (RS485 sensors via Gearman, and a Homebridge instance) and append JSON log lines describing state — for ingestion by a log/observability pipeline (e.g. ELK, given the `@timestamp` and `message` fields). There is no framework, router, or entry point beyond the individual scripts; each script is invoked directly (via cron or as a long-running daemon) with required config supplied through environment variables.

## Setup

```
composer install
cp .env.example .env   # then fill in values and `source .env` (or export via your process manager) before running scripts
```

Required environment variables (see `.env.example`, read via `getRequiredEnv()` in `lib.php`):
- `OBSERVER_PID_FILE` — pidfile path used by `doors.php` for the re-run guard
- `OBSERVER_LOG_FILE` — JSON-lines output file both scripts append to
- `HOMEBRIDGE_PATH`, `HOMEBRIDGE_USER`, `HOMEBRIDGE_PASS` — Homebridge REST API credentials used by `lib/Homebridge.php`

There are no test, build, or lint commands configured for this project.

**Production runs on PHP 5.** Do not use PHP 7+-only syntax or functions (e.g. scalar type hints, return type declarations, null coalescing assignment `??=`, arrow functions, typed properties, `match`). Stick to syntax compatible with PHP 5.

## Running the scripts

- `php temperatures.php` — intended to run on a cron every few minutes. Pulls current values from Homebridge accessories (thermostats/sensors, identified by hardcoded Homebridge `uniqueId`s in `$sensorsList`) and, for some devices, from an RS485 equipment-monitor sensor via Gearman. Appends one JSON line per sensor to `OBSERVER_LOG_FILE`.
- `php doors.php` — a long-running daemon (not a one-shot cron job) that polls garage door sensor state over RS485 every second in an infinite loop, self-terminating after 7 days so a process manager restarts it fresh. It guards against overlapping runs using a PID file (`isProcessRunning`/`removePidFile`), and treats a PID file older than 300s as stale (kills that PID). Only logs a JSON line to `OBSERVER_LOG_FILE` when a door's open/closed state actually changes.
- `schwab.py` is an unrelated, untracked one-off script for testing Schwab API OAuth — not part of the observer pipeline.

## Architecture notes

- `lib.php` is the shared bootstrap: it defines `getRequiredEnv()` (throws if a needed env var is missing/empty) and pulls in `lib/Homebridge.php`. Every top-level script requires `lib.php` first.
- `lib/Homebridge.php` is a minimal curl-based client for the Homebridge REST API. It lazily logs in (`POST /api/auth/login`) and caches the bearer token in-memory until it's within 300s of expiry, then calls `GET /api/accessories/{uniqueId}` per sensor.
- RS485 hardware access goes through two different paths that both end up in the same underlying protocol:
  - `temperatures.php` uses `\SyrotaAutomation\Gearman` (from the `sergesyrota/syrota-automation` composer dependency, installed via VCS repository in `composer.json`), which submits a Gearman job (`doNormal`) to a `rs485` task queue and parses the `\x02>...\n` response envelope.
  - `doors.php` instead requires an **external, not-in-repo** file, `/var/www/home/dashboard/include/rs485.php` (class `rs485`), and calls `->command($device, $command)` directly. This means `doors.php` cannot run standalone outside its deployment host without that external dependency present at that exact path.
- Device/accessory identifiers (Homebridge `uniqueId`s, RS485 device names like `GarageSens`/`BrAcSens`) are hardcoded per-deployment (per-house) constants inside the scripts, not configuration — expect them to be meaningless outside the original deployment and to change if hardware/Homebridge accessories are re-paired (see git history of `temperatures.php` for an example of such a churn).
- All output is unstructured-log-shaped: single JSON object per line, always including `@timestamp` and a human-readable `message`, appended (never rotated) to `OBSERVER_LOG_FILE`. Downstream consumers are expected to tail/ship this file rather than query these scripts directly.
