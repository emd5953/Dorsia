.PHONY: cancellation-format cancellation-fixtures cancellation-reload cancellation-fixture-cleanup history-format

history-format:
	@$(EXEC) vendor/bin/pint app/Modules/Wallet/Actions/GetCancellationCredits.php app/Modules/Reservations tests/Arch/Contracts/ReservationsContractTest.php tests/Arch/Contracts/WalletContractTest.php tests/Modules/Reservations/Feature/CancellationHistoryTest.php

cancellation-format:
	@$(EXEC) vendor/bin/pint app/Modules/Reservations/Actions/PreviewCancellation.php app/Modules/Reservations/Actions/CancelReservation.php app/Modules/Reservations/Support/CancellationPolicy.php app/Modules/Reservations/Data/CancellationPreview.php app/Modules/Reservations/Data/CancellationExpectation.php app/Modules/Reservations/Exceptions/CancellationPreviewChangedException.php app/Modules/Reservations/Http/Requests/CancelReservationRequest.php app/Modules/Reservations/Http/Resources/CancellationPreviewResource.php app/Modules/Reservations/Http/Controllers/ReservationController.php app/Modules/Reservations/routes/api.php app/Modules/Wallet/Actions/PreviewCancellationRefund.php app/Modules/Wallet/Actions/PostCancellationRefund.php app/Modules/Wallet/Support/LateCancelSplit.php tests/Modules/Reservations/Feature/CancellationPreviewTest.php tests/Arch/Contracts/ReservationsContractTest.php tests/Arch/Contracts/WalletContractTest.php tests/Frontend

cancellation-fixtures:
	@$(EXEC) php tests/Frontend/cancellation-fixtures.php

cancellation-reload:
	@$(EXEC) php artisan octane:reload

cancellation-fixture-cleanup:
	@$(EXEC) php tests/Frontend/cancellation-fixture-cleanup.php "$(FIXTURE_RUN)"

.PHONY: wallet-history-format
wallet-history-format:
	@$(EXEC) vendor/bin/pint app/Modules/Wallet/Actions/ListWalletTransactions.php app/Modules/Wallet/Data/WalletTransaction.php app/Modules/Wallet/Data/WalletTransactionPage.php app/Modules/Wallet/Http app/Modules/Wallet/routes/api.php tests/Modules/Wallet/Feature/WalletTransactionsTest.php tests/Arch/Contracts/WalletContractTest.php
