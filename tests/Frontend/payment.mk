.PHONY: payment-fixtures payment-evidence payment-webhook payment-format

# Extend the repository Makefile; PHP always runs in the existing app container.
payment-fixtures:
	@$(EXEC) php tests/Frontend/payment-fixtures.php

payment-evidence:
	@$(EXEC) php tests/Frontend/payment-evidence.php

payment-webhook:
	@$(EXEC) php tests/Frontend/payment-webhook.php "$(HOLD_ID)"

payment-format:
	@$(EXEC) vendor/bin/pint tests/Frontend
