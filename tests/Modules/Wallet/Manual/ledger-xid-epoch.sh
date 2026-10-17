#!/usr/bin/env bash
set -euo pipefail

container="dorsia-ledger-epoch-$$"
image="postgres:16.15-bookworm"
port=""
cleanup() {
    docker rm -f "$container" >/dev/null 2>&1 || true
}
trap cleanup EXIT

docker run -d --name "$container" -e POSTGRES_USER=dorsia -e POSTGRES_PASSWORD=dorsia -e POSTGRES_DB=dorsia -p 127.0.0.1::5432 "$image" >/dev/null
until docker exec "$container" pg_isready -U dorsia >/dev/null 2>&1; do sleep 1; done
docker stop "$container" >/dev/null
docker run --rm --volumes-from "$container" --user postgres "$image" pg_resetwal -e 1 -D /var/lib/postgresql/data
docker start "$container" >/dev/null
until docker exec "$container" pg_isready -U dorsia >/dev/null 2>&1; do sleep 1; done
port="$(docker port "$container" 5432/tcp | sed 's/.*://')"

docker compose exec -T -e DB_HOST=host.docker.internal -e DB_PORT="$port" -e DB_DATABASE=dorsia -e DB_USERNAME=dorsia -e DB_PASSWORD=dorsia app php artisan migrate:fresh --force
docker compose exec -T -e DB_HOST=host.docker.internal -e DB_PORT="$port" -e DB_DATABASE=dorsia -e DB_USERNAME=dorsia -e DB_PASSWORD=dorsia app vendor/bin/pest tests/Modules/Wallet/Feature/BookingPaymentTest.php --filter='nested Laravel savepoints'

docker exec -i "$container" psql -U dorsia -d dorsia -v ON_ERROR_STOP=1 <<'SQL'
INSERT INTO ledger_accounts (owner_type, owner_id, kind, currency, balance_cents, created_at, updated_at)
VALUES ('system', 0, 'cash', 'USD', 0, now(), now()),
       ('system', 0, 'platform', 'USD', 0, now(), now());
BEGIN;
INSERT INTO ledger_transactions (type, reference_type, reference_id, idempotency_key, currency, created_at)
VALUES ('goodwill_credit', 'member', 1, 'epoch:committed', 'USD', now()) RETURNING id \gset
INSERT INTO ledger_entries (transaction_id, account_id, amount_cents, created_at)
SELECT :id, id, amount, now()
FROM (SELECT id, 100 AS amount FROM ledger_accounts WHERE kind = 'cash'
      UNION ALL SELECT id, -100 FROM ledger_accounts WHERE kind = 'platform') entries;
COMMIT;
SELECT 'epoch reset posting committed; xid8=' || created_xid FROM ledger_transactions WHERE id = :id;
SQL

if docker exec "$container" psql -U dorsia -d dorsia -v ON_ERROR_STOP=1 -c "INSERT INTO ledger_entries (transaction_id, account_id, amount_cents, created_at) SELECT 1, id, amount, now() FROM (SELECT id, 1 AS amount FROM ledger_accounts WHERE kind = 'cash' UNION ALL SELECT id, -1 FROM ledger_accounts WHERE kind = 'platform') entries"; then
    echo 'ERROR: append to committed transaction unexpectedly succeeded' >&2
    exit 1
else
    echo 'epoch reset append to committed transaction rejected as expected'
fi
