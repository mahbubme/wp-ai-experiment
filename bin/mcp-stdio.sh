#!/usr/bin/env sh
#
# Serves the MCP adapter's default server over STDIO for a local MCP client.
#
# `wp` is not on the host PATH here - WordPress lives inside ddev - and MCP client
# configs have no working-directory field, so the cd cannot be expressed in
# .mcp.json and has to happen in a wrapper.
#
# `ddev wp` writes its own progress chatter to stderr and leaves stdout as clean
# JSON-RPC, which is what makes this safe to put in front of a client at all.
#
# Usage: mcp-stdio.sh [wp-user]   (default: admin)

set -eu

SITE_DIR="/Users/mahbub/sites/wp"
WP_USER="${1:-admin}"

cd "$SITE_DIR"

exec ddev wp mcp-adapter serve \
    --server=mcp-adapter-default-server \
    --user="$WP_USER"
