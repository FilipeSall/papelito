#!/usr/bin/env bash

set -euo pipefail

if [[ $# -lt 1 ]]; then
  echo "Uso: $0 <pedido> [posted|in_transit|out_for_delivery|delivered] [--dry-run] [--shipment=<id>]" >&2
  exit 2
fi

cd "$(dirname "${BASH_SOURCE[0]}")/.."
exec docker compose exec -T web wp --allow-root papelito tracking simulate "$@"
