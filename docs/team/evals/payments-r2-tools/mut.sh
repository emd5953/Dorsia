cd /tmp/mut
P=app/Modules/Payments
mut() {
  id=$1; f=$2; expr=$3
  cp "$f" /tmp/mut.bak
  perl -0pi -e "$expr" "$f"
  if cmp -s "$f" /tmp/mut.bak; then echo "$id NOT-APPLIED"; return; fi
  out=$(timeout 600 vendor/bin/pest tests/Modules/Payments tests/Arch/Contracts/PaymentsContractTest.php 2>&1)
  if echo "$out" | grep -q "Tests:.*failed"; then
    echo "$id KILLED: $(echo "$out" | grep -E '^\s+(FAILED|⨯)|✗|⨯' | head -2 | tr -s ' ' | cut -c1-150 | tr '\n' '|')"
  else echo "$id SURVIVED $(echo "$out" | grep 'Tests:')"; fi
  cp /tmp/mut.bak "$f"
}
A=$P/Actions/AuthorizePayment.php
W=$P/Support/HandleWebhook.php
F=$P/Support/FakeGateway.php
R=$P/Console/ReapPayments.php
mut D1a $A 's/catch \(Throwable \$error\)/catch (\\App\\Modules\\Payments\\Exceptions\\GatewayTimeoutException \$error)/'
mut D1b $A 's/Log::error\(.*?\);//'
mut D1c $A 's/\(new VoidPayment\(\$this->gateway\)\)->handle\(\$payment->id\);//'
mut D2 $W 's/&& \$ref === null\) \{/\&\& false) {/'
mut D3 $A 's/&& \$result->providerRef === null\)/\&\& false)/'
mut D4a $F 's/\(\$emit && \(\$this->duplicateWebhooks/((\$this->duplicateWebhooks/'
mut D4b $F 's/if \(! \$emit && ! \$chargeDuplicate\)/if (! \$emit \&\& false)/'
mut D5a $W 's/if \(\$event\[.amount_cents.\] !== \$payment->amount_cents \|\|/if (/'
mut D5b $W 's/ \|\| \$event\[.currency.\] !== \$payment->currency\)/)/'
mut D5c $W 's/\[.payment.captured., PaymentStatus::Pending\] => .*?\n//'
mut D5d $W 's/\[.payment.authorized., .payment.captured.\], true\)\n/[\x27payment.authorized\x27], true)\n/'
mut D5e $W 's/return false;\n        \}\n\n        if \(in_array/return true;\n        }\n\n        if (in_array/'
mut A13 $A 's/if \(! in_array\(\$current->status/if (false \&\& ! in_array(\$current->status/'
mut R5 $P/Jobs/RefundPayment.php 's/:void"/:refund"/'
mut H7 $W 's/->lockForUpdate\(\)//g'
mut H15 $W 's/return DB::transaction\(function \(\) use \(\$event\): bool \{/return (function () use (\$event): bool {/; s/(return false;\n        \})\);/$1)();/'
mut S4 $P/Http/Controllers/FakeWebhookController.php 's/\$request->getContent\(\)/trim(\$request->getContent())/'
mut F11 $F 's/\?\? \(bool\) config\(.payments.fake.emit_webhooks.\)/?? false/'
mut F14 $F 's/&& \$amount->currency->value === \$charge\[.currency.\]//'
mut F36 $F 's/if \(\$created\) \{/if (true) {/'
mut G7 $P/Database/Migrations/2026_10_05_000100_create_payments_table.php 's/bigInteger\(.amount_cents.\)/integer(\x27amount_cents\x27)/'
mut RP1 $R 's/->where\(.created_at., .<., \$pendingBefore\)/->where(\x27created_at\x27, \x27<=\x27, \$pendingBefore)/'
mut RP2 $R 's/ \+ \(int\) config\(.payments.reap.orphan_grace_seconds., 120\)//'
mut RP3 $R 's/\(int\) config\(.inventory.hold_ttl_seconds., 300\) \+ //'
mut RP4 $R 's/->whereNull\(.reservation_id.\)//'
mut RP5 $R 's/->where\(.status., PaymentStatus::Pending->value\)//'
mut V1 $P/Actions/VoidPayment.php 's/->whereNull\(.reservation_id.\)//'
