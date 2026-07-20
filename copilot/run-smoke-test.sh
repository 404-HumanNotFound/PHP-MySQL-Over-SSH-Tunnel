#!/usr/bin/env bash
# run-smoke-test.sh
# Wrapper to run the real SMOKE_TEST.php using environment variables.
# WARNING: This will attempt to open real SSH and DB connections. Only run
# if you understand and permit those network interactions.
set -euo pipefail

# Configure these variables as appropriate for your environment.
export TUNNEL_TEST_SERVER="webdev.everosi.net"
export TUNNEL_TEST_SSH_PORT="52219"
export TUNNEL_TEST_LOCAL_PORT="3306"
export TUNNEL_TEST_SSH_USER="mchaggis"
export TUNNEL_TEST_SSH_KEY_PATH="$HOME/.ssh/id_ed25519"
export TUNNEL_TEST_SSH_BINARY_PATH="/usr/bin/ssh"
export TUNNEL_TEST_REMOTE_PORT="3306"
export TUNNEL_TEST_DB_USER="webaccess"
export TUNNEL_TEST_DB_PASSWORD="d3v370pM3!"
export TUNNEL_TEST_DB_NAME="csosi"

# By default run the real SMOKE_TEST.php. Pass --safe to run the harmless
# fake/safe runner instead (no network/DB calls).
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$SCRIPT_DIR"

if [ "${1-}" = "--safe" ]; then
  php "$ROOT_DIR/scripts/run_smoke_test_safe.php"
else
  # Use auto_prepend_file to inject compatibility shim so SMOKE_TEST can find
  # the HumanNotFound\MysqlSshTunnel classes without changing composer autoload.
  php -d auto_prepend_file="$ROOT_DIR/compat/compat.php" "$ROOT_DIR/SMOKE_TEST.php"
fi
