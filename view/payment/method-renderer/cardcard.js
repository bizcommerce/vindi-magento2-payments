// Handle first card amount change
$(document).off('change keyup blur', '#first_card_amount');
$(document).on('change keyup blur', '#first_card_amount', function() {
    var grandTotal = self.getGrandTotal();
    var firstCardAmount = parseFloat($(this).val().replace(',', '.') || 0);
    var $secondCardAmount = $('#second_card_amount');
    var secondCardAmount = parseFloat($secondCardAmount.val().replace(',', '.') || 0);

    // Se o campo do primeiro cartão for preenchido
    if (firstCardAmount > 0 && firstCardAmount <= grandTotal) {
        var remaining = Math.max(0, grandTotal - firstCardAmount);
        $secondCardAmount.val(remaining.toFixed(2));
        $secondCardAmount.prop('disabled', true);
        $(this).prop('disabled', false);
        // Só dispara consulta de parcelas se ambos preenchidos
        if (remaining > 0) {
            self.DualCardInstallmentManager.updateCard(1, { amount: firstCardAmount });
            self.DualCardInstallmentManager.updateCard(2, { amount: remaining });
        }
    } else if (firstCardAmount === 0 || isNaN(firstCardAmount)) {
        $secondCardAmount.val('');
        $secondCardAmount.prop('disabled', false);
        $(this).prop('disabled', false);
        self.DualCardInstallmentManager.clearInstallments();
    }
});
// Handle second card amount change
$(document).off('change keyup blur', '#second_card_amount');
$(document).on('change keyup blur', '#second_card_amount', function() {
    var grandTotal = self.getGrandTotal();
    var secondCardAmount = parseFloat($(this).val().replace(',', '.') || 0);
    var $firstCardAmount = $('#first_card_amount');
    var firstCardAmount = parseFloat($firstCardAmount.val().replace(',', '.') || 0);

    // Se o campo do segundo cartão for preenchido
    if (secondCardAmount > 0 && secondCardAmount <= grandTotal) {
        var remaining = Math.max(0, grandTotal - secondCardAmount);
        $firstCardAmount.val(remaining.toFixed(2));
        $firstCardAmount.prop('disabled', true);
        $(this).prop('disabled', false);
        // Só dispara consulta de parcelas se ambos preenchidos
        if (remaining > 0) {
            self.DualCardInstallmentManager.updateCard(2, { amount: secondCardAmount });
            self.DualCardInstallmentManager.updateCard(1, { amount: remaining });
        }
    } else if (secondCardAmount === 0 || isNaN(secondCardAmount)) {
        $firstCardAmount.val('');
        $firstCardAmount.prop('disabled', false);
        $(this).prop('disabled', false);
        self.DualCardInstallmentManager.clearInstallments();
    }
});